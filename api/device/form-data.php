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
$venues = in_array($roleId, [1, 2], true)
    ? ($db->query('SELECT id, venue_name FROM venues ORDER BY id DESC LIMIT 300') ?: [])
    : ($db->query('SELECT id, venue_name FROM venues WHERE id = ? LIMIT 1', [(string)($user['venue_id'] ?? 0)]) ?: []);
$db->close();

auth_out(0, 'ok', ['venues' => $venues]);

