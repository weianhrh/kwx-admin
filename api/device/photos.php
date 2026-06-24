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
    SELECT v.serial_number, v.name, v.photo_url, v.image_device_serial, v.bk_image_device_serial,
           v.bind_site, ven.venue_name, v.updated_at
    FROM vehicles v
    LEFT JOIN venues ven ON CAST(ven.id AS CHAR) = v.bind_site
    $whereSql
    ORDER BY v.updated_at DESC, v.id DESC
    LIMIT 120
", $params);
$db->close();

auth_out(0, 'ok', ['rows' => $rows ?: []]);

