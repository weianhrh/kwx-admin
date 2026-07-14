<?php
// api/operat/send_zego_custom_msg.php
require_once __DIR__ . '/_bootstrap.php';

date_default_timezone_set('Asia/Shanghai');

// ==========================
// ZEGO 配置
// ==========================
define('ZEGO_APP_ID', '1847604878');

// 建议放环境变量；这里填你自己 ZEGO ServerSecret
define('ZEGO_SERVER_SECRET', getenv('ZEGO_SERVER_SECRET') ?: '70e538efe46bc3450b9ba7759b47f936');

define('ZEGO_RTC_API_URL', 'https://rtc-api.zego.im/');
define('DEFAULT_FROM_USER_ID', 'server_bot_1');

// open 内部调用密钥，不要给前端
define('OPEN_INTERNAL_KEY', 'open_send_zego_internal_20260529_xxxxxxxx');

/**
 * 生成 ZEGO 签名
 */
function generateZegoSignature($appId, $signatureNonce, $serverSecret, $timestamp) {
    return md5($appId . $signatureNonce . $serverSecret . $timestamp);
}

/**
 * 发送 ZEGO 房间自定义信令
 */
function sendZegoRoomCustomCommand($roomId, $messageContent) {
    if ($roomId === '') {
        return [
            'ok' => false,
            'msg' => 'room_id为空，未发送ZEGO信令',
            'raw' => null
        ];
    }

    $signatureNonce = bin2hex(random_bytes(8));
    $timestamp = time();

    $signature = generateZegoSignature(
        ZEGO_APP_ID,
        $signatureNonce,
        ZEGO_SERVER_SECRET,
        $timestamp
    );

    $query = [
        'Action' => 'SendCustomCommand',
        'AppId' => ZEGO_APP_ID,
        'SignatureNonce' => $signatureNonce,
        'Timestamp' => $timestamp,
        'Signature' => $signature,
        'SignatureVersion' => '2.0',
        'IsTest' => 'false',
        'RoomId' => $roomId,
        'FromUserId' => DEFAULT_FROM_USER_ID,
        'MessageContent' => $messageContent
    ];

    $url = ZEGO_RTC_API_URL . '?' . http_build_query($query);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'msg' => 'ZEGO请求失败：' . $curlErr,
            'raw' => null
        ];
    }

    $decoded = json_decode($response, true);

    if ($httpCode === 200 && is_array($decoded) && intval($decoded['Code'] ?? -1) === 0) {
        return [
            'ok' => true,
            'msg' => 'ZEGO信令发送成功',
            'raw' => $decoded
        ];
    }

    return [
        'ok' => false,
        'msg' => 'ZEGO信令发送失败',
        'raw' => $decoded ?: $response
    ];
}

try {
    $db = new Database();
    $req = input_array();

    // 只允许 open 后端内部调用
    $internalKey = trim($req['internal_key'] ?? '');
    if (!hash_equals(OPEN_INTERNAL_KEY, $internalKey)) {
        json_err('无权调用ZEGO发送接口', 1003);
    }

    $target_uid = intval($req['target_uid'] ?? 0);
    $venue_id   = intval($req['venue_id'] ?? 0);

    $banReason = trim($req['ban_reason'] ?? '您已被主播移出房间');
    $banReason = str_replace(["#", "\r", "\n"], ' ', $banReason);

    if ($target_uid <= 0) {
        json_err('参数错误：target_uid不能为空', 1002);
    }

    if ($venue_id <= 0) {
        json_err('参数错误：venue_id不能为空', 1002);
    }

    // 按你的要求：room_id = select venue_room_id from venues where id = user['venue_id']
    $rows = $db->query(
        "SELECT venue_room_id FROM venues WHERE id = ? LIMIT 1",
        [$venue_id],
        false
    );

    $room_id = '';
    if ($rows && isset($rows[0]['venue_room_id'])) {
        $room_id = trim((string)$rows[0]['venue_room_id']);
    }

    if ($room_id === '') {
        json_err('当前场地未配置 venue_room_id，无法发送移除语音房指令', 1004);
    }

    // APP 端收到后判断 UID 是否等于自己
    $messageContent = 'VOICE_ROOM_USER_BAN#1#' . $target_uid . '#' . $banReason;

    $zegoResult = sendZegoRoomCustomCommand($room_id, $messageContent);

    if ($zegoResult['ok']) {
        json_ok([
            'room_id' => $room_id,
            'target_uid' => $target_uid,
            'message_content' => $messageContent,
            'zego_raw' => $zegoResult['raw']
        ], 'ZEGO移除语音房指令发送成功');
    }

    json_err('ZEGO移除语音房指令发送失败：' . $zegoResult['msg'], 2001, [
        'room_id' => $room_id,
        'target_uid' => $target_uid,
        'message_content' => $messageContent,
        'zego_raw' => $zegoResult['raw']
    ]);

} catch (Throwable $e) {
    json_err('ZEGO移除语音房指令发送异常：' . $e->getMessage(), 2002);
}