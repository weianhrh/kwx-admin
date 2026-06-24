<?php
require_once '../Database.php';

$database = new Database();

// 获取当前用户
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户无效或无权限', 'data' => []]);
    exit;
}

// 判断是否为管理员
$role_id = $user['role_id'];
if ($role_id == 1 || $role_id == 2) {
    // 管理员：使用 GET 提供的 venue_id
    $venue_id = $_GET['venue_id'] ?? null;
    if (!$venue_id || !is_numeric($venue_id)) {
        echo json_encode(['code' => 1002, 'msg' => '缺少或无效的场地ID', 'data' => []]);
        exit;
    }
} else {
    // 普通用户：强制使用用户自己的 venue_id
    $venue_id = $user['venue_id'];
    if (!$venue_id) {
        echo json_encode(['code' => 1003, 'msg' => '用户未绑定场地', 'data' => []]);
        exit;
    }
}

// 查询 Reports + vehicles（根据场地筛选）
$sql = "
    SELECT r.*, v.bind_site 
    FROM Reports r
    JOIN vehicles v ON r.device_id = v.serial_number
    WHERE v.bind_site = ?
    ORDER BY r.insert_time DESC
";

$stmt = $database->prepare($sql);
$stmt->bind_param("i", $venue_id);
$stmt->execute();
$result = $stmt->get_result();

$reports = [];
while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}

$stmt->close();
$database->close();

// 返回数据
header('Content-Type: application/json');
echo json_encode([
    'code' => 0,
    'msg' => '获取成功',
    'data' => $reports
]);
