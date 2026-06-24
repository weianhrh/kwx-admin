<?php
require_once '../Database.php';
require_once '../RedisHelper.php';
$database = new Database();
// 从会话中获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;

// 验证 session_token 并获取用户信息
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $database->getUserBySessionToken($session_token);

// 检查用户是否存在和权限获取
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

$role_id = $user['role_id'];
$venue_id = $user['venue_id'];
$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3);

$key = "venue_name_audit:$venue_id";
$data = $redis->get($key);

if (!$data) {
    echo json_encode(['code' => 404, 'msg' => '未找到审核信息']);
    exit;
}

$audit = json_decode($data, true);
echo json_encode(['code' => 0, 'data' => $audit]);
