<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

auth_json_headers();
auth_handle_options();

$token = (string)($_COOKIE[AUTH_COOKIE] ?? '');
if ($token === '') {
    auth_out(1001, '未登录或会话已过期');
}

$db = new Database();
if (auth_has_column($db, 'admin_users', 'session_expires')) {
    $rows = $db->query('SELECT * FROM admin_users WHERE session_token = ? AND session_expires > NOW() LIMIT 1', [$token]);
} else {
    $rows = $db->query('SELECT * FROM admin_users WHERE session_token = ? LIMIT 1', [$token]);
}

$user = $rows[0] ?? null;
$db->close();

if (!$user) {
    auth_clear_cookie();
    auth_out(1001, '未登录或会话已过期');
}

auth_out(0, 'ok', auth_user_payload($user));

