<?php 
// 引入数据库类文件 
// /api/finance/Comsumquery.php
require_once '../Database.php';  
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// 创建数据库连接实例 
$database = new Database(); 
$mysqli = $database->getConnection(); // 假设这里返回的是 mysqli 连接实例 
 
// 从 Cookie 中获取 session_token，如果不存在则赋值为 null 
$session_token = $_COOKIE['session_token'] ?? null; 
 
// 验证 session_token 是否存在 
if (!$session_token) { 
    // 如果不存在，返回用户未登录或会话已过期的 JSON 响应 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]); 
    exit; 
} 
 
// 通过 session_token 获取用户信息 
$user = $database->getUserBySessionToken($session_token); 
 
// 检查用户信息和角色 ID 是否存在 
if (!$user || !isset($user['role_id'])) { 
    // 如果不存在，返回用户未登录或无权访问的 JSON 响应 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]); 
    exit; 
} 
 
// 获取用户的角色 ID 
$role_id = $user['role_id']; 
 
// 假设通过 GET 请求传递 user_id 和 change_type 参数 
$user_id = $_GET['user_id'] ?? null; 
$change_type = $_GET['change_type'] ?? null; 
 
// 验证 user_id 是否存在 
if (!$user_id) { 
    echo json_encode(['code' => 1002, 'msg' => '用户 ID 不能为空', 'data' => []]); 
    exit; 
} 
 
// 构建 SQL 查询语句 
$sql = "SELECT id, user_id, change_amount, balance_before, balance_after, change_type, description, payment_channel, created_at 
        FROM balance_changes 
        WHERE user_id = ?"; 
 
$params = [$user_id]; 
 
// 如果指定了变动类型，则添加到查询条件中 
if ($change_type) { 
    $sql .= " AND change_type = ?"; 
    $params[] = $change_type; 
} 
 
// 准备 SQL 语句 
$stmt = $mysqli->prepare($sql); 
 
// 绑定参数 
if ($change_type) { 
    $stmt->bind_param("ss", $user_id, $change_type); 
} else { 
    $stmt->bind_param("s", $user_id); 
} 
 
// 执行查询 
$stmt->execute(); 
 
// 获取结果集 
$result = $stmt->get_result(); 
 
// 存储查询结果的数组 
$results = []; 
 
// 使用 fetch_assoc() 方法获取结果 
while ($row = $result->fetch_assoc()) { 
    $results[] = $row; 
} 
 
// 关闭语句和连接 
$stmt->close(); 
$mysqli->close(); 
 
// 返回成功响应 
echo json_encode(['code' => 200, 'msg' => '查询成功', 'data' => $results]); 
?> 