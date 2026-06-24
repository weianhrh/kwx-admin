<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

$database = new Database();

$keyword = trim($_GET['keyword'] ?? '');
$keyword = substr($keyword, 0, 100);

try {
    if ($keyword !== '') {
        $like = '%' . $keyword . '%';

        $query = "
            SELECT 
                id,
                venue_name,
                image_url,
                start_time,
                queue_length,
                zone_id
            FROM venues
            WHERE venue_name LIKE ?
               OR CAST(id AS CHAR) LIKE ?
            ORDER BY id DESC
        ";

        $stmt = $database->prepare($query);
        $stmt->bind_param("ss", $like, $like);
    } else {
        $query = "
            SELECT 
                id,
                venue_name,
                image_url,
                start_time,
                queue_length,
                zone_id
            FROM venues
            ORDER BY id DESC
        ";

        $stmt = $database->prepare($query);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'code' => 0,
        'msg'  => '获取成功',
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    echo json_encode([
        'code' => 500,
        'msg'  => '服务器异常：' . $e->getMessage(),
        'data' => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}