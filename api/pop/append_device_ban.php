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
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function log_append_ban($tag, $data = [])
{
    $logFile = __DIR__ . '/log/append_device_ban.log';
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

$db = new Database();
$redis = new RedisHelper();
$redis->connect();

$archiveKey = trim($_POST['archive_key'] ?? '');
$banMinutes = intval($_POST['ban_duration'] ?? 10);
$banReason  = trim($_POST['ban_reason'] ?? '');
$manualRisk = trim($_POST['manual_risk'] ?? '');

if ($archiveKey === '') {
    json_out(false, '缺少 archive_key');
}

$row = $db->query(
    "SELECT *
     FROM device_violation_archive
     WHERE archive_key = ?
     LIMIT 1",
    [$archiveKey]
);

if ($row === false || empty($row)) {
    json_out(false, '未找到对应归档记录');
}

$row = $row[0];

$imageSerial  = $row['image_device_serial'] ?? '';
$serialNumber = $row['serial_number'] ?? '';
$venueId      = $row['venue_id'] ?? '';
$imageUrl     = $row['image_url'] ?? '';
$deviceName   = $row['device_name'] ?? '';

if ($imageSerial === '') {
    json_out(false, '归档记录缺少 image_device_serial');
}

// 兜底查询车辆
if ($serialNumber === '' || $venueId === '') {
    $vehicle = $db->query(
        "SELECT serial_number, bind_site, name
         FROM vehicles
         WHERE image_device_serial = ?
         LIMIT 1",
        [$imageSerial]
    );
    if ($vehicle !== false && !empty($vehicle)) {
        $serialNumber = $serialNumber ?: ($vehicle[0]['serial_number'] ?? '');
        $venueId = $venueId ?: ($vehicle[0]['bind_site'] ?? '');
        $deviceName = $deviceName ?: ($vehicle[0]['name'] ?? '');
    }
}

$banSeconds = $banMinutes * 60;
$banEndTime = date('Y-m-d H:i:s', time() + $banSeconds);

$db->beginTransaction();

try {
    // 1. 更新车辆封禁状态
    $updatedVehicle = $db->query(
        "UPDATE vehicles
         SET start_status = 'false',
             sharing_status = '未共享',
             is_banned = 1
         WHERE image_device_serial = ?",
        [$imageSerial],
        true
    );

    if ($updatedVehicle === false) {
        throw new Exception('更新车辆状态失败');
    }

    // 2. 写 device_bans
    $insertBan = $db->query(
        "INSERT INTO device_bans (
            serial_number, uid, ban_duration, ban_end_time,
            ban_reason, venue_id, image_device_serial, status, image_url
        ) VALUES (?, '01', ?, ?, ?, ?, ?, 1, ?)",
        [
            $serialNumber,
            $banSeconds,
            $banEndTime,
            $banReason,
            $venueId,
            $imageSerial,
            $imageUrl
        ],
        true
    );

    if ($insertBan === false) {
        throw new Exception('写入 device_bans 失败');
    }

    // 3. 更新归档表：追封
    $updatedArchive = $db->query(
        "UPDATE device_violation_archive
         SET review_status = 'banned',
             is_append_processed = 1,
             manual_risk = CASE WHEN ? <> '' THEN ? ELSE manual_risk END,
             ban_duration_minutes = ?,
             ban_reason = ?,
             status_updated_at = NOW(),
             banned_at = NOW(),
             updated_at = NOW()
         WHERE archive_key = ?
         LIMIT 1",
        [
            $manualRisk,
            $manualRisk,
            $banMinutes,
            $banReason,
            $archiveKey
        ],
        true
    );

    if ($updatedArchive === false) {
        throw new Exception('更新归档表失败');
    }

    $db->commit();

    // 4. Redis 1 号池保留封禁态
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
        'archive_key' => $archiveKey
    ];
    $redis->set($banKey, json_encode($banData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    // 5. 顺手删掉 14 号池当前态（存在就删）
    $redis->selectDb(14);
    $k1 = 'device_violation:' . $imageSerial;
    $k2 = 'device_violation:' . $imageSerial . '_predicted';
    if ($redis->exists($k1)) $redis->delete($k1);
    if ($redis->exists($k2)) $redis->delete($k2);

    // 6. 发通知（沿用你原来逻辑）
    if ($venueId !== '') {
        $notificationUrl = "https://rcwulian.cn/app/code/send-Ban.php?venue_id="
            . urlencode($venueId)
            . "&ban_type=场地设备&time="
            . urlencode($banMinutes);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $notificationUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $httpCode = 0;
    }

    json_out(true, '追封成功', [
        'archive_key' => $archiveKey,
        'serial_number' => $serialNumber,
        'image_device_serial' => $imageSerial,
        'notify_http' => $httpCode
    ]);

} catch (Exception $e) {
    $db->rollBack();
    log_append_ban('ERROR', [
        'archive_key' => $archiveKey,
        'msg' => $e->getMessage()
    ]);
    json_out(false, '追封失败：' . $e->getMessage());
}
?>