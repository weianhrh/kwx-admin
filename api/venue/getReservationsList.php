<?php
require_once '../Database.php';  // 确保路径正确

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

// 获取请求参数，用于分页和搜索
$page = $_GET['page'] ?? 1;
$limit = $_GET['limit'] ?? 140;
$offset = ($page - 1) * $limit;
$orderNumber = $_GET['order_number'] ?? '';
$reservationDate = $_GET['reservation_date'] ?? ''; // 修改默认为不设置
$reservationLocation = $_GET['reservation_location'] ?? '';
$status = $_GET['status'] ?? ''; // 获取状态参数

// 构建查询语句和参数
$sql = "SELECT r.id,  r.order_number,  r.reservation_type,  r.reservation_location,  r.reservation_id,  r.reservation_time, 
        r.user_id,  u.nickname,  r.order_status,  r.driving_start_time,  r.driving_end_time,  r.driving_duration,  r.pay_type,  r.pay_money,  r.start_time, 
        r.notification_status,  v.name  as vehicle_name
        FROM Reservations r
        LEFT JOIN users u ON r.user_id  = u.uid 
        LEFT JOIN vehicles v ON r.user_id  = v.driver_id 
        WHERE 1=1"; // 使用 1=1 使得后续条件添加更为灵活

$params = [];

// 只在提供了日期时添加日期条件
if (!empty($reservationDate)) {
    $sql .= " AND DATE(r.reservation_time)  = ?";
    $params[] = $reservationDate;
}

if (!in_array($role_id, [1, 2], true)) {
    // 非管理员，只能看到绑定的场地数据
    $sql .= " AND r.reservation_id  = ?";
    $params[] = $user['venue_id'];
}

// 添加订单编号搜索条件
if (!empty($orderNumber)) {
    $sql .= " AND order_number LIKE ?";
    $params[] = "%$orderNumber%";
}

// 添加预约场地搜索条件
if (!empty($reservationLocation)) {
    $sql .= " AND reservation_location LIKE ?";
    $params[] = "%$reservationLocation%";
}

// 添加状态搜索条件
if (!empty($status)) {
    $sql .= " AND order_status = ?";
    $params[] = $status;
}

// 添加排序逻辑，按预约时间降序排序
$sql .= " ORDER BY reservation_time DESC";

// 添加分页逻辑
$sql .= " LIMIT ?, ?";
$params[] = $offset;
$params[] = $limit;

// 执行查询
$data = $database->query($sql, $params);

// 获取总数（保持与主查询条件一致）
$countSql = "SELECT COUNT(*) as count FROM Reservations r WHERE 1=1";
$countParams = [];

if (!empty($reservationDate)) {
    $countSql .= " AND DATE(r.reservation_time) = ?";
    $countParams[] = $reservationDate;
}

if ($role_id != 1) {
    $countSql .= " AND r.reservation_id = ?";
    $countParams[] = $user['venue_id'];
}

if (!empty($orderNumber)) {
    $countSql .= " AND r.order_number LIKE ?";
    $countParams[] = "%$orderNumber%";
}

if (!empty($reservationLocation)) {
    $countSql .= " AND r.reservation_location LIKE ?";
    $countParams[] = "%$reservationLocation%";
}

if (!empty($status)) {
    $countSql .= " AND r.order_status = ?";
    $countParams[] = $status;
}

$countResult = $database->query($countSql, $countParams);
$totalCount = $countResult ? $countResult[0]['count'] : 0;

// 输出JSON
echo json_encode([
    'code' => 0,
    'msg' => '',
    'count' => $totalCount,
    'data' => $data
]);

// 关闭数据库连接
$database->close();
?>