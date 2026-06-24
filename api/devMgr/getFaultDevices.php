<?php
require_once '../Database.php';  

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
$venue_id = $user['venue_id'];
$today = date('Y-m-d');

$conn = $database->getConnection();

$sql = "SELECT f.serial_number, f.created_at, f.resolved_status, f.user_id, f.fault_reason, v.bind_site, v.name   
        FROM FaultRecords AS f 
        INNER JOIN vehicles AS v 
        ON f.serial_number = v.serial_number   
        WHERE DATE(f.created_at) = ? 
          AND f.resolved_status = 0"; 
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();

$output = [];

while ($row = $result->fetch_assoc()) {
    if ($row['bind_site'] == $venue_id) {
        $output[] = [
            'serial_number' => $row['serial_number'],
            'created_at' => $row['created_at'],
            'user_id' => $row['user_id'],
            'fault_reason' => $row['fault_reason'],
            'resolved_status' => $row['resolved_status'],
            'bind_site' => $row['bind_site'],
            'name' => $row['name']
        ];
    }
}

if (!$result) {
    $error = $conn->error;
    $response = array(
        'code' => 1,
        'data' => [],
        'error' => $error
    );
} elseif (count($output) > 0) {
    $response = array(
        'code' => 0,
        'data' => $output,
        'count' => count($output)  // ✅ 新增：返回总数
    );
} else {
    $response = array(
        'code' => 1,
        'data' => [],
        'count' => 0  // ✅ 新增：返回为 0 的 count
    );
}

$stmt->close();
$database->close();

header('Content-Type: application/json');
echo json_encode($response);
?>
