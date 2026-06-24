<?php
require_once '../Database.php';

$database = new Database();

// 校验登录
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || intval($user['role_id']) !== 1) {
    echo json_encode(['success' => false, 'message' => '无权限操作']);
    exit;
}

// 参数
$uid = $_POST['uid'] ?? null;
if (!$uid) {
    echo json_encode(['success' => false, 'message' => '缺少用户ID']);
    exit;
}

// 查询当前状态
$sql = "SELECT deleted FROM users WHERE uid = ?";
$res = $database->query($sql, [$uid]);
if (!$res) {
    echo json_encode(['success' => false, 'message' => '用户不存在']);
    exit;
}

$current = intval($res[0]['deleted']);
$new = $current === 1 ? 0 : 1;

// 更新状态
$updateSql = "UPDATE users SET deleted = ? WHERE uid = ?";
$database->query($updateSql, [$new, $uid]);

echo json_encode([
    'success' => true,
    'message' => $new === 1 ? '用户已注销' : '用户已恢复'
]);
