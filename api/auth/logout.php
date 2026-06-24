<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

auth_json_headers();
auth_handle_options();

$token = (string)($_COOKIE[AUTH_COOKIE] ?? '');
if ($token !== '') {
    $db = new Database();
    if (auth_has_column($db, 'admin_users', 'session_expires')) {
        $db->query('UPDATE admin_users SET session_token = NULL, session_expires = NULL WHERE session_token = ?', [$token], true);
    } else {
        $db->query('UPDATE admin_users SET session_token = NULL WHERE session_token = ?', [$token], true);
    }
    $db->close();
}

auth_clear_cookie();
auth_out(0, '已退出登录');

