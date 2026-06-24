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

// 获取请求参数
$reservation_id = $_GET['reservation_id'] ?? '';
$uid = $_GET['uid'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$order_number = $_GET['order_number'] ?? '';

// 获取分页参数
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page_size = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 10;
$offset = ($page - 1) * $page_size;

// 构建基础SQL查询
$sql = "SELECT o.reservation_id, ro.uid, ro.order_number, ro.product_name, 
               ro.created_at, ro.payer_total AS total_payment
        FROM RechargeOrders AS ro 
        INNER JOIN `orders` AS o ON ro.reservation_id = o.order_id
        WHERE ro.status = '支付成功'";

// 构建汇总SQL查询
$sum_sql = "SELECT SUM(ro.payer_total) as total_sum
            FROM RechargeOrders AS ro 
            INNER JOIN `orders` AS o ON ro.reservation_id = o.order_id
            WHERE ro.status = '支付成功'";

$where_conditions = [];
$types = "";
$params = [];

// 添加查询条件
if (!empty($reservation_id)) {
    $where_conditions[] = "o.reservation_id = ?";
    $types .= "i";
    $params[] = $reservation_id;
}

if (!empty($uid)) {
    $where_conditions[] = "ro.uid = ?";
    $types .= "i";
    $params[] = $uid;
}

if (!empty($order_number)) {
    $where_conditions[] = "ro.order_number LIKE ?";
    $types .= "s";
    $params[] = $order_number . '%';
}

if (!empty($start_date)) {
    $where_conditions[] = "DATE(ro.created_at) >= ?";
    $types .= "s";
    $params[] = $start_date;
}

if (!empty($end_date)) {
    $where_conditions[] = "DATE(ro.created_at) <= ?";
    $types .= "s";
    $params[] = $end_date;
}

if (!empty($where_conditions)) {
    $where_clause = " AND " . implode(" AND ", $where_conditions);
    $sql .= $where_clause;
    $sum_sql .= $where_clause;
}

// 修改查询语句，添加分页
$count_sql = $sql; // 保存不带 LIMIT 的查询用于计算总数
$sql .= " LIMIT ?, ?";
$types .= "ii";
$params[] = $offset;
$params[] = $page_size;

try {
    // 执行分页查询
    $stmt = $database->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    $stmt->close();

    // 获取总记录数
    $count_stmt = $database->prepare("SELECT COUNT(*) as total FROM ($count_sql) as t");
    if (!empty($params)) {
        // 移除最后两个分页参数
        array_pop($params);
        array_pop($params);
        $types = substr($types, 0, -2);
        if (!empty($params)) {
            $count_stmt->bind_param($types, ...$params);
        }
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_count = $count_result->fetch_assoc()['total'];
    $count_stmt->close();

    // 获取总金额
    $sum_stmt = $database->prepare($sum_sql);
    if (!empty($params)) {
        $sum_stmt->bind_param($types, ...$params);
    }
    $sum_stmt->execute();
    $sum_result = $sum_stmt->get_result();
    $total_sum = $sum_result->fetch_assoc()['total_sum'] ?? 0;
    $sum_stmt->close();

    echo json_encode([
        'code' => 0,
        'msg' => '查询成功',
        'data' => [
            'list' => $orders,
            'total_sum' => $total_sum,
            'total_count' => $total_count,
            'page' => $page,
            'page_size' => $page_size
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'code' => 1002,
        'msg' => '查询失败：' . $e->getMessage(),
        'data' => []
    ]);
}

// 关闭数据库连接
$database->close(); 