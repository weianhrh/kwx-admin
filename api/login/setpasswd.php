<?php
require_once '../Database.php';  // 调整为实际路径

$database = new Database();

// 只接受 POST 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 获取 JSON 格式的请求体内容
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    // 从 JSON 中提取字段
    $uid = $data['uid'] ?? '';
    $newPassword = $data['newpassword'] ?? ''; // 注意字段名是 newpassword，不是 password

    if (!$uid || !$newPassword) {
        echo json_encode(['code' => 1, 'msg' => 'UID 和密码不能为空', 'data' => []]);
        exit;
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateSql = "UPDATE admin_users SET password = ? WHERE id = ?";
    $result = $database->query($updateSql, [$hashedPassword, $uid], true);

    if ($result) {
        echo json_encode(['code' => 0, 'msg' => "UID $uid 密码修改成功", 'data' => [$newPassword,$hashedPassword,$uid]]);
    } else {
        echo json_encode(['code' => 1, 'msg' => '密码修改失败，可能 UID 不存在', 'data' => []]);
    }

    $database->close();
} else {
    echo json_encode(['code' => 1, 'msg' => '无效请求，请使用 POST 方法', 'data' => []]);
}
?>
