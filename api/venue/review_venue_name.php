<?php
require_once '../Database.php';
require_once '../RedisHelper.php';
require_once './_venue_locks.php';   // ✅ 新增
$locks = new VenueLocks();            // ✅ 新增
$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3);

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    logMessage("❌ 未登录访问");
    echo json_encode(['code' => 1001, 'msg' => '请先登录']);
    exit;
}

// 获取当前用户
$sql = "SELECT uid, role_id FROM admin_users WHERE session_token = ?";
$user = $database->query($sql, [$session_token]);

if (!$user || !in_array((int)$user[0]['role_id'], [1, 2], true)) {
    logMessage("❌ 非管理员访问，session_token=$session_token");
    echo json_encode(['code' => 1002, 'msg' => '权限不足，仅管理员可操作']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$venue_id = $data['venue_id'] ?? null;
$action = $data['action'] ?? ''; // 'approve' 或 'reject'
$reason = $data['reason'] ?? '';
$reviewer_uid = $user[0]['uid'];  
if (!$venue_id || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['code' => 400, 'msg' => '参数错误']);
    exit;
}

$key = "venue_name_audit:$venue_id";
$raw = $redis->get($key);
if (!$raw) {
    echo json_encode(['code' => 404, 'msg' => '审核记录不存在']);
    exit;
}

$auditData = json_decode($raw, true);
if (!$auditData) {
    echo json_encode(['code' => 500, 'msg' => '审核数据解析失败']);
    exit;
}

$reflection = new ReflectionClass($redis);
$property = $reflection->getProperty('redis');
$property->setAccessible(true);
$nativeRedis = $property->getValue($redis);

if ($action === 'approve') {
    // 更新数据库
    $sql = "UPDATE venues SET venue_name = ? WHERE id = ?";
    $database->query($sql, [$auditData['venue_name'], $venue_id], true);

    // 删除审核记录和池
    $redis->delete($key);
    $nativeRedis->sRem('venue_name_audit_pool', $key);
    $locks->set('name', $venue_id, '场地名称审核通过', $reviewer_uid);
    echo json_encode(['code' => 0, 'msg' => '审核通过并已更新场地名称']);
} else {
    // 驳回，更新状态与原因，刷新 TTL
    $auditData['status'] = 'rejected';
    $auditData['reason'] = $reason;
    $auditData['timestamp'] = time();

    $redis->save($key, json_encode($auditData, JSON_UNESCAPED_UNICODE), 86400);
    echo json_encode(['code' => 0, 'msg' => '已驳回，缓存保留 1 天']);
}
?>
