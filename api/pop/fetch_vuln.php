<?php
require_once '../RedisHelper.php';
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

// 创建实例
$redis = new RedisHelper();
$db = new Database();

/**
 * 字符串后缀判断
 */
function str_ends_with_aaa($haystack, $needle) {
    $length = strlen($needle);
    if ($length === 0) {
        return true;
    }
    return (substr($haystack, -$length) === $needle);
}

/**
 * 日志
 */
function logMessage($message, $data = []) {
    $logFile = __DIR__ . '/log/operation_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $message";
    if (!empty($data)) {
        $line .= ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
}

/**
 * 归一化图片地址：去掉 query 参数
 * 这样和 backup_device_violation_archive.php 里的 archive_key 算法一致
 */
function log_normalize_image($message, $data = null) {
    $logFile = __DIR__ . '/normalize_image_url.log';
    $time = date('Y-m-d H:i:s');

    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $message .= ' | ' . $data;
    }

    file_put_contents($logFile, "[$time] $message\n", FILE_APPEND);
}

function normalize_image_url_for_key($imageUrl) {
    log_normalize_image('normalize_image_url_for_key input', $imageUrl);

    if (empty($imageUrl)) {
        log_normalize_image('imageUrl is empty, return empty string');
        return '';
    }

    $parts = parse_url($imageUrl);
    log_normalize_image('parse_url result', $parts);

    if ($parts === false) {
        log_normalize_image('parse_url failed, return original', $imageUrl);
        return $imageUrl;
    }

    if (empty($parts['host']) || empty($parts['path'])) {
        log_normalize_image('host or path missing, return original', [
            'host' => $parts['host'] ?? '',
            'path' => $parts['path'] ?? '',
            'original' => $imageUrl
        ]);
        return $imageUrl;
    }

    $scheme = $parts['scheme'] ?? 'https';
    $result = $scheme . '://' . $parts['host'] . $parts['path'];

    log_normalize_image('normalized result', $result);

    return $result;
}
/**
 * 兜底：如果 key 没法提 serial，就尝试从 image_url 文件名取
 * 例如：
 * 045222827d754c1e_20260409023228.jpg -> 045222827d754c1e
 */
function derive_image_serial_from_url($imageUrl) {
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

/**
 * 统一抓取时间格式
 */
function parse_grab_time($timeText) {
    if (!empty($timeText) && strtotime($timeText) !== false) {
        return date('Y-m-d H:i:s', strtotime($timeText));
    }
    return date('Y-m-d H:i:s');
}

/**
 * archive_key 计算规则
 * 必须和 backup_device_violation_archive.php 一致
 */
function build_archive_key($imageDeviceSerial, $grabTime, $sourceType, $normalizedImageUrl) {
    return sha1($imageDeviceSerial . '|' . $grabTime . '|' . $sourceType . '|' . $normalizedImageUrl);
}


/**
 * 判断是否是用户截图/录屏上报
 */
function is_user_capture_report_reason($reason) {
    $reason = trim((string)$reason);

    return in_array($reason, [
        '用户录屏上报',
        '用户截图上报',
    ], true);
}

/**
 * 把本地备份路径转成可访问 URL
 *
 * 例如：
 * /www/wwwroot/open.kwxapp.cn/single/api/pop/review_backup/2026-04-29/xxx.jpeg
 *
 * 转成：
 * /api/pop/review_backup/2026-04-29/xxx.jpeg
 */
function convert_local_review_image_path_to_url($localPath) {
    $localPath = trim((string)$localPath);
    if ($localPath === '') {
        return '';
    }

    $localPath = str_replace('\\', '/', $localPath);

    // 如果本身已经是 URL，直接返回
    if (preg_match('#^https?://#i', $localPath)) {
        return $localPath;
    }

    $localRoot = '/www/wwwroot/open.kwxapp.cn/single';
    // 返回相对地址：在 KWX 主站和 audit-kwx 代理域下都能同源加载，且不会产生 HTTPS 混合内容。
    $publicHost = '';

    if (strpos($localPath, $localRoot) === 0) {
        $relativePath = substr($localPath, strlen($localRoot));

        if ($relativePath === '' || $relativePath[0] !== '/') {
            $relativePath = '/' . $relativePath;
        }

        return $publicHost . $relativePath;
    }

    // 兜底：只要路径里包含 /api/pop/review_backup/，也能转
    $marker = '/api/pop/review_backup/';
    $pos = strpos($localPath, $marker);
    if ($pos !== false) {
        return $publicHost . substr($localPath, $pos);
    }

    return '';
}


try {
    // 连接 Redis
    $redis->connect();
    $redis->selectDb(14);

    // 获取全部违规 key
    $keys = $redis->getAllKeys('device_violation:*');
    $violations = [];
    $venueViolationCounts = [];

    // 用于避免重复展示
    $seenOriginals = [];

    foreach ($keys as $key) {
        $violationData = $redis->get($key);
        if (!$violationData) continue;

        // 去重逻辑：同 baseKey + 同 JSON 内容，只展示一次
        $baseKey = str_replace('_predicted', '', $key);
        $seenKey = $baseKey . ':' . $violationData;
        if (isset($seenOriginals[$seenKey])) {
            continue;
        }

        $decoded = json_decode($violationData, true);
        if (!is_array($decoded)) continue;

        // 是否预测数据
        $isPredicted = str_ends_with_aaa($key, '_predicted');
        $sourceType = $isPredicted ? 'predicted' : 'original';

        // 从 Redis key 提取图传 serial
        $shortKey = str_replace(['device_violation:', '_predicted'], '', $key);
        $imageDeviceSerial = $shortKey;

        // 兜底：如果取不到，则从 image_url 文件名里取
        if (empty($imageDeviceSerial)) {
            $imageDeviceSerial = derive_image_serial_from_url($decoded['image_url'] ?? '');
        }

        if (empty($imageDeviceSerial)) {
            logMessage('SKIP_NO_SERIAL', [
                'redis_key' => $key,
                'json' => $decoded
            ]);
            continue;
        }

        logMessage('LOAD_KEY', [
            'redis_key' => $key,
            'image_device_serial' => $imageDeviceSerial
        ]);

        // 查设备 / 场地
        $bindSite = null;
        $venueName = null;
        $deviceName = null;
        $is_banned = 0;

        $sqlVehicles = "
            SELECT bind_site, is_banned, name
            FROM vehicles
            WHERE image_device_serial = ?
            LIMIT 1
        ";
        $resultVehicles = $db->query($sqlVehicles, [$imageDeviceSerial]);

        if ($resultVehicles !== false && !empty($resultVehicles)) {
            $bindSite = $resultVehicles[0]['bind_site'] ?? null;
            $is_banned = intval($resultVehicles[0]['is_banned'] ?? 0);
            $deviceName = $resultVehicles[0]['name'] ?? null;

            if (!empty($bindSite)) {
                $sqlVenues = "SELECT venue_name FROM venues WHERE id = ? LIMIT 1";
                $resultVenues = $db->query($sqlVenues, [$bindSite]);

                if ($resultVenues !== false && !empty($resultVenues)) {
                    $venueName = $resultVenues[0]['venue_name'] ?? null;
                }

                // 统计这个场地当天违规数（沿用你原来的逻辑：6点为界）
                if (!isset($venueViolationCounts[$bindSite])) {
                    $currentTime = time();
                    $today6am = strtotime('today 6:00');
                    $tomorrow6am = strtotime('tomorrow 6:00');

                    if ($currentTime < $today6am) {
                        $startTime = date('Y-m-d H:i:s', strtotime('yesterday 6:00'));
                        $endTime = date('Y-m-d H:i:s', $today6am);
                    } else {
                        $startTime = date('Y-m-d H:i:s', $today6am);
                        $endTime = date('Y-m-d H:i:s', $tomorrow6am);
                    }

                    $sqlViolationCount = "
                        SELECT COUNT(*) AS violation_count
                        FROM device_bans
                        WHERE venue_id = ?
                          AND created_at >= ?
                          AND created_at < ?
                    ";
                    $resultCount = $db->query($sqlViolationCount, [$bindSite, $startTime, $endTime]);
                    $venueViolationCounts[$bindSite] = intval($resultCount[0]['violation_count'] ?? 0);
                }
            }
        }

        // 已封禁的不返回给当前待处理页
        if ($is_banned === 1) {
            continue;
        }

        // 计算 archive_key
        $grabTime = parse_grab_time($decoded['time'] ?? '');
        $normalizedImageUrl = normalize_image_url_for_key($decoded['image_url'] ?? '');
        $archiveKey = build_archive_key(
            $imageDeviceSerial,
            $grabTime,
            $sourceType,
            $normalizedImageUrl
        );

// 用户截图/录屏上报：把 Redis 里的 OSS 图片替换成本地备份图片 URL
$reasonText = trim((string)($decoded['reason'] ?? ''));
$redisImageUrl = trim((string)($decoded['image_url'] ?? ''));

if (is_user_capture_report_reason($reasonText) && $redisImageUrl !== '') {
    $sqlArchiveImage = "
        SELECT local_image_path
        FROM device_violation_archive
        WHERE image_url = ?
        ORDER BY id DESC
        LIMIT 1
    ";

    $archiveImageRows = $db->query($sqlArchiveImage, [$redisImageUrl]);

    if ($archiveImageRows !== false && !empty($archiveImageRows)) {
        $localImagePath = trim((string)($archiveImageRows[0]['local_image_path'] ?? ''));
        $localImageUrl = convert_local_review_image_path_to_url($localImagePath);

        if ($localImageUrl !== '') {
            // 保留 Redis 原始图片，方便排查
            $decoded['redis_image_url'] = $redisImageUrl;

            // 返回本地文件路径，方便后台调试
            $decoded['local_image_path'] = $localImagePath;

            // 前端最终显示用这个
            $decoded['image_url'] = $localImageUrl;

            logMessage('USER_CAPTURE_IMAGE_REPLACED', [
                'redis_key' => $key,
                'reason' => $reasonText,
                'redis_image_url' => $redisImageUrl,
                'local_image_path' => $localImagePath,
                'final_image_url' => $localImageUrl
            ]);
        } else {
            logMessage('USER_CAPTURE_LOCAL_PATH_CONVERT_FAILED', [
                'redis_key' => $key,
                'reason' => $reasonText,
                'redis_image_url' => $redisImageUrl,
                'local_image_path' => $localImagePath
            ]);
        }
    } else {
        logMessage('USER_CAPTURE_ARCHIVE_NOT_FOUND', [
            'redis_key' => $key,
            'reason' => $reasonText,
            'redis_image_url' => $redisImageUrl
        ]);
    }
}

        // 兼容前端现有字段 + 新增字段
        $decoded['key'] = $imageDeviceSerial; // 保持你前端现有逻辑不炸
        $decoded['redis_key'] = $key;
        $decoded['archive_key'] = $archiveKey;
        $decoded['source_type'] = $sourceType;

        $decoded['source'] = $isPredicted ? '自研' : '原AI数据';
        $decoded['bind_site'] = $bindSite;
        $decoded['venue_name'] = $venueName;
        $decoded['device_name'] = $deviceName;
        $decoded['is_banned'] = $is_banned;
        $decoded['violation_count'] = $bindSite ? ($venueViolationCounts[$bindSite] ?? 0) : 0;

        // remark 保留原样，前端现在还在用它判断“自研”样式
        if (isset($decoded['remark'])) {
            $decoded['remark'] = $decoded['remark'];
        }

        $violations[] = $decoded;

        // 标记去重
        $seenOriginals[$seenKey] = true;
    }

    $db->close();
    $redis->close();

    echo json_encode([
        'success' => true,
        'violations' => $violations
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    error_log($e->getMessage());

    if ($db) {
        $db->close();
    }
    if ($redis) {
        try {
            $redis->close();
        } catch (Exception $e2) {}
    }

    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch violations: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
?>
