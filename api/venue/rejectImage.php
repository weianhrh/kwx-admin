<?php
require_once '../Database.php';
$database = new Database();
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $_POST = json_decode(file_get_contents('php://input'), true) ?? [];
}
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未登录']);
    exit;
}

$user = $database->query("SELECT uid,role_id FROM admin_users WHERE session_token = ?", [$session_token]);
if (!$user || !in_array((int)$user[0]['role_id'], [1, 2], true)) {
    echo json_encode(['code' => 1002, 'msg' => '权限不足']);
    exit;
}

$venue_id = $_POST['venue_id'] ?? null;
$reason = $_POST['reason'] ?? '无原因';
$reviewer_uid =  $user[0]['uid'];
echo "$reviewer_uid";
$reviewed_at = date('y-m-d H:i:s'); 
if (!$venue_id) {
    echo json_encode(['code' => 1003, 'msg' => '缺少场地ID']);
    exit;
}

// 更新状态为 rejected，同时保存 reason（你可以扩展数据库记录拒绝原因）

$update_sql = "UPDATE venue_image_reviews SET status = 'rejected', reason = ?, reviewer_uid = ?, reviewed_at = ? WHERE venue_id = ? AND status = 'pending'";
$result = $database->query($update_sql, [$reason, $reviewer_uid, $reviewed_at, $venue_id], true);

if ($result > 0) {
    echo json_encode(['code' => 0, 'msg' => '图片已拒绝']);
} else {
    echo json_encode(['code' => 1004, 'msg' => '更新失败']);
}

?>
