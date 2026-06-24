<?php
header('Content-Type: application/json; charset=utf-8');

// 如需跨域访问，再打开下面这两行
// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'ok' => false,
        'msg' => '仅支持 POST 请求'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 你的友盟配置 ======
$androidAppKey = '66793976940d5a4c497676c0';
$androidMasterSecret = 'atze1kb29qg2h1a7kawgo7dvv45lflzh';

$iosAppKey = '682ae54b79267e021067de3f';
$iosMasterSecret = 'hpud66vz6bhtj6q8amttynicsswln18u';

// ====== 一个简单口令，避免任何人都能直接调用群发 ======
$serverPushToken = 'rc_admin_push_2026';

// ====== 读取前端提交 ======
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode([
        'ok' => false,
        'msg' => '请求体不是有效 JSON'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$clientPushToken = isset($data['push_token']) ? trim((string)$data['push_token']) : '';
if ($serverPushToken === '12345678' || $clientPushToken !== $serverPushToken) {
    echo json_encode([
        'ok' => false,
        'msg' => '未授权调用'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$platform = isset($data['platform']) ? strtolower(trim((string)$data['platform'])) : 'android';
$title = isset($data['title']) ? trim((string)$data['title']) : '';
$text = isset($data['text']) ? trim((string)$data['text']) : '';

// 兼容你 HTML 里原来的 body 字段
if ($text === '' && isset($data['body'])) {
    $text = trim((string)$data['body']);
}

if ($title === '' || $text === '') {
    echo json_encode([
        'ok' => false,
        'msg' => '标题或内容不能为空'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$productionMode = isset($data['production_mode'])
    ? ((string)$data['production_mode'] === 'true' ? 'true' : 'false')
    : 'false';

// ====== 根据平台取 key ======
if ($platform === 'ios') {
    $appkey = $iosAppKey;
    $masterSecret = $iosMasterSecret;
} else {
    $platform = 'android';
    $appkey = $androidAppKey;
    $masterSecret = $androidMasterSecret;
}

$timestamp = (string) time();
$path = '/api/send';
$url = 'https://msgapi.umeng.com' . $path;

// ====== 组装 payload ======
if ($platform === 'android') {
    $body = [
        'appkey' => $appkey,
        'timestamp' => $timestamp,
        'type' => 'broadcast',
        'payload' => [
            'display_type' => 'notification',
            'body' => [
                'ticker' => $title,
                'title' => $title,
                'text' => $text,
                'after_open' => 'go_app'
            ],
            'extra' => [
                'source' => 'php_admin_page'
            ]
        ],
        'production_mode' => $productionMode,
        'description' => 'HTML后台群发-Android'
    ];
} else {
    $body = [
        'appkey' => $appkey,
        'timestamp' => $timestamp,
        'type' => 'broadcast',
        'payload' => [
            'aps' => [
                'alert' => [
                    'title' => $title,
                    'body' => $text
                ],
                'sound' => 'default'
            ],
            'extra' => [
                'source' => 'php_admin_page'
            ]
        ],
        'production_mode' => $productionMode,
        'description' => 'HTML后台群发-iOS'
    ];
}

$postBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($postBody === false) {
    echo json_encode([
        'ok' => false,
        'msg' => '请求体 JSON 编码失败'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 计算 sign ======
$sign = md5('POST' . $url . $postBody . $masterSecret);
$requestUrl = $url . '?sign=' . $sign;

// ====== 发起 curl 请求 ======
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $requestUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json; charset=utf-8'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

$response = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno) {
    echo json_encode([
        'ok' => false,
        'msg' => 'curl请求失败',
        'errno' => $errno,
        'error' => $error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$umengJson = json_decode($response, true);

$ok = ($httpCode >= 200 && $httpCode < 300);
if (is_array($umengJson) && isset($umengJson['ret'])) {
    $ok = ($umengJson['ret'] === 'SUCCESS');
}

echo json_encode([
    'ok' => $ok,
    'msg' => $ok ? '发送请求已提交' : '发送失败',
    'http_code' => $httpCode,
    'platform' => $platform,
    'production_mode' => $productionMode,
    'umeng_response_raw' => $response,
    'umeng_response_json' => $umengJson
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
