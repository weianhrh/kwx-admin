<?php
require_once '../RedisHelper.php';
header('Content-Type: application/json');

$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(10);

// 获取数据
$serialNumber = $_POST['serial_number'] ?? '';
$imageUrl     = $_POST['image_url'] ?? '';
$riskLevel    = $_POST['risk_level'] ?? '';
$results      = $_POST['results'] ?? ''; // JSON 字符串
$timestamp    = time();

// 参数校验
if (empty($serialNumber) || empty($imageUrl) || empty($riskLevel)) {
    echo json_encode(['status' => 'error', 'message' => '参数缺失']);
    exit;
}

// 只处理有效风险数据
if (strtolower($riskLevel) !== 'none') {
    $feedbackKey = "feedback:" . $serialNumber . ":" . $timestamp;

    $redis->set($feedbackKey, json_encode([
        'risk_level' => $riskLevel,
        'image_url'  => $imageUrl,
        'timestamp'  => $timestamp,
        'results'    => json_decode($results, true)
    ], JSON_UNESCAPED_UNICODE));

    $redis->expire($feedbackKey, 86400); // 1天过期

    echo json_encode(['status' => 'ok', 'message' => '已写入']);
} else {
    echo json_encode(['status' => 'skip', 'message' => 'risk_level 为 none，跳过写入']);
}
