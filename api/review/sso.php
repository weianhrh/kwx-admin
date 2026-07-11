<?php
declare(strict_types=1);
require_once __DIR__ . '/../Database.php';
$config = require __DIR__ . '/_config.php';

function sso_fail(string $message, int $status = 403): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><title>审核登录失败</title>';
    echo '<div style="font-family:Microsoft YaHei,sans-serif;max-width:520px;margin:12vh auto;padding:24px;border:1px solid #f1c7c7;border-radius:12px;background:#fff7f7;color:#a33131">';
    echo '<h2 style="margin-top:0">KWX 审核登录失败</h2><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p style="color:#777;font-size:13px">请返回审核工作台，点击 KWX 面板右上角“刷新”。</p></div>';
    exit;
}

function b64url_decode_strict(string $value)
{
    if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
        return false;
    }
    $padding = (4 - strlen($value) % 4) % 4;
    return base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
}

function has_column(Database $db, string $table, string $column): bool
{
    return !empty($db->query("SHOW COLUMNS FROM `$table` LIKE ?", [$column]));
}

$ticket = trim((string)($_GET['ticket'] ?? ''));
$parts = explode('.', $ticket, 2);
if (count($parts) !== 2) {
    sso_fail('登录凭证格式不正确');
}
[$encoded, $providedSignature] = $parts;
$expectedSignature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encoded, (string)$config['shared_secret'], true)), '+/', '-_'), '=');
if (!hash_equals($expectedSignature, $providedSignature)) {
    sso_fail('登录凭证校验失败');
}

$json = b64url_decode_strict($encoded);
$payload = $json === false ? null : json_decode($json, true);
if (!is_array($payload) || ($payload['aud'] ?? '') !== 'kwx-review') {
    sso_fail('登录凭证内容无效');
}
$now = time();
if ((int)($payload['iat'] ?? 0) > $now + 15 || (int)($payload['exp'] ?? 0) < $now || (int)($payload['exp'] ?? 0) > $now + 90) {
    sso_fail('登录凭证已过期');
}

$username = trim((string)($payload['username'] ?? ''));
$target = (string)($payload['target'] ?? '');
if ($username === '' || !in_array($target, $config['allowed_targets'], true)) {
    sso_fail('审核账号或目标页面无效');
}

$db = new Database();
try {
    $rows = $db->query('SELECT id, role_id, session_token FROM admin_users WHERE username = ? LIMIT 1', [$username]);
    $user = $rows[0] ?? null;
    if (!$user || !in_array((int)$user['role_id'], $config['allowed_admin_roles'], true)) {
        sso_fail('KWX 映射账号不存在，或不是 role_id 1/2');
    }

    $token = trim((string)($user['session_token'] ?? ''));
    if ($token === '') {
        $token = bin2hex(random_bytes(32));
    }
    if (has_column($db, 'admin_users', 'session_expires')) {
        $db->query(
            'UPDATE admin_users SET session_token = ?, session_expires = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?',
            [$token, (int)$user['id']],
            true
        );
    } else {
        $db->query('UPDATE admin_users SET session_token = ? WHERE id = ?', [$token, (int)$user['id']], true);
    }

    // 不设置 Domain：经 audit-kwx.rcwulian.cn 反代后成为该子域自己的 host-only Cookie。
    setcookie('session_token', $token, [
        'expires' => time() + 2592000,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('Location: ' . $target);
} finally {
    $db->close();
}
exit;
