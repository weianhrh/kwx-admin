<?php
require_once '../Database.php';
require_once '../RedisHelper.php';
require_once '../lib/venue_scope.php';

$database = new Database();
$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3);

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未登录']);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user) {
    echo json_encode(['code' => 1002, 'msg' => '无效用户']);
    exit;
}

$device_id = $_GET['device_id'] ?? null;
if (!$device_id) {
    echo json_encode(['code' => 1003, 'msg' => '缺少设备 ID']);
    exit;
}

$deviceRows = $database->query('SELECT bind_site FROM vehicles WHERE serial_number = ? LIMIT 1', [$device_id]);
if (!$deviceRows || !venue_scope_can_access($database, $user, (int)($deviceRows[0]['bind_site'] ?? 0))) {
    echo json_encode(['code' => 1003, 'msg' => '无权查看该设备审核结果', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// Redis 原始对象
$reflection = new ReflectionClass($redis);
$property = $reflection->getProperty('redis');
$property->setAccessible(true);
$nativeRedis = $property->getValue($redis);

// 遍历设备图文字段
$result = [];

foreach (['name', 'share_name', 'photo_url'] as $field) {
    $key = "vehicle_name_audit:{$device_id}:{$field}";
    $raw = $redis->get($key);
    if (!$raw) continue;

    $data = json_decode($raw, true);
    if ($data['status'] === 'rejected') {
        $result[] = [
            'field' => $field,
            'reason' => $data['reason'],
            'redis_key' => $key
        ];
    }
}

echo json_encode([
    'code' => 0,
    'msg' => 'ok',
    'data' => $result
], JSON_UNESCAPED_UNICODE);
?>
