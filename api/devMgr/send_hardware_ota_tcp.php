<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

header('Content-Type: application/json; charset=utf-8');

/**
 * 硬件 OTA TCP 下发
 * 连接 rcwulian.cn:6363
 * 发送 +UP#https://rcwulian.cn/OTA/zxb.bin-
 */

$host = 'rcwulian.cn';
$port = 6363;
$timeout = 5;

function json_out($ok, $msg, $data = []) {
    echo json_encode([
        'ok'   => $ok,
        'msg'  => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_ota_cmd($rawCmd, $url) {
    $rawCmd = trim((string)$rawCmd);
    $url = trim((string)$url);

    if ($rawCmd !== '') {
        $cmd = $rawCmd;
    } else {
        if ($url === '') {
            $url = 'https://rcwulian.cn/OTA/zxb.bin';
        }

        if (!preg_match('#^https?://#i', $url)) {
            json_out(false, 'OTA 地址必须以 http:// 或 https:// 开头');
        }

        if (preg_match('/[\r\n\s]/', $url)) {
            json_out(false, 'OTA 地址不能包含空格或换行');
        }

        $cmd = '+UP#' . $url . '-';
    }

    if (strpos($cmd, '+UP#') !== 0) {
        json_out(false, '指令格式错误，必须以 +UP# 开头');
    }

    if (substr($cmd, -1) !== '-') {
        $cmd .= '-';
    }

    return $cmd;
}

$rawCmd = $_REQUEST['cmd'] ?? '';
$url = $_REQUEST['url'] ?? '';

$cmd = normalize_ota_cmd($rawCmd, $url);

$errno = 0;
$errstr = '';

$fp = @stream_socket_client(
    "tcp://{$host}:{$port}",
    $errno,
    $errstr,
    $timeout
);

if (!$fp) {
    json_out(false, 'TCP连接失败', [
        'host'  => $host,
        'port'  => $port,
        'error' => $errstr,
        'errno' => $errno,
        'cmd'   => $cmd,
    ]);
}

stream_set_timeout($fp, $timeout);

$writeResult = fwrite($fp, $cmd);

if ($writeResult === false) {
    fclose($fp);
    json_out(false, 'TCP发送失败', [
        'host' => $host,
        'port' => $port,
        'cmd'  => $cmd,
    ]);
}

$response = '';
$start = time();

while (!feof($fp)) {
    $chunk = fread($fp, 1024);

    if ($chunk === false) {
        break;
    }

    if ($chunk !== '') {
        $response .= $chunk;
        break;
    }

    $meta = stream_get_meta_data($fp);
    if (!empty($meta['timed_out'])) {
        break;
    }

    if ((time() - $start) >= $timeout) {
        break;
    }

    usleep(100000);
}

fclose($fp);

json_out(true, '硬件 OTA 指令已发送', [
    'host'     => $host,
    'port'     => $port,
    'cmd'      => $cmd,
    'bytes'    => $writeResult,
    'response' => $response,
]);