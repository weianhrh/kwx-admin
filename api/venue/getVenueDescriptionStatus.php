<?php
require_once '../Database.php';
require_once '../RedisHelper.php';

// 设置响应类型为 JSON
header('Content-Type: application/json');

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未登录']);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['venue_id']) {
    echo json_encode(['code' => 1002, 'msg' => '非法用户']);
    exit;
}

$venue_id = $user['venue_id'];

$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3);

$key = "venue_description_audit:$venue_id";
$data = $redis->get($key);

if (!$data) {
    echo json_encode(['code' => 404, 'msg' => '暂无审核记录']);
    exit;
}

$audit = json_decode($data, true);
if (!$audit) {
    echo json_encode(['code' => 500, 'msg' => 'Redis中JSON格式错误']);
    exit;
}

echo json_encode(['code' => 0, 'data' => $audit], JSON_UNESCAPED_UNICODE);
