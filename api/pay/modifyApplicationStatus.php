<?php
require_once '../Database.php'; 

// 创建数据库连接
$database = new Database();

// 从会话中获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

// 验证 session_token 并获取用户信息
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

// 获取前端传递的参数
$id = $_POST['id'] ?? null;
$currentStatus = $_POST['status'] ?? null;

// 检查参数是否存在
if (!$id || !isset($currentStatus)) {
    echo json_encode(['code' => 1002, 'msg' => '缺少必要参数', 'data' => []]);
    exit;
}
$username = $user['username'];
// 根据当前状态计算新状态
$newStatus = null;
if ($currentStatus === '0') {
    $newStatus = 0; // 从待处理改为已处理
} elseif ($currentStatus === '1') {
    $newStatus = 1; // 从已处理改为已打款
}elseif ($currentStatus === '2') {
    $newStatus = 2; // 从已处理改为已打款
} else {
    $newStatus = 0; // 其他状态重置为待处理
}

// 更新数据库中的申请状态
$query = "UPDATE withdrawal_requests SET payout_person= ?, application_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
$stmt = $database->prepare($query);
if (!$stmt) {
    echo json_encode(['code' => 500, 'msg' => '预处理 SQL 语句失败', 'data' => []]);
    exit;
}

$stmt->bind_param('sii',$username, $newStatus, $id);
if (!$stmt->execute()) {
    echo json_encode(['code' => 500, 'msg' => '执行 SQL 语句失败', 'data' => []]);
    exit;
}

// 检查是否有行受到影响
if ($stmt->affected_rows === 0) {
    echo json_encode(['code' => 1003, 'msg' => '未找到对应的提现申请记录或状态未改变', 'data' => []]);
    exit;
}

// 返回成功信息
echo json_encode([
    'code' => 0,
    'msg' => '申请状态修改成功',
    'data' => []
]);

$stmt->close();
$database->close();
?>