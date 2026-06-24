<?php
require_once '../RedisHelper.php';

header('Content-Type: application/json');

$redis = new RedisHelper();

try {
    $redis->connect();

    // 👉 选择 Redis 第 14 库，记录违规信息
    $redis->selectDb(14);
} catch (Exception $e) {
    error_log($e->getMessage());
    die($e->getMessage());
}

// 获取参数
$deviceId = $_POST['serial_number'] ?? null;
$dimage_url = $_POST['image_url'] ?? null;
$violationReason = $_POST['details'] ?? null;
$risk_level = $_POST['risk_level'] ?? null;
$remark = $_POST['remark'] ?? null;
$timestamp = time();
$violationTime = date('Y-m-d H:i:s', $timestamp);

if (empty($deviceId)) {
    echo json_encode(['success' => false, 'message' => '缺少设备编号']);
    exit;
}

try {
    // $violationKey = "device_violation:" . $deviceId;
    // 判断是否有 remark，决定 key 的命名
    $isPredicted = !empty($remark);
    $violationKey = "device_violation:" . $deviceId . ($isPredicted ? "_predicted" : "");

    // 初始化命中次数
    $hitCount = 1;

    if ($redis->exists($violationKey)) {
        $existing = json_decode($redis->get($violationKey), true);
        $hitCount = isset($existing['hit_count']) ? ($existing['hit_count'] + 1) : 1;
    }

    $data = [
        'reason' => $violationReason,
        'image_url' => $dimage_url,
        'risk_level' => $risk_level,
        'time' => $violationTime,
        'hit_count' => $hitCount
    ];
    if ($isPredicted) {
        $data['remark'] = $remark;
    }
    $violationData = json_encode($data, JSON_UNESCAPED_UNICODE);


    // 获取当前 TTL（单位：秒）
    $ttl = $redis->ttl($violationKey);
    
    // 如果还存在有效期，就保留，不重新设定（即只更新值）
    if ($ttl > 0) {
        $redis->set($violationKey, $violationData);
        $redis->expire($violationKey, $ttl); // 重新设定原来的 TTL
    } else {
        // 如果没有 TTL 或 key 不存在，就新设一个 6 分钟 TTL
        $redis->setWithExpiration($violationKey, $violationData, 360);
    }


    // ✅ 再切换 Redis 第 13 库，记录“消失时间为一天”的标记
    $redis->selectDb(13);
    $disappearKey = "device_disappear:" . $deviceId;
    $redis->setWithExpiration($disappearKey, $violationData, 86400); // 设置值为 1，过期时间为 1 天

    echo json_encode([
        'success' => true,
        'message' => '设备违规信息处理完成',
        'hit_count' => $hitCount
    ]);

} catch (Exception $e) {
    error_log('Redis 处理失败: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Redis 操作失败']);
}
?>
