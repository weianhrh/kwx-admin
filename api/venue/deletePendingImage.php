<?php
require_once '../Database.php';

$database = new Database();

// 会话检查
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未登录']);
    exit;
}

// 权限验证
$user = $database->query("SELECT role_id FROM admin_users WHERE session_token = ?", [$session_token]);
if (!$user || !in_array((int)$user[0]['role_id'], [1, 2], true)) {
    echo json_encode(['code' => 1002, 'msg' => '权限不足']);
    exit;
}

// 获取 JSON 请求体
$input = json_decode(file_get_contents("php://input"), true);
$filename = $input['filename'] ?? '';
$venue_id = $input['venue_id'] ?? null;

// 校验参数
if (!$filename || substr($filename, 0, 15) !== 'pending_images/') {
    echo json_encode(['code' => 1003, 'msg' => '无效路径: ' . $filename]);
    exit;
}

if (!$venue_id || !is_numeric($venue_id)) {
    echo json_encode(['code' => 1003, 'msg' => '缺少或无效的场地ID']);
    exit;
}

// 删除本地图片
$filepath = __DIR__ . '/' . $filename;
if (file_exists($filepath)) {
    unlink($filepath);
    $fileDeleted = true;
} else {
    $fileDeleted = false;
}

// 响应
if ($fileDeleted) {
    echo json_encode([
        'code' => 0,
        'msg' => '图片审核通过，' . ($fileDeleted ? '已删除本地文件' : '本地文件不存在'),
    ]);
} else {
    echo json_encode(['code' => 1004, 'msg' => '审核失败或数据未变']);
}
?>
