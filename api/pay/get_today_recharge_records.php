<?php
require_once '../Database.php'; // 确保正确的路径

// 创建数据库连接
$database = new Database();

// 获取今天的日期
$today = date('Y-m-d');

// 接收前端传入的查询参数
$order_number = $_GET['order_number'] ?? '';
$uid = $_GET['uid'] ?? '';
$status = $_GET['status'] ?? '';

// 构建基础查询，并进行表关联
$sql = "SELECT r.id, r.uid, r.order_number, r.product_name, r.shop_id, r.shop_type, 
               r.value, r.extra_value, r.payer_total, r.status, r.created_at, 
               r.paid_at, r.reservation_id, u.nickname
        FROM RechargeOrders r
        LEFT JOIN users u ON r.uid = u.uid
        WHERE DATE(r.created_at) = ?";
$params = [$today];

// 根据查询条件动态构建 SQL 语句
if (!empty($order_number)) {
    $sql .= " AND r.order_number LIKE ?";
    $params[] = "%$order_number%"; // 使用模糊匹配
}
if (!empty($uid)) {
    $sql .= " AND r.uid = ?";
    $params[] = (int)$uid; // 转换为整型
}
if (!empty($status)) {
    $sql .= " AND r.status = ?";
    $params[] = $status; // 使用原始状态值
}

// 执行查询
$result = $database->query($sql, $params);

if ($result !== false) {
    // 获取数据总数
    $count = count($result);

    // 输出符合 Layui 表格的 JSON 格式
    echo json_encode([
        'code' => 0,            // 状态码，0表示成功
        'msg' => '',            // 状态信息
        'count' => $count,      // 数据总数
        'data' => $result       // 具体数据
    ]);
} else {
    // 输出错误信息
    echo json_encode([
        'code' => 1,            // 非0表示失败
        'msg' => '获取今日充值记录失败',
        'count' => 0,
        'data' => []
    ]);
}

// 关闭数据库连接
$database->close();
?>