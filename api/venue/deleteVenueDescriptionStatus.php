<?php
require_once '../Database.php';
require_once '../RedisHelper.php';

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未登录']);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['venue_id']) {
    echo json_encode(['code' => 1002, 'msg' => '非法用户']);
    exit;
}

$venue_id = $user['venue_id'];
$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3);

$key = "venue_description_audit:$venue_id";
$redis->delete($key);

// 删除集合
try {
    $reflection = new ReflectionClass($redis);
    $property = $reflection->getProperty('redis');
    $property->setAccessible(true);
    $nativeRedis = $property->getValue($redis);
    $nativeRedis->sRem('venue_description_audit_pool', $key);
} catch (Exception $e) {}

echo json_encode(['code' => 0, 'msg' => '已清理']);
