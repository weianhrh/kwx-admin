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
$serial_number = $_POST['serial_number'] ?? null;
$name = $_POST['name'] ?? null;
$topic = $_POST['topic'] ?? null;
 
if (!$serial_number || !$name || !$topic) {
    echo json_encode(['code' => 1002, 'msg' => '缺少必要参数', 'data' => []]);
    exit;
}
 
// 检查 serial_number 和 name 在 vehicles 表中是否唯一 
$checkVehicleSql = "SELECT COUNT(*) as count FROM vehicles WHERE serial_number = ? OR name = ?";
$vehicleCount = $database->query($checkVehicleSql, [$serial_number, $name])[0]['count'] ?? 0;
 
if ($vehicleCount > 0) {
    echo json_encode(['code' => 1003, 'msg' => 'serial_number 或 name 已存在', 'data' => []]);
    exit;
}
 
// 检查 serial_number 和 topic 在 vehicle_control_settings 表中是否唯一 
$checkControlSettingsSql = "SELECT COUNT(*) as count FROM vehicle_control_settings WHERE serial_number = ? OR topic = ?";
$controlSettingsCount = $database->query($checkControlSettingsSql, [$serial_number, $topic])[0]['count'] ?? 0;
 
if ($controlSettingsCount > 0) {
    echo json_encode(['code' => 1004, 'msg' => 'serial_number 或 topic 已存在', 'data' => []]);
    exit;
}
 
// 从现有记录中复制数据 
// 获取 vehicles 表中的第一条记录作为模板 
$vehicleTemplateSql = "SELECT * FROM vehicles LIMIT 1";
$vehicleTemplate = $database->query($vehicleTemplateSql)[0] ?? null;
 
if (!$vehicleTemplate) {
    echo json_encode(['code' => 1005, 'msg' => '未找到现有车辆记录，无法复制数据', 'data' => []]);
    exit;
}
 
// 获取 vehicle_control_settings 表中的第一条记录作为模板 
$controlSettingsTemplateSql = "SELECT * FROM vehicle_control_settings LIMIT 1";
$controlSettingsTemplate = $database->query($controlSettingsTemplateSql)[0] ?? null;
 
if (!$controlSettingsTemplate) {
    echo json_encode(['code' => 1006, 'msg' => '未找到现有控制设置记录，无法复制数据', 'data' => []]);
    exit;
}
 
// 在 vehicles 表中插入新记录 
$insertVehicleSql = "INSERT INTO vehicles (serial_number, name, photo_url, status, battery_level, voltage, vehicle_status, created_at, updated_at, uid, bind_site, bind_city, sharing_status, driver, driver_id, start_status, billing_rules, share_password, Reservation_lock, ReservationCode, share_name, image_device_serial, bk_image_device_serial, vehicle_level) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$database->execute($insertVehicleSql, [
    $serial_number,
    $name,
    $vehicleTemplate['photo_url'],
    $vehicleTemplate['status'],
    $vehicleTemplate['battery_level'],
    $vehicleTemplate['voltage'],
    $vehicleTemplate['vehicle_status'],
    $vehicleTemplate['uid'],
    $vehicleTemplate['bind_site'],
    $vehicleTemplate['bind_city'],
    $vehicleTemplate['sharing_status'],
    $vehicleTemplate['driver'],
    $vehicleTemplate['driver_id'],
    $vehicleTemplate['start_status'],
    $vehicleTemplate['billing_rules'],
    $vehicleTemplate['share_password'],
    $vehicleTemplate['Reservation_lock'],
    $vehicleTemplate['ReservationCode'],
    $vehicleTemplate['share_name'],
    $vehicleTemplate['image_device_serial'],
    $vehicleTemplate['bk_image_device_serial'],
    $vehicleTemplate['vehicle_level']
]);
 
// 在 vehicle_control_settings 表中插入新记录 
$insertControlSettingsSql = "INSERT INTO vehicle_control_settings (serial_number, car_type, car_category, driver_type, direction_max, direction_min, throttle_max, throttle_min, direction_mid, throttle_mid, gear_position, topic, car_id, direction, throttle, version, ttt, ddd, hhh, ch1, ch2, ch3, ch4, ch5, ch6, created_at, updated_at) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
$database->execute($insertControlSettingsSql, [
    $serial_number,
    $controlSettingsTemplate['car_type'],
    $controlSettingsTemplate['car_category'],
    $controlSettingsTemplate['driver_type'],
    $controlSettingsTemplate['direction_max'],
    $controlSettingsTemplate['direction_min'],
    $controlSettingsTemplate['throttle_max'],
    $controlSettingsTemplate['throttle_min'],
    $controlSettingsTemplate['direction_mid'],
    $controlSettingsTemplate['throttle_mid'],
    $controlSettingsTemplate['gear_position'],
    $topic,
    $controlSettingsTemplate['car_id'],
    $controlSettingsTemplate['direction'],
    $controlSettingsTemplate['throttle'],
    $controlSettingsTemplate['version'],
    $controlSettingsTemplate['ttt'],
    $controlSettingsTemplate['ddd'],
    $controlSettingsTemplate['hhh'],
    $controlSettingsTemplate['ch1'],
    $controlSettingsTemplate['ch2'],
    $controlSettingsTemplate['ch3'],
    $controlSettingsTemplate['ch4'],
    $controlSettingsTemplate['ch5'],
    $controlSettingsTemplate['ch6']
]);
 
// 返回成功响应 
echo json_encode(['code' => 0, 'msg' => '设备添加成功', 'data' => []]);
 
$database->close();