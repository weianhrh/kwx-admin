<?php
// open.kwxapp.cn/single/api/vehicle/modifyDevice.php
require_once '../Database.php';
require_once '../RedisHelper.php';

$database = new Database();
$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3); // 专用审核 Redis DB

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

$role_id = $user['role_id'];
$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['device_id']) || !isset($data['name']) || !isset($data['share_name']) || !isset($data['image_device_serial']) || !isset($data['sharing_status'])) {
    echo json_encode(['code' => 1006, 'msg' => '缺少必要参数', 'data' => []]);
    exit;
}

$device_id = $data['device_id'];
$name = $data['name'];
$share_name = $data['share_name'];
$image_device_serial = $data['image_device_serial'];
$bk_image_device_serial = $data['bk_image_device_serial'] ?? '';
$sharing_status = $data['sharing_status'];
$incoming_status = trim((string)($data['status'] ?? ''));
// $photo_url = $data['photo_url'] ?? '';
// $status = $data['status'];
// 获取旧值
$deviceInfo = $database->query("SELECT name,share_name,photo_url,image_device_serial,bk_image_device_serial,is_banned,status FROM vehicles WHERE serial_number = ?", [$device_id]);

if (!$deviceInfo) {
    echo json_encode(['code' => 1007, 'msg' => '未找到设备信息', 'data' => []]);
    exit;
}
$old_name = $deviceInfo[0]['name'];
$old_share_name = $deviceInfo[0]['share_name'];
$old_image_device_serial = $deviceInfo[0]['image_device_serial'];
$old_bk_image_device_serial = $deviceInfo[0]['bk_image_device_serial'];
$old_photo_url = $deviceInfo[0]['photo_url'];

$old_status = trim((string)($deviceInfo[0]['status'] ?? '离线'));
if ($old_status !== '在线' && $old_status !== '离线') {
    $old_status = '离线';
}

// role_id 1 和 2 允许修改设备在线/离线状态
if (in_array((int)$role_id, [1, 2], true)) {
    $status = in_array($incoming_status, ['在线', '离线'], true) ? $incoming_status : $old_status;
} else {
    $status = $old_status;
}
// ✅ 权限：只有 role_id 为 1 或 2 才允许改 photo_url
if ($role_id == 1 || $role_id == 2) {
    $photo_url = isset($data['photo_url']) ? trim((string)$data['photo_url']) : $old_photo_url;
    if ($photo_url === '') $photo_url = $old_photo_url; // 防止传空覆盖
} else {
    // role_id=3（或其它）一律不允许改
    $photo_url = $old_photo_url;
}

if (intval($deviceInfo[0]['is_banned']) === 1) {
    echo json_encode(['code' => 403, 'msg' => '该设备已被封禁，无法修改信息', 'data' => []]);
    $database->close();
    exit;
}
$oldImageDeviceInfo = findDeviceInformationById($database, (string)$old_image_device_serial);
$resolvedFrontImage = null;

// RC 保持原权限：仅 role_id == 1 可改图传
if ((int)$role_id !== 1) {
    $image_device_serial = $old_image_device_serial;
    $bk_image_device_serial = $old_bk_image_device_serial;

    if (!empty($oldImageDeviceInfo)) {
        $resolvedFrontImage = [
            'id' => (string)$oldImageDeviceInfo['id'],
            'room_id' => (string)($oldImageDeviceInfo['room_id'] ?? ''),
            'matched_by' => 'id'
        ];
    }
} else {
    $input_image_device_serial = trim((string)($data['image_device_serial'] ?? ''));
    $input_bk_image_device_serial = trim((string)($data['bk_image_device_serial'] ?? ''));
    $bk_image_device_serial = $input_bk_image_device_serial;

    // 没改前图传：沿用旧值，并顺手把旧 room_id 查出来
    if ($input_image_device_serial === $old_image_device_serial) {
        $image_device_serial = $old_image_device_serial;

        if (!empty($oldImageDeviceInfo)) {
            $resolvedFrontImage = [
                'id' => (string)$oldImageDeviceInfo['id'],
                'room_id' => (string)($oldImageDeviceInfo['room_id'] ?? ''),
                'matched_by' => 'id'
            ];
        }
    } else {
        // 改了前图传：优先按 device_information.id / room_id 解析
        $resolvedFrontImage = resolveImageDeviceSerial($database, $input_image_device_serial);

        if (!empty($resolvedFrontImage['ok'])) {
            // 查到了：统一落库成真正的 id
            $image_device_serial = (string)$resolvedFrontImage['id'];
        } else {
            // 查不到：保持 RC 旧逻辑，原样保存用户输入
            $image_device_serial = $input_image_device_serial;
            $resolvedFrontImage = null;
        }
    }
}

// ========== 敏感词加载 ==========
function loadSensitiveWords($filePath = '../sensitive_words.json') {
    return file_exists($filePath) ? json_decode(file_get_contents($filePath), true) : [];
}
function containsSensitiveWords($text, $words) {
    $text = trim((string)$text);

    foreach ($words as $word) {
        $word = trim((string)$word);

        if ($word === '') {
            continue;
        }

        // 跳过单字，避免“红色”“白色”误伤
        if (mb_strlen($word, 'UTF-8') < 2) {
            continue;
        }

        if (mb_strpos($text, $word, 0, 'UTF-8') !== false) {
            return ['pass' => false, 'msg' => "包含敏感词：{$word}"];
        }
    }

    return ['pass' => true];
}

$words = loadSensitiveWords();
$is_sensitive = false;
$need_audit = false;

// ========== 名称审核处理 ==========
function submitAudit($redis, $device_id, $field, $old, $new) {
    $auditKey = "vehicle_name_audit:{$device_id}:{$field}";
    $auditData = [
        'device_id' => $device_id,
        'field' => $field,
        'old' => $old,
        'new' => $new,
        'status' => 'pending',
        'reason' => '',
        'timestamp' => time()
    ];
    $redis->save($auditKey, json_encode($auditData), 86400);

    // 加入审核池
    $reflection = new ReflectionClass($redis);
    $property = $reflection->getProperty('redis');
    $property->setAccessible(true);
    $nativeRedis = $property->getValue($redis);
    $nativeRedis->sAdd('vehicle_name_audit_pool', $auditKey);
}
function resolveImageDeviceSerial(Database $database, string $inputValue): array {
    $inputValue = trim($inputValue);

    if ($inputValue === '') {
        return [
            'ok' => false,
            'msg' => '前图传不能为空'
        ];
    }

    $byId = $database->query(
        "SELECT id, room_id FROM device_information WHERE CAST(id AS CHAR) = ? LIMIT 1",
        [$inputValue]
    );

    if (!empty($byId)) {
        return [
            'ok' => true,
            'id' => (string)$byId[0]['id'],
            'room_id' => (string)$byId[0]['room_id'],
            'matched_by' => 'id'
        ];
    }

    $byRoomId = $database->query(
        "SELECT id, room_id FROM device_information WHERE CAST(room_id AS CHAR) = ? LIMIT 1",
        [$inputValue]
    );

    if (!empty($byRoomId)) {
        return [
            'ok' => true,
            'id' => (string)$byRoomId[0]['id'],
            'room_id' => (string)$byRoomId[0]['room_id'],
            'matched_by' => 'room_id'
        ];
    }

    return [
        'ok' => false,
        'msg' => "未在 device_information 的 id 或 room_id 中匹配到 {$inputValue}"
    ];
}

function findDeviceInformationById(Database $database, string $id): ?array {
    $id = trim($id);

    if ($id === '' || !preg_match('/^\d+$/', $id)) {
        return null;
    }

    $rows = $database->query(
        "SELECT id, room_id FROM device_information WHERE id = ? LIMIT 1",
        [(int)$id]
    );

    return !empty($rows) ? $rows[0] : null;
}
// name 字段变更
if ($name !== $old_name) {
    $check = containsSensitiveWords($name, $words);
    if (!$check['pass']) {
        $is_sensitive = true;
        $name = $old_name; // 敏感词：不提交审核，直接保留旧值
    } else {
        $need_audit = true;
        submitAudit($redis, $device_id, 'name', $old_name, $name);
        $name = $old_name; // 待审核期间仍显示旧值
    }
}

// share_name 字段变更
if ($share_name !== $old_share_name) {
    $check = containsSensitiveWords($share_name, $words);
    if (!$check['pass']) {
        $is_sensitive = true;
        $share_name = $old_share_name; // 敏感词：不提交审核
    } else {
        $need_audit = true;
        submitAudit($redis, $device_id, 'share_name', $old_share_name, $share_name);
        $share_name = $old_share_name; // 待审核期间仍显示旧值
    }
}

// 直接更新允许的字段
$updateSql = "UPDATE vehicles SET name = ?, share_name = ?, photo_url = ?, image_device_serial = ?, bk_image_device_serial = ?, sharing_status = ?, status = ? WHERE serial_number = ?";
$updateResult = $database->query(
    $updateSql,
    [$name, $share_name, $photo_url, $image_device_serial, $bk_image_device_serial, $sharing_status, $status, $device_id],
    true
);

// $needSyncStatusRedis = in_array((int)$role_id, [1, 2], true)
//     && in_array($incoming_status, ['在线', '离线'], true);
// 只有 role_id 1/2 主动修改了 在线/离线，并且新旧状态不一样，才同步 Redis
$needSyncStatusRedis = in_array((int)$role_id, [1, 2], true)
    && in_array($incoming_status, ['在线', '离线'], true)
    && $status !== $old_status;

if ($updateResult !== false && $needSyncStatusRedis) {
    try {
        $statusRedis = new RedisHelper();
        $statusRedis->connect();
        $statusRedis->selectDb(4); // 在线状态库

    if ($status === '在线') {
        // 只有“离线 -> 在线”才走到这里：
        // 取 Redis 旧值，存在就保留原内容，然后设为永久
            $oldRedisValue = $statusRedis->get($device_id);

            $redisPayload = [
                'serial_number' => $device_id,
                'voltage' => 7.2,
                'longitude' => 0.0,
                'latitude' => 0.0,
                'speed' => 0.0
            ];

            if ($oldRedisValue) {
                $decoded = json_decode($oldRedisValue, true);
                if (is_array($decoded)) {
                    $redisPayload = array_merge($redisPayload, $decoded);
                    $redisPayload['serial_number'] = $device_id;
                }
            }

            // expire=0 => 永久
            $statusRedis->set(
                $device_id,
                json_encode($redisPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                0
            );
        } else {
            // 离线：删除对应 key
            $statusRedis->delete($device_id);
        }

        $statusRedis->close();
    } catch (Exception $e) {
        echo json_encode([
            'code' => 501,
            'msg' => '设备信息已保存，但 Redis 状态同步失败：' . $e->getMessage(),
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        $database->close();
        exit;
    }
}

if ($updateResult === false) {
    echo json_encode(['code' => 500, 'msg' => '设备信息修改失败', 'data' => []]);
} elseif ($is_sensitive) {
    echo json_encode([
        'code' => 1008,
        'msg' => '含敏感词，设备名称或分享名未修改，其它信息已保存',
        'data' => []
    ]);
} elseif ($need_audit) {
    echo json_encode([
        'code' => 1009,
        'msg' => '名称或分享名已提交人工审核，其它信息修改成功。通过则默认会变成修改后的，审核未通过会在加载该页面时弹窗说明原因，请耐心等候...',
        'data' => []
    ]);
} else {
echo json_encode([
    'code' => 0,
    'msg' => '设备信息修改成功',
    'data' => [
        'photo_url' => $photo_url,
        'image_device_serial' => $image_device_serial,
        'image_device_room_id' => $resolvedFrontImage['room_id'] ?? null,
        'matched_by' => $resolvedFrontImage['matched_by'] ?? null,
        'status' => $status
    ]
], JSON_UNESCAPED_UNICODE);
}


$database->close();
