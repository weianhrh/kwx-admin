<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/_common.php';

auth_json_headers();
auth_handle_options();

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
$userVenueId = (int)($user['venue_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;
$keyword = trim((string)($_GET['keyword'] ?? ''));
$venueId = trim((string)($_GET['venue_id'] ?? ''));

$where = [];
$params = [];

if (!in_array($roleId, [1, 2], true) && $userVenueId > 0) {
    $where[] = 'v.bind_site = ?';
    $params[] = (string)$userVenueId;
} elseif ($venueId !== '' && ctype_digit($venueId)) {
    $where[] = 'v.bind_site = ?';
    $params[] = $venueId;
}

if ($keyword !== '') {
    $like = '%' . $keyword . '%';
    $where[] = "(
        v.serial_number LIKE ?
        OR v.name LIKE ?
        OR v.share_name LIKE ?
        OR v.image_device_serial LIKE ?
        OR v.bk_image_device_serial LIKE ?
        OR di.room_id LIKE ?
        OR ven.venue_name LIKE ?
    )";
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$totalRows = $db->query("
    SELECT COUNT(*) AS total
    FROM vehicles v
    LEFT JOIN venues ven ON CAST(ven.id AS CHAR) = v.bind_site
    LEFT JOIN device_information di ON CAST(di.id AS CHAR) = v.image_device_serial
    $whereSql
", $params);
$total = (int)($totalRows[0]['total'] ?? 0);

$rows = $db->query("
    SELECT
        v.uid,
        v.bind_site,
        ven.venue_name,
        v.serial_number,
        v.status,
        v.is_banned,
        v.name,
        v.share_name,
        v.sharing_status,
        v.photo_url,
        v.image_device_serial,
        di.room_id AS image_device_room_id,
        v.bk_image_device_serial,
        v.battery_level,
        v.voltage,
        v.vehicle_status,
        v.updated_at,
        vcs.car_type
    FROM vehicles v
    LEFT JOIN venues ven ON CAST(ven.id AS CHAR) = v.bind_site
    LEFT JOIN vehicle_control_settings vcs ON vcs.serial_number = v.serial_number
    LEFT JOIN device_information di ON CAST(di.id AS CHAR) = v.image_device_serial
    $whereSql
    ORDER BY v.updated_at DESC, v.id DESC
    LIMIT $limit OFFSET $offset
", $params);

$statsRows = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN v.status = '在线' THEN 1 ELSE 0 END) AS online,
        SUM(CASE WHEN v.status <> '在线' OR v.status IS NULL THEN 1 ELSE 0 END) AS offline,
        SUM(CASE WHEN v.is_banned = 1 THEN 1 ELSE 0 END) AS banned
    FROM vehicles v
    LEFT JOIN venues ven ON CAST(ven.id AS CHAR) = v.bind_site
    LEFT JOIN device_information di ON CAST(di.id AS CHAR) = v.image_device_serial
    $whereSql
", $params);

$venues = [];
if (in_array($roleId, [1, 2], true)) {
    $venues = $db->query('SELECT id, venue_name FROM venues ORDER BY id DESC LIMIT 300') ?: [];
}

$db->close();

auth_out(0, 'ok', [
    'page' => $page,
    'limit' => $limit,
    'total' => $total,
    'stats' => [
        'total' => (int)($statsRows[0]['total'] ?? 0),
        'online' => (int)($statsRows[0]['online'] ?? 0),
        'offline' => (int)($statsRows[0]['offline'] ?? 0),
        'banned' => (int)($statsRows[0]['banned'] ?? 0),
    ],
    'venues' => $venues,
    'devices' => $rows ?: [],
]);

