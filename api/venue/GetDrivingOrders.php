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

$role_id = (int)$user['role_id'];

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

$order_number   = $_GET['order_number'] ?? '';
$uid            = $_GET['uid'] ?? '';
$serial_number  = $_GET['serial_number'] ?? '';
$start_date     = $_GET['start_date'] ?? '';
$end_date       = $_GET['end_date'] ?? '';
$status         = $_GET['status'] ?? '';
$exclude_energy = $_GET['exclude_energy'] ?? 'off';
$requestedVenueId = venue_scope_requested_id($_GET);

// 收入类型：all 保持旧页面“累计收入”口径；gift 仅 orders.note='场地礼物'；drive 为其余收入。
$incomeType = strtolower(trim((string)($_GET['income_type'] ?? 'all')));
if (!in_array($incomeType, ['all', 'drive', 'gift'], true)) {
    $incomeType = 'all';
}

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

// 收入按订单结束时间统计，保持现有页面口径。
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

// 先保存未按收入类型切分的条件，用于同时返回累计总收入 / 驾驶收入 / 礼物收入。
$summaryWhereSql = $whereSql;
$summaryParams = $params;

if ($incomeType === 'gift') {
    $whereSql .= " AND o.note = '场地礼物'";
} elseif ($incomeType === 'drive') {
    $whereSql .= " AND (o.note <> '场地礼物' OR o.note IS NULL)";
}

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

// 主播后台仅展示订单原金额的 80%，不修改数据库中的订单金额。
if ($role_id === 4 && is_array($data)) {
    foreach ($data as &$order) {
        $order['payment_amount'] = number_format(
            (float)($order['payment_amount'] ?? 0) * 0.8,
            2,
            '.',
            ''
        );
    }
    unset($order);
}

// 查询当前收入类型总数
$countSql = "SELECT COUNT(*) AS count FROM orders o $whereSql";
$countResult = $database->query($countSql, $params);
$totalCount = is_array($countResult) && isset($countResult[0]['count']) ? (int)$countResult[0]['count'] : 0;

// 一次聚合出总收入、驾驶收入、礼物收入。礼物收入只认 note='场地礼物'。
$summarySql = "
    SELECT
        COALESCE(SUM(o.payment_amount), 0) AS total_all_income,
        COALESCE(SUM(CASE
            WHEN o.note = '场地礼物'
            THEN o.payment_amount ELSE 0 END), 0) AS total_gift_income,
        COALESCE(SUM(CASE
            WHEN (o.note <> '场地礼物' OR o.note IS NULL)
            THEN o.payment_amount ELSE 0 END), 0) AS total_drive_income
    FROM orders o
    $summaryWhereSql
";
logMessage($summarySql . ' | params=' . json_encode($summaryParams, JSON_UNESCAPED_UNICODE));

$summaryResult = $database->query($summarySql, $summaryParams);
$summaryRow = is_array($summaryResult) && isset($summaryResult[0]) ? $summaryResult[0] : [];
$totalAllIncome = round((float)($summaryRow['total_all_income'] ?? 0), 2);
$totalDriveIncome = round((float)($summaryRow['total_drive_income'] ?? 0), 2);
$totalGiftIncome = round((float)($summaryRow['total_gift_income'] ?? 0), 2);

if ($role_id === 4) {
    $totalAllIncome = round($totalAllIncome * 0.8, 2);
    $totalDriveIncome = round($totalDriveIncome * 0.8, 2);
    $totalGiftIncome = round($totalGiftIncome * 0.8, 2);
}

$totalIncomeMap = [
    'all' => $totalAllIncome,
    'drive' => $totalDriveIncome,
    'gift' => $totalGiftIncome,
];
$totalIncome = $totalIncomeMap[$incomeType];

// 返回结果
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'code' => 0,
    'msg'  => '',
    'count' => $totalCount,
    'data'  => is_array($data) ? $data : [],
    'income_type' => $incomeType,
    'total_income' => $totalIncome,
    'total_all_income' => $totalAllIncome,
    'total_drive_income' => $totalDriveIncome,
    'total_gift_income' => $totalGiftIncome,
], JSON_UNESCAPED_UNICODE);

$database->close();
?>
