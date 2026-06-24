<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../Database.php';

$database = new Database();

// 校验登录
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode([
        'success' => false,
        'message' => '用户未登录或会话已过期'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !isset($user['role_id'])) {
    echo json_encode([
        'success' => false,
        'message' => '用户未登录或无权访问'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 可按需限制权限
if (!in_array((int)$user['role_id'], [1, 2], true)) {
    echo json_encode([
        'success' => false,
        'message' => '无权限操作'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$uid = isset($_POST['uid']) ? intval($_POST['uid']) : 0;
if ($uid <= 0) {
    echo json_encode([
        'success' => false,
        'message' => '缺少用户ID'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 查询当前状态
$checkSql = "SELECT uid, IFNULL(is_mute, 0) AS is_mute FROM users WHERE uid = ?";
$checkRes = $database->query($checkSql, [$uid]);

if (!$checkRes || empty($checkRes[0])) {
    echo json_encode([
        'success' => false,
        'message' => '用户不存在'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentMute = (int)$checkRes[0]['is_mute'];
$newMute = $currentMute === 1 ? 0 : 1;

// 更新
$updateSql = "UPDATE users SET is_mute = ? WHERE uid = ?";
$updateRes = $database->query($updateSql, [$newMute, $uid], true);

if ($updateRes === false) {
    echo json_encode([
        'success' => false,
        'message' => '操作失败'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => $newMute === 1 ? '禁言成功' : '取消禁言成功',
    'is_mute' => $newMute
], JSON_UNESCAPED_UNICODE);

$database->close();