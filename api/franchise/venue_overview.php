<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/_common.php';
require_once __DIR__ . '/../lib/venue_scope.php';

auth_json_headers();
auth_handle_options();

function franchise_placeholders(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

function franchise_feature_label(string $feature): string
{
    $labels = [
        'device' => '设备管理',
        'order' => '订单管理',
        'finance' => '财务管理',
        'venue' => '场地管理',
        'ops' => '运营管理',
        'tools' => '系统工具',
    ];
    return $labels[$feature] ?? '场地总览';
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

$feature = strtolower(trim((string)($_GET['feature'] ?? 'venue')));
$visibleVenues = venue_scope_visible_venues($db, $user);
$venueIds = venue_scope_ints(array_column($visibleVenues, 'id'));

if (!$venueIds) {
    $db->close();
    auth_out(0, 'ok', [
        'feature' => $feature,
        'feature_label' => franchise_feature_label($feature),
        'summary' => [
            'venue_count' => 0,
            'today_revenue' => 0,
            'today_order_count' => 0,
            'online_devices' => 0,
            'total_devices' => 0,
        ],
        'venues' => [],
    ]);
}

$todayStart = date('Y-m-d 00:00:00');
$tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));
$ph = franchise_placeholders($venueIds);

$params = array_merge(
    array_map('strval', $venueIds),
    array_map('strval', $venueIds),
    [$todayStart, $tomorrowStart],
    array_map('strval', $venueIds)
);

$rows = $db->query("
    SELECT
        v.id,
        v.venue_name,
        v.image_url,
        v.venue_status,
        COALESCE(d.total_devices, 0) AS total_devices,
        COALESCE(d.online_devices, 0) AS online_devices,
        COALESCE(d.offline_devices, 0) AS offline_devices,
        COALESCE(o.today_order_count, 0) AS today_order_count,
        COALESCE(o.today_revenue, 0) AS today_revenue
    FROM venues v
    LEFT JOIN (
        SELECT
            CAST(bind_site AS UNSIGNED) AS venue_id,
            COUNT(*) AS total_devices,
            SUM(CASE WHEN status = '在线' THEN 1 ELSE 0 END) AS online_devices,
            SUM(CASE WHEN status <> '在线' OR status IS NULL THEN 1 ELSE 0 END) AS offline_devices
        FROM vehicles
        WHERE bind_site IN ($ph)
        GROUP BY CAST(bind_site AS UNSIGNED)
    ) d ON d.venue_id = v.id
    LEFT JOIN (
        SELECT
            reservation_id AS venue_id,
            COUNT(*) AS today_order_count,
            COALESCE(ROUND(SUM(CASE WHEN pays_type <> '能量' THEN payment_amount ELSE 0 END), 2), 0) AS today_revenue
        FROM orders
        WHERE reservation_id IN ($ph)
          AND end_time >= ?
          AND end_time < ?
        GROUP BY reservation_id
    ) o ON o.venue_id = v.id
    WHERE v.id IN ($ph)
    ORDER BY v.id ASC
", $params) ?: [];

$summary = [
    'venue_count' => count($rows),
    'today_revenue' => 0.0,
    'today_order_count' => 0,
    'online_devices' => 0,
    'total_devices' => 0,
];

foreach ($rows as &$row) {
    $row['id'] = (int)$row['id'];
    $row['total_devices'] = (int)$row['total_devices'];
    $row['online_devices'] = (int)$row['online_devices'];
    $row['offline_devices'] = (int)$row['offline_devices'];
    $row['today_order_count'] = (int)$row['today_order_count'];
    $row['today_revenue'] = (float)$row['today_revenue'];

    $summary['today_revenue'] += $row['today_revenue'];
    $summary['today_order_count'] += $row['today_order_count'];
    $summary['online_devices'] += $row['online_devices'];
    $summary['total_devices'] += $row['total_devices'];
}
unset($row);

$summary['today_revenue'] = round((float)$summary['today_revenue'], 2);

$db->close();

auth_out(0, 'ok', [
    'feature' => $feature,
    'feature_label' => franchise_feature_label($feature),
    'summary' => $summary,
    'venues' => $rows,
]);

