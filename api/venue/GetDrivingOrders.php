<?php
require_once '../Database.php'; // 确保路径正确
require_once '../lib/venue_scope.php';
function logMessage($message) {
    $logFile = __DIR__ . '/order_test.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}
$database = new Database();
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

$role_id = $user['role_id'];

$page = $_GET['page'] ?? 1;
$limit = $_GET['limit'] ?? 20;
$offset = ($page - 1) * $limit;

$order_number   = $_GET['order_number'] ?? '';
$uid            = $_GET['uid'] ?? '';
$serial_number  = $_GET['serial_number'] ?? '';
$start_date     = $_GET['start_date'] ?? '';
$end_date       = $_GET['end_date'] ?? '';
$status         = $_GET['status'] ?? '';
$exclude_energy = $_GET['exclude_energy'] ?? 'off';
$requestedVenueId = venue_scope_requested_id($_GET);

$whereSql = " WHERE 1=1";
$params = [];

// 模糊查询订单号
if (!empty($order_number)) {
    $whereSql .= " AND order_id LIKE ?";
    $params[] = "%$order_number%";
}

// 指定UID
if (!empty($uid)) {
    $whereSql .= " AND o.uid = ?";
    $params[] = $uid;
}

// 设备号模糊查询
if (!empty($serial_number)) {
    $whereSql .= " AND serial_number LIKE ?";
    $params[] = "%$serial_number%";
}

// 起始时间：精确到分钟
// if (!empty($start_date)) {
//     $whereSql .= " AND start_time >= ?";
//     $params[] = $start_date;
// }

// if (!empty($end_date)) {
//     $whereSql .= " AND start_time <= ?";
//     $params[] = $end_date;
// }
if (!empty($start_date)) {
    $whereSql .= " AND end_time >= ?";
    $params[] = $start_date;
}

if (!empty($end_date)) {
    $whereSql .= " AND end_time <= ?";
    $params[] = $end_date;
}


// 状态筛选
if (!empty($status)) {
    $whereSql .= " AND status = ?";
    $params[] = $status;
}

// 排除能量支付类型
if ($exclude_energy === 'on') {
    $whereSql .= " AND pays_type != '能量'";
}

$whereSql .= venue_scope_apply_filter($database, $user, 'o.reservation_id', $params, $requestedVenueId);

// 查询语句（关联昵称）
$sql = "SELECT o.*, u.nickname 
        FROM orders o 
        JOIN users u ON o.uid = u.uid 
        $whereSql 
        ORDER BY o.start_time DESC 
        LIMIT ?, ?";

$paramsForData = $params;
$paramsForData[] = (int)$offset;
$paramsForData[] = (int)$limit;

$data = $database->query($sql, $paramsForData);

// 查询总数
$countSql = "SELECT COUNT(*) AS count FROM orders o $whereSql";
$countResult = $database->query($countSql, $params);
$totalCount = is_array($countResult) && isset($countResult[0]['count']) ? $countResult[0]['count'] : 0;

// 构建总金额统计 SQL
$sumSql = "SELECT SUM(payment_amount) as total_income FROM orders o $whereSql";
logMessage($sumSql . ' | params=' . json_encode($params));

$sumResult = $database->query($sumSql, $params);
$totalIncome = 0.00;

if (is_array($sumResult) && isset($sumResult[0]['total_income'])) {
    $totalIncome = round(floatval($sumResult[0]['total_income']), 2);
}

// 返回结果
echo json_encode([
    'code' => 0,
    'msg'  => '',
    'count' => $totalCount,
    'data'  => $data,
    'total_income' => $totalIncome
]);

$database->close();
?>
