<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$url = $_GET['url'] ?? '';
if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['code' => 400, 'msg' => '无效 URL']);
    exit;
}

$options = [
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: Mozilla/5.0\r\n"
    ]
];
$context = stream_context_create($options);
$imageData = @file_get_contents($url, false, $context);

if ($imageData === false) {
    echo json_encode(['code' => 500, 'msg' => '无法读取图片']);
    exit;
}

// 🧪 临时 MIME 推测，只支持 jpeg/png/gif
$prefix = substr($imageData, 0, 4);
if (strpos($imageData, "\xFF\xD8") === 0) {
    $mime = 'image/jpeg';
} elseif (strpos($imageData, "\x89PNG") === 0) {
    $mime = 'image/png';
} elseif (strpos($imageData, "GIF8") === 0) {
    $mime = 'image/gif';
} else {
    $mime = 'application/octet-stream';
}

$base64 = 'data:' . $mime . ';base64,' . base64_encode($imageData);

echo json_encode(['code' => 0, 'data' => $base64]);
