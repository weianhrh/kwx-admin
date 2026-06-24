<?php
// /api/devMgr/upload_violation_image.php
header('Content-Type: application/json; charset=utf-8');

function json_out($code, $msg, $data = []) {
    echo json_encode(['code'=>$code, 'msg'=>$msg, 'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}

// ✅ 你现在对外访问是：/api/devMgr/uploads/...
// ✅ 但真实磁盘路径在：/single/api/devMgr/uploads/...
// __DIR__ 就是 /single/api/devMgr 这个目录，所以 OK
$baseDir = __DIR__ . '/uploads';

// ====== 允许类型：图片 + mp4 ======
$allowImageExt = ['jpg','jpeg','png','webp'];
$allowVideoExt = ['mp4'];

// 大小限制：图片 5MB，视频 50MB（你可调）
$maxImageBytes = 5 * 1024 * 1024;
$maxVideoBytes = 50 * 1024 * 1024;

if (!isset($_FILES['file'])) json_out(1, '缺少文件字段 file');
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) json_out(1, '上传失败 error=' . $f['error']);

$size = (int)$f['size'];
if ($size <= 0) json_out(1, '空文件');

// 扩展名（只作为辅助判断）
$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));

$isImage = in_array($ext, $allowImageExt, true);
$isVideo = in_array($ext, $allowVideoExt, true);

if (!$isImage && !$isVideo) {
    json_out(1, '不支持的文件类型，只允许 jpg/png/webp/mp4');
}

// ====== 校验图片 / 视频 ======
$finalExt = null;
$mime = null;

if ($isImage) {
    if ($size > $maxImageBytes) json_out(1, '图片过大，最大 5MB');

    $info = @getimagesize($f['tmp_name']);
    if ($info === false || empty($info['mime'])) {
        json_out(1, '文件不是有效图片');
    }
    $mime = $info['mime'];

    $mimeMap = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($mimeMap[$mime])) {
        json_out(1, '不支持的图片类型: ' . $mime);
    }
    $finalExt = $mimeMap[$mime];
}

if ($isVideo) {
    if ($size > $maxVideoBytes) json_out(1, '视频过大，最大 50MB');

    // 简单“魔数”检查：MP4/ISO BMFF 通常在前 16 字节附近包含 "ftyp"
    $fh = fopen($f['tmp_name'], 'rb');
    if (!$fh) json_out(1, '无法读取上传文件');
    $head = fread($fh, 64);
    fclose($fh);

    if ($head === false || strpos($head, 'ftyp') === false) {
        json_out(1, '视频格式校验失败（非标准 mp4）');
    }

    $mime = 'video/mp4';
    $finalExt = 'mp4';
}

// ====== ✅ 关键改动：按日期分目录 + 文件名带 ymd ======
$ymd = date('Ymd');
$hms = date('His');

// 当天目录：uploads/20260206/
$dayDir = $baseDir . '/' . $ymd;
if (!is_dir($dayDir)) {
    @mkdir($dayDir, 0755, true);
}
if (!is_dir($dayDir) || !is_writable($dayDir)) {
    json_out(1, '上传目录不可写: ' . $dayDir);
}

// 文件名：20260206_094549_xxxxxx.mp4/jpg
$rand = bin2hex(random_bytes(4)); // 8位，够用了
$filename = $ymd . '_' . $hms . '_' . $rand . '.' . $finalExt;
$dest = $dayDir . '/' . $filename;

if (!move_uploaded_file($f['tmp_name'], $dest)) {
    json_out(1, '保存文件失败');
}

// ✅ 返回 URL 也带日期目录
$url = 'https://open.rcwulian.cn/api/devMgr/uploads/' . $ymd . '/' . $filename;

json_out(0, '成功', [
    'image_url' => $url,     // 兼容你现有字段名
    'ymd'       => $ymd,
    'filename'  => $filename,
    'mime'      => $mime,
    'size'      => $size,
]);
