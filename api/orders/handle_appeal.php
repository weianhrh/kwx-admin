<?php
require_once '../Database.php';
header('Content-Type: application/json');

$database = new Database();

// 鉴权
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未登录']);
    exit;
}
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1002, 'msg' => '权限不足']);
    exit;
}
$handler_uid = $user['uid'];

// 参数获取
$id = $_POST['appeal_id'] ?? null;
$action = $_POST['action'] ?? null; // approve / reject
$result = $_POST['result'] ?? '';

if (!$id || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['code' => 1003, 'msg' => '参数错误']);
    exit;
}

$status = $action === 'approve' ? 1 : 2;
$handled_at = date('Y-m-d H:i:s');

$sql = "UPDATE order_appeals SET status = ?, result = ?, handler_uid = ?, handled_at = ? WHERE id = ?";
$affected = $database->query($sql, [$status, $result, $handler_uid, $handled_at, $id], true);

if ($affected) {
    echo json_encode(['code' => 0, 'msg' => '处理成功']);
} else {
    echo json_encode(['code' => 1004, 'msg' => '处理失败']);
}
