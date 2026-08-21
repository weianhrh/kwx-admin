<?php
require_once '../Database.php'; // 确保正确的路径
require_once '../lib/venue_scope.php';
require_once '../lib/device_wifi_whitelist.php';
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
$requestedVenueId = venue_scope_requested_id($_GET);
$wifiAllowedVenueIds = device_wifi_whitelist_ids();

// 构建查询语句，根据用户角色和站点进行数据过滤
$sql = "SELECT
            v.id,
            v.serial_number,
            v.photo_url,
            v.name,
            v.status,
            v.battery_level,
            v.voltage,
            v.vehicle_status,
            v.created_at,
            v.updated_at,
            v.uid,
            v.bind_site,
            v.bind_city,
            v.sharing_status,
            v.driver,
            v.start_status,
            v.billing_rules,
            v.share_password,
            v.Reservation_lock,
            v.ReservationCode,
            v.share_name,
            v.image_device_serial,
            di.room_id AS image_device_room_id,
            v.bk_image_device_serial
        FROM vehicles v
        LEFT JOIN device_information di
            ON CAST(di.id AS CHAR) = v.image_device_serial
        WHERE 1=1";

$params = [];
$sql .= venue_scope_apply_filter($database, $user, 'v.bind_site', $params, $requestedVenueId);
$sql .= " ORDER BY v.updated_at DESC, v.id DESC";

// 执行查询
$result = $database->query($sql, $params);

if ($result !== false) {
    // role_id 3/4 且设备所属场地已被老板开放时，允许显示“设备改网”。
    $canChangeWifiRole = in_array((int)$role_id, [3, 4], true);
    foreach ($result as &$device) {
        $device['can_change_wifi'] = $canChangeWifiRole
            && in_array((int)($device['bind_site'] ?? 0), $wifiAllowedVenueIds, true);
    }
    unset($device);

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
