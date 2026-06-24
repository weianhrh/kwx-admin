<?php // api/operat/_bootstrap.php
require_once __DIR__.'/../Database.php';
header('Content-Type: application/json; charset=utf-8');

function json_ok($data=[], $msg='ok', $code=0){
    echo json_encode(['code'=>$code,'msg'=>$msg,'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err($msg='error', $code=1001, $data=[]){
    echo json_encode(['code'=>$code,'msg'=>$msg,'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}

function auth_or_die(Database $db){
    $session_token = $_COOKIE['session_token'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);
    if(!$session_token){ json_err('用户未登录或会话已过期', 1001); }
    $user = $db->getUserBySessionToken($session_token);
    if(!$user || empty($user['role_id'])){ json_err('用户未登录或无权访问', 1001); }
    return $user;
}

/**
 * 简单的权限判定：平台管理员(role_id=1) 或 场地方(=2)允许操作。
 * 你可以根据自己的角色体系调整。
 */
function can_block($user){
    $roleId = intval($user['role_id'] ?? 0);
    return in_array($roleId, [1,2,3,4], true);
}

/** 读取 JSON 或表单 */
function input_array(){
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if(!is_array($data)) $data = $_POST;
    return $data ?: [];
}
