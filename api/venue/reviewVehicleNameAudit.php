<?php
require_once '../Database.php';
require_once '../RedisHelper.php';

$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3);
$database = new Database();

$data = json_decode(file_get_contents('php://input'), true);

$device_id = $data['device_id'] ?? null;
$field = $data['field'] ?? null; // name 或 share_name
$action = $data['action'] ?? ''; // 'approve' 或 'reject'
$reason = $data['reason'] ?? '';

if (!$device_id || !in_array($field, ['name', 'share_name']) || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['code' => 400, 'msg' => '参数错误']);
    exit;
}

$key = "vehicle_name_audit:{$device_id}:{$field}";
$raw = $redis->get($key);
if (!$raw) {
    echo json_encode(['code' => 404, 'msg' => '审核记录不存在']);
    exit;
}

$auditData = json_decode($raw, true);
if (!$auditData || $auditData['field'] !== $field) {
    echo json_encode(['code' => 500, 'msg' => '审核数据解析失败']);
    exit;
}

// Redis 原生对象
$reflection = new ReflectionClass($redis);
$property = $reflection->getProperty('redis');
$property->setAccessible(true);
$nativeRedis = $property->getValue($redis);

if ($action === 'approve') {
    // 审核通过 -> 更新 vehicles 表中字段
    $sql = "UPDATE vehicles SET `$field` = ? WHERE serial_number = ?";
    $ok = $database->query($sql, [$auditData['new'], $device_id], true);

    // 删除 Redis 缓存与池
    $redis->delete($key);
    $nativeRedis->sRem('vehicle_name_audit_pool', $key);

    echo json_encode([
        'code' => 0,
        'msg' => "审核通过，字段 {$field} 已更新为新值",
        'affected' => $ok
    ]);
} else {
    // 驳回 -> 修改 Redis 审核记录内容
    $auditData['status'] = 'rejected';
    $auditData['reason'] = $reason;
    $auditData['timestamp'] = time();

    $redis->save($key, json_encode($auditData, JSON_UNESCAPED_UNICODE), 86400);

    echo json_encode(['code' => 0, 'msg' => '审核已驳回，记录保留 1 天']);
}
?>
