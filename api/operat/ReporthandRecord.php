<?php
require_once '../Database.php';
require_once '../lib/venue_scope.php';

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

// 判断是否为管理员，并支持加盟商多场地从 URL 传入 venue_id
$role_id = intval($user['role_id']);
$requested_venue_id = venue_scope_requested_id($_GET);

if ($requested_venue_id <= 0) {
    echo json_encode(['code' => 1002, 'msg' => '缺少或无效的场地ID', 'data' => []]);
    exit;
}

if (!venue_scope_can_access($database, $user, $requested_venue_id)) {
    echo json_encode(['code' => 1006, 'msg' => '无权查看该场地处理记录', 'data' => []]);
    exit;
}

$venue_id = $requested_venue_id;

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
