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

// 接收查询参数
$area = $_GET['area'] ?? '';
$group = $_GET['group'] ?? '';

// 构建查询SQL
$sql = "SELECT * FROM image_transmitters WHERE 1=1";
$params = [];
$types = "";

if ($area) {
    $sql .= " AND area = ?";
    $params[] = $area;
    $types .= "s";
}

if ($group) {
    $sql .= " AND group_number = ?";
    $params[] = $group;
    $types .= "i";
}

$sql .= " ORDER BY area, group_number, created_at DESC";

try {
    $stmt = $database->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    echo json_encode(['code' => 200, 'msg' => '查询成功', 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['code' => 1003, 'msg' => '查询失败：' . $e->getMessage(), 'data' => []]);
}
?> 