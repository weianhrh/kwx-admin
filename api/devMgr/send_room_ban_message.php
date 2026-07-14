<?php
/**
 * https://open.kwxapp.cn/api/devMgr/send_room_ban_message.php
 *
 * 接收：
 * room_id       必填
 * ban_reason    可选，默认：违规封禁
 * from_user_id  可选，默认：server_bot_1
 *
 * 最终发送 ZEGO 自定义信令：
 * MessageContent = ROOM_BAN#封禁原因
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

// ==========================
// 配置区
// ==========================
// $appId = 1847604878;
// $serverSecret = '70e538efe46bc3450b9ba7759b47f936';
// 你的 ZEGO AppId
define('ZEGO_APP_ID', '1847604878');

// 你的 ZEGO ServerSecret
// 建议正式环境改成 getenv('ZEGO_SERVER_SECRET')
define('ZEGO_SERVER_SECRET', '70e538efe46bc3450b9ba7759b47f936');

// ZEGO RTC Server API
define('ZEGO_RTC_API_URL', 'https://rtc-api.zego.im/');

// 默认服务器机器人账号
define('DEFAULT_FROM_USER_ID', 'server_bot_1');

// 可选：内部调用密钥，防止外部乱调
// 如果不想校验，保持空字符串即可
define('INTERNAL_API_KEY', '');

// ==========================
// 输出函数
// ==========================
function jsonOut(int $code, string $msg, array $data = []): void
{
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
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

    // 不传 ToUserId，表示广播给房间内所有用户
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

// ==========================
// 生成随机 nonce
// ==========================
function generateSignatureNonceHex16(): string
{
    return bin2hex(random_bytes(8));
}

// ==========================
// ZEGO v2 签名
// Signature = md5(AppId + SignatureNonce + ServerSecret + Timestamp)
// ==========================
function generateSignatureMd5($appId, string $nonce, string $serverSecret, int $timestamp): string
{
    return md5((string)$appId . $nonce . $serverSecret . (string)$timestamp);
}

// ==========================
// 主逻辑
// ==========================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(405, '只支持POST');
}

$input = getInputData();
checkInternalKey($input);

// if (ZEGO_SERVER_SECRET === '' || ZEGO_SERVER_SECRET === '5bfaa3399946c98cc6792dd19f9a08ec') {
//     jsonOut(500, 'ZEGO_SERVER_SECRET未配置');
// }

$roomId = trim((string)($input['room_id'] ?? ''));

// 兼容 from_user_id，不传就默认 server_bot_1
$fromUserId = trim((string)($input['from_user_id'] ?? DEFAULT_FROM_USER_ID));
if ($fromUserId === '') {
    $fromUserId = DEFAULT_FROM_USER_ID;
}

$banReason = trim((string)($input['ban_reason'] ?? '违规封禁'));
if ($banReason === '') {
    $banReason = '违规封禁';
}

if ($roomId === '') {
    jsonOut(400, 'room_id不能为空');
}

// 客户端最终收到这个
$message = 'ROOM_BAN#1#' . $banReason;

if (strlen($message) > 1024) {
    jsonOut(400, '消息过长，ROOM_BAN消息最大1024字节', [
        'message_bytes' => strlen($message)
    ]);
}

$result = sendCustomCommand($roomId, $fromUserId, $message);

if (!$result['success']) {
    jsonOut(500, 'ROOM_BAN发送失败：' . $result['error'], [
        'room_id' => $roomId,
        'from_user_id' => $fromUserId,
        'message' => $message,
        'zego_result' => $result
    ]);
}

jsonOut(0, 'ROOM_BAN发送成功', [
    'room_id' => $roomId,
    'from_user_id' => $fromUserId,
    'message' => $message,
    'zego_result' => $result['zego'] ?? []
]);