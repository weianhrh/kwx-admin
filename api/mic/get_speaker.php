<?php
// /api/mic/get_speaker.php
// 获取设备喇叭输出音量配置 fVideo.Volume

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/jf_audio_common.php';

try {
    checkLocalBearer();

    $requestDebug = [];
    $params = readRequestParams($requestDebug);

    debugLog('INCOMING_GET_SPEAKER_REQUEST', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
        'authorization' => getAuthorizationHeader(),
        'debug' => $requestDebug,
        'params' => $params,
    ]);

    $sn = parseStringParam($params, ['sn', 'serial_number']);
    if ($sn === '') {
        jsonOut([
            'code' => 400,
            'msg' => '缺少设备序列号参数 sn 或 serial_number',
            'debug' => $requestDebug,
        ], 400);
    }

    $deviceUserName = parseStringParam($params, ['device_username', 'username', 'UserName'], 'admin');
    if ($deviceUserName === '') {
        $deviceUserName = 'admin';
    }

    $devicePassword = parseStringParam($params, ['device_password', 'password', 'PassWord'], '');
    $passPrefix = parseStringParam($params, ['pass_prefix', 'PassPrefix'], '');
    $accessToken = parseStringParam($params, ['access_token', 'accessToken'], '');
    $channel = parseStringParam($params, ['channel', 'Channel'], '');

    $keepaliveTimeRaw = firstParam($params, ['keepalive_time', 'KeepaliveTime'], 3600);
    $keepaliveTime = is_numeric($keepaliveTimeRaw) ? intval($keepaliveTimeRaw) : 3600;

    if ($keepaliveTime <= 0) {
        $keepaliveTime = 3600;
    }

    if ($keepaliveTime > 86400) {
        $keepaliveTime = 86400;
    }

    // 1. 获取 deviceToken
    $tokenRes = getDeviceToken($sn, $accessToken);
    if (($tokenRes['ok'] ?? false) !== true) {
        jsonOut([
            'code' => 502,
            'msg' => $tokenRes['msg'] ?? '获取 deviceToken 失败',
            'step' => 'get_device_token',
            'response' => $tokenRes['response'] ?? null,
        ], 502);
    }

    $deviceToken = (string)$tokenRes['token'];

    // 2. 登录设备
    $loginRes = loginDevice($deviceToken, $deviceUserName, $devicePassword, $passPrefix, $keepaliveTime);
    if (!jfDeviceRetOk($loginRes)) {
        jsonOut([
            'code' => 502,
            'msg' => '设备登录失败',
            'step' => 'device_login',
            'sn' => $sn,
            'device_token' => maskString($deviceToken),
            'response' => slimResponse($loginRes),
        ], 502);
    }

    // 3. 获取喇叭输出音量
    $configRes = getSpeakerVolumeConfig($deviceToken, $channel);
    if (!jfDeviceRetOk($configRes)) {
        jsonOut([
            'code' => 502,
            'msg' => '获取喇叭输出音量失败',
            'step' => 'get_speaker_volume',
            'sn' => $sn,
            'response' => slimResponse($configRes),
        ], 502);
    }

    $config = $configRes['json']['data']['fVideo.Volume'] ?? null;

    jsonOut([
        'code' => 200,
        'msg' => '获取喇叭输出音量成功',
        'data' => [
            'sn' => $sn,
            'channel' => $channel,
            'device_token' => maskString($deviceToken),
            'volume_config' => $config,
            'login' => slimResponse($loginRes),
            'speaker' => slimResponse($configRes),
        ],
    ]);

} catch (Throwable $e) {
    debugLog('GET_SPEAKER_EXCEPTION', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    jsonOut([
        'code' => 500,
        'msg' => '服务器异常：' . $e->getMessage(),
    ], 500);
}