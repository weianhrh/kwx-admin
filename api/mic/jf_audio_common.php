<?php
// /api/mic/jf_audio_common.php

declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

// ================== 杰峰配置：跟 set_mic.php 保持一致 ==================
const JF_ENDPOINT   = 'https://api.jftechws.com';
const JF_UUID       = '65d1a8037816ab11a812db04';
const JF_APP_KEY    = '00c41718600d1b07313a70f22e1731cc';
const JF_APP_SECRET = '41ce348d9daa430ba25e5030933896f5';

const JF_MOVED_CARD = 5;

// 你自己接口的 Bearer 校验。为空表示不校验。
const LOCAL_BEARER_TOKEN = '';

// 日志
const DEBUG_LOG_ENABLE = true;
const DEBUG_LOG_FILE   = __DIR__ . '/jf_audio_get.log';
const JF_COUNTER_FILE  = __DIR__ . '/jf_time_counter.txt';

const CURL_SSL_VERIFY = true;

// ================== 通用输出 ==================
function jsonOut(array $arr, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function maskString(string $value, int $left = 6, int $right = 4): string
{
    $len = strlen($value);
    if ($len <= $left + $right) {
        return '***';
    }
    return substr($value, 0, $left) . '...' . substr($value, -$right);
}

function sanitizeForLog($data)
{
    if (is_array($data)) {
        $clean = [];
        foreach ($data as $k => $v) {
            $key = strtolower((string)$k);
            if (
                strpos($key, 'password') !== false ||
                strpos($key, 'passwd') !== false ||
                strpos($key, 'token') !== false ||
                strpos($key, 'authorization') !== false ||
                strpos($key, 'signature') !== false ||
                strpos($key, 'secret') !== false
            ) {
                $clean[$k] = is_string($v) ? maskString($v) : '***';
            } else {
                $clean[$k] = sanitizeForLog($v);
            }
        }
        return $clean;
    }

    return $data;
}

function debugLog(string $title, array $data = []): void
{
    if (!DEBUG_LOG_ENABLE) {
        return;
    }

    $line = "==============================\n";
    $line .= '[' . date('Y-m-d H:i:s') . '] ' . $title . "\n";
    $line .= json_encode(
        sanitizeForLog($data),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ) . "\n";

    @file_put_contents(DEBUG_LOG_FILE, $line, FILE_APPEND);
}

// ================== 读取 JSON / POST 参数 ==================
function readRequestParams(array &$debug): array
{
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false) {
        $rawBody = '';
    }

    $jsonBody = null;
    $jsonError = 'No JSON parsed';

    if ($rawBody !== '') {
        $jsonBody = json_decode($rawBody, true);
        $jsonError = json_last_error_msg();
    }

    $debug = [
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
        'content_length' => $_SERVER['CONTENT_LENGTH'] ?? '',
        'raw_body_len' => strlen($rawBody),
        'post_count' => count($_POST),
        'json_error' => $jsonError,
    ];

    if (is_array($jsonBody)) {
        return array_merge($_POST, $jsonBody);
    }

    return $_POST;
}

function firstParam(array $params, array $keys, $default = null)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $params)) {
            return $params[$key];
        }
    }
    return $default;
}

function parseStringParam(array $params, array $keys, string $default = ''): string
{
    $value = firstParam($params, $keys, $default);

    if (is_array($value) || is_object($value)) {
        return $default;
    }

    return trim((string)$value);
}

function getAuthorizationHeader(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return (string)$_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                return (string)$value;
            }
        }
    }

    return '';
}

function checkLocalBearer(): void
{
    if (LOCAL_BEARER_TOKEN === '') {
        return;
    }

    $auth = getAuthorizationHeader();

    if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        jsonOut([
            'code' => 401,
            'msg' => '缺少 Authorization Bearer Token',
        ], 401);
    }

    $token = trim($m[1]);

    if (!hash_equals(LOCAL_BEARER_TOKEN, $token)) {
        jsonOut([
            'code' => 401,
            'msg' => 'Authorization Token 无效',
        ], 401);
    }
}

// ================== 杰峰签名 ==================
function nextCounterPrefix(): string
{
    $fp = @fopen(JF_COUNTER_FILE, 'c+');

    if (!$fp) {
        return str_pad((string)random_int(1, 9999999), 7, '0', STR_PAD_LEFT);
    }

    try {
        @flock($fp, LOCK_EX);
        rewind($fp);

        $old = trim((string)stream_get_contents($fp));
        $counter = ctype_digit($old) ? intval($old) : 0;
        $counter = ($counter % 9999999) + 1;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string)$counter);
        fflush($fp);

        @flock($fp, LOCK_UN);
        fclose($fp);

        return str_pad((string)$counter, 7, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        @flock($fp, LOCK_UN);
        @fclose($fp);

        return str_pad((string)random_int(1, 9999999), 7, '0', STR_PAD_LEFT);
    }
}

function makeTimeMillis(): string
{
    return nextCounterPrefix() . (string)floor(microtime(true) * 1000);
}

function jfStringToBytes(string $str): array
{
    $bytes = [];
    $len = strlen($str);

    for ($i = 0; $i < $len; $i++) {
        $bytes[] = ord($str[$i]);
    }

    return $bytes;
}

function jfBytesToString(array $bytes): string
{
    $str = '';

    foreach ($bytes as $byte) {
        $str .= chr($byte);
    }

    return $str;
}

function jfChangeBytes(string $encryptStr, int $moveCard): array
{
    $bytes = jfStringToBytes($encryptStr);
    $len = count($bytes);

    for ($i = 0; $i < $len; $i++) {
        $j = $len - ($i + 1);

        if (($i % $moveCard) > (($len - $i) % $moveCard)) {
            $temp = $bytes[$i];
        } else {
            $temp = $bytes[$j];
        }

        $bytes[$i] = $bytes[$j];
        $bytes[$j] = $temp;
    }

    return $bytes;
}

function buildSignature(string $timeMillis): string
{
    $encryptStr = JF_UUID . JF_APP_KEY . JF_APP_SECRET . $timeMillis;

    $encryptBytes = jfStringToBytes($encryptStr);
    $changeBytes = jfChangeBytes($encryptStr, JF_MOVED_CARD);

    $mergeBytes = [];
    $len = count($encryptBytes);
    $mergeLen = $len * 2;

    for ($i = 0; $i < $mergeLen; $i++) {
        $mergeBytes[$i] = 0;
    }

    for ($i = 0; $i < $len; $i++) {
        $mergeBytes[$i] = $encryptBytes[$i];
        $mergeBytes[$mergeLen - 1 - $i] = $changeBytes[$i];
    }

    return md5(jfBytesToString($mergeBytes));
}

function makeRequestId(): string
{
    return bin2hex(random_bytes(16));
}

function jfHeaders(): array
{
    $timeMillis = makeTimeMillis();

    return [
        'Content-Type: application/json; charset=UTF-8',
        'Accept: application/json',
        'uuid: ' . JF_UUID,
        'appKey: ' . JF_APP_KEY,
        'timeMillis: ' . $timeMillis,
        'signature: ' . buildSignature($timeMillis),
        'X-Request-Id: ' . makeRequestId(),
        'User-Agent: rcwulian-jf-audio-get/1.0',
    ];
}

// ================== 杰峰请求 ==================
function tokenToPath(string $deviceToken): string
{
    return rawurlencode(rawurldecode($deviceToken));
}

function jfPostJson(string $path, array $body, int $timeout = 15): array
{
    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'http_code' => 0,
            'error' => 'PHP 未安装 curl 扩展',
        ];
    }

    $url = rtrim(JF_ENDPOINT, '/') . $path;
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        return [
            'ok' => false,
            'http_code' => 0,
            'error' => '请求 JSON 编码失败',
        ];
    }

    $headers = jfHeaders();

    debugLog('JF_REQUEST', [
        'url' => $url,
        'headers' => $headers,
        'body' => $body,
    ]);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => CURL_SSL_VERIFY,
        CURLOPT_SSL_VERIFYHOST => CURL_SSL_VERIFY ? 2 : 0,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));

    curl_close($ch);

    if ($errno) {
        $res = [
            'ok' => false,
            'http_code' => $httpCode,
            'error' => 'curl_error_' . $errno . ': ' . $error,
        ];

        debugLog('JF_RESPONSE_CURL_ERROR', $res);

        return $res;
    }

    $json = json_decode((string)$raw, true);

    if (!is_array($json)) {
        $res = [
            'ok' => false,
            'http_code' => $httpCode,
            'error' => '杰峰返回不是合法 JSON',
            'raw' => (string)$raw,
        ];

        debugLog('JF_RESPONSE_INVALID_JSON', $res);

        return $res;
    }

    $res = [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'json' => $json,
    ];

    debugLog('JF_RESPONSE', $res);

    return $res;
}

function jfOpenApiOk(array $res): bool
{
    return ($res['ok'] ?? false) === true
        && intval($res['json']['code'] ?? 0) === 2000;
}

function jfDeviceRetOk(array $res): bool
{
    return jfOpenApiOk($res)
        && intval($res['json']['data']['Ret'] ?? 0) === 100;
}

function slimResponse(?array $res): ?array
{
    if ($res === null) {
        return null;
    }

    return sanitizeForLog([
        'ok' => $res['ok'] ?? false,
        'http_code' => $res['http_code'] ?? 0,
        'json' => $res['json'] ?? null,
        'error' => $res['error'] ?? null,
        'raw' => $res['raw'] ?? null,
    ]);
}

// ================== 杰峰业务函数 ==================
function getDeviceToken(string $sn, string $accessToken = ''): array
{
    $res = jfPostJson('/gwp/v3/rtc/device/token', [
        'sns' => [$sn],
        'accessToken' => $accessToken,
    ]);

    if (!jfOpenApiOk($res)) {
        return [
            'ok' => false,
            'msg' => '获取 deviceToken 失败',
            'response' => slimResponse($res),
        ];
    }

    $list = $res['json']['data'] ?? [];

    if (!is_array($list) || count($list) === 0) {
        return [
            'ok' => false,
            'msg' => '杰峰没有返回该设备 token，请确认设备已绑定、SN 正确、开放平台账号有权限',
            'response' => slimResponse($res),
        ];
    }

    foreach ($list as $row) {
        if (($row['sn'] ?? '') === $sn && !empty($row['token'])) {
            return [
                'ok' => true,
                'token' => (string)$row['token'],
                'response' => slimResponse($res),
            ];
        }
    }

    return [
        'ok' => false,
        'msg' => '杰峰返回了数据，但没有匹配当前 SN 的 token',
        'response' => slimResponse($res),
    ];
}

function loginDevice(
    string $deviceToken,
    string $userName,
    string $passWord,
    string $passPrefix,
    int $keepaliveTime
): array {
    $body = [
        'UserName' => $userName,
        'KeepaliveTime' => $keepaliveTime,
    ];

    if ($passWord !== '') {
        $body['PassWord'] = $passWord;
    }

    if ($passPrefix !== '') {
        $body['PassPrefix'] = $passPrefix;
    }

    return jfPostJson('/gwp/v3/rtc/device/login/' . tokenToPath($deviceToken), $body);
}

function getSpeakerVolumeConfig(string $deviceToken, string $channel = ''): array
{
    $body = [
        'Name' => 'fVideo.Volume',
    ];

    if ($channel !== '') {
        $body['Channel'] = $channel;
    }

    return jfPostJson('/gwp/v3/rtc/device/getconfig/' . tokenToPath($deviceToken), $body);
}

function getMicVolumeConfig(string $deviceToken, string $channel = ''): array
{
    $body = [
        'Name' => 'fVideo.VolumeIn',
    ];

    if ($channel !== '') {
        $body['Channel'] = $channel;
    }

    return jfPostJson('/gwp/v3/rtc/device/getconfig/' . tokenToPath($deviceToken), $body);
}