<?php
require_once '../RedisHelper.php';

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
    $redis->delete($key);
    $nativeRedis->sRem('vehicle_name_audit_pool', $key);
}

echo json_encode(['code' => 0, 'msg' => '已确认并清除记录']);
?>
