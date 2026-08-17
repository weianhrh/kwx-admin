<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/_common.php';
require_once __DIR__ . '/../lib/device_wifi_whitelist.php';

auth_json_headers();
auth_handle_options();

$token = (string)($_COOKIE[AUTH_COOKIE] ?? '');
if ($token === '') {
    auth_out(1001, '未登录或会话已过期');
}

$db = new Database();
if (auth_has_column($db, 'admin_users', 'session_expires')) {
    $users = $db->query(
        'SELECT * FROM admin_users WHERE session_token = ? AND session_expires > NOW() LIMIT 1',
        [$token]
    );
} else {
    $users = $db->query('SELECT * FROM admin_users WHERE session_token = ? LIMIT 1', [$token]);
}

$user = $users[0] ?? null;
if (!$user) {
    $db->close();
    auth_clear_cookie();
    auth_out(1001, '未登录或会话已过期');
}

if (!in_array((int)($user['role_id'] ?? 0), [1, 2], true)) {
    $db->close();
    auth_out(403, '仅老板或平台管理员可管理改网白名单');
}

function device_wifi_whitelist_payload(Database $db): array
{
    $venues = $db->query(
        'SELECT id AS venue_id, venue_name FROM venues ORDER BY id ASC LIMIT 1000'
    );

    return [
        'venues' => is_array($venues) ? $venues : [],
        'whitelist_venue_ids' => device_wifi_whitelist_ids(),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $payload = device_wifi_whitelist_payload($db);
    $db->close();
    auth_out(0, 'ok', $payload);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $db->close();
    auth_out(405, '仅支持 GET 或 POST 请求');
}

$data = auth_request_data();
$action = trim((string)($data['action'] ?? ''));
$venueIdRaw = $data['venue_id'] ?? '';

if (!in_array($action, ['add', 'remove'], true)) {
    $db->close();
    auth_out(422, 'action 仅支持 add 或 remove');
}

if (!is_scalar($venueIdRaw) || !ctype_digit((string)$venueIdRaw) || (int)$venueIdRaw <= 0) {
    $db->close();
    auth_out(422, 'venue_id 参数不正确');
}

$venueId = (int)$venueIdRaw;
$venueRows = $db->query('SELECT id, venue_name FROM venues WHERE id = ? LIMIT 1', [$venueId]);
$venue = $venueRows[0] ?? null;
if (!$venue) {
    $db->close();
    auth_out(404, '场地不存在');
}

$ids = device_wifi_whitelist_ids();
if ($action === 'add') {
    $ids[] = $venueId;
} else {
    $ids = array_values(array_filter(
        $ids,
        static fn(int $id): bool => $id !== $venueId
    ));
}

if (!device_wifi_whitelist_save($ids)) {
    $db->close();
    auth_out(500, '白名单TXT保存失败，请检查 api/config 目录写入权限');
}

$db->logToFile(
    json_encode([
        'action' => $action === 'add' ? 'device_wifi_whitelist_add' : 'device_wifi_whitelist_remove',
        'operator_id' => (int)($user['id'] ?? 0),
        'operator_username' => (string)($user['username'] ?? ''),
        'operator_role_id' => (int)($user['role_id'] ?? 0),
        'venue_id' => $venueId,
        'venue_name' => (string)($venue['venue_name'] ?? ''),
        'client_ip' => auth_client_ip(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'log/device_wifi_whitelist.log'
);

$payload = device_wifi_whitelist_payload($db);
$db->close();

auth_out(
    0,
    $action === 'add' ? '场地已加入改网白名单' : '场地已从改网白名单移除',
    $payload
);
