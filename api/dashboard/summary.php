<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/_common.php';
require_once __DIR__ . '/../lib/venue_scope.php';

auth_json_headers();
auth_handle_options();

function dashboard_scalar(Database $db, string $sql, array $params, string $field, $default = 0)
{
    $rows = $db->query($sql, $params);
    if (!$rows || !isset($rows[0][$field])) {
        return $default;
    }
    return $rows[0][$field];
}

function dashboard_count_pending_images(Database $db): int
{
    $dir = __DIR__ . '/../venue/pending_images/';
    if (!is_dir($dir)) {
        return 0;
    }
    $count = 0;
    foreach (scandir($dir) ?: [] as $file) {
        if (preg_match('/venue_\d+_\d{14}\.(jpg|jpeg|png|gif|webp)$/i', $file)) {
            $count++;
        }
    }
    return $count;
}

$token = (string)($_COOKIE[AUTH_COOKIE] ?? '');
if ($token === '') {
    auth_out(1001, '未登录或会话已过期');
}

$db = new Database();
if (auth_has_column($db, 'admin_users', 'session_expires')) {
    $users = $db->query('SELECT * FROM admin_users WHERE session_token = ? AND session_expires > NOW() LIMIT 1', [$token]);
} else {
    $users = $db->query('SELECT * FROM admin_users WHERE session_token = ? LIMIT 1', [$token]);
}

$user = $users[0] ?? null;
if (!$user || empty($user['role_id'])) {
    $db->close();
    auth_clear_cookie();
    auth_out(1001, '未登录或会话已过期');
}

$roleId = (int)$user['role_id'];
$venueId = (int)($user['venue_id'] ?? 0);
$todayStart = date('Y-m-d 00:00:00');
$tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));

$franchiseVenueName = $user['venue_name'] ?? '未绑定场地';
$franchiseTodayRevenue = '0.00';
$franchiseTodayOrderCount = 0;
$franchiseOnlineDevices = 0;
$franchiseTotalDevices = 0;
$franchiseVenueRows = [];
$franchiseVenueIds = [];

if ($roleId === 3) {
    $franchiseVenueRows = venue_scope_visible_venues($db, $user);
    $franchiseVenueIds = venue_scope_ints(array_column($franchiseVenueRows, 'id'));

    if (count($franchiseVenueRows) === 1) {
        $franchiseVenueName = (string)($franchiseVenueRows[0]['venue_name'] ?? $franchiseVenueName);
    } elseif (count($franchiseVenueRows) > 1) {
        $franchiseVenueName = count($franchiseVenueRows) . ' 个场地';
    }
}

if ($roleId === 3 && $franchiseVenueIds) {
    $orderRevenueParams = [$todayStart, $tomorrowStart];
    $orderRevenueWhere = venue_scope_filter_sql('reservation_id', $franchiseVenueIds, $orderRevenueParams);
    $franchiseTodayRevenue = dashboard_scalar($db, "
        SELECT COALESCE(ROUND(SUM(payment_amount), 2), 0) AS total
        FROM orders
        WHERE end_time >= ? AND end_time < ?
          AND pays_type <> '能量'
          {$orderRevenueWhere}
    ", $orderRevenueParams, 'total', '0.00');

    $orderCountParams = [$todayStart, $tomorrowStart];
    $orderCountWhere = venue_scope_filter_sql('reservation_id', $franchiseVenueIds, $orderCountParams);
    $franchiseTodayOrderCount = dashboard_scalar($db, "
        SELECT COUNT(*) AS total
        FROM orders
        WHERE end_time >= ? AND end_time < ?
          {$orderCountWhere}
    ", $orderCountParams, 'total', 0);

    $onlineParams = [];
    $onlineWhere = venue_scope_filter_sql('bind_site', $franchiseVenueIds, $onlineParams);
    $franchiseOnlineDevices = dashboard_scalar($db, "
        SELECT COUNT(*) AS total
        FROM vehicles
        WHERE status = '在线'
          {$onlineWhere}
    ", $onlineParams, 'total', 0);

    $deviceParams = [];
    $deviceWhere = venue_scope_filter_sql('bind_site', $franchiseVenueIds, $deviceParams);
    $franchiseTotalDevices = dashboard_scalar($db, "
        SELECT COUNT(*) AS total
        FROM vehicles
        WHERE 1=1
          {$deviceWhere}
    ", $deviceParams, 'total', 0);
}

$registerCount = dashboard_scalar($db, "
    SELECT COUNT(*) AS total
    FROM users
    WHERE created_at >= ? AND created_at < ?
", [$todayStart, $tomorrowStart], 'total', 0);

$activeUserCount = dashboard_scalar($db, "
    SELECT COUNT(*) AS total
    FROM users
    WHERE last_active_at >= ? AND last_active_at < ?
", [$todayStart, $tomorrowStart], 'total', 0);

$totalRecharge = dashboard_scalar($db, "
    SELECT COALESCE(ROUND(SUM(payer_total), 2), 0) AS total
    FROM RechargeOrders
    WHERE status = '支付成功'
      AND created_at >= ? AND created_at < ?
", [$todayStart, $tomorrowStart], 'total', '0.00');

$orderConsumption = dashboard_scalar($db, "
    SELECT COALESCE(ROUND(SUM(payment_amount), 2), 0) AS total
    FROM orders
    WHERE end_time >= ? AND end_time < ?
      AND pays_type <> '能量'
", [$todayStart, $tomorrowStart], 'total', '0.00');

$giftConsumption = dashboard_scalar($db, "
    SELECT COALESCE(ROUND(SUM(payment_amount) / 10 * 0.6, 2), 0) AS total
    FROM gift_orders
    WHERE send_time >= ? AND send_time < ?
      AND status = '已完成'
", [$todayStart, $tomorrowStart], 'total', '0.00');

$totalUserConsumption = number_format((float)$orderConsumption + (float)$giftConsumption, 2, '.', '');

$dollRevenueToday = dashboard_scalar($db, "
    SELECT COALESCE(ROUND(SUM(payment_amount), 2), 0) AS total
    FROM orders
    WHERE reservation_id = 60
      AND end_time >= ? AND end_time < ?
      AND TRIM(pays_type) = '余额'
      AND TRIM(note) = '娃娃机抓取扣费'
", [$todayStart, $tomorrowStart], 'total', '0.00');

$appleGoldRevenue = dashboard_scalar($db, "
    SELECT COALESCE(ROUND(SUM(p.price), 2), 0) AS total
    FROM apple_iap_orders a
    INNER JOIN iap_gold_products p ON a.product_id = p.product_id
    WHERE a.order_status = 'success'
      AND a.verify_status = 1
      AND COALESCE(a.purchase_date, a.created_at) >= CURDATE()
      AND COALESCE(a.purchase_date, a.created_at) < CURDATE() + INTERVAL 1 DAY
", [], 'total', '0.00');

$appleGoldCount = dashboard_scalar($db, "
    SELECT COUNT(*) AS total
    FROM apple_iap_orders a
    INNER JOIN iap_gold_products p ON a.product_id = p.product_id
    WHERE a.order_status = 'success'
      AND a.verify_status = 1
      AND COALESCE(a.purchase_date, a.created_at) >= CURDATE()
      AND COALESCE(a.purchase_date, a.created_at) < CURDATE() + INTERVAL 1 DAY
", [], 'total', 0);

$androidGoldRevenue = dashboard_scalar($db, "
    SELECT COALESCE(ROUND(SUM(CAST(payer_total AS DECIMAL(10,2))), 2), 0) AS total
    FROM RechargeOrders
    WHERE order_number LIKE '%GO%'
      AND status = '支付成功'
      AND created_at >= CURDATE()
      AND created_at < CURDATE() + INTERVAL 1 DAY
", [], 'total', '0.00');

$androidGoldCount = dashboard_scalar($db, "
    SELECT COUNT(*) AS total
    FROM RechargeOrders
    WHERE order_number LIKE '%GO%'
      AND status = '支付成功'
      AND created_at >= CURDATE()
      AND created_at < CURDATE() + INTERVAL 1 DAY
", [], 'total', 0);

$reportParams = [];
if (in_array($roleId, [1, 2], true)) {
    $reportSql = "
        SELECT (
            SELECT COUNT(*) FROM Reports WHERE status IN ('未处理', '处理中')
        ) + (
            SELECT COUNT(*) FROM voice_reports WHERE status IN ('未处理', '处理中')
        ) AS total
    ";
} else {
    if ($roleId === 3 && $franchiseVenueIds) {
        $reportParams = [];
        $reportDeviceWhere = venue_scope_filter_sql('v.bind_site', $franchiseVenueIds, $reportParams);
        $reportVoiceWhere = venue_scope_filter_sql('handler_uid', $franchiseVenueIds, $reportParams);
        $reportSql = "
            SELECT (
                SELECT COUNT(*)
                FROM Reports r
                INNER JOIN vehicles v ON v.serial_number = r.device_id
                WHERE r.status IN ('未处理', '处理中')
                  {$reportDeviceWhere}
            ) + (
                SELECT COUNT(*)
                FROM voice_reports
                WHERE report_type = 0
                  AND status IN ('未处理', '处理中')
                  {$reportVoiceWhere}
            ) AS total
        ";
    } else {
    $reportSql = "
        SELECT (
            SELECT COUNT(*)
            FROM Reports r
            INNER JOIN vehicles v ON v.serial_number = r.device_id
            WHERE v.bind_site = ? AND r.status IN ('未处理', '处理中')
        ) + (
            SELECT COUNT(*)
            FROM voice_reports
            WHERE report_type = 0 AND handler_uid = ? AND status IN ('未处理', '处理中')
        ) AS total
    ";
    $reportParams = [$venueId, $venueId];
    }
}
$reportCount = dashboard_scalar($db, $reportSql, $reportParams, 'total', 0);

if (in_array($roleId, [1, 2], true)) {
    $withdrawCount = dashboard_scalar($db, "
        SELECT COUNT(*) AS total
        FROM withdrawal_requests
        WHERE payout_status = 0
    ", [], 'total', 0);
} else {
    if ($roleId === 3 && $franchiseVenueIds) {
        $withdrawParams = [];
        $withdrawWhere = venue_scope_filter_sql('venue_id', $franchiseVenueIds, $withdrawParams);
        $withdrawCount = dashboard_scalar($db, "
            SELECT COUNT(*) AS total
            FROM withdrawal_requests
            WHERE payout_status = 0
              {$withdrawWhere}
        ", $withdrawParams, 'total', 0);
    } else {
        $withdrawCount = dashboard_scalar($db, "
        SELECT COUNT(*) AS total
        FROM withdrawal_requests
        WHERE payout_status = 0 AND venue_id = ?
        ", [$venueId], 'total', 0);
    }
}

$appealCount = dashboard_scalar($db, "
    SELECT COUNT(*) AS total
    FROM order_appeals
    WHERE status = 0
", [], 'total', 0);

$franchiseCount = 0;
if (in_array($roleId, [1, 2], true)) {
    $franchiseCount = dashboard_scalar($db, "
        SELECT COUNT(*) AS total
        FROM contact_form_submissions
        WHERE status = '未处理'
    ", [], 'total', 0);
}

$pendingImages = in_array($roleId, [1, 2], true) ? dashboard_count_pending_images($db) : 0;

$db->close();

$quickActions = [
    ['title' => '设备管理', 'group' => 'device', 'jump' => '/iframe/link/deviceMgt', 'badge' => 0, 'roles' => [1, 2]],
    ['title' => '场地管理', 'group' => 'venue', 'jump' => '/iframe/link/VenuesManagement', 'badge' => 0, 'roles' => [1, 2]],
    ['title' => '用户管理', 'group' => 'user', 'jump' => '/iframe/link/Balancerecharge', 'badge' => 0, 'roles' => [1, 2]],
    ['title' => '订单查询', 'group' => 'order', 'jump' => '/iframe/link/AdminDrivingOrders', 'badge' => 0, 'roles' => [1, 2]],
    ['title' => '消费查询', 'group' => 'finance', 'jump' => '/iframe/link/Comsumquery_v2', 'badge' => 0, 'roles' => [1, 2]],
    ['title' => '专区管理', 'group' => 'venue', 'jump' => '/iframe/link/zonemgt', 'badge' => 0, 'roles' => [1, 2]],
    ['title' => '消息管理', 'group' => 'ops', 'jump' => '/iframe/link/MessageMgmt', 'badge' => 0, 'roles' => [1, 2]],
    ['title' => '资费商品', 'group' => 'finance', 'jump' => '/iframe/link/pricing_options', 'badge' => 0, 'roles' => [1, 2]],
];

$quickActions = array_values(array_filter($quickActions, function ($item) use ($roleId) {
    return in_array($roleId, $item['roles'], true);
}));

auth_out(0, 'ok', [
    'role_id' => $roleId,
    'venue_name' => $user['venue_name'] ?? '酷玩星工作台',
    'metrics' => [
        'registerCount' => (int)$registerCount,
        'activeUserCount' => (int)$activeUserCount,
        'totalRecharge' => (float)$totalRecharge,
        'totalUserConsumption' => (float)$totalUserConsumption,
        'dollRevenueToday' => (float)$dollRevenueToday,
        'todayGoldRechargeRevenue' => (float)$appleGoldRevenue + (float)$androidGoldRevenue,
        'goldRechargeOrderCount' => (int)$appleGoldCount + (int)$androidGoldCount,
        'franchiseVenueCount' => count($franchiseVenueIds),
        'franchiseTodayRevenue' => (float)$franchiseTodayRevenue,
        'franchiseTodayOrderCount' => (int)$franchiseTodayOrderCount,
        'franchiseOnlineDevices' => (int)$franchiseOnlineDevices,
        'franchiseTotalDevices' => (int)$franchiseTotalDevices,
    ],
    'todos' => [
        'appealCount' => (int)$appealCount,
        'reportCount' => (int)$reportCount,
        'withdrawCount' => (int)$withdrawCount,
        'pendingImages' => (int)$pendingImages,
        'franchiseCount' => (int)$franchiseCount,
    ],
    'quickActions' => $quickActions,
    'franchise' => [
        'venue_id' => $franchiseVenueIds[0] ?? $venueId,
        'venue_name' => $franchiseVenueName,
        'venues' => $franchiseVenueRows,
        'notices' => [
            ['title' => '运营提醒', 'content' => '请保持车辆电量、网络与视频画面稳定，避免影响玩家远程驾驶体验。', 'date' => date('Y-m-d')],
            ['title' => '结算提示', 'content' => '今日收益按场地订单实时汇总，最终结算以财务审核后的账单为准。', 'date' => date('Y-m-d')],
        ],
    ],
]);
