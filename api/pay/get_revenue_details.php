<?php
require_once '../Database.php';
require_once '../lib/venue_scope.php';

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

$requestedVenueId = venue_scope_requested_id($_GET);

// 获取时间范围和分页参数
$start = $_GET['start'] ?? date('Y-m-d');
$end = $_GET['end'] ?? $start;
$page = intval($_GET['page'] ?? 1);
$limit = intval($_GET['limit'] ?? 10);
$offset = ($page - 1) * $limit;

// 查询总记录数（当前可访问场地）
$scopeParams = [$start, $end];
$scopeSql = venue_scope_apply_filter($database, $user, 'venue_id', $scopeParams, $requestedVenueId);
$totalSql = "SELECT COUNT(*) AS total FROM VenueRevenueDetails 
             WHERE date BETWEEN ? AND ? {$scopeSql}";
$totalResult = $database->query($totalSql, $scopeParams);
$totalRecords = $totalResult[0]['total'];
$totalPages = ceil($totalRecords / $limit);

$fetchParams = $scopeParams;
$fetchParams[] = $limit;
$fetchParams[] = $offset;

// 查询分页数据（当前可访问场地）
$fetchSql = "SELECT * FROM VenueRevenueDetails 
             WHERE date BETWEEN ? AND ? {$scopeSql}
             ORDER BY date DESC, time_start ASC
             LIMIT ? OFFSET ?";
$records = $database->query($fetchSql, $fetchParams);

echo json_encode([
    'code' => 0,
    'msg' => 'success',
    'records' => $records,
    'totalPages' => $totalPages
]);

$database->close();
?>
