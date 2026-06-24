<?php
require_once '../RedisHelper.php';
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

/**
 * 说明：
 * 1. 扫 Redis 14号池的 device_violation:*
 * 2. 生成 archive_key
 * 3. 下载图片到本地 review_backup/YYYY-MM-DD/
 * 4. 写入 / 更新 device_violation_archive
 *
 * 这版按我们前面约定的 device_violation_archive 字段写：
 * archive_key, redis_key, image_device_serial, serial_number, device_name,
 * venue_id, venue_name, source_type, risk_level, manual_risk, reason, remark,
 * image_url, local_image_path, local_image_name, hit_count, raw_json,
 * review_status, is_append_processed, grab_time, first_seen_at, last_seen_at,
 * status_updated_at, banned_at, cleared_at, disappeared_at,
 * ban_duration_minutes, ban_reason, created_at, updated_at
 *
 * 如果你的表字段名和这版不一致，把建表 SQL 发我，我给你对齐。
 */

function json_out($success, $message, $data = [])
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function log_archive($tag, $data = [])
{
    $logFile = __DIR__ . '/log/backup_device_violation_archive.log';
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

function derive_image_serial_from_url($imageUrl)
{
    if (empty($imageUrl)) return '';
    $path = parse_url($imageUrl, PHP_URL_PATH);
    if (!$path) return '';

    $base = basename($path);
    if (!$base) return '';

    $nameWithoutExt = pathinfo($base, PATHINFO_FILENAME);
    if (!$nameWithoutExt) return '';

    $parts = explode('_', $nameWithoutExt);
    return $parts[0] ?? '';
}

function build_archive_key($imageDeviceSerial, $grabTime, $sourceType, $normalizedImageUrl)
{
    return sha1($imageDeviceSerial . '|' . $grabTime . '|' . $sourceType . '|' . $normalizedImageUrl);
}

function swap_pending_risk_path($url)
{
    if (stripos($url, '/pending_images_folder/') !== false) {
        return str_ireplace('/pending_images_folder/', '/risk_images_folder/', $url);
    }
    if (stripos($url, '/risk_images_folder/') !== false) {
        return str_ireplace('/risk_images_folder/', '/pending_images_folder/', $url);
    }
    return $url;
}

function try_download_image($imageUrl)
{
    if (empty($imageUrl)) {
        return [false, '', '空图片地址'];
    }

    $candidates = [];
    $candidates[] = $imageUrl;

    $swapped = swap_pending_risk_path($imageUrl);
    if ($swapped !== $imageUrl) {
        $candidates[] = $swapped;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 8,
            'ignore_errors' => true,
        ],
        'https' => [
            'timeout' => 8,
            'ignore_errors' => true,
        ]
    ]);

    foreach ($candidates as $url) {
        $data = @file_get_contents($url, false, $context);
        if ($data !== false && strlen($data) > 0) {
            return [true, $data, $url];
        }
    }

    return [false, '', '下载失败'];
}

function ensure_dir($dir)
{
    if (!is_dir($dir)) {
        return @mkdir($dir, 0777, true);
    }
    return true;
}

function get_file_ext_from_url($imageUrl)
{
    $path = parse_url($imageUrl, PHP_URL_PATH);
    $ext = strtolower(pathinfo($path ?? '', PATHINFO_EXTENSION));
    if (!$ext) $ext = 'jpg';

    $allow = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'];
    if (!in_array($ext, $allow, true)) {
        $ext = 'jpg';
    }
    return $ext;
}

function save_image_to_local($imageUrl, $grabTime, $imageDeviceSerial, $archiveKey)
{
    $dateDir = date('Y-m-d', strtotime($grabTime ?: 'now'));
    $rootDir = __DIR__ . '/review_backup/' . $dateDir;

    if (!ensure_dir($rootDir)) {
        return [false, '', '', '创建目录失败'];
    }

    $ext = get_file_ext_from_url($imageUrl);
    $fileName = date('Ymd_His', strtotime($grabTime ?: 'now'))
        . '_' . $imageDeviceSerial
        . '_' . substr($archiveKey, 0, 8)
        . '.' . $ext;

    $fullPath = $rootDir . '/' . $fileName;

    // 已存在就直接复用
    if (file_exists($fullPath) && filesize($fullPath) > 0) {
        return [true, $fullPath, $fileName, 'exists'];
    }

    list($ok, $imgData, $downloadMsg) = try_download_image($imageUrl);
    if (!$ok) {
        return [false, '', '', $downloadMsg];
    }

    $written = @file_put_contents($fullPath, $imgData);
    if ($written === false) {
        return [false, '', '', '写入本地失败'];
    }

    return [true, $fullPath, $fileName, 'saved'];
}

function parse_grab_time($timeText)
{
    if (!empty($timeText) && strtotime($timeText) !== false) {
        return date('Y-m-d H:i:s', strtotime($timeText));
    }
    return date('Y-m-d H:i:s');
}
function should_download_image($riskLevel)
{
    return strtolower(trim((string)$riskLevel)) === 'high';
}
function get_vehicle_and_venue(Database $db, $imageDeviceSerial)
{
    $result = [
        'serial_number' => null,
        'device_name'   => null,
        'venue_id'      => null,
        'venue_name'    => null,
    ];

    if (empty($imageDeviceSerial)) {
        return $result;
    }

    $vehicles = $db->query(
        "SELECT serial_number, bind_site, name
         FROM vehicles
         WHERE image_device_serial = ?
         LIMIT 1",
        [$imageDeviceSerial]
    );

    if ($vehicles !== false && !empty($vehicles)) {
        $result['serial_number'] = $vehicles[0]['serial_number'] ?? null;
        $result['device_name']   = $vehicles[0]['name'] ?? null;
        $result['venue_id']      = $vehicles[0]['bind_site'] ?? null;

        if (!empty($result['venue_id'])) {
            $venues = $db->query(
                "SELECT venue_name
                 FROM venues
                 WHERE id = ?
                 LIMIT 1",
                [$result['venue_id']]
            );
            if ($venues !== false && !empty($venues)) {
                $result['venue_name'] = $venues[0]['venue_name'] ?? null;
            }
        }
    }

    return $result;
}

function upsert_archive_record(Database $db, array $row)
{
    $exists = $db->query(
        "SELECT id, review_status, local_image_path
         FROM device_violation_archive
         WHERE archive_key = ?
         LIMIT 1",
        [$row['archive_key']]
    );

    if ($exists !== false && !empty($exists)) {
        $id = $exists[0]['id'];

        $sql = "UPDATE device_violation_archive SET
                    redis_key = ?,
                    image_device_serial = ?,
                    serial_number = ?,
                    device_name = ?,
                    venue_id = ?,
                    venue_name = ?,
                    source_type = ?,
                    risk_level = ?,
                    reason = ?,
                    remark = ?,
                    image_url = ?,
                    local_image_path = CASE
                        WHEN (local_image_path IS NULL OR local_image_path = '') AND ? <> '' THEN ?
                        ELSE local_image_path
                    END,
                    local_image_name = CASE
                        WHEN (local_image_name IS NULL OR local_image_name = '') AND ? <> '' THEN ?
                        ELSE local_image_name
                    END,
                    hit_count = ?,
                    raw_json = ?,
                    grab_time = ?,
                    last_seen_at = NOW()
                WHERE id = ?";

        $updated = $db->query($sql, [
            $row['redis_key'],
            $row['image_device_serial'],
            $row['serial_number'],
            $row['device_name'],
            $row['venue_id'],
            $row['venue_name'],
            $row['source_type'],
            $row['risk_level'],
            $row['reason'],
            $row['remark'],
            $row['image_url'],
            $row['local_image_path'],
            $row['local_image_path'],
            $row['local_image_name'],
            $row['local_image_name'],
            $row['hit_count'],
            $row['raw_json'],
            $row['grab_time'],
            $id
        ], true);

        if ($updated === false) {
            return [false, '更新失败'];
        }
        return [true, 'updated'];
    }

    $sql = "INSERT INTO device_violation_archive (
                archive_key,
                redis_key,
                image_device_serial,
                serial_number,
                device_name,
                venue_id,
                venue_name,
                source_type,
                risk_level,
                manual_risk,
                reason,
                remark,
                image_url,
                local_image_path,
                local_image_name,
                hit_count,
                raw_json,
                review_status,
                is_append_processed,
                grab_time,
                first_seen_at,
                last_seen_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?, NOW(), NOW()
            )";

    $inserted = $db->query($sql, [
        $row['archive_key'],
        $row['redis_key'],
        $row['image_device_serial'],
        $row['serial_number'],
        $row['device_name'],
        $row['venue_id'],
        $row['venue_name'],
        $row['source_type'],
        $row['risk_level'],
        $row['reason'],
        $row['remark'],
        $row['image_url'],
        $row['local_image_path'],
        $row['local_image_name'],
        $row['hit_count'],
        $row['raw_json'],
        $row['grab_time'],
    ], true);

    if ($inserted === false) {
        return [false, '插入失败'];
    }

    return [true, 'inserted'];
}

/* ===================== 主流程 ===================== */

try {
    $redis = new RedisHelper();
    $db = new Database();

    $redis->connect();
    $redis->selectDb(14);

    $keys = $redis->getAllKeys('device_violation:*');
    if ($keys === false) {
        json_out(false, '读取14号池失败');
    }

    $scanned = 0;
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];

    foreach ($keys as $key) {
        $scanned++;

        try {
            $rawJson = $redis->get($key);
            if (!$rawJson) {
                $skipped++;
                continue;
            }

            $decoded = json_decode($rawJson, true);
            if (!is_array($decoded)) {
                $skipped++;
                log_archive('SKIP_JSON', ['key' => $key, 'raw' => $rawJson]);
                continue;
            }

            // 从 Redis key 拿 serial 和 source_type
            $shortKey = str_replace('device_violation:', '', $key);
            $isPredicted = str_ends_with_local($shortKey, '_predicted');
            $imageDeviceSerial = str_replace('_predicted', '', $shortKey);
            $sourceType = $isPredicted ? 'predicted' : 'original';

            // 如果 key 解析不到，再从图片文件名兜底
            if (empty($imageDeviceSerial)) {
                $imageDeviceSerial = derive_image_serial_from_url($decoded['image_url'] ?? '');
            }

            if (empty($imageDeviceSerial)) {
                $skipped++;
                log_archive('SKIP_NO_SERIAL', ['key' => $key, 'json' => $decoded]);
                continue;
            }

            $grabTime = parse_grab_time($decoded['time'] ?? '');
            $imageUrl = $decoded['image_url'] ?? '';
            $normalizedImageUrl = normalize_image_url_for_key($imageUrl);

            $archiveKey = build_archive_key(
                $imageDeviceSerial,
                $grabTime,
                $sourceType,
                $normalizedImageUrl
            );

            $riskLevel = $decoded['risk_level'] ?? null;
            $reason    = $decoded['reason'] ?? null;
            $remark    = $decoded['remark'] ?? null;
            $hitCount  = isset($decoded['hit_count']) ? intval($decoded['hit_count']) : 0;

            $deviceInfo = get_vehicle_and_venue($db, $imageDeviceSerial);

            $localImagePath = '';
            $localImageName = '';
            
            if (!empty($imageUrl) && should_download_image($riskLevel)) {
                list($saveOk, $fullPath, $fileName, $saveMsg) = save_image_to_local(
                    $imageUrl,
                    $grabTime,
                    $imageDeviceSerial,
                    $archiveKey
                );
            
                if ($saveOk) {
                    $localImagePath = $fullPath;
                    $localImageName = $fileName;
                } else {
                    log_archive('IMG_SAVE_FAIL', [
                        'key' => $key,
                        'archive_key' => $archiveKey,
                        'image_url' => $imageUrl,
                        'risk_level' => $riskLevel,
                        'msg' => $saveMsg
                    ]);
                }
            } else {
                log_archive('IMG_SKIP_NOT_HIGH', [
                    'key' => $key,
                    'archive_key' => $archiveKey,
                    'image_url' => $imageUrl,
                    'risk_level' => $riskLevel
                ]);
            }

            $row = [
                'archive_key'        => $archiveKey,
                'redis_key'          => $key,
                'image_device_serial'=> $imageDeviceSerial,
                'serial_number'      => $deviceInfo['serial_number'],
                'device_name'        => $deviceInfo['device_name'],
                'venue_id'           => $deviceInfo['venue_id'],
                'venue_name'         => $deviceInfo['venue_name'],
                'source_type'        => $sourceType,
                'risk_level'         => $riskLevel,
                'reason'             => $reason,
                'remark'             => $remark,
                'image_url'          => $imageUrl,
                'local_image_path'   => $localImagePath,
                'local_image_name'   => $localImageName,
                'hit_count'          => $hitCount,
                'raw_json'           => $rawJson,
                'grab_time'          => $grabTime,
            ];

            list($ok, $type) = upsert_archive_record($db, $row);
            if (!$ok) {
                $errors[] = [
                    'key' => $key,
                    'archive_key' => $archiveKey,
                    'msg' => $type
                ];
                log_archive('UPSERT_FAIL', end($errors));
                continue;
            }

            if ($type === 'inserted') $inserted++;
            if ($type === 'updated')  $updated++;

        } catch (Exception $e) {
            $errors[] = [
                'key' => $key,
                'msg' => $e->getMessage()
            ];
            log_archive('LOOP_EXCEPTION', end($errors));
        }
    }

    $db->close();
    $redis->close();

    json_out(true, '备份完成', [
        'scanned'  => $scanned,
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'errors'   => $errors,
    ]);

} catch (Exception $e) {
    log_archive('FATAL', ['msg' => $e->getMessage()]);
    json_out(false, '执行失败：' . $e->getMessage());
}