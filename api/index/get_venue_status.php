<?php
require_once '../Database.php';

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $database->getUserBySessionToken($session_token);

if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

$venue_id = $user['venue_id'];

// 查询今天是否有封禁的设备记录
$sql_device = "
    SELECT COUNT(*) as count 
    FROM device_bans 
    WHERE venue_id = ? 
      AND status = 1 
      AND DATE(created_at) = CURDATE()
";
$stmt1 = $database->prepare($sql_device);
$stmt1->bind_param("i", $venue_id);
$stmt1->execute();
$result1 = $stmt1->get_result();
$row1 = $result1->fetch_assoc();
$device_banned = $row1['count'] > 0;
$stmt1->close();

// 查询场地是否被封禁
$sql_venue = "SELECT is_banned FROM venues WHERE id = ?";
$stmt2 = $database->prepare($sql_venue);
$stmt2->bind_param("i", $venue_id);
$stmt2->execute();
$result2 = $stmt2->get_result();
$row2 = $result2->fetch_assoc();
$venue_banned = isset($row2['is_banned']) && $row2['is_banned'] == 1;
$stmt2->close();

$database->close();

// 组合判断逻辑
if (!$device_banned && !$venue_banned) {
    $status = 0;
} elseif ($device_banned && !$venue_banned) {
    $status = 1;
} else { // 场地封禁，无论设备是否封禁
    $status = 2;
}

// 返回结果
header('Content-Type: application/json');
echo json_encode([
    'code' => 0,
    'msg' => '查询成功',
    'status' => $status
]);
