<?php

/* 
1. App 传 token：
   - 查 users.token
   - 得到 uid / is_streamer / streamer_venue

2. 如果 users.is_streamer == 1：
   - 只允许登录 role_id = 4
   - 优先找 admin_users.uid = 当前 uid AND role_id = 4
   - 如果没有，就自动创建 role_id = 4
   - 绝对不能 fallback 到 role_id = 3

3. 如果 users.is_streamer != 1：
   - 才允许查 admin_users.uid = 当前 uid AND role_id = 3
   - 找到则作为加盟商进入 index.html
   - 找不到就拒绝

4. 如果以后改传 uid：
   - 不能直接信任 uid
   - 必须加 sign 签名校验
*/
// /api/admin/app_webview_login1.php ==> 标注废弃弃用版
require_once '../Database.php';

date_default_timezone_set('Asia/Shanghai');

$database = new Database();

function fail_page($msg, $code = 403) {
    http_response_code(403);
    echo "<!doctype html><html><head><meta charset='utf-8'><title>登录失败</title></head><body>";
    echo "<div style='padding:24px;font-family:Arial,\"Microsoft YaHei\"'>";
    echo "<h3>后台自动登录失败</h3>";
    echo "<p>错误码：".htmlspecialchars((string)$code, ENT_QUOTES, 'UTF-8')."</p>";
    echo "<p>".htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')."</p>";
    echo "</div></body></html>";
    exit;
}

function safe_redirect($redirect) {
    $redirect = trim((string)$redirect);
    if ($redirect === '') return '/res/index.html';
    if (preg_match('#^(https?:)?//#i', $redirect)) return '/res/index.html';
    if (strpos($redirect, "\r") !== false || strpos($redirect, "\n") !== false) return '/res/index.html';
    if ($redirect[0] !== '/') return '/res/index.html';
    return $redirect;
}

function set_admin_cookie($sessionToken) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    setcookie('session_token', $sessionToken, [
        'expires'  => time() + 86400 * 30,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function get_param($name, $default = '') {
    return $_GET[$name] ?? $_POST[$name] ?? $default;
}

$redirect = safe_redirect(get_param('redirect', '/res/index.html'));

$appToken = trim((string)(
    get_param('token')
    ?: get_param('auth_token')
    ?: get_param('access_token')
));

$uidParam = intval(get_param('uid', 0));

// ===== 1. 先支持 token 登录 =====
$appUser = null;

if ($appToken !== '') {
    $rows = $database->query("
        SELECT uid, is_streamer, streamer_venue, deleted
        FROM users
        WHERE token = ?
        LIMIT 1
    ", [$appToken]);

    if (!$rows || empty($rows[0])) {
        fail_page('App 登录态无效，请重新登录 App', 1002);
    }

    $appUser = $rows[0];
}

// ===== 2. 后续如果改成 uid，需要签名，不能裸 uid =====
// 签名格式：uid=123&ts=时间戳&nonce=随机串&sign=hash_hmac('sha256', uid|ts|nonce, 密钥)
if (!$appUser && $uidParam > 0) {
    $ts    = intval(get_param('ts', 0));
    $nonce = trim((string)get_param('nonce', ''));
    $sign  = trim((string)get_param('sign', ''));

    $secret = '请换成你自己的强随机密钥';

    if (!$ts || !$nonce || !$sign) {
        fail_page('缺少 uid 登录签名参数', 1101);
    }

    if (abs(time() - $ts) > 300) {
        fail_page('登录链接已过期', 1102);
    }

    $raw = $uidParam . '|' . $ts . '|' . $nonce;
    $expectSign = hash_hmac('sha256', $raw, $secret);

    if (!hash_equals($expectSign, $sign)) {
        fail_page('uid 签名校验失败', 1103);
    }

    $rows = $database->query("
        SELECT uid, is_streamer, streamer_venue, deleted
        FROM users
        WHERE uid = ?
        LIMIT 1
    ", [(string)$uidParam]);

    if (!$rows || empty($rows[0])) {
        fail_page('App 用户不存在', 1104);
    }

    $appUser = $rows[0];
}

if (!$appUser) {
    fail_page('缺少 App 登录 token 或安全 uid 参数', 1001);
}

$uid = intval($appUser['uid'] ?? 0);
$isStreamer = intval($appUser['is_streamer'] ?? 0);
$streamerVenueId = intval($appUser['streamer_venue'] ?? 0);
$deleted = intval($appUser['deleted'] ?? 0);

if ($uid <= 0) {
    fail_page('用户 UID 异常', 1003);
}

if ($deleted === 1) {
    fail_page('该 App 用户已被禁用', 1004);
}

$admin = null;

// ===== 3. 主播身份：强制 role_id=4，绝不登录 role_id=3 =====
if ($isStreamer === 1) {
    if ($streamerVenueId <= 0) {
        fail_page('主播未绑定场地，无法进入主播后台', 1201);
    }

    $venueRows = $database->query("
        SELECT id, venue_name, is_banned
        FROM venues
        WHERE id = ?
        LIMIT 1
    ", [(string)$streamerVenueId]);

    if (!$venueRows || empty($venueRows[0])) {
        fail_page('主播绑定场地不存在', 1202);
    }
    //后面可以注释这个 不用判断场地是否已被封禁的
    if (intval($venueRows[0]['is_banned'] ?? 0) === 1) {
        fail_page('主播绑定场地已被封禁', 1203);
    }

    $venueName = (string)$venueRows[0]['venue_name'];

    // 只找 role_id=4
    $adminRows = $database->query("
        SELECT id, username, role_id, venue_id, venue_name, uid, session_token
        FROM admin_users
        WHERE uid = ?
          AND role_id = 4
        ORDER BY id DESC
        LIMIT 1
    ", [(string)$uid]);

    if ($adminRows && !empty($adminRows[0])) {
        $admin = $adminRows[0];

        // 同步主播当前绑定场地
        if (intval($admin['venue_id'] ?? 0) !== $streamerVenueId) {
            $database->query("
                UPDATE admin_users
                SET venue_id = ?, venue_name = ?, updated_at = NOW()
                WHERE id = ?
            ", [
                (string)$streamerVenueId,
                $venueName,
                (string)$admin['id']
            ], true);

            $admin['venue_id'] = $streamerVenueId;
            $admin['venue_name'] = $venueName;
        }
    } else {
        // 没有 role_id=4 才创建主播后台账号
        $username = 'app_streamer_' . $uid;
        $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $sessionToken = bin2hex(random_bytes(32));

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
            $sessionToken
        ], true);

        if ($insert === false) {
            fail_page('创建主播后台账号失败，请检查 admin_users 是否限制了 uid 唯一', 1204);
        }

        $adminRows = $database->query("
            SELECT id, username, role_id, venue_id, venue_name, uid, session_token
            FROM admin_users
            WHERE username = ?
              AND role_id = 4
            LIMIT 1
        ", [$username]);

        if (!$adminRows || empty($adminRows[0])) {
            fail_page('创建主播后台账号后读取失败', 1205);
        }

        $admin = $adminRows[0];
    }
}

// ===== 4. 非主播：只允许加盟商 role_id=3 =====
if ($isStreamer !== 1) {
    $adminRows = $database->query("
        SELECT id, username, role_id, venue_id, venue_name, uid, session_token
        FROM admin_users
        WHERE uid = ?
          AND role_id = 3
        ORDER BY id DESC
        LIMIT 1
    ", [(string)$uid]);

    if (!$adminRows || empty($adminRows[0])) {
        fail_page('当前账号不是加盟商或主播，无法进入后台', 1301);
    }

    $admin = $adminRows[0];
}

if (!$admin) {
    fail_page('未找到可登录的后台身份', 1401);
}

// ===== 5. 确保最终身份只能是 3 或 4 =====
$roleId = intval($admin['role_id'] ?? 0);

if (!in_array($roleId, [3, 4], true)) {
    fail_page('当前后台身份无权通过 App WebView 登录', 1402);
}

// ===== 6. 补 session_token =====
$sessionToken = trim((string)($admin['session_token'] ?? ''));

if ($sessionToken === '') {
    $sessionToken = bin2hex(random_bytes(32));

    $database->query("
        UPDATE admin_users
        SET session_token = ?, updated_at = NOW()
        WHERE id = ?
    ", [
        $sessionToken,
        (string)$admin['id']
    ], true);
}

set_admin_cookie($sessionToken);

$sep = strpos($redirect, '?') === false ? '?' : '&';
header('Location: ' . $redirect . $sep . '_=' . time(), true, 302);
exit;