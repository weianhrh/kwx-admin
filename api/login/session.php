<?php
require_once '../Database.php'; // 确保路径正确

$database = new Database();

// 从请求中获取 session_token
$session_token = $_COOKIE['session_token'] ?? '';

// 检查 session_token 是否存在
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未提供有效的身份验证令牌', 'data' => []]);
    exit;
}
//echo $session_token;
// 查询数据库中的用户信息
$sql = "SELECT username, role_id FROM admin_users WHERE session_token = ?";
$params = [$session_token];
$user = $database->query($sql, $params);

if ($user) {
    $userData = [
        'username' => $user[0]['username'],
        'role' => $user[0]['role_id']
    ];
    echo json_encode([
        'code' => 0,
        'msg' => '',
        'data' => $userData
    ]);
} else {
    echo json_encode([
        'code' => 1001,
        'msg' => 'session_token无效或用户不存在',
        'data' => []
    ]);
}

$database->close();
?>