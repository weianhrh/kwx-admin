<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function json_out($success, $message, $data = [])
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function risk_text($level)
{
    switch ($level) {
        case 'high': return '高风险';
        case 'medium': return '中风险';
        case 'low': return '低风险';
        case 'safe': return '安全';
        default: return '未知';
    }
}

function build_local_image_url($grabTime, $localImageName, $fallbackUrl = '')
{
    if (!empty($localImageName) && !empty($grabTime)) {
        $dateDir = date('Y-m-d', strtotime($grabTime));
        return '/api/pop/review_backup/' . $dateDir . '/' . $localImageName;
    }
    return $fallbackUrl;
}

$db = new Database();

try {
// 固定口径：当天 04:00:00 到当天 09:00:00，只看高风险
$defaultStart = date('Y-m-d 04:00:00');
$defaultEnd   = date('Y-m-d 09:00:00');

    // 前端已经去掉时间筛选，这里保留 GET 参数只是为了你临时调试接口方便
    $startTime = trim($_GET['start_time'] ?? $defaultStart);
    $endTime   = trim($_GET['end_time'] ?? $defaultEnd);
    $status    = trim($_GET['status'] ?? 'all');
    $keyword   = trim($_GET['keyword'] ?? '');

    $page = intval($_GET['page'] ?? 1);
    if ($page < 1) $page = 1;

    $pageSize = intval($_GET['page_size'] ?? 20);
    if ($pageSize < 1) $pageSize = 20;
    if ($pageSize > 100) $pageSize = 100; // 防止一次拉太多又卡

    $offset = ($page - 1) * $pageSize;

    $where = [
        "first_seen_at >= ?",
        "first_seen_at < ?",
        "risk_level = ?",
        "(review_ignore IS NULL OR review_ignore <> 1)"
    ];
    $params = [$startTime, $endTime, 'high'];

    if ($status !== '' && $status !== 'all') {
        $where[] = "review_status = ?";
        $params[] = $status;
    }

    if ($keyword !== '') {
        $where[] = "(venue_name LIKE ? OR device_name LIKE ? OR image_device_serial LIKE ? OR ban_reason LIKE ? OR reason LIKE ?)";
        $kw = '%' . $keyword . '%';
        array_push($params, $kw, $kw, $kw, $kw, $kw);
    }

    $whereSql = implode(' AND ', $where);

    // 先查总数，前端才能知道总页数
    $countSql = "
        SELECT COUNT(*) AS total
        FROM device_violation_archive
        WHERE {$whereSql}
    ";
    $countRows = $db->query($countSql, $params);
    if ($countRows === false) {
        json_out(false, '统计失败');
    }
    $total = intval($countRows[0]['total'] ?? 0);
    $totalPages = $pageSize > 0 ? (int)ceil($total / $pageSize) : 1;
    if ($totalPages < 1) $totalPages = 1;

    // 如果当前页被批量忽略后超出总页数，自动回到最后一页，避免前端出现空白页
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $pageSize;

    // 再查当前页数据，注意 LIMIT/OFFSET 用上面 intval 之后的数字拼进去
    $sql = "
        SELECT
            id,
            archive_key,
            redis_key,
            image_device_serial,
            serial_number,
            device_name,
            venue_id,
            venue_name,
            source_type,
            risk_level,
            manual_risk,
            reason,
            remark,
            image_url,
            local_image_path,
            local_image_name,
            hit_count,
            raw_json,
            review_status,
            is_append_processed,
            review_ignore,
            grab_time,
            first_seen_at,
            last_seen_at,
            status_updated_at,
            banned_at,
            cleared_at,
            disappeared_at,
            ban_duration_minutes,
            ban_reason,
            created_at,
            updated_at
        FROM device_violation_archive
        WHERE {$whereSql}
        ORDER BY first_seen_at DESC, id DESC
        LIMIT {$pageSize} OFFSET {$offset}
    ";

    $rows = $db->query($sql, $params);
    if ($rows === false) {
        json_out(false, '查询失败');
    }

    $list = [];
    foreach ($rows as $row) {
        $reviewStatus = $row['review_status'] ?? '';

        if ($reviewStatus === 'pending') {
            $displayStatus = '消失未处理';
            $displayTime = $row['first_seen_at'] ?: '';
        } elseif ($reviewStatus === 'banned') {
            $displayStatus = '已追封';
            $displayTime = $row['banned_at'] ?: ($row['status_updated_at'] ?: '');
        } elseif ($reviewStatus === 'cleared') {
            $displayStatus = '已清理';
            $displayTime = $row['cleared_at'] ?: ($row['status_updated_at'] ?: '');
        } else {
            $displayStatus = $reviewStatus ?: '未知';
            $displayTime = $row['status_updated_at'] ?: ($row['first_seen_at'] ?: '');
        }

        $row['risk_level_text'] = risk_text($row['risk_level'] ?? '');
        $row['display_status'] = $displayStatus;
        $row['display_time'] = $displayTime;
        $row['display_image_url'] = build_local_image_url(
            $row['grab_time'] ?? '',
            $row['local_image_name'] ?? '',
            $row['image_url'] ?? ''
        );

        $list[] = $row;
    }

    json_out(true, '查询成功', [
        'start_time' => $startTime,
        'end_time' => $endTime,
        'status' => $status,
        'keyword' => $keyword,
        'risk_level' => 'high',
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'total_pages' => $totalPages,
        'list' => $list
    ]);

} catch (Exception $e) {
    json_out(false, '异常：' . $e->getMessage());
}
?>
