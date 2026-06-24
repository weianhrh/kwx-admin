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

function log_clear($tag, $data = [])
{
    $logFile = __DIR__ . '/log/clear_device_violation.log';
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
 * 如果前端没传 archive_key，再退化到 image_device_serial + image_url
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
 * 只有当前 Redis 里的这条记录重新算出的 archive_key 与前端传来的 archive_key 一致时，才删除
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

/* ===================== 接收参数 ===================== */

$db = new Database();
$redis = new RedisHelper();
$redis->connect();

$rawSerial   = trim($_POST['serial_number'] ?? '');
$manualRisk  = trim($_POST['manual_risk'] ?? '');
$imageUrl    = trim($_POST['image_url'] ?? '');
$archiveKey  = trim($_POST['archive_key'] ?? '');
$redisKey    = trim($_POST['redis_key'] ?? '');

// 兼容你前端现在“自研时会拼 _predicted”这一套
$imageSerial = $rawSerial;
if (substr($imageSerial, -10) === '_predicted') {
    $imageSerial = substr($imageSerial, 0, -10);
}

if (empty($rawSerial)) {
    json_out(false, '缺少 serial_number 参数');
}

/* ===================== 找归档记录 ===================== */

$archiveRow = resolve_archive_row($db, $archiveKey, $imageSerial, $imageUrl);
$resolvedArchiveKey = $archiveRow['archive_key'] ?? $archiveKey;

/* ===================== 数据库事务：更新归档状态 ===================== */

$db->beginTransaction();

try {
    if (!empty($resolvedArchiveKey)) {
        $updateArchiveSql = "
            UPDATE device_violation_archive
            SET review_status = 'cleared',
                manual_risk = CASE
                    WHEN ? <> '' THEN ?
                    ELSE manual_risk
                END,
                status_updated_at = NOW(),
                cleared_at = NOW(),
                is_append_processed = CASE
                    WHEN review_status <> 'pending' AND review_status <> 'cleared' THEN 1
                    ELSE is_append_processed
                END
            WHERE archive_key = ?
            LIMIT 1
        ";

        $archiveUpdated = $db->query($updateArchiveSql, [
            $manualRisk,
            $manualRisk,
            $resolvedArchiveKey
        ], true);

        if ($archiveUpdated === false) {
            throw new Exception("归档表状态更新失败");
        }
    } else {
        // 不强制报错，避免旧数据没有 archive_key 时整个 clear 失败
        log_clear('ARCHIVE_NOT_FOUND', [
            'serial_number' => $rawSerial,
            'image_device_serial' => $imageSerial,
            'image_url' => $imageUrl,
            'archive_key' => $archiveKey,
            'redis_key' => $redisKey
        ]);
    }

    $db->commit();

    /* ===================== 事务提交后：安全删除 Redis 14 ===================== */

    $deleteResult = delete_redis_violation_if_same($redis, $redisKey, $resolvedArchiveKey);
    log_clear('REDIS_DELETE_RESULT', [
        'redis_key' => $redisKey,
        'archive_key' => $resolvedArchiveKey,
        'result' => $deleteResult
    ]);

    json_out(true, '违规信息已清除', [
        'archive_key'   => $resolvedArchiveKey,
        'redis_deleted' => $deleteResult['deleted'] ?? false,
        'redis_reason'  => $deleteResult['reason'] ?? ''
    ]);

} catch (Exception $e) {
    $db->rollBack();
    $db->logToFile("清除违规失败: " . $e->getMessage());
    log_clear('ERROR', [
        'msg' => $e->getMessage(),
        'serial_number' => $rawSerial,
        'image_device_serial' => $imageSerial,
        'archive_key' => $archiveKey,
        'redis_key' => $redisKey
    ]);
    json_out(false, '操作失败: ' . $e->getMessage());
}
?>