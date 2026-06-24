<?php
require_once '../Database.php';
require_once '../RedisHelper.php';

$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3);

$database = new Database();

$data = json_decode(file_get_contents('php://input'), true);
$venue_id = $data['venue_id'] ?? null;
$action = $data['action'] ?? '';
$reason = $data['reason'] ?? '';

if (!$venue_id || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['code' => 400, 'msg' => '参数错误']);
    exit;
}

$key = "venue_description_audit:$venue_id";
$raw = $redis->get($key);
if (!$raw) {
    echo json_encode(['code' => 404, 'msg' => '审核记录不存在']);
    exit;
}

$auditData = json_decode($raw, true);
if (!$auditData) {
    echo json_encode(['code' => 500, 'msg' => '数据解析失败']);
    exit;
}

$reflection = new ReflectionClass($redis);
$property = $reflection->getProperty('redis');
$property->setAccessible(true);
$nativeRedis = $property->getValue($redis);

if ($action === 'approve') {
    $sql = "UPDATE venues SET venue_description = ? WHERE id = ?";
    $database->query($sql, [$auditData['venue_description'], $venue_id], true);

    $redis->delete($key);
    $nativeRedis->sRem('venue_description_audit_pool', $key);
     // ✅ 补充删除展示缓存
    $nativeRedis->del("venue:desc:$venue_id");

    echo json_encode(['code' => 0, 'msg' => '描述审核通过，已更新']);
} else {
    $auditData['status'] = 'rejected';
    $auditData['reason'] = $reason;
    $auditData['timestamp'] = time();

    $redis->save($key, json_encode($auditData, JSON_UNESCAPED_UNICODE), 86400);
    echo json_encode(['code' => 0, 'msg' => '描述已驳回']);
}
?>
