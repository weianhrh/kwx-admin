<?php
require_once '../Database.php'; // 确保正确的路径

// 创建数据库连接
$database = new Database();

// 接收前端传入的查询参数
$order_number = $_GET['order_number'] ?? '';
$uid = $_GET['uid'] ?? '';
$status = $_GET['status'] ?? '';
$start_date = $_GET['start_date'] ?? ''; // 开始日期
$end_date = $_GET['end_date'] ?? '';     // 结束日期

// 构建基础查询
$sql = "SELECT id, uid, order_number, product_name, shop_id, shop_type, value, extra_value, payer_total, status, created_at, paid_at, reservation_id
        FROM RechargeOrders
        WHERE 1=1"; // 使用 1=1 便于后续拼接条件

$params = [];

// 根据查询条件动态构建 SQL 语句
if (!empty($order_number)) {
    $sql .= " AND order_number LIKE ?";
    $params[] = "%$order_number%"; // 使用模糊匹配
}
if (!empty($uid)) {
    $sql .= " AND uid = ?";
    $params[] = (int)$uid; // 转换为整型
}
if (!empty($status)) {
    $sql .= " AND status = ?";
    $params[] = $status; // 使用原始状态值
}
if (!empty($start_date)) {
    $sql .= " AND DATE(created_at) >= ?";
    $params[] = $start_date; // 添加开始日期过滤
}
if (!empty($end_date)) {
    $sql .= " AND DATE(created_at) <= ?";
    $params[] = $end_date; // 添加结束日期过滤
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
        'msg' => '获取充值记录失败',
        'count' => 0,
        'data' => []
    ]);
}

// 关闭数据库连接
$database->close();
?>