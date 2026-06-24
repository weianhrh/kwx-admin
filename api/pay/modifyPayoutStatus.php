<?php
// 引入数据库操作类
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
$username = $user['username'];
// 获取前端传来的参数
$id = $_POST['id'] ?? null;
$status = $_POST['status'] ?? null;

if (!$id || !isset($status)) {
    echo json_encode(['code' => 1002, 'msg' => '缺少必要参数', 'data' => []]);
    exit;
}

// 验证状态值是否合法
if ($status !== '0' && $status !== '1') {
    echo json_encode(['code' => 1003, 'msg' => '无效的打款状态值', 'data' => []]);
    exit;
}

// 准备 SQL 查询语句
$query = "UPDATE withdrawal_requests SET auditor = ? ,payout_status = ? WHERE id = ?";
$stmt = $database->getConnection()->prepare($query);

if (!$stmt) {
    echo json_encode(['code' => 500, 'msg' => '数据库查询准备失败', 'data' => []]);
    exit;
}

// 绑定参数
$stmt->bind_param('sii', $username,$status, $id);

// 执行查询
if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(['code' => 0, 'msg' => '打款状态修改成功', 'data' => []]);
    } else {
        echo json_encode(['code' => 1004, 'msg' => '未找到对应的提现记录或状态未改变', 'data' => []]);
    }
} else {
    echo json_encode(['code' => 500, 'msg' => '打款状态修改失败', 'data' => []]);
}

// 关闭数据库连接
$database->close();
?>