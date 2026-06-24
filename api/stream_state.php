<?php
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
$appId = 141962251;
$serverSecret = '5bfaa3399946c98cc6792dd19f9a08ec';
$isTest = false; // 测试环境改 true

function json_out($arr, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

$streamId = trim($_GET['stream_id'] ?? '');
if ($streamId === '') {
    json_out(["code" => 400, "message" => "missing stream_id"], 400);
}

if ($isTest) {
    $prefix = "zegotest-{$appId}-";
    if (strpos($streamId, $prefix) !== 0) {
        $streamId = $prefix . $streamId;
    }
}

$timestamp = time();
$signatureNonce = bin2hex(random_bytes(8));
$sequence = (string)round(microtime(true) * 1000);
$signature = md5($appId . $signatureNonce . $serverSecret . $timestamp);

$params = [
    "Action" => "DescribeRTCStreamState",
    "AppId" => $appId,
    "SignatureNonce" => $signatureNonce,
    "Timestamp" => $timestamp,
    "Signature" => $signature,
    "SignatureVersion" => "2.0",
    "StreamId" => $streamId,
    "Sequence" => $sequence,
];

// ✅ 如果是测试环境，建议把 IsTest 也传过去
if ($isTest) {
    $params["IsTest"] = 1;
}

$url = "https://rtc-api.zego.im/?" . http_build_query($params);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 8);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$resp = curl_exec($ch);
$err  = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resp === false) {
    json_out(["code" => 500, "message" => "curl error: " . $err], 500);
}

$zego = json_decode($resp, true);
if (!is_array($zego)) {
    json_out(["code" => 500, "message" => "invalid zego response", "raw" => $resp], 500);
}

$zegoCode = $zego["Code"] ?? -1;
$zegoMsg  = $zego["Message"] ?? "unknown";
$data     = $zego["Data"] ?? (object)[];

$active = ($zegoCode === 0);

json_out([
    "code" => 200,
    "active" => $active,
    "zego_code" => $zegoCode,
    "message" => $active ? "stream active" : $zegoMsg,
    "stream_id" => $streamId,
    "data" => $data,
    "zego_http" => $http, // ✅ 方便排查
]);
