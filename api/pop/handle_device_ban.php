<?php
require_once '../Database.php';
require_once '../RedisHelper.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function json_out($success, $message, $data = [])
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function log_ban($tag, $data = [])
{
    $logFile = __DIR__ . '/log/handle_device_ban.log';
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $line = '[' . date('Y-m-d H:i:s') . '] [' . $tag . '] ';
    if (!empty($data)) {
        $line .= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
}

function str_ends_with_local($haystack, $needle)
{
    $length = strlen($needle);
    if ($length === 0) return true;
    return substr($haystack, -$length) === $needle;
}

function normalize_image_url_for_key($imageUrl)
{
    if (empty($imageUrl)) return '';
    $parts = parse_url($imageUrl);
    if (empty($parts['host']) || empty($parts['path'])) {
        return $imageUrl;
    }
    $scheme = $parts['scheme'] ?? 'https';
    return $scheme . '://' . $parts['host'] . $parts['path'];
}

function parse_grab_time($timeText)
{
    if (!empty($timeText) && strtotime($timeText) !== false) {
        return date('Y-m-d H:i:s', strtotime($timeText));
    }
    return date('Y-m-d H:i:s');
}

function build_archive_key($imageDeviceSerial, $grabTime, $sourceType, $normalizedImageUrl)
{
    return sha1($imageDeviceSerial . '|' . $grabTime . '|' . $sourceType . '|' . $normalizedImageUrl);
}

/**
 * 优先按 archive_key 找归档记录
 * 如果前端暂时还没传 archive_key，就退化到 image_device_serial + image_url 找最新一条
 */
function resolve_archive_row(Database $db, $archiveKey, $imageSerial, $imageUrl)
{
    if (!empty($archiveKey)) {
        $rows = $db->query(
            "SELECT id, archive_key, review_status
             FROM device_violation_archive
             WHERE archive_key = ?
             LIMIT 1",
            [$archiveKey]
        );
        if ($rows !== false && !empty($rows)) {
            return $rows[0];
        }
    }

    if (!empty($imageSerial) && !empty($imageUrl)) {
        $rows = $db->query(
            "SELECT id, archive_key, review_status
             FROM device_violation_archive
             WHERE image_device_serial = ?
               AND image_url = ?
             ORDER BY id DESC
             LIMIT 1",
            [$imageSerial, $imageUrl]
        );
        if ($rows !== false && !empty($rows)) {
            return $rows[0];
        }
    }

    return null;
}

/**
 * 安全删除 Redis 14 当前违规 key
 * 只有当前 Redis 里的这条记录重新计算出的 archive_key 与前端传来的 archive_key 一致时，才删除
 */
function delete_redis_violation_if_same(RedisHelper $redis, $redisKey, $archiveKey)
{
    if (empty($redisKey) || empty($archiveKey)) {
        return [
            'deleted' => false,
            'reason'  => 'archive_key 或 redis_key 缺失，跳过安全删除'
        ];
    }

    $redis->selectDb(14);

    if (!$redis->exists($redisKey)) {
        return [
            'deleted' => false,
            'reason'  => 'redis_key 不存在，无需删除'
        ];
    }

    $rawJson = $redis->get($redisKey);
    if (!$rawJson) {
        return [
            'deleted' => false,
            'reason'  => 'redis_key 读取为空'
        ];
    }

    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded)) {
        return [
            'deleted' => false,
            'reason'  => 'redis 内容不是有效 JSON'
        ];
    }

    $shortKey = str_replace('device_violation:', '', $redisKey);
    $isPredicted = str_ends_with_local($shortKey, '_predicted');
    $imageDeviceSerial = str_replace('_predicted', '', $shortKey);
    $sourceType = $isPredicted ? 'predicted' : 'original';

    $grabTime = parse_grab_time($decoded['time'] ?? '');
    $normalizedImageUrl = normalize_image_url_for_key($decoded['image_url'] ?? '');
    $currentArchiveKey = build_archive_key(
        $imageDeviceSerial,
        $grabTime,
        $sourceType,
        $normalizedImageUrl
    );

    if ($currentArchiveKey !== $archiveKey) {
        return [
            'deleted' => false,
            'reason'  => '当前 redis 里的记录已不是同一条抓拍，跳过删除',
            'current_archive_key' => $currentArchiveKey
        ];
    }

    $redis->delete($redisKey);

    return [
        'deleted' => true,
        'reason'  => '删除成功'
    ];
}

/**
 * 根据车辆 serial_number 查询 ZEGO room_id
 * 优先取 device_information.room_id
 * 如果查不到，且 image_device_serial 本身像 ZEGO 房间号，则 fallback 用 image_device_serial
 */
function getZegoRoomIdBySerialNumber(Database $db, string $serialNumber): string
{
    $serialNumber = trim($serialNumber);
    if ($serialNumber === '') {
        return '';
    }

    $sql = "
        SELECT
            v.image_device_serial,
            di.room_id AS room_id
        FROM vehicles v
        LEFT JOIN device_information di
            ON CAST(di.id AS CHAR) = v.image_device_serial
        WHERE v.serial_number = ?
        LIMIT 1
    ";

    $rows = $db->query($sql, [$serialNumber]);

    if ($rows === false || empty($rows)) {
        return '';
    }

    $roomId = trim((string)($rows[0]['room_id'] ?? ''));

    if ($roomId !== '') {
        return $roomId;
    }

    $imageDeviceSerial = trim((string)($rows[0]['image_device_serial'] ?? ''));

    // 兜底：ZEGO 数字房间 ID，通常 image_device_serial 长度小于 8
    if ($imageDeviceSerial !== '' && strlen($imageDeviceSerial) < 8) {
        return $imageDeviceSerial;
    }

    return '';
}

/**
 * 调用 send_room_ban_message.php，发送 ROOM_BAN#封禁原因
 * 注意：失败不影响封禁成功，只记录日志
 */
function notifyRoomBanMessage(string $roomId, string $banReason, string $fromUserId = 'server_bot_1'): array
{
    $url = 'https://open.rcwulian.cn/api/devMgr/send_room_ban_message.php';

    $banReason = trim($banReason);
    if ($banReason === '') {
        $banReason = '违规封禁';
    }

    $payload = [
        'room_id'      => $roomId,
        'from_user_id' => $fromUserId,
        'ban_reason'   => $banReason,
    ];

    // 如果 send_room_ban_message.php 配置了 INTERNAL_API_KEY，这里打开：
    // $payload['internal_key'] = '你的内部密钥';

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);

    curl_close($ch);

    if ($curlErr) {
        return [
            'success'   => false,
            'error'     => 'cURL错误：' . $curlErr,
            'http_code' => $httpCode,
            'raw'       => (string)$raw,
        ];
    }

    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        return [
            'success'   => false,
            'error'     => '响应不是合法JSON',
            'http_code' => $httpCode,
            'raw'       => (string)$raw,
        ];
    }

    $code = (int)($data['code'] ?? -1);

    return [
        'success'   => ($httpCode === 200 && $code === 0),
        'error'     => $data['msg'] ?? '',
        'http_code' => $httpCode,
        'raw'       => (string)$raw,
        'json'      => $data,
    ];
}

/* ===================== 接收参数 ===================== */

$db = new Database();
$redis = new RedisHelper();
$redis->connect();

$imageSerial = trim($_POST['image_device_serial'] ?? '');
$manualRisk  = trim($_POST['manual_risk'] ?? '');
$imageUrl    = trim($_POST['image_url'] ?? '');
$banMinutes  = intval($_POST['ban_duration'] ?? 0);
$banSeconds  = $banMinutes * 60;
$banReason = trim($_POST['ban_reason'] ?? '');

if ($banReason === '') {
    $banReason = '违规封禁';
}
$archiveKey  = trim($_POST['archive_key'] ?? '');
$redisKey    = trim($_POST['redis_key'] ?? '');

// 兼容前端偶尔传 _predicted 的情况
if (substr($imageSerial, -10) === '_predicted') {
    $imageSerial = substr($imageSerial, 0, -10);
}

if (empty($imageSerial) || empty($imageUrl) || $banMinutes <= 0) {
    json_out(false, '参数不完整');
}

/* ===================== 查设备信息 ===================== */

$vehicle = $db->query(
    "SELECT serial_number, bind_site, name
     FROM vehicles
     WHERE image_device_serial = ?
     LIMIT 1",
    [$imageSerial]
);

if ($vehicle === false || empty($vehicle)) {
    json_out(false, '未找到对应设备');
}

$serialNumber = $vehicle[0]['serial_number'] ?? '';
$venueId      = $vehicle[0]['bind_site'] ?? '';
$deviceName   = $vehicle[0]['name'] ?? '';

$archiveRow = resolve_archive_row($db, $archiveKey, $imageSerial, $imageUrl);
$resolvedArchiveKey = $archiveRow['archive_key'] ?? $archiveKey;

/* ===================== 开始事务 ===================== */

$db->beginTransaction();

try {
    // 1) 更新车辆状态
    $updateVehicleSql = "
        UPDATE vehicles
        SET start_status = 'false',
            sharing_status = '未共享',
            is_banned = 1
        WHERE image_device_serial = ?
    ";
    $updated = $db->query($updateVehicleSql, [$imageSerial], true);

    if ($updated === false) {
        throw new Exception("车辆状态更新失败");
    }

    // 2) 插入封禁记录
    $banEndTime = date('Y-m-d H:i:s', time() + $banSeconds);

    $insertBanSql = "
        INSERT INTO device_bans (
            serial_number, uid, ban_duration, ban_end_time,
            ban_reason, venue_id, image_device_serial, status, image_url
        ) VALUES (?, '01', ?, ?, ?, ?, ?, 1, ?)
    ";

    $result = $db->query($insertBanSql, [
        $serialNumber,
        $banSeconds,
        $banEndTime,
        $banReason,
        $venueId,
        $imageSerial,
        $imageUrl
    ], true);

    if ($result === false) {
        throw new Exception("封禁记录插入失败");
    }

    // 3) 更新归档表状态
    if (!empty($resolvedArchiveKey)) {
        $updateArchiveSql = "
            UPDATE device_violation_archive
            SET review_status = 'banned',
                manual_risk = CASE
                    WHEN ? <> '' THEN ?
                    ELSE manual_risk
                END,
                ban_duration_minutes = ?,
                ban_reason = ?,
                status_updated_at = NOW(),
                banned_at = NOW(),
                is_append_processed = CASE
                    WHEN review_status <> 'pending' AND review_status <> 'banned' THEN 1
                    ELSE is_append_processed
                END
            WHERE archive_key = ?
            LIMIT 1
        ";

        $archiveUpdated = $db->query($updateArchiveSql, [
            $manualRisk,
            $manualRisk,
            $banMinutes,
            $banReason,
            $resolvedArchiveKey
        ], true);

        if ($archiveUpdated === false) {
            throw new Exception("归档表状态更新失败");
        }
    } else {
        log_ban('ARCHIVE_NOT_FOUND', [
            'image_device_serial' => $imageSerial,
            'image_url' => $imageUrl,
            'archive_key' => $archiveKey
        ]);
    }

    $db->commit();

    /* ===================== 事务提交后：写 Redis / 删 Redis / 通知 ===================== */

    // 4) Redis 1号池写封禁记录（保留你原来的逻辑）
    $redis->selectDb(1);
    $banKey = 'device_ban:' . $serialNumber;
    $banData = [
        'serial_number' => $serialNumber,
        'uid' => '01',
        'venue_id' => $venueId,
        'ban_duration' => $banSeconds,
        'ban_end_time' => $banEndTime,
        'ban_reason' => $banReason,
        'image_device_serial' => $imageSerial,
        'image_url' => $imageUrl,
        'device_name' => $deviceName,
    ];
    $redis->set($banKey, json_encode($banData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    // 5) 安全删除 Redis 14 当前待处理项
    $deleteResult = delete_redis_violation_if_same($redis, $redisKey, $resolvedArchiveKey);
    log_ban('REDIS_DELETE_RESULT', [
        'redis_key' => $redisKey,
        'archive_key' => $resolvedArchiveKey,
        'result' => $deleteResult
    ]);

// 6) 发送封禁通知（保留你原来的逻辑）
$notificationUrl = "https://rcwulian.cn/app/code/send-Ban.php?venue_id="
    . urlencode($venueId)
    . "&ban_type=场地设备&time="
    . urlencode($banMinutes);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $notificationUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

log_ban('SEND_BAN_NOTIFY_RESULT', [
    'venue_id' => $venueId,
    'http_code' => $httpCode,
    'curl_error' => $curlErr,
    'response' => $response
]);

// 7) 发送 ROOM_BAN 到当前 ZEGO 房间
// 注意：这个通知失败不影响封禁成功
$roomBanNotify = [
    'attempted' => false,
    'success'   => false,
    'room_id'   => '',
    'error'     => '',
];

try {
    $roomId = getZegoRoomIdBySerialNumber($db, $serialNumber);
    $roomBanNotify['room_id'] = $roomId;

    if ($roomId !== '') {
        $roomBanNotify['attempted'] = true;

        $notifyResult = notifyRoomBanMessage($roomId, $banReason, 'server_bot_1');

        $roomBanNotify['success']   = (bool)($notifyResult['success'] ?? false);
        $roomBanNotify['http_code'] = $notifyResult['http_code'] ?? 0;
        $roomBanNotify['error']     = $notifyResult['error'] ?? '';

        log_ban('ROOM_BAN_NOTIFY_RESULT', [
            'serial_number' => $serialNumber,
            'image_device_serial' => $imageSerial,
            'room_id' => $roomId,
            'ban_reason' => $banReason,
            'result' => $notifyResult
        ]);
    } else {
        $roomBanNotify['error'] = '未找到room_id';

        log_ban('ROOM_BAN_NOTIFY_SKIP', [
            'serial_number' => $serialNumber,
            'image_device_serial' => $imageSerial,
            'reason' => '未找到room_id'
        ]);
    }
} catch (Exception $notifyException) {
    $roomBanNotify['error'] = $notifyException->getMessage();

    log_ban('ROOM_BAN_NOTIFY_EXCEPTION', [
        'serial_number' => $serialNumber,
        'image_device_serial' => $imageSerial,
        'error' => $notifyException->getMessage()
    ]);
}

json_out(true, '封禁成功', [
    'archive_key'     => $resolvedArchiveKey,
    'redis_deleted'   => $deleteResult['deleted'] ?? false,
    'redis_reason'    => $deleteResult['reason'] ?? '',
    'notify_http'     => $httpCode,
    'room_ban_notify' => $roomBanNotify
]);

} catch (Exception $e) {
    $db->rollBack();
    $db->logToFile("设备封禁失败: " . $e->getMessage());
    log_ban('ERROR', [
        'msg' => $e->getMessage(),
        'image_device_serial' => $imageSerial,
        'archive_key' => $archiveKey,
        'redis_key' => $redisKey
    ]);
    json_out(false, '操作失败: ' . $e->getMessage());
}
?>