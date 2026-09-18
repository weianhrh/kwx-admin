<?php
require_once '../RedisHelper.php';
require_once '../Database.php';
require_once '../lib/venue_scope.php';

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? '';
$user = $session_token !== '' ? $database->getUserBySessionToken($session_token) : null;
if (!$user) {
    echo json_encode(['code' => 1001, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3);

$data = json_decode(file_get_contents('php://input'), true);
$redis_keys = $data['keys'] ?? [];

if (!is_array($redis_keys) || count($redis_keys) === 0) {
    echo json_encode(['code' => 400, 'msg' => '无效参数']);
    exit;
}

$reflection = new ReflectionClass($redis);
$property = $reflection->getProperty('redis');
$property->setAccessible(true);
$nativeRedis = $property->getValue($redis);

foreach ($redis_keys as $key) {
    if (!is_string($key) || !preg_match('/^vehicle_name_audit:([A-Za-z0-9_-]+):(name|share_name|photo_url)$/', $key, $matches)) {
        continue;
    }
    $deviceRows = $database->query('SELECT bind_site FROM vehicles WHERE serial_number = ? LIMIT 1', [$matches[1]]);
    if (!$deviceRows || !venue_scope_can_access($database, $user, (int)($deviceRows[0]['bind_site'] ?? 0))) {
        continue;
    }
    $redis->delete($key);
    $nativeRedis->sRem('vehicle_name_audit_pool', $key);
}

echo json_encode(['code' => 0, 'msg' => '已确认并清除记录']);
?>
