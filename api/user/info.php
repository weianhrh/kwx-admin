<?php
require_once '../Database.php'; 
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function out_json($payload) {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 创建数据库连接
$database = new Database();

// 获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    out_json(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
}

// 验证用户信息
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    out_json(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
}

$uid = $user['uid'] ?? '';

// 判断请求方式
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 接收更新字段
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $nickname = $input['nickname'] ?? null;
    $sex = $input['sex'] ?? null;
    $phone_number = $input['phone_number'] ?? null;

    $existing_user = $uid !== '' ? $database->query("SELECT uid FROM `users` WHERE uid = ? LIMIT 1", [$uid]) : [];
    if (empty($existing_user)) {
        out_json(['code' => 1002, 'msg' => '当前后台账号没有对应用户资料，暂不能保存昵称和手机', 'data' => []]);
    }


    // 通过 $database->prepare 处理（你原来的方式）
    $stmt = $database->prepare("UPDATE `users` SET nickname = ?, sex = ?, phone_number = ? WHERE uid = ?");
    
    if ($stmt === false) {
        out_json(['code' => 1004, 'msg' => 'SQL 预处理失败', 'data' => []]);
    }

    // 绑定参数（s = string, i = integer）
    $stmt->bind_param("siss", $nickname, $sex, $phone_number,$uid);

    $exec_result = $stmt->execute();

    if ($exec_result) {
        out_json(['code' => 0, 'msg' => '用户信息更新成功', 'data' => []]);
    } else {
        out_json(['code' => 1004, 'msg' => '更新失败', 'data' => []]);
    }

    $stmt->close();


} else {
    // GET 请求：获取用户信息
    $role_id = $user['role_id'];
    $role_sql = "SELECT role_name FROM `roles` WHERE id = ?";
    $role_result = $database->query($role_sql, [$role_id]);
    $role_name = $role_result[0]['role_name'] ?? ('role_' . $role_id);

    $user_info_sql = "SELECT nickname, headimgurl, sex, phone_number FROM `users` WHERE uid = ?";
    $user_info = $database->query($user_info_sql, [$uid]);
    $profile = $user_info[0] ?? null;

    $response_data = [
        'username' => $user['username'] ?? '',
        'role_name' => $role_name,
        'nickname' => $profile['nickname'] ?? '',
        'headimgurl' => $profile['headimgurl'] ?? '',
        'sex' => isset($profile['sex']) && (int)$profile['sex'] === 1 ? '女' : '男',
        'phone_number' => $profile['phone_number'] ?? '',
        'editable' => !empty($profile),
        'source' => !empty($profile) ? 'users' : 'admin_users'
    ];

    out_json(['code' => 0, 'msg' => '操作成功', 'data' => $response_data]);
}
?>
