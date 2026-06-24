<?php
require_once '../Database.php'; 
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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

$uid = $user['uid'];

// 判断请求方式
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 接收更新字段
    $input = json_decode(file_get_contents('php://input'), true);
    $nickname = $input['nickname'] ?? null;
    $sex = $input['sex'] ?? null;
    $phone_number = $input['phone_number'] ?? null;



    // 通过 $database->prepare 处理（你原来的方式）
    $stmt = $database->prepare("UPDATE `users` SET nickname = ?, sex = ?, phone_number = ? WHERE uid = ?");
    
    if ($stmt === false) {
        echo json_encode(['code' => 1004, 'msg' => 'SQL 预处理失败', 'data' => []]);
        exit;
    }

    // 绑定参数（s = string, i = integer）
    $stmt->bind_param("siss", $nickname, $sex, $phone_number,$uid);

    $exec_result = $stmt->execute();

    if ($exec_result) {
        echo json_encode(['code' => 0, 'msg' => '用户信息更新成功', 'data' => []]);
    } else {
        echo json_encode(['code' => 1004, 'msg' => '更新失败', 'data' => []]);
    }

    $stmt->close();


} else {
    // GET 请求：获取用户信息
    $role_id = $user['role_id'];
    $role_sql = "SELECT role_name FROM `roles` WHERE id = ?";
    $role_result = $database->query($role_sql, [$role_id]);

    $user_info_sql = "SELECT nickname, headimgurl, sex, phone_number FROM `users` WHERE uid = ?";
    $user_info = $database->query($user_info_sql, [$uid]);

    if ($role_result && $user_info) {
        $response_data = [
            'username' => $user['username'],
            'role_name' => $role_result[0]['role_name'],
            'nickname' => $user_info[0]['nickname'],
            'headimgurl' => $user_info[0]['headimgurl'],
            'sex' => $user_info[0]['sex'] == 0 ? '男' : '女',
            'phone_number' => $user_info[0]['phone_number'] 
        ];

        echo json_encode(['code' => 0, 'msg' => '操作成功', 'data' => $response_data]);
    } else {
        echo json_encode(['code' => 1002, 'msg' => '获取用户信息失败', 'data' => []]);
    }
}
?>
