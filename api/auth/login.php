<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

auth_json_headers();
auth_handle_options();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_out(1, '请使用 POST 登录');
}

$data = auth_request_data();
$username = trim((string)($data['username'] ?? $data['account'] ?? ''));
$password = trim((string)($data['password'] ?? ''));
$ip = auth_client_ip();
$userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');

if ($username === '' || $password === '') {
    auth_out(1, '用户名或密码不能为空');
}

$redis = auth_redis();
$maxAttempts = 3;
$lockTime = 300;
$blacklistThreshold = 10;
$blacklistDuration = 600;
$blacklistKey = "kwx:login:blacklist:ip:$ip";
$failKeyUser = "kwx:login:fail:user:$username";
$failKeyIP = "kwx:login:fail:ip:$username:$ip";
$failKeyIPOnly = "kwx:login:fail:ip_only:$ip";

if ($redis && $redis->exists($blacklistKey)) {
    auth_out(1, '当前 IP 登录异常，请稍后再试');
}

if ($redis) {
    $failCountUser = (int)$redis->get($failKeyUser);
    $failCountIP = (int)$redis->get($failKeyIP);
    $ttl = max((int)$redis->ttl($failKeyUser), (int)$redis->ttl($failKeyIP));
    if (($failCountUser >= $maxAttempts || $failCountIP >= $maxAttempts) && $ttl > 0) {
        auth_out(1, '登录失败次数过多，请在 ' . $ttl . ' 秒后重试');
    }
}

$db = new Database();
$rows = $db->query('SELECT * FROM admin_users WHERE username = ? LIMIT 1', [$username]);
$user = $rows[0] ?? null;
$dummyHash = '$2y$10$usesomesaltystringforequaldelay123456789012345678901234';
$hash = $user['password'] ?? $dummyHash;
$valid = is_string($hash) && password_verify($password, $hash);

if ($user && $valid) {
    if ($redis) {
        $redis->delete($failKeyUser);
        $redis->delete($failKeyIP);
        $redis->delete($failKeyIPOnly);
    }

    $token = bin2hex(random_bytes(32));
    if (auth_has_column($db, 'admin_users', 'session_expires')) {
        $db->query(
            'UPDATE admin_users SET session_token = ?, session_expires = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?',
            [$token, (string)$user['id']],
            true
        );
    } else {
        $db->query('UPDATE admin_users SET session_token = ? WHERE id = ?', [$token, (string)$user['id']], true);
    }

    auth_set_cookie($token);
    $db->close();
    if ($redis) {
        $redis->close();
    }

    auth_out(0, '登录成功', [
        'access_token' => $token,
        'user' => auth_user_payload($user),
    ]);
}

if ($redis) {
    $failCountUser = (int)$redis->get($failKeyUser) + 1;
    $failCountIP = (int)$redis->get($failKeyIP) + 1;
    $failCountIPOnly = (int)$redis->get($failKeyIPOnly) + 1;
    $redis->setWithExpiration($failKeyUser, $failCountUser, $lockTime);
    $redis->setWithExpiration($failKeyIP, $failCountIP, $lockTime);
    $redis->setWithExpiration($failKeyIPOnly, $failCountIPOnly, $lockTime);
    if ($failCountIPOnly >= $blacklistThreshold) {
        $redis->setWithExpiration($blacklistKey, 1, $blacklistDuration);
    }
    $redis->close();
}

error_log(sprintf('[kwx-auth] login failed user=%s ip=%s ua=%s', $username, $ip, $userAgent));
$db->close();
auth_out(1, '用户名或密码错误');

