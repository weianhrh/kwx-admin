<?php
require_once '../Database.php'; // 确保路径正确
require_once '../lib/venue_scope.php';
require_once '../lib/kwx_8899_policy.php';

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

// 仅 role_id=3 的关联 8899 场地显示驾驶订单预估收益。
// 场地集合由后台账号的关系表确定，不使用前端传入的场地 ID 授权。
$eligible8899Ids = [];
if ($role_id === 3) {
    $boundIds = venue_scope_user_ids($database, $user);
    if ($boundIds) {
        $marks = implode(',', array_fill(0, count($boundIds), '?'));
        $eligibleRows = $database->query(
            "SELECT id FROM venues
             WHERE id IN ({$marks})
               AND venue_subtitle = '8899'
               AND venue_unique_subtitle IS NOT NULL
               AND TRIM(venue_unique_subtitle) <> ''
               AND TRIM(venue_unique_subtitle) <> '8899'",
            $boundIds
        );
        if (is_array($eligibleRows)) {
            $eligible8899Ids = venue_scope_ints(array_column($eligibleRows, 'id'));
        }
    }
}
$show8899Estimate = $role_id === 3 && $incomeType !== 'gift' && $eligible8899Ids
    && ($requestedVenueId === 0 || in_array($requestedVenueId, $eligible8899Ids, true));

$estimateSelect = '';
if ($show8899Estimate) {
    $idList = implode(',', $eligible8899Ids); // IDs 已由 venue_scope_ints 转为正整数。
    $validDrivingSql = "o.reservation_id IN ({$idList})
        AND o.status = '已完成'
        AND COALESCE(o.payment_amount, 0) > 0
        AND (o.pays_type IS NULL OR o.pays_type <> '能量')
        AND (o.note IS NULL OR o.note NOT IN ('gift', '场地礼物'))
        AND NOT EXISTS (SELECT 1 FROM refund_records rr WHERE rr.order_id = o.order_id)";
    // 与首页 8899 预估口径一致：用户归属其他 8899 场地时扣订单金额的 10%。
    $promotionExistsSql = "DATE(o.end_time) >= '" . KWX_8899_CUTOVER_DATE . "' AND EXISTS (
        SELECT 1 FROM users promotion_user
        INNER JOIN venues promotion_venue
                ON promotion_venue.venue_subtitle = '8899'
               AND promotion_venue.venue_unique_subtitle IS NOT NULL
               AND TRIM(promotion_venue.venue_unique_subtitle) <> ''
               AND TRIM(promotion_venue.venue_unique_subtitle) <> '8899'
               AND promotion_venue.venue_unique_subtitle = promotion_user.invitation_code
        WHERE promotion_user.uid = o.uid
          AND promotion_venue.id <> o.reservation_id
    )";
    $promotionDeductionSql = "CASE WHEN {$promotionExistsSql}
        THEN ROUND(o.payment_amount * 0.10, 2) ELSE 0 END";
    $ordinaryRateSql = "COALESCE((
        SELECT LEAST(100, GREATEST(0, cfg.withdraw_ratio)) / 100
        FROM venue_withdrawal_configs cfg WHERE cfg.venue_id = o.reservation_id LIMIT 1
    ), 0.20)";
    $platformDeductionSql = "CASE WHEN {$promotionExistsSql}
        THEN ROUND(o.payment_amount * 0.20, 2)
        ELSE ROUND(o.payment_amount * {$ordinaryRateSql}, 2) END";
    $estimateSelect = ",
        CASE WHEN {$validDrivingSql} THEN {$promotionDeductionSql}
             ELSE NULL END AS kwx_8899_promotion_deduction,
        CASE WHEN {$validDrivingSql}
             THEN ROUND(o.payment_amount - ({$promotionDeductionSql}) - ({$platformDeductionSql}), 2)
             ELSE NULL END AS kwx_8899_estimated_income";
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
$sql = "SELECT o.*, u.nickname {$estimateSelect}
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

$estimated8899Total = null;
if ($show8899Estimate) {
    $estimateSumSql = "SELECT
        ROUND(COALESCE(SUM(o.payment_amount - ({$promotionDeductionSql}) - ({$platformDeductionSql})), 0), 2)
            AS total_estimated_income
        FROM orders o
        {$summaryWhereSql}
          AND {$validDrivingSql}";
    $estimateSumRows = $database->query($estimateSumSql, $summaryParams);
    if (is_array($estimateSumRows) && isset($estimateSumRows[0]['total_estimated_income'])) {
        $estimated8899Total = (float)$estimateSumRows[0]['total_estimated_income'];
    } else {
        $show8899Estimate = false;
    }
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
    'kwx_8899_estimate_visible' => (bool)$show8899Estimate,
    'kwx_8899_estimated_total' => $estimated8899Total,
], JSON_UNESCAPED_UNICODE);

$database->close();
?>
