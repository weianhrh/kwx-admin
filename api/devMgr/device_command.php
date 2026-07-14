<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// ===== ZEGO 配置 =====
// 临时兼容混发摄像头：酷玩星优先，只有 Code=104（room not exist）才用 RC 物联兜底。
$zegoApps = [
    [
        'name' => '酷玩星',
        'app_id' => 1847604878,
        'server_secret' => '70e538efe46bc3450b9ba7759b47f936',
    ],
    [
        'name' => 'RC物联',
        'app_id' => 141962251,
        'server_secret' => '5bfaa3399946c98cc6792dd19f9a08ec',
    ],
];
$rtcApiUrl = 'https://rtc-api.zego.im/';

function json_out($code, $msg, $data = [], $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function generate_signature_nonce_hex16(): string {
    return bin2hex(random_bytes(8));
}

function generate_signature_md5($appId, string $nonce, string $serverSecret, int $timestamp): string {
    return md5((string)$appId . $nonce . $serverSecret . (string)$timestamp);
}

function parse_to_user_ids($raw): array {
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $raw = trim((string)$raw);
        if ($raw === '') return [];
        $parts = preg_split('/[,\s，]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    }

    $parts = array_map('trim', $parts);
    $parts = array_filter($parts, fn($v) => $v !== '');
    $parts = array_values(array_unique($parts));

    return $parts;
}

function build_query_with_to_user_ids(array $params, array $toUserIds): string {
    unset($params['ToUserId']);

    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    foreach ($toUserIds as $uid) {
        $query .= '&ToUserId%5B%5D=' . rawurlencode($uid);
    }

    return $query;
}

function send_zego_command(array $app, string $rtcApiUrl, string $roomId, string $fromUserId, string $message, array $toUserIds): array {
    $appId = (int)$app['app_id'];
    $timestamp = time();
    $signatureNonce = generate_signature_nonce_hex16();
    $signature = generate_signature_md5($appId, $signatureNonce, (string)$app['server_secret'], $timestamp);

    $params = [
        'Action'           => 'SendCustomCommand',
        'AppId'            => (string)$appId,
        'SignatureNonce'   => $signatureNonce,
        'Timestamp'        => (string)$timestamp,
        'Signature'        => $signature,
        'SignatureVersion' => '2.0',
        'RoomId'           => $roomId,
        'FromUserId'       => $fromUserId,
        'MessageContent'   => $message,
    ];

    $query = !empty($toUserIds)
        ? build_query_with_to_user_ids($params, $toUserIds)
        : http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => rtrim($rtcApiUrl, '/') . '/?' . $query,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'GET',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $base = [
        'app_name' => $app['name'],
        'app_id' => $appId,
        'http_code' => $httpCode,
        'raw' => $resp,
    ];

    if ($curlErr !== '') {
        return $base + ['request_ok' => false, 'zego_code' => -1, 'zego_message' => 'cURL错误: ' . $curlErr, 'zego_data' => []];
    }
    if ($httpCode !== 200) {
        return $base + ['request_ok' => false, 'zego_code' => -1, 'zego_message' => 'HTTP错误代码: ' . $httpCode, 'zego_data' => []];
    }

    $data = json_decode((string)$resp, true);
    if (!is_array($data)) {
        return $base + ['request_ok' => false, 'zego_code' => -1, 'zego_message' => '响应不是合法 JSON', 'zego_data' => []];
    }

    return $base + [
        'request_ok' => true,
        'zego_code' => (int)($data['Code'] ?? -1),
        'zego_message' => (string)($data['Message'] ?? ''),
        'zego_data' => $data['Data'] ?? [],
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(405, '仅支持 POST 请求');
}

$roomId = trim((string)($_POST['room_id'] ?? ''));
$fromUserId = trim((string)($_POST['from_user_id'] ?? 'server_bot_1'));
$message = (string)($_POST['message'] ?? '');
$toUserIds = parse_to_user_ids($_POST['to_user_ids'] ?? '');

if ($roomId === '') {
    json_out(400, 'room_id 不能为空');
}
if ($fromUserId === '') {
    json_out(400, 'from_user_id 不能为空');
}
if (trim($message) === '') {
    json_out(400, 'message 不能为空');
}

$msgBytes = strlen($message);
if ($msgBytes > 1024) {
    json_out(400, "消息内容过长：{$msgBytes} 字节，最大允许 1024 字节");
}

if (count($toUserIds) > 10) {
    json_out(400, 'ToUserId 最多支持 10 个');
}

$primary = send_zego_command($zegoApps[0], $rtcApiUrl, $roomId, $fromUserId, $message, $toUserIds);
$result = $primary;
$fallbackUsed = false;

// 只在酷玩星明确表示房间不存在时，临时使用 RC 物联重发。
if ($primary['request_ok'] && $primary['zego_code'] === 104) {
    $result = send_zego_command($zegoApps[1], $rtcApiUrl, $roomId, $fromUserId, $message, $toUserIds);
    $fallbackUsed = true;
}

if (!$result['request_ok']) {
    json_out(500, $result['zego_message'], $result);
}

if ($result['zego_code'] !== 0) {
    json_out(500, "ZEGO返回失败：Code={$result['zego_code']}, Message={$result['zego_message']}", $result + [
        'fallback_used' => $fallbackUsed,
        'primary_zego_code' => $primary['zego_code'],
    ]);
}

$zegoData = $result['zego_data'];
json_out(200, $result['app_name'] . '发送成功', [
    'app_name' => $result['app_name'],
    'app_id' => $result['app_id'],
    'fallback_used' => $fallbackUsed,
    'primary_zego_code' => $primary['zego_code'],
    'zego_code' => $result['zego_code'],
    'zego_message' => $result['zego_message'],
    'fail_users' => $zegoData['FailUsers'] ?? [],
    'zego_data' => $zegoData,
    'raw' => $result['raw'],
]);
