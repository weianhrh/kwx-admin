<?php
require_once '../Database.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_COOKIE['session_token'])) {
    header("Location: login.html");
    exit;
}

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->getUserBySessionToken($session_token);

if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$role_id  = (int)$user['role_id'];
$venue_id = (int)($user['venue_id'] ?? 0);

$todayStart    = date('Y-m-d 00:00:00');
$tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));

// 今日注册用户数量
$registerCountSql = "
    SELECT COUNT(*) AS register_count
    FROM users
    WHERE created_at >= ? AND created_at < ?
";
$registerCount = $database->query($registerCountSql, [$todayStart, $tomorrowStart])[0]['register_count'] ?? 0;

// 今日实际充值总额
$rechargeTotalSql = "
    SELECT COALESCE(ROUND(SUM(payer_total), 2), 0) AS total_recharged_today
    FROM RechargeOrders
    WHERE status = '支付成功'
      AND created_at >= ? AND created_at < ?
";
$totalRechargeFromBalanceChanges = $database->query($rechargeTotalSql, [$todayStart, $tomorrowStart])[0]['total_recharged_today'] ?? '0.00';

// 今日用户实际总消费：订单消费 + 礼物收益
$UserConsumptionSql = "
    SELECT COALESCE(ROUND(SUM(payment_amount), 2), 0) AS total_consumption
    FROM orders
    WHERE end_time >= ? AND end_time < ?
      AND pays_type <> '能量'
";
$orderConsumption = $database->query($UserConsumptionSql, [
    $todayStart,
    $tomorrowStart
])[0]['total_consumption'] ?? '0.00';


// 今日礼物收益：gift_orders 金币数 / 10 * 60%
$GiftConsumptionSql = "
    SELECT COALESCE(ROUND(SUM(payment_amount) / 10 * 0.6, 2), 0) AS gift_consumption
    FROM gift_orders
    WHERE send_time >= ? AND send_time < ?
      AND status = '已完成'
";
$giftConsumption = $database->query($GiftConsumptionSql, [
    $todayStart,
    $tomorrowStart
])[0]['gift_consumption'] ?? '0.00';


// 今日用户实际总消费 = 普通订单消费 + 礼物收益
$totalUserConsumption = number_format(
    floatval($orderConsumption) + floatval($giftConsumption),
    2,
    '.',
    ''
);
// 今日娃娃机收益
$dollRevenueSql = "
    SELECT COALESCE(ROUND(SUM(payment_amount), 2), 0) AS doll_revenue_today
    FROM orders
    WHERE reservation_id = 60
      AND end_time >= ? AND end_time < ?
      AND TRIM(pays_type) = '余额'
      AND TRIM(note) = '娃娃机抓取扣费'
";
$dollRevenueToday = $database->query($dollRevenueSql, [$todayStart, $tomorrowStart])[0]['doll_revenue_today'] ?? '0.00';
// 今日活跃用户数
$activeUserCountSql = "
    SELECT COUNT(*) AS active_user_count
    FROM users
    WHERE last_active_at >= ? AND last_active_at < ?
";
$activeUserCount = $database->query($activeUserCountSql, [$todayStart, $tomorrowStart])[0]['active_user_count'] ?? 0;
/*金币*/
/*
$goldRechargeRevenueSql = "
    SELECT
        COALESCE(SUM(p.price), 0) AS today_gold_revenue,
        COUNT(*) AS gold_recharge_order_count
    FROM apple_iap_orders a
    INNER JOIN iap_gold_products p
        ON a.product_id = p.product_id
    WHERE a.order_status = 'success'
      AND a.verify_status = 1
      AND COALESCE(a.purchase_date, a.created_at) >= CURDATE()
      AND COALESCE(a.purchase_date, a.created_at) < CURDATE() + INTERVAL 1 DAY
";
$goldRechargeRevenueResult = $database->query($goldRechargeRevenueSql);
$todayGoldRechargeRevenue = $goldRechargeRevenueResult[0]['today_gold_revenue'] ?? '0.00';
$goldRechargeOrderCount = $goldRechargeRevenueResult[0]['gold_recharge_order_count'] ?? 0;
*/

/* 金币充值收益：苹果 + 安卓 */

// 1. 苹果金币充值收益
$appleGoldRechargeRevenueSql = "
    SELECT
        COALESCE(ROUND(SUM(p.price), 2), 0) AS apple_gold_revenue,
        COUNT(*) AS apple_gold_order_count
    FROM apple_iap_orders a
    INNER JOIN iap_gold_products p
        ON a.product_id = p.product_id
    WHERE a.order_status = 'success'
      AND a.verify_status = 1
      AND COALESCE(a.purchase_date, a.created_at) >= CURDATE()
      AND COALESCE(a.purchase_date, a.created_at) < CURDATE() + INTERVAL 1 DAY
";
$appleGoldResult = $database->query($appleGoldRechargeRevenueSql);
$appleGoldRechargeRevenue = $appleGoldResult[0]['apple_gold_revenue'] ?? '0.00';
$appleGoldRechargeOrderCount = $appleGoldResult[0]['apple_gold_order_count'] ?? 0;


// 2. 安卓金币充值收益
$androidGoldRechargeRevenueSql = "
    SELECT
        COALESCE(ROUND(SUM(CAST(payer_total AS DECIMAL(10,2))), 2), 0) AS android_gold_revenue,
        COUNT(*) AS android_gold_order_count
    FROM RechargeOrders
    WHERE order_number LIKE '%GO%'
      AND status = '支付成功'
      AND created_at >= CURDATE()
      AND created_at < CURDATE() + INTERVAL 1 DAY
";
$androidGoldResult = $database->query($androidGoldRechargeRevenueSql);
$androidGoldRechargeRevenue = $androidGoldResult[0]['android_gold_revenue'] ?? '0.00';
$androidGoldRechargeOrderCount = $androidGoldResult[0]['android_gold_order_count'] ?? 0;


// 3. 今日金币充值总收益 = 苹果 + 安卓
$todayGoldRechargeRevenue = number_format(
    floatval($appleGoldRechargeRevenue) + floatval($androidGoldRechargeRevenue),
    2,
    '.',
    ''
);

// 4. 今日金币充值订单数 = 苹果 + 安卓
$goldRechargeOrderCount =
    intval($appleGoldRechargeOrderCount) + intval($androidGoldRechargeOrderCount);

// 待处理举报数
if (in_array($role_id, [1, 2], true)) {
    // 管理员：统计全部设备举报 + 全部语音房举报
    $reportCountSql = "
        SELECT
            (
                SELECT COUNT(*)
                FROM Reports
                WHERE status IN ('未处理', '处理中')
            ) +
            (
                SELECT COUNT(*)
                FROM voice_reports
                WHERE status IN ('未处理', '处理中')
            ) AS report_count
    ";
    $reportCountResult = $database->query($reportCountSql);
} else {
    // 非管理员：统计本场地设备举报 + 本场地房间举报
    // voice_reports 中：
    // report_type = 0 表示房间举报
    // handler_uid   = 场地 venues.id
    $reportCountSql = "
        SELECT
            (
                SELECT COUNT(*)
                FROM Reports r
                INNER JOIN vehicles v ON v.serial_number = r.device_id
                WHERE v.bind_site = ?
                  AND r.status IN ('未处理', '处理中')
            ) +
            (
                SELECT COUNT(*)
                FROM voice_reports
                WHERE report_type = 0
                  AND handler_uid = ?
                  AND status IN ('未处理', '处理中')
            ) AS report_count
    ";
    $reportCountResult = $database->query($reportCountSql, [$venue_id, $venue_id]);
}

// 待审核提现
if (in_array($role_id, [1, 2], true)) {
    $PendingwithdrawalapprovalSql = "
        SELECT COUNT(*) AS total_count
        FROM withdrawal_requests
        WHERE payout_status = 0
    ";
    $totalPendingwithdrawalapproval = $database->query($PendingwithdrawalapprovalSql);
} else {
    $PendingwithdrawalapprovalSql = "
        SELECT COUNT(*) AS total_count
        FROM withdrawal_requests
        WHERE payout_status = 0
          AND venue_id = ?
    ";
    $totalPendingwithdrawalapproval = $database->query($PendingwithdrawalapprovalSql, [$venue_id]);
}

// 申诉数
$appeals_sql = "
    SELECT COUNT(*) AS appeal_count
    FROM order_appeals
    WHERE status = 0
";
$appeals = $database->query($appeals_sql);

echo json_encode([
    'code' => 0,
    'msg'  => '',
    'data' => [
        'role_id' => $role_id,
        'registerCount' => $registerCount,
        'totalRechargeFromBalanceChanges' => $totalRechargeFromBalanceChanges,
                        // 新增：今日苹果金币充值收益
        'todayGoldRechargeRevenue' => $todayGoldRechargeRevenue,

        // 新增：今日苹果金币充值订单数
        'goldRechargeOrderCount' => (int)$goldRechargeOrderCount,
        'totalUserConsumption' => $totalUserConsumption,
        'activeUserCount' => $activeUserCount,
        'reportCount' => $reportCountResult[0]['report_count'] ?? 0,
        'totalPendingwithdrawalapproval' => $totalPendingwithdrawalapproval[0]['total_count'] ?? 0,
        'appeal_count' => $appeals[0]['appeal_count'] ?? 0,
        'dollRevenueToday' => $dollRevenueToday
    ]
], JSON_UNESCAPED_UNICODE);

$database->close();
?>