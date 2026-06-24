<?php
require_once '../RedisHelper.php';

$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3);

// 反射访问内部 redis 对象
$reflection = new ReflectionClass($redis);
$property = $reflection->getProperty('redis');
$property->setAccessible(true);
$nativeRedis = $property->getValue($redis);

// 获取所有待审核的 key
$auditKeys = $nativeRedis->sMembers('venue_name_audit_pool');
$nameList = [];
$descList = [];

foreach ($nativeRedis->sMembers('venue_name_audit_pool') as $key) {
    $json = $redis->get($key);
    if ($json) {
        $item = json_decode($json, true);
        if ($item && $item['status'] === 'pending') {
            $nameList[] = $item;
        }
    }
}

foreach ($nativeRedis->sMembers('venue_description_audit_pool') as $key) {
    $json = $redis->get($key);
    if ($json) {
        $item = json_decode($json, true);
        if ($item && $item['status'] === 'pending') {
            $descList[] = $item;
        }
    }
}

echo json_encode([
    'code' => 0,
    'msg' => 'ok',
    'data' => [
        'name_list' => $nameList,
        'desc_list' => $descList
    ]
], JSON_UNESCAPED_UNICODE);

?>
