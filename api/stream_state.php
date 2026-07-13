<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$isTest = false; // 测试环境改 true

// 按顺序查询：酷玩星在线后立即返回；离线或查不到时再查询 RC 物联。
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

function json_out($arr, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function query_stream_state($streamId, $app, $isTest) {
    $appId = (int)$app['app_id'];
    $queryStreamId = $streamId;

    if ($isTest) {
        $prefix = "zegotest-{$appId}-";
        if (strpos($queryStreamId, $prefix) !== 0) {
            $queryStreamId = $prefix . $queryStreamId;
        }
    }

    $timestamp = time();
    $signatureNonce = bin2hex(random_bytes(8));
    $sequence = (string)round(microtime(true) * 1000);
    $signature = md5($appId . $signatureNonce . $app['server_secret'] . $timestamp);

    $params = [
        'Action' => 'DescribeRTCStreamState',
        'AppId' => $appId,
        'SignatureNonce' => $signatureNonce,
        'Timestamp' => $timestamp,
        'Signature' => $signature,
        'SignatureVersion' => '2.0',
        'StreamId' => $queryStreamId,
        'Sequence' => $sequence,
    ];
    if ($isTest) {
        $params['IsTest'] = 1;
    }

    $ch = curl_init('https://rtc-api.zego.im/?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return [
            'active' => false,
            'zego_code' => -1,
            'message' => 'curl error: ' . $err,
            'stream_id' => $queryStreamId,
            'data' => (object)[],
            'zego_http' => $http,
            'app_name' => $app['name'],
            'app_id' => $appId,
        ];
    }

    $zego = json_decode($resp, true);
    if (!is_array($zego)) {
        return [
            'active' => false,
            'zego_code' => -1,
            'message' => 'invalid zego response',
            'stream_id' => $queryStreamId,
            'data' => (object)[],
            'zego_http' => $http,
            'app_name' => $app['name'],
            'app_id' => $appId,
        ];
    }

    $zegoCode = (int)($zego['Code'] ?? -1);
    return [
        'active' => $zegoCode === 0,
        'zego_code' => $zegoCode,
        'message' => $zegoCode === 0 ? 'stream active' : ($zego['Message'] ?? 'unknown'),
        'stream_id' => $queryStreamId,
        'data' => $zego['Data'] ?? (object)[],
        'zego_http' => $http,
        'app_name' => $app['name'],
        'app_id' => $appId,
    ];
}

$streamId = trim($_GET['stream_id'] ?? '');
if ($streamId === '') {
    json_out(['code' => 400, 'message' => 'missing stream_id'], 400);
}

$checkedApps = [];
$lastResult = null;
foreach ($zegoApps as $app) {
    $result = query_stream_state($streamId, $app, $isTest);
    $checkedApps[] = [
        'app_name' => $result['app_name'],
        'app_id' => $result['app_id'],
        'active' => $result['active'],
        'zego_code' => $result['zego_code'],
        'message' => $result['message'],
    ];
    $lastResult = $result;

    if ($result['active']) {
        $result['code'] = 200;
        $result['checked_apps'] = $checkedApps;
        json_out($result);
    }
}

// 两个 AppID 均未查到在线流，返回最后一次（RC 物联）的查询结果。
$lastResult['code'] = 200;
$lastResult['checked_apps'] = $checkedApps;
json_out($lastResult);
