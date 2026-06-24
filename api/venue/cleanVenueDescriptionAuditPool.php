<?php
require_once '../RedisHelper.php';

$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3); // 确保使用审核专用的 Redis DB

try {
    // 通过反射访问 native redis
    $reflection = new ReflectionClass($redis);
    $property = $reflection->getProperty('redis');
    $property->setAccessible(true);
    $nativeRedis = $property->getValue($redis);

    $keys = $nativeRedis->sMembers('venue_description_audit_pool');
    $removed = 0;
    $checked = 0;

    foreach ($keys as $key) {
        $checked++;
        $json = $redis->get($key);
        $item = json_decode($json, true);

        // 条件：字段不完整或结构错误（不是数组 或 缺字段）
        if (!is_array($item) || !isset($item['venue_id']) || !isset($item['venue_description']) || !isset($item['status'])) {
            echo "❌ 清理无效审核记录: $key\n";
            $redis->delete($key);
            $nativeRedis->sRem('venue_description_audit_pool', $key);
            $removed++;
        }
    }

    echo "✅ 总共检查：$checked 个记录，已清理无效记录：$removed 个\n";

} catch (Exception $e) {
    echo "发生错误：" . $e->getMessage();
}
