<?php
/**
 * /api/devMgr/check_stream_set_sn_status.php
 *
 * 检测即构流状态 + bind_site=60 时设置 Redis 永久在线
 *
 * 传入：
 *   sn / serial_number
 *   playing_stream_id / stream_id
 *
 * 逻辑：
 *   1. SELECT bind_site FROM vehicles WHERE serial_number = ?
 *   2. 查询即构流状态
 *   3. 如果流在线 && bind_site == 60：
 *        Redis DB4 写入 key = sn，TTL = 0，永久在线
 *   4. 其他情况：
 *        pass，不写 Redis，不删 Redis，不改车辆状态
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../RedisHelper.php';

/**
 * 即构配置（按顺序检测：酷玩星优先，未在线时再检测 RC 物联）
 */
$ZEGO_APPS = [
    [
        'name'          => '酷玩星',
        'app_id'        => 1847604878,
        'server_secret' => '70e538efe46bc3450b9ba7759b47f936',
    ],
    [
        'name'          => 'RC物联',
        'app_id'        => 141962251,
        'server_secret' => getenv('ZEGO_SERVER_SECRET') ?: '5bfaa3399946c98cc6792dd19f9a08ec',
    ],
];

$ZEGO_IS_TEST = false;

/**
 * Redis DB4
 */
$REDIS_HOST = '127.0.0.1';
$REDIS_PORT = 6379;
$REDIS_DB   = 4;

/**
 * 只有这个 bind_site 才设置 Redis 永久在线
 */
$SPECIAL_BIND_SITE = 60;

/**
 * Redis 永久在线 TTL
 * 0 = 不过期
 */
$REDIS_FOREVER_TTL = 0;

function jsonOut(array $arr): void
{
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 获取请求参数
 */
function getRequestValue(array $keys): string
{
    foreach ($keys as $key) {
        if (isset($_POST[$key])) {
            return trim((string)$_POST[$key]);
        }

        if (isset($_GET[$key])) {
            return trim((string)$_GET[$key]);
        }
    }

    return '';
}

/**
 * 构建即构查询流状态 URL
 */
function buildZegoStreamStateUrl(string $streamId, array $app): string
{
    global $ZEGO_IS_TEST;

    $appId = (int)$app['app_id'];
    $serverSecret = (string)$app['server_secret'];

    $streamId = trim($streamId);

    if ($ZEGO_IS_TEST) {
        $prefix = "zegotest-{$appId}-";
        if (strpos($streamId, $prefix) !== 0) {
            $streamId = $prefix . $streamId;
        }
    }

    $timestamp = time();
    $signatureNonce = bin2hex(random_bytes(8));
    $sequence = (string)round(microtime(true) * 1000);

    /**
     * 即构签名：
     * md5(AppId + SignatureNonce + ServerSecret + Timestamp)
     */
    $signature = md5($appId . $signatureNonce . $serverSecret . $timestamp);

    $params = [
        'Action'           => 'DescribeRTCStreamState',
        'AppId'            => $appId,
        'SignatureNonce'   => $signatureNonce,
        'Timestamp'        => $timestamp,
        'Signature'        => $signature,
        'SignatureVersion' => '2.0',
        'StreamId'         => $streamId,
        'Sequence'         => $sequence,
    ];

    if ($ZEGO_IS_TEST) {
        $params['IsTest'] = 1;
    }

    return 'https://rtc-api.zego.im/?' . http_build_query($params);
}

/**
 * 查询即构流是否在线
 *
 * 返回：
 * [
 *   'ok' => true/false,
 *   'active' => true/false,
 *   'zego_code' => 0,
 *   'message' => '',
 *   'http' => 200,
 *   'raw' => []
 * ]
 */
function queryZegoStreamOnline(string $streamId, array $app): array
{
    if (!function_exists('curl_init')) {
        return [
            'ok'        => false,
            'active'    => false,
            'zego_code' => -1,
            'message'   => '服务器未安装或未启用 cURL',
            'http'      => 0,
            'raw'       => null,
            'app_name'  => $app['name'],
            'app_id'    => (int)$app['app_id'],
        ];
    }

    $url = buildZegoStreamStateUrl($streamId, $app);

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $resp = curl_exec($ch);
    $errNo = curl_errno($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($errNo) {
        return [
            'ok'        => false,
            'active'    => false,
            'zego_code' => -1,
            'message'   => 'curl error: ' . $err,
            'http'      => $http,
            'raw'       => null,
            'app_name'  => $app['name'],
            'app_id'    => (int)$app['app_id'],
        ];
    }

    if ($resp === false || trim((string)$resp) === '') {
        return [
            'ok'        => false,
            'active'    => false,
            'zego_code' => -1,
            'message'   => 'empty zego response',
            'http'      => $http,
            'raw'       => null,
            'app_name'  => $app['name'],
            'app_id'    => (int)$app['app_id'],
        ];
    }

    $zego = json_decode((string)$resp, true);

    if (!is_array($zego)) {
        return [
            'ok'        => false,
            'active'    => false,
            'zego_code' => -1,
            'message'   => 'invalid zego response',
            'http'      => $http,
            'raw'       => $resp,
            'app_name'  => $app['name'],
            'app_id'    => (int)$app['app_id'],
        ];
    }

    $zegoCode = (int)($zego['Code'] ?? -1);
    $zegoMsg  = (string)($zego['Message'] ?? 'unknown');

    /**
     * 按你之前巡检脚本逻辑：
     * Code === 0 认为流在线
     */
    $active = ($zegoCode === 0);

    return [
        'ok'        => true,
        'active'    => $active,
        'zego_code' => $zegoCode,
        'message'   => $active ? 'stream active' : $zegoMsg,
        'http'      => $http,
        'raw'       => $zego,
        'app_name'  => $app['name'],
        'app_id'    => (int)$app['app_id'],
    ];
}

/**
 * 酷玩星优先；只有酷玩星未在线（离线、未找到或查询异常）才检测 RC 物联。
 */
function queryZegoStreamOnlineByPriority(string $streamId): array
{
    global $ZEGO_APPS;

    $checkedApps = [];
    $lastState = null;

    foreach ($ZEGO_APPS as $app) {
        $state = queryZegoStreamOnline($streamId, $app);
        $checkedApps[] = [
            'app_name'  => $state['app_name'],
            'app_id'    => $state['app_id'],
            'ok'        => $state['ok'],
            'active'    => $state['active'],
            'zego_code' => $state['zego_code'],
            'message'   => $state['message'],
        ];
        $lastState = $state;

        if ($state['active']) {
            $state['checked_apps'] = $checkedApps;
            return $state;
        }
    }

    $lastState['checked_apps'] = $checkedApps;
    return $lastState;
}

/**
 * Redis value
 */
function buildRedisValue(string $sn): array
{
    return [
        'serial_number' => $sn,
        'voltage'       => 7.8,
        'longitude'     => 0,
        'latitude'      => 0,
        'speed'         => 0,
    ];
}

$database = null;
$redis = null;

try {
    $sn = getRequestValue(['sn', 'serial_number']);
    $playingStreamId = getRequestValue(['playing_stream_id', 'stream_id']);

    if ($sn === '') {
        jsonOut([
            'code' => 400,
            'msg'  => '缺少 sn 参数',
            'time' => date('Y-m-d H:i:s'),
        ]);
    }

    if ($playingStreamId === '') {
        jsonOut([
            'code' => 400,
            'msg'  => '缺少 playing_stream_id 参数',
            'sn'   => $sn,
            'time' => date('Y-m-d H:i:s'),
        ]);
    }

    // if ($ZEGO_SERVER_SECRET === '' || $ZEGO_SERVER_SECRET === '请填你原来的ZEGO_SERVER_SECRET') {
    //     jsonOut([
    //         'code' => 500,
    //         'msg'  => 'ZEGO_SERVER_SECRET 未配置',
    //         'sn'   => $sn,
    //         'time' => date('Y-m-d H:i:s'),
    //     ]);
    // }

    $database = new Database();

    /**
     * 先查询车辆 bind_site
     * 按你最新版 Database.php：
     * SELECT 时 query() 返回二维数组
     */
    $vehicleRows = $database->query(
        "SELECT bind_site FROM vehicles WHERE serial_number = ? LIMIT 1",
        [$sn]
    );

    if ($vehicleRows === false) {
        jsonOut([
            'code'              => 500,
            'msg'               => '查询车辆 bind_site 失败',
            'sn'                => $sn,
            'playing_stream_id' => $playingStreamId,
            'time'              => date('Y-m-d H:i:s'),
        ]);
    }

    if (empty($vehicleRows)) {
        jsonOut([
            'code'              => 404,
            'msg'               => '未找到对应车辆',
            'sn'                => $sn,
            'playing_stream_id' => $playingStreamId,
            'time'              => date('Y-m-d H:i:s'),
        ]);
    }

    $bindSiteRaw = $vehicleRows[0]['bind_site'] ?? null;
    $bindSite = is_null($bindSiteRaw) ? null : (int)$bindSiteRaw;
    $isSpecialBindSite = ((int)$bindSite === (int)$SPECIAL_BIND_SITE);

    /**
     * 查询即构流状态
     */
    $state = queryZegoStreamOnlineByPriority($playingStreamId);

    /**
     * 查询失败：不写 Redis，不改数据库
     */
    if (!$state['ok']) {
        jsonOut([
            'code'              => 502,
            'msg'               => '查询即构流状态失败，本次不操作 Redis',
            'sn'                => $sn,
            'playing_stream_id' => $playingStreamId,
            'bind_site'         => $bindSite,
            'is_special_site'   => $isSpecialBindSite ? 1 : 0,
            'active'            => false,
            'stream_status'     => 'unknown',
            'redis_action'      => 'pass',
            'redis_db'          => $REDIS_DB,
            'redis_key'         => $sn,
            'redis_written'     => 0,
            'zego_code'         => $state['zego_code'],
            'zego_message'      => $state['message'],
            'http'              => $state['http'],
            'app_name'          => $state['app_name'],
            'app_id'            => $state['app_id'],
            'checked_apps'      => $state['checked_apps'],
            'time'              => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 流在线 && bind_site == 60
     * 设置 Redis DB4 永久在线
     */
    if ($state['active'] && $isSpecialBindSite) {
        $redisValue = buildRedisValue($sn);
        $redisValueJson = json_encode($redisValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $redis = new RedisHelper();
        $redis->connect($REDIS_HOST, $REDIS_PORT, 0);
        $redis->selectDb($REDIS_DB);

        /**
         * 第三个参数 0 = 永久不过期
         */
        $redis->save($sn, $redisValueJson, $REDIS_FOREVER_TTL);

        jsonOut([
            'code'              => 200,
            'msg'               => '流在线（' . $state['app_name'] . ' AppID：' . $state['app_id'] . '），bind_site=60，已设置 Redis DB4 永久在线',
            'sn'                => $sn,
            'playing_stream_id' => $playingStreamId,
            'bind_site'         => $bindSite,
            'is_special_site'   => 1,
            'active'            => true,
            'stream_status'     => 'online',
            'redis_action'      => 'set_forever',
            'redis_db'          => $REDIS_DB,
            'redis_key'         => $sn,
            'redis_ttl'         => $REDIS_FOREVER_TTL,
            'redis_written'     => 1,
            'redis_value'       => $redisValue,
            'zego_code'         => $state['zego_code'],
            'zego_message'      => $state['message'],
            'http'              => $state['http'],
            'app_name'          => $state['app_name'],
            'app_id'            => $state['app_id'],
            'checked_apps'      => $state['checked_apps'],
            'time'              => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 其他情况全部 pass
     * 包括：
     *   1. 流在线，但 bind_site != 60
     *   2. 流离线
     *
     * 注意：
     *   不删除 Redis
     *   不修改 vehicles.status
     *   不修改 vehicles.sharing_status
     */
    $msg = '';

    if ($state['active']) {
        $msg = '流在线（' . $state['app_name'] . ' AppID：' . $state['app_id'] . '）';
    } else {
        $msg = '流离线（已依次检测酷玩星和 RC物联）';
    }

    jsonOut([
        'code'              => 200,
        'msg'               => $msg,
        'sn'                => $sn,
        'playing_stream_id' => $playingStreamId,
        'bind_site'         => $bindSite,
        'is_special_site'   => $isSpecialBindSite ? 1 : 0,
        'active'            => $state['active'] ? true : false,
        'stream_status'     => $state['active'] ? 'online' : 'offline',
        'redis_action'      => 'pass',
        'redis_db'          => $REDIS_DB,
        'redis_key'         => $sn,
        'redis_written'     => 0,
        'redis_ttl'         => null,
        'zego_code'         => $state['zego_code'],
        'zego_message'      => $state['message'],
        'http'              => $state['http'],
        'app_name'          => $state['app_name'],
        'app_id'            => $state['app_id'],
        'checked_apps'      => $state['checked_apps'],
        'time'              => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    jsonOut([
        'code' => 500,
        'msg'  => '执行异常：' . $e->getMessage(),
        'time' => date('Y-m-d H:i:s'),
    ]);

} finally {
    if ($redis) {
        try {
            $redis->close();
        } catch (Throwable $e) {
        }
    }

    if ($database) {
        try {
            $database->close();
        } catch (Throwable $e) {
        }
    }
}
