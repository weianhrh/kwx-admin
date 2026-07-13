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

    // 同一账号的多个浏览器共用一个有效 Token，避免后登录挤掉先登录。
    // 使用数据库原子更新，避免两个浏览器首次同时登录时互相覆盖 Token。
    $candidateToken = bin2hex(random_bytes(32));
    if (auth_has_column($db, 'admin_users', 'session_expires')) {
        $db->query(
            "UPDATE admin_users
             SET session_token = CASE
                    WHEN session_token IS NULL
                      OR session_token = ''
                      OR session_expires IS NULL
                      OR session_expires <= NOW()
                    THEN ?
                    ELSE session_token
                 END,
                 session_expires = DATE_ADD(NOW(), INTERVAL 30 DAY)
             WHERE id = ?",
            [$candidateToken, (string)$user['id']],
            true
        );
    } else {
        $db->query(
            "UPDATE admin_users
             SET session_token = CASE
                    WHEN session_token IS NULL OR session_token = '' THEN ?
                    ELSE session_token
                 END
             WHERE id = ?",
            [$candidateToken, (string)$user['id']],
            true
        );
    }

    // 必须重新读取数据库中的最终 Token；并发登录时可能使用的是另一请求先写入的 Token。
    $tokenRows = $db->query(
        'SELECT session_token FROM admin_users WHERE id = ? LIMIT 1',
        [(string)$user['id']]
    );
    $token = trim((string)($tokenRows[0]['session_token'] ?? ''));
    if ($token === '') {
        $db->close();
        if ($redis) {
            $redis->close();
        }
        auth_out(1, '登录会话创建失败，请稍后重试');
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
