<?php
require_once '../RedisHelper.php';

header('Content-Type: application/json');

try {
    $redis = new RedisHelper();
    $redis->connect();
    $redis->selectDb(1);

    // 获取所有 feedback:* 键
    $keys = $redis->getAllKeys('feedback:*');

    $results = [];

    foreach ($keys as $key) {
        $value = $redis->get($key);
        if (!$value) continue;

        $data = json_decode($value, true);
        if (!isset($data['image_url']) || !isset($data['risk_level'])) continue;

        $results[] = [
            'image_url' => $data['image_url'],
            'risk_level' => $data['risk_level'],
            'key' => $key
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $results,
        'count' => count($results)
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Redis 查询失败: ' . $e->getMessage()
    ]);
}
