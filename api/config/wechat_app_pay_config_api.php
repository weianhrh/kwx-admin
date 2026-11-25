<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth/_common.php';
require_once __DIR__ . '/../lib/WechatAppPayConfig.php';

auth_json_headers();

function wechatConfigView(Database $db): array
{
    $profiles = WechatAppPayConfig::profiles($db);
    $data = [];
    foreach (WechatAppPayConfig::MERCHANTS as $id => [$directory, $serial]) {
        $row = $profiles[$id] ?? [];
        $data[] = [
            'merchant_id' => (string) $id,
            'app_id' => $row['app_id'] ?? WechatAppPayConfig::LEGACY_APP_ID,
            'merchant_serial' => $row['merchant_serial'] ?? $serial,
            'verify_serial' => $row['verify_serial'] ?? '',
            'private_key_path' => $directory . '/apiclient_key.pem',
            'public_key_path' => $directory . '/pub_key.pem',
            'key_configured' => !empty($row['api_v3_key']),
            'revision' => (int) ($row['revision'] ?? 0),
            'updated_at' => $row['updated_at'] ?? '',
        ];
    }
    return ['active_merchant_id' => WechatAppPayConfig::activeId($db), 'merchants' => $data];
}

try {
    $token = $_COOKIE[AUTH_COOKIE] ?? '';
    if ($token === '') {
        auth_out(1001, '未登录或会话已过期');
    }

    $db = new Database();
    $sessionSql = 'SELECT id, role_id FROM admin_users WHERE session_token = ?';
    if (auth_has_column($db, 'admin_users', 'session_expires')) {
        $sessionSql .= ' AND session_expires > NOW()';
    }
    $user = $db->query($sessionSql . ' LIMIT 1', [$token])[0] ?? null;
    if (!$user) {
        auth_out(1001, '未登录或会话已过期');
    }
    if (!in_array((int) $user['role_id'], [1, 2], true)) {
        auth_out(1002, '无权管理微信支付商户');
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method === 'GET') {
        auth_out(200, '获取成功', wechatConfigView($db));
    }
    if ($method !== 'POST') {
        http_response_code(405);
        auth_out(405, '请求方式错误');
    }
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
    if ($contentType !== 'application/json' || ($_SERVER['HTTP_X_KWX_PAYMENT_CONFIG'] ?? '') !== '1') {
        http_response_code(403);
        auth_out(403, '请从后台配置页提交');
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || ($input['action'] ?? '') !== 'activate') {
        http_response_code(400);
        auth_out(400, '商户资料已锁定，只能切换收款商户');
    }
    if (array_diff_key($input, ['action' => true, 'merchant_id' => true])) {
        http_response_code(400);
        auth_out(400, '切换请求仅接受商户号');
    }
    $id = $input['merchant_id'] ?? '';
    if (!is_string($id) || !isset(WechatAppPayConfig::MERCHANTS[$id])) {
        auth_out(400, '商户号无效');
    }

    $profile = WechatAppPayConfig::profiles($db)[$id] ?? null;
    if (empty($profile['api_v3_key'])) {
        auth_out(400, '该商户配置未就绪，无法切换');
    }
    WechatAppPayConfig::decryptKey($id, $profile['api_v3_key']);
    $changed = $db->query('UPDATE kwx_wechat_app_active SET merchant_id = ? WHERE id = 1', [$id], true);
    if ($changed === false) {
        throw new RuntimeException('Cannot activate WeChat merchant');
    }

    error_log(sprintf('[kwx-wxpay] activate merchant=%s admin=%d', $id, (int) $user['id']));
    auth_out(200, '已切换收款商户', wechatConfigView($db));
} catch (Throwable $e) {
    error_log('[kwx-wxpay] ' . $e->getMessage());
    auth_out(500, '商户读取或切换失败，请检查数据库和服务器密钥文件');
}
