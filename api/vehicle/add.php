<?php
require_once '../Database.php'; // 确保正确的路径

// 创建数据库连接
$database = new Database();

// 接收前端传入的查询参数
$serial_number = $_POST['serial_number'] ?? '';//设备id
$name = $_POST['name'] ?? '';//车辆名称
$sharing_status = $_POST['sharing_status'] ?? '';//分享状态
$image_device_serial = $_POST['image_device_serial'] ?? ''; // 前摄
$bk_image_device_serial = $_POST['bk_image_device_serial'] ?? ''; // 后摄
$car_type = $_POST['car_type'] ?? ''; // 车辆类型





// 从请求中获取数据
$data = json_decode(file_get_contents('php://input'), true);

// 验证参数
if (empty($data['$serial_number']) || empty($data['$name']) || empty($data['$car_type']) ) {
    echo json_encode(['code' => 1, 'msg' => '缺少必要参数']);
    exit;
}

// 获取当前用户的 session_token
$session_token = $_COOKIE['session_token'] ?? null;

// 验证 session_token 并获取用户信息
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期']);
    exit;
}

$user = $database->getUserBySessionToken($session_token);

// 检查用户是否存在和权限获取
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问']);
    exit;
}

$uid = $user['uid'];//设备绑定用户的id
$bind_site = $user['bind_site'] ?? null; // 绑定的场地id

// 接收前端传入的查询参数
$serial_number = $_POST['serial_number'] ?? '';//设备id
$name = $_POST['name'] ?? '';//车辆名称
$sharing_status = $_POST['sharing_status'] ?? '';//分享状态
$image_device_serial = $_POST['image_device_serial'] ?? ''; // 前摄
$bk_image_device_serial = $_POST['bk_image_device_serial'] ?? ''; // 后摄
$car_type = $_POST['car_type'] ?? ''; // 车辆类型


// 第一步插入设备数据
$sql = "INSERT INTO vehicles (serial_number, name, sharing_status, image_device_serial, bk_image_device_serial,bind_site)
        VALUES (?, ?, ?, ?, ?,  ?)";

$params = [
    $serial_number,
    $name,
    $sharing_status,
    $image_device_serial,
    $bk_image_device_serial,
    $bind_site
];

// 执行插入操作
$insert_result = $database->execute($sql, $params);

//第二步 插入默认的设备配置
$sql = "INSERT INTO vehicle_control_settings (serial_number, topic, car_type)
        VALUES (?, ?, ?)";
$params = [
    $serial_number,
    $serial_number,
    $car_type,
];

// 执行插入操作
$insert_result = $database->execute($sql, $params);

if ($insert_result) {
    echo json_encode(['code' => 0, 'msg' => '设备添加成功']);
} else {
    echo json_encode(['code' => 1, 'msg' => '设备添加失败']);
}

// 关闭数据库连接
$database->close();
?>
