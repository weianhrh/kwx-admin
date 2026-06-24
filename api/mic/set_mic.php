
<?php
// /api/mic/set_mic.php
// 功能：配置杰峰设备喇叭输出音量 + 麦克风/咪头输入音量
// 支持 application/json 和普通表单 POST

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');
const JF_MOVED_CARD = 5;
// 按你自己的开放平台信息填写
const JF_ENDPOINT   = 'https://api.jftechws.com';
const JF_UUID       = '65d1a8037816ab11a812db04';
const JF_APP_KEY    = '00c41718600d1b07313a70f22e1731cc';
const JF_APP_SECRET = '41ce348d9daa430ba25e5030933896f5';

// 你自己接口的 Bearer 校验。为空表示不校验。
// 如果要校验，就填你自己系统的固定 token，或者改成你自己的 TokenAuth 校验逻辑。
const LOCAL_BEARER_TOKEN = '';

// 是否记录日志。上线稳定后可以改成 false。
const DEBUG_LOG_ENABLE = true;
const DEBUG_LOG_FILE   = __DIR__ . '/set_mic_request.log';
const JF_COUNTER_FILE  = __DIR__ . '/jf_time_counter.txt';

// HTTPS 证书校验。正式环境建议 true。
const CURL_SSL_VERIFY = true;

// ================== 2. 通用输出 ==================
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
    $line .= json_encode(sanitizeForLog($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";

    @file_put_contents(DEBUG_LOG_FILE, $line, FILE_APPEND);
}

// ================== 3. 读取请求参数：修复 JSON body 读不到的问题 ==================
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
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? ''),
        'raw_body_len' => strlen($rawBody),
        'post_count' => count($_POST),
        'json_error' => $jsonError,
    ];

    if (is_array($jsonBody)) {
        // JSON 优先，同时兼容 URL 表单里补参数
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

function parseVolumeParam(array $params, array $keys, string $name): ?int
{
    $exists = false;
    $value = null;

    foreach ($keys as $key) {
        if (array_key_exists($key, $params)) {
            $exists = true;
            $value = $params[$key];
            break;
        }
    }

    if (!$exists) {
        return null;
    }

    // 注意：0 是合法音量，不能用 empty() 判断
    if ($value === '' || $value === null || is_array($value) || is_object($value)) {
        throw new InvalidArgumentException($name . ' 不能为空，范围必须是 0-100');
    }

    if (!is_numeric($value)) {
        throw new InvalidArgumentException($name . ' 必须是数字，范围必须是 0-100');
    }

    $volume = intval($value);
    if ($volume < 0 || $volume > 100) {
        throw new InvalidArgumentException($name . ' 范围必须是 0-100');
    }

    return $volume;
}

function parseBoolParam(array $params, array $keys, bool $default): bool
{
    $value = firstParam($params, $keys, null);
    if ($value === null || $value === '') {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    $str = strtolower(trim((string)$value));
    return in_array($str, ['1', 'true', 'yes', 'on'], true);
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
        jsonOut(['code' => 401, 'msg' => '缺少 Authorization Bearer Token'], 401);
    }

    $token = trim($m[1]);
    if (!hash_equals(LOCAL_BEARER_TOKEN, $token)) {
        jsonOut(['code' => 401, 'msg' => 'Authorization Token 无效'], 401);
    }
}

// ================== 4. 杰峰 OpenAPI 签名和请求 ==================
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
    // 文档示例是“7位计数器 + 毫秒时间戳”的格式
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

/**
 * 杰峰签名里的简单移位算法
 */
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
function testJfSignature(): array
{
    $uuid = 'test';
    $appKey = 'test';
    $appSecret = 'password';
    $timeMillis = '00000011461748332239';

    $encryptStr = $uuid . $appKey . $appSecret . $timeMillis;

    $encryptBytes = jfStringToBytes($encryptStr);
    $changeBytes = jfChangeBytes($encryptStr, 5);

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

    $signature = md5(jfBytesToString($mergeBytes));

    return [
        'signature' => $signature,
        'expected' => '285a38b2ebf8787e42f047e0b711297b',
        'ok' => $signature === '285a38b2ebf8787e42f047e0b711297b',
    ];
}
/**
 * 杰峰 OpenAPI 签名算法
 */
function buildSignature(string $timeMillis): string
{
    $encryptStr = JF_UUID . JF_APP_KEY . JF_APP_SECRET . $timeMillis;

    // 原始字节
    $encryptBytes = jfStringToBytes($encryptStr);

    // 移位后的字节
    $changeBytes = jfChangeBytes($encryptStr, JF_MOVED_CARD);

    // 合并：前半段放原始字节，后半段倒序放移位字节
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
        'User-Agent: rcwulian-jf-mic/1.0',
    ];
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

function tokenToPath(string $deviceToken): string
{
    // 避免 token 里出现 =、/、+ 等字符导致路径错误
    return rawurlencode(rawurldecode($deviceToken));
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

// ================== 5. 杰峰业务步骤 ==================
function getDeviceToken(string $sn, string $accessToken = ''): array
{
    $body = [
        'sns' => [$sn],
        'accessToken' => $accessToken,
    ];

    $res = jfPostJson('/gwp/v3/rtc/device/token', $body);

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
            'msg' => '杰峰没有返回该设备的 token，请确认设备已绑定、SN 正确、开放平台账号有权限',
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

function setSpeakerVolume(string $deviceToken, int $volume, string $channel = ''): array
{
    $body = [
        'Name' => 'fVideo.Volume',
        'fVideo.Volume' => [
            [
                'AudioMode' => 'Single',
                'LeftVolume' => $volume,
                'RightVolume' => $volume,
            ],
        ],
    ];

    if ($channel !== '') {
        $body['Channel'] = $channel;
    }

    return jfPostJson('/gwp/v3/rtc/device/setconfig/' . tokenToPath($deviceToken), $body);
}

function setMicVolume(string $deviceToken, int $volume, bool $anrOpen = true, string $channel = ''): array
{
    $body = [
        'Name' => 'fVideo.VolumeIn',
        'fVideo.VolumeIn' => [
            [
                'AudioMode' => 'Single',
                'LeftVolume' => $volume,
                'RightVolume' => $volume,
                'AnrOpen' => $anrOpen,
            ],
        ],
    ];

    if ($channel !== '') {
        $body['Channel'] = $channel;
    }

    return jfPostJson('/gwp/v3/rtc/device/setconfig/' . tokenToPath($deviceToken), $body);
}

// ================== 6. 主入口 ==================
try {
    if (
        JF_UUID === '' || JF_UUID === '填写你的uuid' ||
        JF_APP_KEY === '' || JF_APP_KEY === '填写你的appKey' ||
        JF_APP_SECRET === '' || JF_APP_SECRET === '填写你的appSecret'
    ) {
        jsonOut([
            'code' => 500,
            'msg' => '请先在 PHP 顶部配置 JF_UUID、JF_APP_KEY、JF_APP_SECRET',
        ], 500);
    }

    checkLocalBearer();

    $requestDebug = [];
    $params = readRequestParams($requestDebug);

    debugLog('INCOMING_REQUEST', [
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

    $speakerVolume = parseVolumeParam($params, ['speaker_volume', 'output_volume', 'volume_out'], 'speaker_volume');
    $micVolume = parseVolumeParam($params, ['mic_volume', 'input_volume', 'volume_in'], 'mic_volume');

    if ($speakerVolume === null && $micVolume === null) {
        jsonOut([
            'code' => 400,
            'msg' => '请至少传 speaker_volume 或 mic_volume，范围 0-100',
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

    $anrOpen = parseBoolParam($params, ['anr_open', 'AnrOpen'], true);

    // Step 1：获取 deviceToken
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

    // Step 2：登录设备
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

    // Step 3：设置喇叭输出音量。0 是合法值，会被当成静音。
    $speakerRes = null;
    if ($speakerVolume !== null) {
        $speakerRes = setSpeakerVolume($deviceToken, $speakerVolume, $channel);
        if (!jfDeviceRetOk($speakerRes)) {
            jsonOut([
                'code' => 502,
                'msg' => '设置喇叭输出音量失败',
                'step' => 'set_speaker_volume',
                'sn' => $sn,
                'speaker_volume' => $speakerVolume,
                'response' => slimResponse($speakerRes),
            ], 502);
        }
    }

    // Step 4：设置麦克风输入音量。0 是合法值，会被当成静音。
    $micRes = null;
    if ($micVolume !== null) {
        $micRes = setMicVolume($deviceToken, $micVolume, $anrOpen, $channel);
        if (!jfDeviceRetOk($micRes)) {
            jsonOut([
                'code' => 502,
                'msg' => '设置麦克风输入音量失败',
                'step' => 'set_mic_volume',
                'sn' => $sn,
                'mic_volume' => $micVolume,
                'response' => slimResponse($micRes),
            ], 502);
        }
    }

    jsonOut([
        'code' => 200,
        'msg' => '音量配置成功',
        'data' => [
            'sn' => $sn,
            'speaker_volume' => $speakerVolume,
            'mic_volume' => $micVolume,
            'channel' => $channel,
            'anr_open' => $anrOpen,
            'keepalive_time' => $keepaliveTime,
            'device_token' => maskString($deviceToken),
            'login' => slimResponse($loginRes),
            'speaker' => slimResponse($speakerRes),
            'mic' => slimResponse($micRes),
        ],
    ]);

} catch (InvalidArgumentException $e) {
    jsonOut([
        'code' => 400,
        'msg' => $e->getMessage(),
    ], 400);
} catch (Throwable $e) {
    debugLog('SERVER_EXCEPTION', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    jsonOut([
        'code' => 500,
        'msg' => '服务器异常：' . $e->getMessage(),
    ], 500);
}