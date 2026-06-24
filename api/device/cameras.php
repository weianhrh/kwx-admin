<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/_common.php';

auth_json_headers();
auth_handle_options();

$token = (string)($_COOKIE[AUTH_COOKIE] ?? '');
if ($token === '') auth_out(1001, '未登录或会话已过期');

$db = new Database();
$users = $db->query('SELECT * FROM admin_users WHERE session_token = ? LIMIT 1', [$token]);
$user = $users[0] ?? null;
if (!$user || empty($user['role_id'])) {
    $db->close();
    auth_clear_cookie();
    auth_out(1001, '未登录或会话已过期');
}

$roleId = (int)$user['role_id'];
$venueId = (int)($user['venue_id'] ?? 0);
$where = [];
$params = [];
if (!in_array($roleId, [1, 2], true) && $venueId > 0) {
    $where[] = 'v.bind_site = ?';
    $params[] = (string)$venueId;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$rows = $db->query("
    SELECT di.id, di.device_id, di.playing_stream_id, di.room_id, di.rtc_user_id,
           v.serial_number, v.name, v.status, v.bind_site, ven.venue_name
    FROM device_information di
    LEFT JOIN vehicles v ON CAST(di.id AS CHAR) = v.image_device_serial
    LEFT JOIN venues ven ON CAST(ven.id AS CHAR) = v.bind_site
    $whereSql
    ORDER BY di.id DESC
    LIMIT 120
", $params);
$db->close();

auth_out(0, 'ok', ['rows' => $rows ?: []]);

