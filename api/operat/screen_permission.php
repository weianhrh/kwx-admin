<?php
/**
 * KWX 管理端截图录屏白名单：role_id=1/2。
 * 复用后台会话校验，通过 HTTPS 签名调用 App 站，不跨站读目录、不依赖 RC。
 */
ini_set('display_errors', '0');
require_once __DIR__ . '/../auth/_common.php';
auth_json_headers();

function kwx_screen_admin_out($code, $msg, array $data = [], $status = 200)
{
    http_response_code($status);
    auth_out($code, $msg, $data);
}
function kwx_screen_admin_secret()
{
    $value = getenv('KWX_SCREEN_PERMISSION_SECRET');
    return is_string($value) && strlen($value) >= 32
        ? $value : '4f17ce3e4de1577f8609f402f430efb01373d4aa7e375d299f44c783ef03449c';
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    kwx_screen_admin_out(405, '请求方法不支持', [], 405);
}
$token = $_COOKIE[AUTH_COOKIE] ?? '';
if (!is_string($token) || $token === '' || strlen($token) > 512) {
    kwx_screen_admin_out(1001, '未登录或会话已过期', [], 401);
}
try {
    $db = new Database();
    try {
        // 与 api/auth/me.php 保持一致：存在 session_expires 时必须未过期。
        if (auth_has_column($db, 'admin_users', 'session_expires')) {
            $rows = $db->query('SELECT * FROM admin_users WHERE session_token = ? AND session_expires > NOW() LIMIT 1', [$token]);
        } else {
            $rows = $db->query('SELECT * FROM admin_users WHERE session_token = ? LIMIT 1', [$token]);
        }
        $user = $rows[0] ?? null;
    } finally {
        $db->close();
    }
    if (!$user) kwx_screen_admin_out(1001, '未登录或会话已过期', [], 401);
    $roleId = (int)($user['role_id'] ?? 0);
    if (!in_array($roleId, [1, 2], true)) {
        kwx_screen_admin_out(403, '只有管理员可以查看和管理截图录屏白名单', [], 403);
    }

    $secret = kwx_screen_admin_secret();
    $csrf = hash_hmac('sha256', 'kwx-screen-csrf|' . $token, $secret);
    if ($method === 'GET') {
        if (($_GET['action'] ?? 'list') !== 'list') {
            kwx_screen_admin_out(405, '增删白名单必须使用 POST', [], 405);
        }
        $input = ['action' => 'list'];
    } else {
        $provided = $_SERVER['HTTP_X_SCREEN_CSRF'] ?? '';
        if (!is_string($provided) || !hash_equals($csrf, $provided)) {
            kwx_screen_admin_out(419, '页面校验已失效，请刷新白名单后重试', [], 419);
        }
        $raw = file_get_contents('php://input', false, null, 0, 4097);
        $input = is_string($raw) && strlen($raw) <= 4096 ? json_decode($raw, true) : null;
        if (!is_array($input) || !in_array($input['action'] ?? '', ['add', 'delete'], true)) {
            kwx_screen_admin_out(400, '请求格式错误或操作不支持', [], 400);
        }
        $uid = $input['uid'] ?? null;
        if ((!is_string($uid) && !is_int($uid)) || !preg_match('/^[0-9]{1,16}$/D', (string)$uid)) {
            kwx_screen_admin_out(400, 'UID 必须是正整数', [], 400);
        }
        if (isset($input['remark']) && (!is_string($input['remark']) || strlen($input['remark']) > 800)) {
            kwx_screen_admin_out(400, '备注格式错误或超过长度限制', [], 400);
        }
    }
    // 只转发白名单字段；操作人及角色始终取服务端登录身份。
    $payload = [
        'action' => $input['action'],
        'timestamp' => time(),
        'nonce' => bin2hex(random_bytes(16)),
        'operator' => [
            'uid' => (int)($user['uid'] ?? 0), 'admin_id' => (int)($user['id'] ?? 0),
            'role_id' => $roleId, 'username' => (string)($user['username'] ?? ''),
            'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ],
    ];
    if ($method === 'POST') {
        $payload['uid'] = $input['uid'];
        $payload['remark'] = $input['remark'] ?? '';
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('请求编码失败');
    if (!function_exists('curl_init')) {
        kwx_screen_admin_out(500, '后台 PHP 未启用 cURL 扩展，请在宝塔 PHP 扩展中启用', [], 500);
    }
    $curl = curl_init('https://app.kwxapp.cn/app/user/car/screen_permission_whitelist_api.php');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json', 'Accept: application/json',
            'X-KWX-Screen-Signature: ' . hash_hmac('sha256', $body, $secret),
        ],
    ]);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($response)) {
        error_log('[kwx-screen-admin] bridge error: ' . $error);
        kwx_screen_admin_out(502, '连接 App 白名单接口失败；请检查 App 文件、HTTPS 和 PHP 日志。增删后遇此提示请先刷新核对', [], 502);
    }
    $json = json_decode($response, true);
    if (!is_array($json) || !isset($json['code'])) {
        error_log('[kwx-screen-admin] invalid bridge response, HTTP ' . $status);
        kwx_screen_admin_out(502, 'App 白名单接口未就绪，请先上传 App 包；增删后遇此提示请先刷新核对', [], 502);
    }
    if ((int)$json['code'] !== 0) {
        // 桥接鉴权失败不是浏览器身份变化，返回 502，不让页面误以为管理员角色被撤销。
        $code = (int)$json['code'];
        if ($code === 403) {
            kwx_screen_admin_out(502, '后台与 App 桥接鉴权失败，请确认两站文件为同一包、密钥一致且系统时间正确', [], 502);
        }
        $http = in_array($code, [400, 409], true) ? $code : 502;
        kwx_screen_admin_out($code, (string)($json['msg'] ?? 'App 白名单操作失败'), [], $http);
    }
    $data = $json['data'] ?? null;
    if (!is_array($data) || !isset($data['list']) || !is_array($data['list'])) {
        kwx_screen_admin_out(502, 'App 白名单返回格式错误', [], 502);
    }
    $data['csrf_token'] = $csrf;
    kwx_screen_admin_out(0, (string)($json['msg'] ?? '操作成功'), $data);
} catch (Throwable $e) {
    error_log('[kwx-screen-admin] ' . $e->getMessage());
    kwx_screen_admin_out(500, '白名单接口异常，请查看后台 PHP 错误日志', [], 500);
}
