<?php
require_once '../Database.php';
require_once '../RedisHelper.php';

header('Content-Type: application/json; charset=utf-8');

$database = new Database();

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->query(
    "SELECT uid, role_id FROM admin_users WHERE session_token = ?",
    [$session_token]
);

if (!$user || !in_array((int)$user[0]['role_id'], [1, 2], true)) {
    echo json_encode(['code' => 1002, 'msg' => '权限不足'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 图片路径转成前端可访问路径
 * 数据库存的是：pending_images/venue_xxx.jpg
 * 前端真正应该访问：/api/venue/pending_images/venue_xxx.jpg
 */
function buildImageUrl($url) {
    $url = trim((string)$url);

    if ($url === '') {
        return '';
    }

    // 已经是完整地址
    if (preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }

    // 已经是绝对路径
    if (strpos($url, '/') === 0) {
        return $url;
    }

    // 数据库常见格式：pending_images/xxx.jpg
    if (strpos($url, 'pending_images/') === 0) {
        return $url;
    }

    // 兜底
    return '/api/venue/' . ltrim($url, '/');
}

/**
 * 1. 从数据库读取待审核图片
 * 注意：这里不再 scandir 目录
 */
$sql = "
    SELECT
        r.id AS review_id,
        r.venue_id,
        r.image_url,
        r.status,
        r.reason,
        r.uploaded_at,
        v.venue_name
    FROM venue_image_reviews r
    LEFT JOIN venues v ON v.id = r.venue_id
    WHERE r.status = 'pending'
    ORDER BY r.uploaded_at DESC, r.id DESC
";

$rows = $database->query($sql, []);

$results = [];

if ($rows) {
    foreach ($rows as $row) {
        $rawUrl = $row['image_url'] ?? '';

        $results[] = [
            // 兼容旧前端：原来 id 返回的是 venue_id
            'id' => (int)$row['venue_id'],

            // 新增：真正的审核记录 ID，后续审核接口建议用这个
            'review_id' => (int)$row['review_id'],

            'venue_id' => (int)$row['venue_id'],
            'venue_name' => $row['venue_name'] ?? '未知场地',

            // 前端展示用
            'image_url' => buildImageUrl($rawUrl),

            // 原始数据库路径，调试用
            'raw_image_url' => $rawUrl,

            'image_status' => $row['status'] ?? 'pending',
            'reason' => $row['reason'] ?? '',
            'upload_time' => $row['uploaded_at'] ?? ''
        ];
    }
}

/**
 * 2. Redis 里的名称 / 描述 / 设备名称审核数量，保留你原来的逻辑
 */
$nameCount = 0;
$descCount = 0;
$deviceCount = 0;

try {
    $redis = new RedisHelper();
    $redis->connect();
    $redis->selectDb(3);

    $reflection = new ReflectionClass($redis);
    $property = $reflection->getProperty('redis');
    $property->setAccessible(true);
    $nativeRedis = $property->getValue($redis);

    // 场地名称
    $nameKeys = $nativeRedis->sMembers('venue_name_audit_pool');
    foreach ($nameKeys as $key) {
        $data = $redis->get($key);
        $json = json_decode($data, true);
        if ($json && ($json['status'] ?? '') === 'pending') {
            $nameCount++;
        }
    }

    // 场地描述
    $descKeys = $nativeRedis->sMembers('venue_description_audit_pool');
    foreach ($descKeys as $key) {
        $data = $redis->get($key);
        $json = json_decode($data, true);
        if ($json && ($json['status'] ?? '') === 'pending') {
            $descCount++;
        }
    }

    // 设备名称 / 分享名称
    $deviceKeys = $nativeRedis->sMembers('vehicle_name_audit_pool');
    foreach ($deviceKeys as $key) {
        $data = $redis->get($key);
        $json = json_decode($data, true);
        if ($json && ($json['status'] ?? '') === 'pending') {
            $deviceCount++;
        }
    }

} catch (Throwable $e) {
    // Redis 出问题时，不影响图片审核列表
    $nameCount = 0;
    $descCount = 0;
    $deviceCount = 0;
}

$total = $nameCount + $descCount + $deviceCount;

echo json_encode([
    'code' => 0,
    'msg' => '获取成功',
    'count' => count($results) + $total,
    'image_count' => count($results),
    'name_count' => $nameCount,
    'desc_count' => $descCount,
    'device_count' => $deviceCount,
    'data' => $results
], JSON_UNESCAPED_UNICODE);