<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../Database.php';
require_once '../lib/venue_scope.php';
require_once '../RedisHelper.php';

$database = new Database();
$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3);

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
  echo json_encode(['code'=>1001,'msg'=>'无有效认证信息，请登录','data'=>[]], JSON_UNESCAPED_UNICODE);
  exit;
}

$sql = "SELECT id, uid, role_id, venue_id FROM admin_users WHERE session_token = ?";
$user = $database->query($sql, [$session_token]);
if (!$user) {
  echo json_encode(['code'=>1001,'msg'=>'用户未登录或不存在','data'=>[]], JSON_UNESCAPED_UNICODE);
  exit;
}

$role_id = (int)$user[0]['role_id'];
$venue_id = isset($_GET['id']) && trim($_GET['id']) !== '' ? (int)$_GET['id'] : (int)$user[0]['venue_id'];

if (!$venue_id) {
  echo json_encode(['code'=>1002,'msg'=>'缺少场地ID','data'=>[]], JSON_UNESCAPED_UNICODE);
  exit;
}
// 仅管理员或当前账号可管理的场地可查
if (!venue_scope_can_access($database, $user[0], $venue_id)) {
  echo json_encode(['code'=>1003,'msg'=>'权限不足','data'=>[]], JSON_UNESCAPED_UNICODE);
  exit;
}

$key = "live:ban:venue:" . intval($venue_id);
$ttl = $redis->ttl($key);
if ($ttl === null) $ttl = -2;

$payload = $redis->get($key);
$data = $payload ? json_decode($payload, true) : null;

echo json_encode([
  'code' => 0,
  'msg'  => 'ok',
  'data' => [
    'venue_id' => (int)$venue_id,
    'banned'   => ($ttl > 0),
    'ttl'      => $ttl,
    'until'    => ($ttl > 0) ? date('c', time() + (int)$ttl) : null,
    'info'     => $data
  ]
], JSON_UNESCAPED_UNICODE);

$database->close();
