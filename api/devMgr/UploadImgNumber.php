<?php
require_once '../Database.php';

// 创建数据库连接
$database = new Database();

// 获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

// 验证用户信息
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

// 接收前端输入的数据
$image_device_serials_str = $_POST['image_device_serials'] ?? '';
$area = $_POST['area'] ?? ''; // 接收区域参数 A-Z
$group = $_POST['group'] ?? ''; // 接收组号参数 1-9

// 去除换行符，将字符串转换为数组
$image_device_serials = explode(PHP_EOL, $image_device_serials_str);

// 去除数组元素前后的空白字符并移除空元素
$image_device_serials = array_filter(array_map('trim', $image_device_serials));

// 检查数据完整性
if (empty($image_device_serials) || empty($area) || empty($group)) {
    echo json_encode(['code' => 1002, 'msg' => '缺少必要参数', 'data' => []]);
    exit;
}

// 检查区域格式
if (!preg_match('/^[A-Z]{2}$/', $area)) {
    echo json_encode(['code' => 1002, 'msg' => '区域格式错误，必须是两个相同的大写字母（如AA、BB）', 'data' => []]);
    exit;
}

// 检查区域是否为相同字母
if ($area[0] !== $area[1]) {
    echo json_encode(['code' => 1002, 'msg' => '区域必须是两个相同的字母（如AA、BB）', 'data' => []]);
    exit;
}

// 检查组号格式
if (!preg_match('/^[1-9]$/', $group)) {
    echo json_encode(['code' => 1002, 'msg' => '组号格式错误，必须是1-9之间的数字', 'data' => []]);
    exit;
}

// 检查数据库中是否存在重复的序列号
$existing_serials = [];
foreach ($image_device_serials as $serial) {
    $check_stmt = $database->prepare("SELECT image_device_serial FROM image_transmitters WHERE image_device_serial = ?");
    $check_stmt->bind_param("s", $serial);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    if ($result->num_rows > 0) {
        $existing_serials[] = $serial;
    }
}

if (!empty($existing_serials)) {
    echo json_encode(['code' => 1003, 'msg' => '以下序列号已存在：' . implode(', ', $existing_serials), 'data' => $existing_serials]);
    exit;
}

try {
    // 开始事务
    $database->beginTransaction();

    // 准备插入语句
    $stmt = $database->prepare("INSERT INTO image_transmitters (image_device_serial, area, group_number, created_at) VALUES (?, ?, ?, NOW())");

    // 循环插入数据
    foreach ($image_device_serials as $serial) {
        $stmt->bind_param("ssi", $serial, $area, $group);
        $stmt->execute();
    }

    // 提交事务
    $database->commit();
    echo json_encode(['code' => 200, 'msg' => '图传序列号批量添加成功！', 'data' => []]);
} catch (Exception $e) {
    // 回滚事务
    $database->rollBack();
    echo json_encode(['code' => 1003, 'msg' => '添加图传序列号时发生错误：' . $e->getMessage(), 'data' => []]);
}
?>
