<?php
/**
 * send_voice_room_ban_message.php
 *
 * 接收：
 * room_id       必填
 * ban_reason    可选，默认：直播内容存在违规，已关闭语音房功能
 * from_user_id  可选，默认：server_bot_1
 *
 * 最终发送 ZEGO 自定义信令：
 * MessageContent = VOICE_ROOM_BAN#1#原因
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

// ==========================
// 配置区
// ==========================

// 你的 ZEGO AppId
define('ZEGO_APP_ID', '1847604878');

// 你的 ZEGO ServerSecret
// 建议正式环境改成 getenv('ZEGO_SERVER_SECRET')
define('ZEGO_SERVER_SECRET', '70e538efe46bc3450b9ba7759b47f936');

define('ZEGO_RTC_API_URL', 'https://rtc-api.zego.im/');

define('DEFAULT_FROM_USER_ID', 'server_bot_1');

// 内部调用密钥，不需要就留空
define('INTERNAL_API_KEY', '');

// 日志目录：默认当前PHP文件同级 logs 目录
define('VOICE_ROOM_BAN_LOG_DIR', __DIR__ . '/logs');
// ==========================
// 输出函数
// ==========================
function jsonOut(int $code, string $msg, array $data = []): void
{
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ==========================
// 日志函数
// ==========================
function maskSensitiveLogData(array $data): array
{
    foreach ($data as $key => $value) {
        $lowerKey = strtolower((string)$key);

        // 避免敏感信息进日志
        if (in_array($lowerKey, [
            'serversecret',
            'zego_server_secret',
            'signature',
            'signaturenonce',
            'url',
            'internal_key'
        ], true)) {
            $data[$key] = '***';
            continue;
        }

        if (is_array($value)) {
            $data[$key] = maskSensitiveLogData($value);
            continue;
        }

        // 太长的字符串截断，避免日志爆掉
        if (is_string($value) && strlen($value) > 3000) {
            $data[$key] = substr($value, 0, 3000) . '...[truncated]';
        }
    }

    return $data;
}

function writeVoiceRoomBanLog(string $event, array $context = []): void
{
    $dir = VOICE_ROOM_BAN_LOG_DIR;

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $file = rtrim($dir, '/\\') . '/voice_room_ban_' . date('Ymd') . '.log';

    $log = [
        'time' => date('Y-m-d H:i:s'),
        'event' => $event,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'context' => maskSensitiveLogData($context),
    ];

    @file_put_contents(
        $file,
        json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

// ==========================
// 读取 POST JSON / Form
// ==========================
function getInputData(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode((string)$raw, true);

    if (is_array($json)) {
        return $json;
    }

    return $_POST ?: [];
}

// ==========================
// 校验内部 key，可选
// ==========================
function checkInternalKey(array $input): void
{
    if (INTERNAL_API_KEY === '') {
        return;
    }

    $headerKey = $_SERVER['HTTP_X_INTERNAL_KEY'] ?? '';
    $postKey   = $input['internal_key'] ?? '';

    if ($headerKey !== INTERNAL_API_KEY && $postKey !== INTERNAL_API_KEY) {
        jsonOut(403, 'internal_key无效');
    }
}

// ==========================
// 发送 ZEGO 自定义信令
// ==========================
function sendCustomCommand(string $roomId, string $fromUserId, string $message): array
{
    $timestamp = time();
    $nonce = generateSignatureNonceHex16();
    $signature = generateSignatureMd5(ZEGO_APP_ID, $nonce, ZEGO_SERVER_SECRET, $timestamp);

    $params = [
        'Action'           => 'SendCustomCommand',
        'AppId'            => (string)ZEGO_APP_ID,
        'SignatureNonce'   => $nonce,
        'Timestamp'        => (string)$timestamp,
        'Signature'        => $signature,
        'SignatureVersion' => '2.0',
        'RoomId'           => $roomId,
        'FromUserId'       => $fromUserId,
        'MessageContent'   => $message,
    ];

    // 不传 ToUserId，就是广播给房间内所有用户
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $url = rtrim(ZEGO_RTC_API_URL, '/') . '/?' . $query;

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'GET',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
        ],
    ]);

    $raw = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($curl);

    curl_close($curl);

    if ($curlErr) {
        return [
            'success' => false,
            'error'   => 'cURL错误：' . $curlErr,
            'http_code' => $httpCode,
            'raw' => (string)$raw
        ];
    }

    if ($httpCode !== 200) {
        return [
            'success' => false,
            'error'   => 'HTTP错误：' . $httpCode,
            'http_code' => $httpCode,
            'raw' => (string)$raw
        ];
    }

    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        return [
            'success' => false,
            'error'   => 'ZEGO响应不是合法JSON',
            'http_code' => $httpCode,
            'raw' => (string)$raw
        ];
    }

    $zegoCode = (int)($data['Code'] ?? -1);
    $zegoMsg  = (string)($data['Message'] ?? '');

    if ($zegoCode !== 0) {
        return [
            'success' => false,
            'error'   => "ZEGO返回失败 Code={$zegoCode}, Message={$zegoMsg}",
            'http_code' => $httpCode,
            'raw' => (string)$raw,
            'zego' => $data
        ];
    }

    return [
        'success' => true,
        'http_code' => $httpCode,
        'raw' => (string)$raw,
        'zego' => $data
    ];
}

function generateSignatureNonceHex16(): string
{
    return bin2hex(random_bytes(8));
}

function generateSignatureMd5($appId, string $nonce, string $serverSecret, int $timestamp): string
{
    return md5((string)$appId . $nonce . $serverSecret . (string)$timestamp);
}

// ==========================
// 主逻辑
// ==========================
$requestId = bin2hex(random_bytes(6));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    writeVoiceRoomBanLog('VOICE_ROOM_BAN_INVALID_METHOD', [
        'request_id' => $requestId,
        'error' => '只支持POST'
    ]);

    jsonOut(405, '只支持POST');
}

$input = getInputData();
checkInternalKey($input);

if (ZEGO_APP_ID === '' || ZEGO_SERVER_SECRET === '') {
    jsonOut(500, 'ZEGO配置未完成');
}

$roomId = trim((string)($input['room_id'] ?? ''));

$fromUserId = trim((string)($input['from_user_id'] ?? DEFAULT_FROM_USER_ID));
if ($fromUserId === '') {
    $fromUserId = DEFAULT_FROM_USER_ID;
}

$banReason = trim((string)($input['ban_reason'] ?? '直播内容存在违规，已关闭语音房功能'));
if ($banReason === '') {
    $banReason = '直播内容存在违规，已关闭语音房功能';
}

if ($roomId === '') {
    writeVoiceRoomBanLog('VOICE_ROOM_BAN_INVALID_ROOM_ID', [
        'request_id' => $requestId,
        'input' => $input,
        'error' => 'room_id不能为空'
    ]);

    jsonOut(400, 'room_id不能为空');
}

// 客户端最终收到这个
$message = 'VOICE_ROOM_BAN#1#' . $banReason;

if (strlen($message) > 1024) {
    writeVoiceRoomBanLog('VOICE_ROOM_BAN_MESSAGE_TOO_LONG', [
        'request_id' => $requestId,
        'room_id' => $roomId,
        'from_user_id' => $fromUserId,
        'message_bytes' => strlen($message),
        'message' => $message
    ]);

    jsonOut(400, '消息过长，VOICE_ROOM_BAN消息最大1024字节', [
        'message_bytes' => strlen($message)
    ]);
}

$result = sendCustomCommand($roomId, $fromUserId, $message);

if (!$result['success']) {
    writeVoiceRoomBanLog('VOICE_ROOM_BAN_SEND_FAIL', [
        'request_id' => $requestId,
        'room_id' => $roomId,
        'from_user_id' => $fromUserId,
        'ban_reason' => $banReason,
        'message' => $message,
        'error' => $result['error'] ?? '',
        'http_code' => $result['http_code'] ?? 0,
        'zego_result' => $result
    ]);

    jsonOut(500, 'VOICE_ROOM_BAN发送失败：' . $result['error'], [
        'room_id' => $roomId,
        'from_user_id' => $fromUserId,
        'message' => $message,
        'zego_result' => $result
    ]);
}

writeVoiceRoomBanLog('VOICE_ROOM_BAN_SEND_SUCCESS', [
    'request_id' => $requestId,
    'room_id' => $roomId,
    'from_user_id' => $fromUserId,
    'ban_reason' => $banReason,
    'message' => $message,
    'http_code' => $result['http_code'] ?? 0,
    'zego_result' => $result['zego'] ?? []
]);

// 为了兼容你原来的 send_room_ban_message.php，这里成功还是返回 code = 0
jsonOut(0, 'VOICE_ROOM_BAN发送成功', [
    'room_id' => $roomId,
    'from_user_id' => $fromUserId,
    'message' => $message,
    'zego_result' => $result['zego'] ?? []
]);