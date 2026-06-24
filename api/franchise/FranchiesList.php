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

$role_id = $user['role_id'];

// 假设只有特定角色可以访问加盟商信息，这里简单示例角色 ID 为 1 的用户可以访问
if (!in_array($role_id, [1, 2], true)) {
    echo json_encode(['code' => 1002, 'msg' => '没有权限访问加盟商信息', 'data' => []]);
    exit;
}

try {
    // 检查是否是更新状态的请求
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
        $id = $_POST['id'];
        if (!ctype_digit($id)) {
            echo json_encode(['code' => 1006, 'msg' => '参数非法', 'data' => []]);
            exit;
        }
                // 更新状态为已处理
        $updateQuery = "UPDATE contact_form_submissions SET status = '已处理' WHERE id = ?";
        $stmt = $database->getConnection()->prepare($updateQuery);
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            echo json_encode(['code' => 200, 'msg' => '成功更新加盟商信息状态', 'data' => []]);
        } else {
            echo json_encode(['code' => 1005, 'msg' => '更新加盟商信息状态失败', 'data' => []]);
        }
        $stmt->close();
    } else {
        // 查询加盟商信息
        $query = "SELECT id, name, email, message, submitted_at FROM contact_form_submissions WHERE status = '未处理'";
        $result = $database->query($query);
        
        if ($result === false) {
            echo json_encode(['code' => 1003, 'msg' => '查询加盟商信息失败', 'data' => []]);
            exit;
        }
        
        // 统一返回格式
        $submissions = [];
        
        if (is_array($result)) {
            $submissions = $result;
        } else {
            while ($row = $result->fetch_assoc()) {
                $submissions[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'message' => $row['message'],
                    'submitted_at' => $row['submitted_at']
                ];
            }
        }
        
        echo json_encode(['code' => 200, 'msg' => '成功获取加盟商信息', 'data' => $submissions]);

    }
} catch (Exception $e) {
    echo json_encode(['code' => 1004, 'msg' => '系统错误，请联系管理员', 'data' => []]);
}