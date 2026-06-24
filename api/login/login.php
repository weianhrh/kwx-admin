<?php
require_once '../Database.php';
require_once '../RedisHelper.php';

session_start();

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

$MAX_ATTEMPTS = 3;
$LOCK_TIME = 300; // 5 分钟锁定
$BLACKLIST_THRESHOLD = 10; // IP独立失败次数上限
$BLACKLIST_DURATION = 600; // 黑名单时长（秒）

$database = new Database();
$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3); // 使用3号库记录登录失败信息

// 黑名单检测
$blacklistKey = "login:blacklist:ip:{$ip}";
if ($redis->exists($blacklistKey)) {
    echo json_encode(['code' => 1, 'msg' => '当前IP被封禁，请稍后再试', 'data' => []]);
    exit;
}

// 检查空字段
if (!$username || !$password) {
    echo json_encode(['code' => 1, 'msg' => '用户名或密码不能为空', 'data' => []]);
    exit;
}

// 登录失败统计键
$failKeyUser = "login:fail:user:{$username}";
$failKeyIP   = "login:fail:ip:{$username}:{$ip}";
$failKeyIPOnly = "login:fail:ip_only:{$ip}";

$failCountUser = (int)$redis->get($failKeyUser);
$failCountIP   = (int)$redis->get($failKeyIP);
$failCountIPOnly = (int)$redis->get($failKeyIPOnly);

$ttl = max($redis->ttl($failKeyUser), $redis->ttl($failKeyIP));

if (($failCountUser >= $MAX_ATTEMPTS || $failCountIP >= $MAX_ATTEMPTS) && $ttl > 0) {
    $minutes = floor($ttl / 60);
    $seconds = $ttl % 60;
    echo json_encode([
        'code' => 1,
        'msg' => "账号已锁定，请在 {$minutes}分{$seconds}秒 后重试",
        'data' => []
    ]);
    exit;
}

// 查询数据库
$sql = "SELECT username, password, session_token FROM admin_users WHERE username = ?";
$user = $database->query($sql, [$username]);

// 为防止用户名枚举攻击，使用 dummy hash
$dummy_hash = '$2y$10$usesomesaltystringforequaldelay123456789012345678901234';
$real_hash = $user[0]['password'] ?? $dummy_hash;
$isValid = password_verify($password, $real_hash);

if ($user && $isValid) {
    // 登录成功，清除失败记录
    $redis->delete($failKeyUser);
    $redis->delete($failKeyIP);
    $redis->delete($failKeyIPOnly);

    session_regenerate_id(true); // 防止 session fixation

    $session_token = $user[0]['session_token'] ?: bin2hex(random_bytes(32));
    if (!$user[0]['session_token']) {
        $database->query("UPDATE admin_users SET session_token = ? WHERE username = ?", [$session_token, $username], true);
    }

    // 设置 HttpOnly + Secure Cookie
    setcookie('session_token', $session_token, time() + 2592000, '/', '', isset($_SERVER['HTTPS']), true);
    $_SESSION['user_id'] = $username;

    logLoginAttempt($username, $ip, $userAgent, true);

    echo json_encode([
        'code' => 0,
        'msg' => '登录成功',
        'data' => ['access_token' => $session_token]
    ]);
} else {
    // 登录失败 +1
    $failCountUser++;
    $failCountIP++;
    $failCountIPOnly++;

    $redis->setWithExpiration($failKeyUser, $failCountUser, $LOCK_TIME);
    $redis->setWithExpiration($failKeyIP, $failCountIP, $LOCK_TIME);
    $redis->setWithExpiration($failKeyIPOnly, $failCountIPOnly, $LOCK_TIME);

    // 若 IP 失败过多，立刻加入黑名单
    if ($failCountIPOnly >= $BLACKLIST_THRESHOLD) {
        $redis->setWithExpiration($blacklistKey, 1, $BLACKLIST_DURATION);
        logLoginAttempt($username, $ip, $userAgent, false, true);
        echo json_encode([
            'code' => 1,
            'msg' => '检测到异常行为，当前IP已被封禁',
            'data' => []
        ]);
        exit;
    }

    $remaining = max(0, $MAX_ATTEMPTS - max($failCountUser, $failCountIP));
    $msg = $remaining > 0
        ? "用户名或密码错误，还可尝试 {$remaining} 次"
        : "登录失败次数过多，账号已锁定 {$LOCK_TIME} 秒";

    logLoginAttempt($username, $ip, $userAgent, false);
    echo json_encode(['code' => 1, 'msg' => $msg, 'data' => []]);
}

$database->close();
$redis->close();

function logLoginAttempt($username, $ip, $ua, $success, $blacklisted = false)
{
    $status = $success ? 'SUCCESS' : ($blacklisted ? 'BLACKLISTED' : 'FAILURE');
    $logLine = sprintf("[%s] [%s] user=%s ip=%s ua=%s\n", date('Y-m-d H:i:s'), $status, $username, $ip, $ua);
    file_put_contents(__DIR__ . '/login_audit.log', $logLine, FILE_APPEND);
}
?>
