<?php
// /api/venue/upload_pending_image.php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

const INTERNAL_UPLOAD_SECRET = 'kwx_pending_image_upload_20260630';

function ret($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$secret = $_SERVER['HTTP_X_UPLOAD_SECRET'] ?? '';
if (!hash_equals(INTERNAL_UPLOAD_SECRET, $secret)) {
    ret(401, '非法上传请求');
}

$database = new Database();

$venue_id = $_GET['venue_id'] ?? null;
if (!$venue_id || !is_numeric($venue_id)) {
    ret(1002, '缺少场地ID');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['image'])) {
    ret(1001, '请选择要上传的图片');
}

$file = $_FILES['image'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    ret(1003, '图片上传失败');
}

$maxSize = 2 * 1024 * 1024;
if (($file['size'] ?? 0) > $maxSize) {
    ret(1004, '图片大小不能超过2MB');
}

$info = @getimagesize($file['tmp_name']);
$mime = $info['mime'] ?? null;
$mimeToExt = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

if (!$mime || !isset($mimeToExt[$mime])) {
    ret(1006, '仅支持 JPG/PNG/WEBP 格式图片');
}

if (!$info) {
    ret(1007, '上传文件不是有效的图片');
}

$upload_dir = __DIR__ . '/pending_images/';
if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
    ret(1005, '上传目录创建失败');
}

if (!is_writable($upload_dir)) {
    ret(1005, '上传目录不可写');
}

$filename = 'venue_' . intval($venue_id) . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $mimeToExt[$mime];
$target_path = $upload_dir . $filename;
$public_path = 'pending_images/' . $filename;
$uploaded_at = date('Y-m-d H:i:s');

if (!move_uploaded_file($file['tmp_name'], $target_path)) {
    ret(1005, '文件保存失败');
}

$insert_sql = "INSERT INTO venue_image_reviews (venue_id, image_url, status, uploaded_at)
               VALUES (?, ?, 'pending', ?)";
$inserted = $database->query($insert_sql, [$venue_id, $public_path, $uploaded_at], true);
$database->close();

if ($inserted === false) {
    @unlink($target_path);
    ret(1009, '图片审核记录保存失败');
}

ret(0, '图片上传成功，等待审核', [
    'image_url' => $public_path,
    'full_url'  => 'https://open.kwxapp.cn/api/venue/' . $public_path,
]);
?>
