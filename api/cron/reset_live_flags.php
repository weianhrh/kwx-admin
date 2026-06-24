<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

date_default_timezone_set('Asia/Taipei');

header('Content-Type: application/json; charset=utf-8');

$database = new Database();
$conn = $database->getConnection();

$batchNo   = 'live_cleanup_' . date('Ymd_His');
$cleanedAt = date('Y-m-d H:i:s');

try {
    $conn->begin_transaction();

    // 1. 查出当前所有 is_live=1 的场地，并锁住
    $sql = "
        SELECT id, venue_name, is_recommend, live_marked_at
        FROM venues
        WHERE is_live = 1
        FOR UPDATE
    ";
    $result = $conn->query($sql);

    $liveVenues = [];
    while ($row = $result->fetch_assoc()) {
        $liveVenues[] = $row;
    }

    $affected = count($liveVenues);

    // 2. 先写清理日志
    if ($affected > 0) {
        $insertSql = "
            INSERT INTO venue_live_cleanup_logs
            (batch_no, venue_id, venue_name, was_recommend, live_marked_at, cleaned_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        $stmt = $conn->prepare($insertSql);

        foreach ($liveVenues as $venue) {
            $venueId       = (int)$venue['id'];
            $venueName     = (string)($venue['venue_name'] ?? '');
            $wasRecommend  = (int)($venue['is_recommend'] ?? 0);
            $liveMarkedAt  = $venue['live_marked_at'] ?: null;

            $stmt->bind_param(
                'sissss',
                $batchNo,
                $venueId,
                $venueName,
                $wasRecommend,
                $liveMarkedAt,
                $cleanedAt
            );
            $stmt->execute();
        }
        $stmt->close();

        // 3. 再统一清理直播标记，并打上优先审核标记
        $updateSql = "
            UPDATE venues
            SET is_live = 0,
                live_marked_at = NULL,
                need_live_review = 1,
                live_review_priority = 1,
                last_live_cleared_at = ?
            WHERE is_live = 1
        ";
        $stmt2 = $conn->prepare($updateSql);
        $stmt2->bind_param('s', $cleanedAt);
        $stmt2->execute();
        $stmt2->close();
    }

    $conn->commit();

    echo json_encode([
        'code' => 0,
        'msg' => '清理完成',
        'batch_no' => $batchNo,
        'cleaned_at' => $cleanedAt,
        'affected' => $affected
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($conn->errno === 0 || $conn) {
        $conn->rollback();
    }

    http_response_code(500);
    echo json_encode([
        'code' => 500,
        'msg' => '清理失败：' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}