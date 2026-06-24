<?php
require_once '../Database.php';

// 创建数据库连接
$database = new Database();

// 从会话中获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;

// 验证 session_token 并获取用户信息
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $database->getUserBySessionToken($session_token);

// 检查用户是否存在和权限获取
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

$role_id = $user['role_id'];

// 获取查询时间参数
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

// 构建SQL查询
$sql = "SELECT SUM(payer_total) AS total_payment FROM RechargeOrders WHERE status = '支付成功'";

// 添加时间筛选条件
if ($start_date && $end_date) {
    $sql .= " AND created_at BETWEEN ? AND ?";
    $stmt = $database->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
} else {
    $stmt = $database->prepare($sql);
}

$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

// 返回结果
echo json_encode([
    'code' => 0,
    'msg' => '获取成功',
    'data' => [
        'total_payment' => $row['total_payment'] ?? 0
    ]
]);

$stmt->close();
?>