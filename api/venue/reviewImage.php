<?php
// /api/venue/reviewImage.php
require_once './_venue_locks.php';
$locks = new VenueLocks();
require_once '../Database.php'; // 引入数据库连接
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $_POST = json_decode(file_get_contents('php://input'), true) ?? [];
}
// 日志记录函数 
function logMessage($message) { 
    $logFile = __DIR__ . '/operation_log.txt';  
    $timestamp = date('Y-m-d H:i:s'); 
    $logEntry = "[$timestamp] $message\n"; 
    file_put_contents($logFile, $logEntry, FILE_APPEND); 
} 

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    logMessage("❌ 未登录访问");
    echo json_encode(['code' => 1001, 'msg' => '请先登录']);
    exit;
}

// 获取当前用户
$sql = "SELECT uid, role_id FROM admin_users WHERE session_token = ?";
$user = $database->query($sql, [$session_token]);

if (!$user || !in_array((int)$user[0]['role_id'], [1, 2], true)) {
    logMessage("❌ 非管理员访问，session_token=$session_token");
    echo json_encode(['code' => 1002, 'msg' => '权限不足，仅管理员可操作']);
    exit;
}

$venue_id = $_POST['venue_id'] ?? null;
$oss_uploaded_url = $_POST['oss_uploaded_url'] ?? null;
$reviewer_uid = $user[0]['uid'];         

if (!$venue_id || !is_numeric($venue_id)) {
    logMessage("❌ 审核失败，venue_id 无效: " . json_encode($_POST));
    echo json_encode(['code' => 1003, 'msg' => '缺少或无效的场地ID']);
    exit;
}

if (!$oss_uploaded_url) {
    logMessage("❌ 审核失败，oss_uploaded_url 缺失: " . json_encode($_POST));
    echo json_encode(['code' => 1005, 'msg' => '缺少 OSS 图片地址']);
    exit;
}

$reviewed_at = date('Y-m-d H:i:s'); 

// 更新审核状态
$update_sql = "UPDATE venue_image_reviews 
               SET status = 'approved', reviewer_uid = ?, reviewed_at = ?, image_url = ? 
               WHERE venue_id = ? AND status = 'pending'";
$result = $database->query($update_sql, [$reviewer_uid, $reviewed_at, $oss_uploaded_url, $venue_id], true);
$locks->set('image', $venue_id, '场地图片审核通过', $reviewer_uid);
logMessage("✅ 审核提交: venue_id=$venue_id reviewer_uid=$reviewer_uid oss_url=$oss_uploaded_url result=$result");

if ($result > 0) {
    // ✅ 审核通过 → 上 10 天锁
    

    echo json_encode(['code' => 0, 'msg' => '图片已审核通过并锁定10天']);
    // echo json_encode(['code' => 0, 'msg' => '图片已审核通过']);
} else {
    echo json_encode(['code' => 1004, 'msg' => '审核失败或数据未变']);
}
?>
