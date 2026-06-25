<?php
// api/venue/getVenueEditLock.php
header('Content-Type: application/json; charset=utf-8');
require_once '../Database.php';
require_once '../lib/venue_scope.php';
require_once '../RedisHelper.php';
require_once './_venue_locks.php';

$database = new Database();

// 登录校验
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code'=>1001,'msg'=>'无有效认证信息，请登录','data'=>[]], JSON_UNESCAPED_UNICODE); exit;
}

$sql  = "SELECT id, uid, role_id, venue_id FROM admin_users WHERE session_token = ?";
$user = $database->query($sql, [$session_token]);
if (!$user) {
    echo json_encode(['code'=>1001,'msg'=>'用户未登录或不存在','data'=>[]], JSON_UNESCAPED_UNICODE); 
    $database->close(); exit;
}

$role_id      = (int)$user[0]['role_id'];
$operator_uid = (int)$user[0]['uid'];
// 传了 id 就用传入的；否则用自己场地
$venue_id = isset($_GET['id']) ? intval($_GET['id']) : (int)$user[0]['venue_id'];
if ($venue_id <= 0) {
    echo json_encode(['code'=>1002,'msg'=>'缺少场地ID','data'=>[]], JSON_UNESCAPED_UNICODE); exit;
}
// 非超管只能看自己可管理的场地锁
if (!venue_scope_can_access($database, $user[0], $venue_id)) {
    echo json_encode(['code'=>1003,'msg'=>'权限不足','data'=>[]], JSON_UNESCAPED_UNICODE); exit;
}

$locks = new VenueLocks();
// ✅ 管理员：返回 is_admin=true，并把锁置空（前端自然不会禁用）
if ($role_id === 1 || $role_id === 2) {
    echo json_encode([
        'code' => 0,
        'msg'  => 'ok',
        'data' => [
            'role_id'    => $role_id,
            'is_admin'   => true,
            // 'name_lock'  => null,
            // 'image_lock' => null,
                        'name_lock'  => $locks->get('name',  $venue_id),
            'image_lock' => $locks->get('image', $venue_id),
        ]
    ], JSON_UNESCAPED_UNICODE);
    $database->close();
    exit;
}
// ❗普通用户：照常返回真实锁信息
echo json_encode([
    'code' => 0,
    'msg'  => 'ok',
    'data' => [
        'role_id' => $role_id,
        'name_lock'  => $locks->get('name',  $venue_id),
        'image_lock' => $locks->get('image', $venue_id),
    ]
], JSON_UNESCAPED_UNICODE);

$database->close();
