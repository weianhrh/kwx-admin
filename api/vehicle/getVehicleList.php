<?php
require_once '../Database.php'; // 确保正确的路径
// /api/vehicle/getVehicleList.php
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
$bind_site = $user['bind_site'] ?? null; // 假设用户表中包含 bind_site 字段

// 构建查询语句，根据用户角色和站点进行数据过滤
$sql = "SELECT 
            id, 
            serial_number, 
            photo_url, 
            name, 
            status, 
            battery_level, 
            voltage, 
            vehicle_status, 
            created_at, 
            updated_at, 
            uid, 
            bind_site, 
            bind_city, 
            sharing_status, 
            driver, 
            start_status, 
            billing_rules, 
            share_password, 
            Reservation_lock, 
            ReservationCode, 
            share_name, 
            image_device_serial, 
            bk_image_device_serial
        FROM vehicles";

// 如果用户不是管理员，限制他们只能看到特定站点的车辆
if (!in_array($role_id, [1, 2], true)) {
    $sql .= " WHERE bind_site = ?";
    $params[] = $user['venue_id'];
} else {
    $params = [];
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
        'msg' => '获取车辆详情失败',
        'count' => 0,
        'data' => []
    ]);
}

// 关闭数据库连接
$database->close();
?>