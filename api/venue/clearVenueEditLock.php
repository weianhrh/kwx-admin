<?php
// /api/venue/clearVenueEditLock.php
header('Content-Type: application/json; charset=utf-8');
require_once '../Database.php';
require_once './_venue_locks.php';

function out($code, $msg, $data = []) {
  echo json_encode(['code'=>$code,'msg'=>$msg,'data'=>$data], JSON_UNESCAPED_UNICODE);
  exit;
}

$database = new Database();

// 登录校验
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) out(1001, '无有效认证信息，请登录');

// 查用户
$sql  = "SELECT uid, role_id, venue_id FROM admin_users WHERE session_token = ?";
$user = $database->query($sql, [$session_token]);
if (!$user) out(1001, '用户未登录或不存在');

$role_id      = (int)$user[0]['role_id'];
$operator_uid = (int)$user[0]['uid'];

// 只允许超管/管理员（按你系统角色自己调）
if (!in_array($role_id, [1, 2], true)) out(1003, '权限不足');

// 参数
$venue_id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
$type     = isset($_REQUEST['type']) ? trim($_REQUEST['type']) : '';

if ($venue_id <= 0) out(1002, '缺少场地ID');
if (!in_array($type, ['name','image','all'], true)) out(1002, 'type 只能是 name / image / all');

$locks = new VenueLocks();

if ($type === 'all') {
  $locks->clear('name', $venue_id);
  $locks->clear('image', $venue_id);
  out(0, '已解除名称锁和图片锁', ['id'=>$venue_id,'type'=>'all','by_uid'=>$operator_uid]);
} else {
  $locks->clear($type, $venue_id);
  out(0, '已解除锁', ['id'=>$venue_id,'type'=>$type,'by_uid'=>$operator_uid]);
}
