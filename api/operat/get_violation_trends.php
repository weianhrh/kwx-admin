<?php
require_once '../Database.php';

$database = new Database();
$granularity = $_GET['granularity'] ?? 'day'; // 'day', 'week', 'month'
$venue_id = $_GET['venue_id'] ?? 'all';

// 设置时间范围，默认近 30 天
$end = date('Y-m-d');
$start = date('Y-m-d', strtotime('-30 days'));

function getDateFormat($granularity) {
    switch ($granularity) {
        case 'week': return "%Y-%u"; // 周
        case 'month': return "%Y-%m"; // 月
        default: return "%Y-%m-%d"; // 日
    }
}

$dateFormat = getDateFormat($granularity);

// 设备违规统计
$sql_device = "
SELECT DATE_FORMAT(created_at, ?) AS period, COUNT(*) as count 
FROM device_bans
" . ($venue_id !== 'all' ? " WHERE venue_id = ?" : "") . "
GROUP BY period
ORDER BY period ASC
";
$params = [$dateFormat];
if ($venue_id !== 'all') $params[] = $venue_id;
$device_data = $database->query($sql_device, $params);

// 场地违规统计
$sql_venue = "
SELECT DATE_FORMAT(created_at, ?) AS period, COUNT(*) as count 
FROM venue_bans
" . ($venue_id !== 'all' ? " WHERE venue_id = ?" : "") . "
GROUP BY period
ORDER BY period ASC
";
$venue_data = $database->query($sql_venue, $params);

// 对齐时间轴
$labels = [];
$device_map = [];
$venue_map = [];

foreach ($device_data as $row) {
    $labels[] = $row['period'];
    $device_map[$row['period']] = (int)$row['count'];
}
foreach ($venue_data as $row) {
    if (!in_array($row['period'], $labels)) $labels[] = $row['period'];
    $venue_map[$row['period']] = (int)$row['count'];
}

sort($labels);

$device_counts = [];
$venue_counts = [];
foreach ($labels as $label) {
    $device_counts[] = $device_map[$label] ?? 0;
    $venue_counts[] = $venue_map[$label] ?? 0;
}

echo json_encode([
    'code' => 0,
    'msg' => '趋势数据获取成功',
    'data' => [
        'labels' => $labels,
        'device_bans' => $device_counts,
        'venue_bans' => $venue_counts
    ]
]);
