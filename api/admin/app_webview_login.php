<?php
// /api/admin/app_webview_login.php ==> 标注废弃弃用版
require_once '../Database.php';

session_start();
date_default_timezone_set('Asia/Shanghai');

$database = new Database();

function fail_html($code, $msg) {
    http_response_code(403);
    echo "<!doctype html><html><head><meta charset='utf-8'><title>登录失败</title></head><body>";
    echo "<div style='padding:24px;font-family:Arial,\"Microsoft YaHei\";color:#333;'>";
    echo "<h3>后台自动登录失败</h3>";
    echo "<p>错误码：".htmlspecialchars((string)$code, ENT_QUOTES, 'UTF-8')."</p>";
    echo "<p>".htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')."</p>";
    echo "</div></body></html>";
    exit;
}

function safe_redirect($redirect) {
    $redirect = trim((string)$redirect);

    if ($redirect === '') {
        return '/res/index.html';
    }

    // 禁止外链跳转，避免开放重定向
    if (preg_match('#^(https?:)?//#i', $redirect)) {
        return '/res/index.html';
    }

    // 禁止 header 注入
    if (strpos($redirect, "\r") !== false || strpos($redirect, "\n") !== false) {
        return '/res/index.html';
    }

    // 只允许站内路径
    if ($redirect[0] !== '/') {
        return '/res/index.html';
    }

    return $redirect;
}

function set_admin_cookie($sessionToken) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    if (PHP_VERSION_ID >= 70300) {
        setcookie('session_token', $sessionToken, [
            'expires'  => time() + 2592000,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        setcookie('session_token', $sessionToken, time() + 2592000, '/', '', $secure, true);
    }
}

function log_auto_login($message) {
    $logFile = __DIR__ . '/app_webview_login.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    file_put_contents($logFile, $line, FILE_APPEND);
}

// 兼容 token / auth_token / access_token
$appToken = trim((string)(
    $_GET['token']
    ?? $_POST['token']
    ?? $_GET['auth_token']
    ?? $_POST['auth_token']
    ?? $_GET['access_token']
    ?? $_POST['access_token']
    ?? ''
));

$redirect = safe_redirect($_GET['redirect'] ?? '/res/index.html');

if ($appToken === '') {
    fail_html(1001, '缺少 App 登录 token');
}

// 1. 校验 App 用户 token
$appRows = $database->query("
    SELECT uid, is_streamer, streamer_venue, deleted
    FROM users
    WHERE token = ?
    LIMIT 1
", [$appToken]);

if (!$appRows || empty($appRows[0])) {
    log_auto_login("fail invalid_app_token ip=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    fail_html(1002, 'App 登录态无效，请重新登录 App');
}

$appUser = $appRows[0];

$uid = intval($appUser['uid'] ?? 0);
$isStreamer = intval($appUser['is_streamer'] ?? 0);
$streamerVenueId = intval($appUser['streamer_venue'] ?? 0);
$deleted = intval($appUser['deleted'] ?? 0);

if ($uid <= 0) {
    fail_html(1003, '用户 UID 异常');
}

if ($deleted === 1) {
    fail_html(1004, '该 App 用户已被禁用');
}

// 2. 优先查已有加盟商/主播后台账号
$adminRows = $database->query("
    SELECT id, username, role_id, venue_id, venue_name, session_token
    FROM admin_users
    WHERE uid = ?
      AND role_id IN (3, 4)
    ORDER BY role_id ASC, id DESC
    LIMIT 1
", [(string)$uid]);

$admin = null;

if ($adminRows && !empty($adminRows[0])) {
    $admin = $adminRows[0];

    $adminId = intval($admin['id']);
    $roleId = intval($admin['role_id']);
    $adminVenueId = intval($admin['venue_id'] ?? 0);

    // role_id=4 是主播类账号，必须仍然是主播
    if ($roleId === 4 && $isStreamer !== 1) {
        log_auto_login("fail streamer_revoked uid={$uid} admin_id={$adminId}");
        fail_html(1005, '当前用户不是主播身份，无法进入主播后台');
    }

    // 主播场地发生变化时，同步 admin_users.venue_id
    if ($roleId === 4 && $streamerVenueId > 0 && $streamerVenueId !== $adminVenueId) {
        $venueRows = $database->query("
            SELECT id, venue_name, is_banned
            FROM venues
            WHERE id = ?
            LIMIT 1
        ", [(string)$streamerVenueId]);

        if (!$venueRows || empty($venueRows[0])) {
            fail_html(1006, '主播绑定场地不存在');
        }

        if (intval($venueRows[0]['is_banned'] ?? 0) === 1) {
            fail_html(1007, '主播绑定场地已被封禁');
        }

        $database->query("
            UPDATE admin_users
            SET venue_id = ?, venue_name = ?, updated_at = NOW()
            WHERE id = ?
        ", [
            (string)$streamerVenueId,
            (string)$venueRows[0]['venue_name'],
            (string)$adminId
        ], true);

        $admin['venue_id'] = $streamerVenueId;
        $admin['venue_name'] = $venueRows[0]['venue_name'];
    }

} else {
    // 3. 没有后台账号时，只允许主播自动创建 role_id=4
    if ($isStreamer !== 1 || $streamerVenueId <= 0) {
        log_auto_login("fail no_admin_not_streamer uid={$uid}");
        fail_html(1008, '当前账号不是加盟商或主播，无法进入后台');
    }

    $venueRows = $database->query("
        SELECT id, venue_name, is_banned
        FROM venues
        WHERE id = ?
        LIMIT 1
    ", [(string)$streamerVenueId]);

    if (!$venueRows || empty($venueRows[0])) {
        fail_html(1009, '主播绑定场地不存在');
    }

    if (intval($venueRows[0]['is_banned'] ?? 0) === 1) {
        fail_html(1010, '主播绑定场地已被封禁');
    }

    $venueName = (string)$venueRows[0]['venue_name'];

    $username = 'app_streamer_' . $uid;
    $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $newSessionToken = bin2hex(random_bytes(32));

    $insert = $database->query("
        INSERT INTO admin_users
            (username, password, role_id, venue_id, venue_name, uid, session_token, created_at, updated_at)
        VALUES
            (?, ?, 4, ?, ?, ?, ?, NOW(), NOW())
    ", [
        $username,
        $passwordHash,
        (string)$streamerVenueId,
        $venueName,
        (string)$uid,
        $newSessionToken
    ], true);

    if ($insert === false) {
        log_auto_login("fail create_admin uid={$uid} venue_id={$streamerVenueId}");
        fail_html(1011, '创建主播后台账号失败，可能账号已存在');
    }

    $adminRows = $database->query("
        SELECT id, username, role_id, venue_id, venue_name, session_token
        FROM admin_users
        WHERE username = ?
        LIMIT 1
    ", [$username]);

    if (!$adminRows || empty($adminRows[0])) {
        fail_html(1012, '创建后台账号后读取失败');
    }

    $admin = $adminRows[0];
}

// 4. 写入后台 session_token Cookie
$adminId = intval($admin['id']);
$username = (string)$admin['username'];
$sessionToken = trim((string)($admin['session_token'] ?? ''));

if ($sessionToken === '') {
    $sessionToken = bin2hex(random_bytes(32));
    $database->query("
        UPDATE admin_users
        SET session_token = ?, updated_at = NOW()
        WHERE id = ?
    ", [
        $sessionToken,
        (string)$adminId
    ], true);
}

session_regenerate_id(true);
$_SESSION['user_id'] = $username;

set_admin_cookie($sessionToken);

log_auto_login(
    "success uid={$uid} admin_id={$adminId} username={$username} role_id=" .
    intval($admin['role_id']) . " venue_id=" . intval($admin['venue_id'])
);

// 5. 跳转到后台 index.html
$sep = (strpos($redirect, '?') === false) ? '?' : '&';
header('Location: ' . $redirect . $sep . '_=' . time(), true, 302);
exit;