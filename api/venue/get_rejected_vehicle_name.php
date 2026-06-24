<?php
require_once '../Database.php';
require_once '../RedisHelper.php';

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

// Redis 原始对象
$reflection = new ReflectionClass($redis);
$property = $reflection->getProperty('redis');
$property->setAccessible(true);
$nativeRedis = $property->getValue($redis);

// 遍历 name 和 share_name 两个字段
$result = [];

foreach (['name', 'share_name'] as $field) {
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
