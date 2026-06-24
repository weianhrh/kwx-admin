<?php
require_once '../Database.php'; 
require_once '../RedisHelper.php';

header('Content-Type: application/json');

$key = $_POST['key'] ?? '';

if (empty($key)) {
    echo json_encode(['success' => false, 'message' => '缺少 key 参数']);
    exit;
}

try {
    $redis = new RedisHelper();
    $redis->connect();
    $redis->selectDb(1);

    if ($redis->exists($key)) {
        $redis->delete($key);
        echo json_encode(['success' => true, 'message' => '已删除']);
    } else {
        echo json_encode(['success' => false, 'message' => 'key 不存在']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Redis 异常: ' . $e->getMessage()]);
}
