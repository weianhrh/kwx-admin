<?php
require_once '../RedisHelper.php';
require_once '../Database.php';

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? '';
$user = $session_token !== '' ? $database->getUserBySessionToken($session_token) : null;
if (!$user || !in_array((int)($user['role_id'] ?? 0), [1, 2], true)) {
    echo json_encode(['code' => 1002, 'msg' => '权限不足，仅管理员可查看审核列表', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3);

// 通过反射访问 Redis 原生对象
$reflection = new ReflectionClass($redis);
$property = $reflection->getProperty('redis');
$property->setAccessible(true);
$nativeRedis = $property->getValue($redis);

// 获取设备审核池中的所有 key
$auditKeys = $nativeRedis->sMembers('vehicle_name_audit_pool');

$result = [];

foreach ($auditKeys as $key) {
    $json = $redis->get($key);
    if (!$json) continue;

    $item = json_decode($json, true);
    if (!$item || !isset($item['status']) || $item['status'] !== 'pending') continue;

    // 提取字段（设备 ID、类型、旧名称、新名称、提交时间等）
    $result[] = [
        'device_id' => $item['device_id'] ?? '',
        'field'     => $item['field'] ?? '',
        'old'       => $item['old'] ?? '',
        'new'       => $item['new'] ?? '',
        'status'    => $item['status'],
        'reason'    => $item['reason'] ?? '',
        'timestamp' => $item['timestamp'] ?? 0,
        'redis_key' => $key
    ];
}

// 输出 JSON 结果
echo json_encode([
    'code' => 0,
    'msg' => 'ok',
    'data' => $result
], JSON_UNESCAPED_UNICODE);
?>
