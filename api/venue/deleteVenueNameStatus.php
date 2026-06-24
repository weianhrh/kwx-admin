<?php
require_once '../Database.php';
require_once '../RedisHelper.php';

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $database->getUserBySessionToken($session_token);

if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

$role_id = $user['role_id'];
$venue_id = $user['venue_id'];

$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3);

// 删除审核详情
$auditKey = "venue_name_audit:$venue_id";
$redis->delete($auditKey);

// 从审核池集合中移除
try {
    $reflection = new ReflectionClass($redis);
    $property = $reflection->getProperty('redis');
    $property->setAccessible(true);
    $nativeRedis = $property->getValue($redis);

    $nativeRedis->sRem('venue_name_audit_pool', $auditKey);
} catch (Exception $e) {
    // 如果反射失败也可以忽略
}

echo json_encode(['code' => 0, 'msg' => '删除成功']);
