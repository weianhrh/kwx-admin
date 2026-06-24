<?php
require_once '../Database.php';

$database = new Database();

// 获取当前用户身份
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

$venue_id = $user['venue_id'];

// 获取时间范围和分页参数
$start = $_GET['start'] ?? date('Y-m-d');
$end = $_GET['end'] ?? $start;
$page = intval($_GET['page'] ?? 1);
$limit = intval($_GET['limit'] ?? 10);
$offset = ($page - 1) * $limit;

// 查询总记录数（当前场地）
$totalSql = "SELECT COUNT(*) AS total FROM VenueRevenueDetails 
             WHERE date BETWEEN ? AND ? AND venue_id = ?";
$totalResult = $database->query($totalSql, [$start, $end, $venue_id]);
$totalRecords = $totalResult[0]['total'];
$totalPages = ceil($totalRecords / $limit);

// 查询分页数据（当前场地）
$fetchSql = "SELECT * FROM VenueRevenueDetails 
             WHERE date BETWEEN ? AND ? AND venue_id = ? 
             ORDER BY date DESC, time_start ASC
             LIMIT ? OFFSET ?";
$records = $database->query($fetchSql, [$start, $end, $venue_id, $limit, $offset]);

echo json_encode([
    'code' => 0,
    'msg' => 'success',
    'records' => $records,
    'totalPages' => $totalPages
]);

$database->close();
?>
