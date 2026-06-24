<?php
require_once '../Database.php';
require_once '../RedisHelper.php';


$database = new Database();

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未登录']);
    exit;
}

$user = $database->query("SELECT role_id FROM admin_users WHERE session_token = ?", [$session_token]);
if (!$user || !in_array((int)$user[0]['role_id'], [1, 2], true)) {
    echo json_encode(['code' => 1002, 'msg' => '权限不足']);
    exit;
}

$dir = __DIR__ . '/pending_images/';
$files = scandir($dir);
$results = [];

foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;

    // 新正则，提取 venue_id 和时间戳
    if (preg_match('/venue_(\d+)_(\d{14})\.(jpg|jpeg|png|gif|webp)$/i', $file, $matches)) {
        $venue_id = $matches[1];
        $timestamp = $matches[2];

        // 格式化时间
        $formatted_time = DateTime::createFromFormat('YmdHis', $timestamp)->format('Y-m-d H:i:s');

        $venue = $database->query("SELECT venue_name FROM venues WHERE id = ?", [$venue_id]);

        $results[] = [
            'id' => $venue_id,
            'venue_name' => $venue[0]['venue_name'] ?? '未知场地',
            'image_url' => 'pending_images/' . $file,
            'image_status' => 'pending',
            'upload_time' => $formatted_time // 加上时间字段
        ];
    }
}

$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3);

$reflection = new ReflectionClass($redis);
$property = $reflection->getProperty('redis');
$property->setAccessible(true);
$nativeRedis = $property->getValue($redis);

// 场地名称
$nameKeys = $nativeRedis->sMembers('venue_name_audit_pool');
$nameCount = 0;
foreach ($nameKeys as $key) {
    $data = $redis->get($key);
    if ($data && json_decode($data, true)['status'] === 'pending') {
        $nameCount++;
    }
}

// 场地描述
$descKeys = $nativeRedis->sMembers('venue_description_audit_pool');
$descCount = 0;
foreach ($descKeys as $key) {
    $data = $redis->get($key);
    if ($data && json_decode($data, true)['status'] === 'pending') {
        $descCount++;
    }
}

// 设备名称/分享名称
$deviceKeys = $nativeRedis->sMembers('vehicle_name_audit_pool');
$deviceCount = 0;
foreach ($deviceKeys as $key) {
    $data = $redis->get($key);
    if ($data && json_decode($data, true)['status'] === 'pending') {
        $deviceCount++;
    }
}

$total = $nameCount + $descCount + $deviceCount;

echo json_encode([
    'code' => 0,
    'msg' => '获取成功',
    'count' => count($results) + $total, // 新增统计数量字段
    'data' => $results
], JSON_UNESCAPED_UNICODE);

?>
