<?php 
// /api/vehicle/modifDevicecfg.php

require_once '../Database.php';  
header('Content-Type: application/json; charset=utf-8');
 function logMessage($message) {
    $logFile = __DIR__ . '/operation_debug_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function parseChannelValue($raw, $defaultLabel = 'ch') {
    $raw = trim((string)$raw);
    $parts = explode('#', $raw);

    $label = $parts[0] ?? $defaultLabel;
    $range = isset($parts[1]) ? explode('-', $parts[1]) : ['1000', '2000'];

    return [
        'raw'    => $raw,
        'label'  => $label,
        'min'    => (int)($range[0] ?? 1000),
        'max'    => (int)($range[1] ?? 2000),
        'status' => (($parts[2] ?? 'false') === 'true') ? 'true' : 'false',
        'mid'    => (int)($parts[3] ?? 1500),
    ];
}

function buildChannelValue($label, $min, $max, $status, $mid) {
    return $label . '#' . $min . '-' . $max . '#' . $status . '#' . $mid;
}

function logChannelDiff($deviceId, $ch, $old, $new, $affectedRows) {
    $changed = [];

    if ($old['status'] !== $new['status']) $changed[] = 'status';
    if ((int)$old['min'] !== (int)$new['min']) $changed[] = 'min';
    if ((int)$old['max'] !== (int)$new['max']) $changed[] = 'max';
    if ((int)$old['mid'] !== (int)$new['mid']) $changed[] = 'mid';

    $changedText = empty($changed) ? '无变化' : implode(', ', $changed);

    $log = "\n"
        . "==================== {$ch} 配置变更 ====================\n"
        . "设备: {$deviceId}\n"
        . "字段: {$ch}\n"
        . "旧值raw: {$old['raw']}\n"
        . "新值raw: {$new['raw']}\n"
        . "-------------------------------------------------------\n"
        . str_pad('项目', 10) . str_pad('旧值', 12) . "新值\n"
        . "-------------------------------------------------------\n"
        . str_pad('label', 10)  . str_pad($old['label'], 12)  . $new['label'] . "\n"
        . str_pad('status', 10) . str_pad($old['status'], 12) . $new['status'] . "\n"
        . str_pad('min', 10)    . str_pad((string)$old['min'], 12) . $new['min'] . "\n"
        . str_pad('max', 10)    . str_pad((string)$old['max'], 12) . $new['max'] . "\n"
        . str_pad('mid', 10)    . str_pad((string)$old['mid'], 12) . $new['mid'] . "\n"
        . "-------------------------------------------------------\n"
        . "变化项: {$changedText}\n"
        . "affected_rows: {$affectedRows}\n"
        . "=======================================================\n";

    logMessage($log);
}
function jsonText($value) {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function getClientIp() {
    $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];

    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            return substr($ip, 0, 64);
        }
    }

    return '';
}

function getOperatorId($user) {
    foreach (['id', 'uid', 'user_id'] as $key) {
        if (isset($user[$key])) {
            return (int)$user[$key];
        }
    }

    return 0;
}

function getOperatorName($user) {
    foreach (['username', 'nickname', 'name', 'account', 'phone'] as $key) {
        if (!empty($user[$key])) {
            return (string)$user[$key];
        }
    }

    return '';
}

function fetchVehicleControlSettings($database, $device_id) {
    $query = "
        SELECT 
            audio_source_type,
            is_show_achievements,
            is_display,
            cooldown,
            bullet_channel,
            ch1,
            ch2,
            ch3,
            ch4,
            ch5,
            ch6,
            car_type,
            direction_mid,
            throttle_mid,
            driver_type,
            throttle_max,
            throttle_min,
            direction,
            throttle
        FROM vehicle_control_settings
        WHERE serial_number = ?
        LIMIT 1
    ";

    $stmt = $database->getConnection()->prepare($query);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        return null;
    }

    return $result->fetch_assoc();
}

function buildChanges($before, $after) {
    $changes = [];

    if (!$before || !$after) {
        return $changes;
    }

    foreach ($after as $field => $newValue) {
        $oldValue = array_key_exists($field, $before) ? $before[$field] : null;

        if ((string)$oldValue !== (string)$newValue) {
            $changes[$field] = [
                'old' => $oldValue,
                'new' => $newValue
            ];
        }
    }

    return $changes;
}

function insertVehicleCfgOperationLog(
    $database,
    $request_id,
    $device_id,
    $user,
    $role_id,
    $submittedPayload,
    $allowedPayload,
    $deniedPayload,
    $beforeSettings,
    $afterSettings,
    $changes,
    $result_code,
    $result_msg
) {
    $conn = $database->getConnection();

    $action = 'save_config_rc';
    $operator_id = getOperatorId($user);
    $operator_name = getOperatorName($user);
    $role_id_int = (int)$role_id;
    $ip = getClientIp();
    $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

    $submitted_json = jsonText($submittedPayload);
    $allowed_json = jsonText($allowedPayload);
    $denied_json = jsonText($deniedPayload);
    $before_json = jsonText($beforeSettings);
    $after_json = jsonText($afterSettings);
    $changes_json = jsonText($changes);
    $changed_count = count($changes);
    $result_code_int = (int)$result_code;

    $sql = "
        INSERT INTO vehicle_control_settings_operation_logs (
            request_id,
            serial_number,
            action,
            operator_id,
            operator_name,
            role_id,
            ip,
            user_agent,
            submitted_json,
            allowed_json,
            denied_json,
            before_json,
            after_json,
            changes_json,
            changed_count,
            result_code,
            result_msg
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        logMessage("写入配置操作日志失败：prepare失败 " . $conn->error);
        return 0;
    }

    $stmt->bind_param(
        "sssisissssssssiis",
        $request_id,
        $device_id,
        $action,
        $operator_id,
        $operator_name,
        $role_id_int,
        $ip,
        $user_agent,
        $submitted_json,
        $allowed_json,
        $denied_json,
        $before_json,
        $after_json,
        $changes_json,
        $changed_count,
        $result_code_int,
        $result_msg
    );

    if (!$stmt->execute()) {
        logMessage("写入配置操作日志失败：" . $stmt->error);
        return 0;
    }

    return $stmt->insert_id;
}
// 创建数据库连接 
$database = new Database(); 
 
// 从会话中获取 session_token 
$session_token = $_COOKIE['session_token'] ?? null; 
if (!$session_token) { 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]); 
    exit; 
} 
 
// 验证 session_token 并获取用户信息 
$user = $database->getUserBySessionToken($session_token); 
if (!$user || !$user['role_id']) { 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]); 
    exit; 
} 
 
$role_id = $user['role_id']; 
 
// 获取前端提交的 device.serial_number  
$input = file_get_contents('php://input'); 
$data = json_decode($input, true); 
logMessage("-----------进入日志记录-----------");
logMessage("收到前端数据：" . json_encode($data));

if (!$data) { 
    echo json_encode(['code' => 1005, 'msg' => '请求数据格式错误', 'data' => []]); 
    exit; 
} 
 
$device_id = trim((string)($data['device_id'] ?? ''));
if ($device_id === '') {
    echo json_encode(['code' => 1008, 'msg' => '缺少设备序列号', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
} 
//  var_dump($data);
// 查询默认的挡位,方向和油门值 
$query = "SELECT audio_source_type,is_show_achievements,is_display,cooldown,bullet_channel,ch1,ch2,ch3,ch4,ch5,ch6, car_type, direction_mid,throttle_mid, driver_type, throttle_max,  throttle_min, direction, throttle
          FROM vehicle_control_settings 
          WHERE serial_number = ?";
$stmt = $database->getConnection()->prepare($query); 
$stmt->bind_param("s", $device_id); 
$stmt->execute(); 
$result = $stmt->get_result(); 
 
if ($result->num_rows === 0) { 
    echo json_encode(['code' => 1006, 'msg' => '未找到对应的设备配置信息', 'data' => []]); 
    exit; 
} 
 
$settings = $result->fetch_assoc(); 
 
// 构造响应数据，默认使用数据库中的值 
$response = [ 
    'code' => 200, 
    'msg' => '成功获取设备配置信息', 
    'data' => [ 
        
        'serial_number' => $device_id,
        'car_type' => $settings['car_type'],
        'direction_mid' => $settings['direction_mid'], 
        'throttle_mid' => $settings['throttle_mid'], 
        'throttle_max' => $settings['throttle_max'], 
        'driver_type' => $settings['driver_type'],
        'throttle_min' => $settings['throttle_min'], 
        'direction' => $settings['direction'], 
        'throttle' => $settings['throttle'],
        'ch1' => $settings['ch1'],
        'ch2' => $settings['ch2'],
        'ch3' => $settings['ch3'],
        'ch4' => $settings['ch4'],
        'ch5' => $settings['ch5'],
        'ch6' => $settings['ch6'],
        
        'is_display' => $settings['is_display'],
        'is_show_achievements' => $settings['is_show_achievements'] === null ? 0 : (int)$settings['is_show_achievements'],
        'cooldown' => $settings['cooldown'],
        'bullet_channel' => $settings['bullet_channel'],
        'audio_source_type' => $settings['audio_source_type'] === null ? 0 : (int)$settings['audio_source_type']
    ] 
]; 
 
$action = $data['action'] ?? 'get';

if ($action === 'get') {
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action !== 'save') {
    echo json_encode([
        'code' => 1007,
        'msg' => '未知操作类型',
        'data' => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$request_id = date('YmdHis') . '_' . bin2hex(random_bytes(4));

// 修改前配置
$beforeSettings = $settings;
$conn = $database->getConnection();

$isAdmin = in_array((int)$role_id, [1, 2], true);
$isSiteRole = in_array((int)$role_id, [3, 4], true);
$oldCarType = (int)($settings['car_type'] ?? 0);

// ✅ 和前端保持一致：发射配置 role=1/2/3/4 可改；通道配置 role=1/2 可改，role=3/4 且旧车种 7/9 可改
$canEditShoot = $isAdmin || $isSiteRole;
$canEditChannels = $isAdmin || ($isSiteRole && in_array($oldCarType, [7, 9], true));

// ✅ allowedPayload 只记录“实际允许并准备应用”的字段，不再等于全部提交内容
$allowedPayload = [
    'action' => 'save',
    'device_id' => $device_id
];
$deniedPayload = [];
$pendingUpdates = [];

$denyField = function ($key, $reason) use (&$deniedPayload, $data) {
    if (array_key_exists($key, $data)) {
        $deniedPayload[$key] = [
            'value' => $data[$key],
            'reason' => $reason
        ];
    }
};

$addUpdate = function ($field, $value, $type, $allowed, $reason = '无权限修改该字段') use (&$pendingUpdates, &$allowedPayload, $denyField, $data, $settings) {
    if (!array_key_exists($field, $data)) {
        return;
    }

    if (!$allowed) {
        $denyField($field, $reason);
        return;
    }

    $oldValue = array_key_exists($field, $settings) ? $settings[$field] : null;

    // ✅ 后端二次兜底：提交值和库里一样就不更新
    if ((string)$oldValue === (string)$value) {
        return;
    }

    $pendingUpdates[$field] = [
        'value' => $value,
        'type'  => $type
    ];
    $allowedPayload[$field] = $value;
};

$clampInt = function ($value, $min, $max) {
    $v = (int)$value;
    return max($min, min($v, $max));
};

// ✅ 所有允许字段白名单。没在这里的字段，就算构造请求也不会进 UPDATE。
$knownKeys = [
    'action' => true,
    'device_id' => true,
];

$commonIntFields = [
    // 如果你不想让 role=3/4 改车辆类型，就把 car_type 从这里删掉，放到 $adminIntFields。
    'car_type'      => [1, 9],
    'direction_mid' => [1000, 2000],
    'throttle_max'  => [1500, 2000],
    'throttle_min'  => [1000, 1500],
];

foreach ($commonIntFields as $field => $range) {
    $knownKeys[$field] = true;
    if (array_key_exists($field, $data)) {
        $addUpdate($field, $clampInt($data[$field], $range[0], $range[1]), 'i', true);
    }
}

foreach (['direction', 'throttle'] as $field) {
    $knownKeys[$field] = true;
    if (array_key_exists($field, $data)) {
        $value = ($data[$field] === 'true') ? 'true' : 'false';
        $addUpdate($field, $value, 's', true);
    }
}

$adminIntFields = [
    'throttle_mid' => [1000, 2000],
    'driver_type'  => [0, 1],
];

foreach ($adminIntFields as $field => $range) {
    $knownKeys[$field] = true;
    if (array_key_exists($field, $data)) {
        $addUpdate($field, $clampInt($data[$field], $range[0], $range[1]), 'i', $isAdmin, '仅平台管理员可修改该字段');
    }
}

foreach (['is_display'] as $field) {
    $knownKeys[$field] = true;
    if (array_key_exists($field, $data)) {
        $value = ((int)$data[$field] === 1) ? 1 : 0;
        $addUpdate($field, $value, 'i', $canEditShoot, '无权限修改发射展示配置');
    }
}

$knownKeys['cooldown'] = true;
if (array_key_exists('cooldown', $data)) {
    $addUpdate('cooldown', $clampInt($data['cooldown'], 100, 500), 'i', $canEditShoot, '无权限修改冷却时间');
}

$knownKeys['bullet_channel'] = true;
if (array_key_exists('bullet_channel', $data)) {
    $bullet_channel = strtoupper(trim((string)$data['bullet_channel']));
    if (!in_array($bullet_channel, ['C1', 'C2', 'C3', 'C4', 'C5', 'C6'], true)) {
        $denyField('bullet_channel', '子弹发射通道值非法');
    } else {
        $addUpdate('bullet_channel', $bullet_channel, 's', $canEditShoot, '无权限修改子弹发射通道');
    }
}

$knownKeys['audio_source_type'] = true;
if (array_key_exists('audio_source_type', $data)) {
    $audio_source_type = ((int)$data['audio_source_type'] === 1) ? 1 : 0;
    $addUpdate('audio_source_type', $audio_source_type, 'i', $isAdmin, '仅平台管理员可修改音频来源');
}

$knownKeys['is_show_achievements'] = true;
if (array_key_exists('is_show_achievements', $data)) {
    $is_show_achievements = ((int)$data['is_show_achievements'] === 1) ? 1 : 0;
    $addUpdate('is_show_achievements', $is_show_achievements, 'i', $isAdmin, '仅平台管理员可修改驾驶页成绩展示');
}

$channels = ['ch1','ch2','ch3','ch4','ch5','ch6'];
$channelUpdates = [];

foreach ($channels as $ch) {
    $status_key = $ch . '_status';
    $max_key    = $ch . '_max';
    $min_key    = $ch . '_min';
    $mid_key    = $ch . '_mid';

    foreach ([$status_key, $max_key, $min_key, $mid_key] as $key) {
        $knownKeys[$key] = true;
    }

    $hasStatus = array_key_exists($status_key, $data);
    $hasMax    = array_key_exists($max_key, $data);
    $hasMin    = array_key_exists($min_key, $data);
    $hasMid    = array_key_exists($mid_key, $data);

    if (!$hasStatus && !$hasMax && !$hasMin && !$hasMid) {
        continue;
    }

    if (!$canEditChannels) {
        foreach ([$status_key, $max_key, $min_key, $mid_key] as $key) {
            if (array_key_exists($key, $data)) {
                $denyField($key, '无权限修改通道配置');
            }
        }
        continue;
    }

    $original = trim((string)($settings[$ch] ?? ''));
    $old = parseChannelValue($original, $ch);

    $newStatus = $hasStatus ? (($data[$status_key] === 'true') ? 'true' : 'false') : $old['status'];
    $newMax    = $hasMax ? (int)$data[$max_key] : $old['max'];
    $newMin    = $hasMin ? (int)$data[$min_key] : $old['min'];
    $newMid    = $hasMid ? (int)$data[$mid_key] : $old['mid'];

    $actuallyChanged =
        ($hasStatus && $newStatus !== $old['status']) ||
        ($hasMax && $newMax !== (int)$old['max']) ||
        ($hasMin && $newMin !== (int)$old['min']) ||
        ($hasMid && $newMid !== (int)$old['mid']);

    if (!$actuallyChanged) {
        continue;
    }

    // 只改启用/禁用时，保留原 min/max/mid；改数值时只做范围限制，不强制 min < max。
    if ($hasStatus && !$hasMax && !$hasMin && !$hasMid) {
        $newRaw = buildChannelValue($old['label'], $old['min'], $old['max'], $newStatus, $old['mid']);
    } else {
        $newMin = max(800, min($newMin, 2500));
        $newMax = max(800, min($newMax, 2500));
        $newMid = max(800, min($newMid, 2500));
        $newRaw = buildChannelValue($old['label'], $newMin, $newMax, $newStatus, $newMid);
    }

    if ($newRaw === $original) {
        continue;
    }

    $channelUpdates[$ch] = [
        'old' => $old,
        'new' => parseChannelValue($newRaw, $ch),
        'raw' => $newRaw
    ];
    $allowedPayload[$ch] = $newRaw;
}

// ✅ 未知字段直接拒绝，避免构造请求顺手改库
foreach ($data as $key => $value) {
    if (!isset($knownKeys[$key]) && !isset($deniedPayload[$key])) {
        $deniedPayload[$key] = [
            'value' => $value,
            'reason' => '字段不在白名单，已忽略'
        ];
    }
}

try {
    $conn->begin_transaction();

    foreach ($pendingUpdates as $field => $item) {
        // 字段名来自上面的白名单，不能直接使用用户提交的字段名。
        $query = "UPDATE vehicle_control_settings SET `$field` = ? WHERE serial_number = ?";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("prepare失败：{$field}，" . $conn->error);
        }

        if ($item['type'] === 'i') {
            $value = (int)$item['value'];
            $stmt->bind_param("is", $value, $device_id);
        } else {
            $value = (string)$item['value'];
            $stmt->bind_param("ss", $value, $device_id);
        }

        if (!$stmt->execute()) {
            throw new Exception("更新失败：{$field}，" . $stmt->error);
        }
    }

    foreach ($channelUpdates as $ch => $info) {
        $query = "UPDATE vehicle_control_settings SET `$ch` = ? WHERE serial_number = ?";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("prepare失败：{$ch}，" . $conn->error);
        }

        $newRaw = $info['raw'];
        $stmt->bind_param("ss", $newRaw, $device_id);

        if (!$stmt->execute()) {
            throw new Exception("更新失败：{$ch}，" . $stmt->error);
        }

        logChannelDiff($device_id, $ch, $info['old'], $info['new'], $stmt->affected_rows);
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();

    $afterSettings = fetchVehicleControlSettings($database, $device_id) ?: [];
    $changes = buildChanges($beforeSettings, $afterSettings);

    $log_id = insertVehicleCfgOperationLog(
        $database,
        $request_id,
        $device_id,
        $user,
        $role_id,
        $data,
        $allowedPayload,
        $deniedPayload,
        $beforeSettings,
        $afterSettings,
        $changes,
        500,
        '设备信息修改失败：' . $e->getMessage()
    );

    echo json_encode([
        'code' => 500,
        'msg'  => '设备信息修改失败：' . $e->getMessage(),
        'data' => [
            'serial_number' => $device_id,
            'action' => 'save',
            'log_id' => $log_id,
            'changed_count' => count($changes)
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

logMessage("请求 {$request_id} 实际应用字段：" . jsonText($allowedPayload));
logMessage("请求 {$request_id} 拒绝/忽略字段：" . jsonText($deniedPayload));

// 修改后重新查询一次配置
$afterSettings = fetchVehicleControlSettings($database, $device_id);

if (!$afterSettings) {
    $afterSettings = [];
}

// 对比修改前 / 修改后
$changes = buildChanges($beforeSettings, $afterSettings);

// 写入透明操作日志
$log_id = insertVehicleCfgOperationLog(
    $database,
    $request_id,
    $device_id,
    $user,
    $role_id,
    $data,
    $allowedPayload,
    $deniedPayload,
    $beforeSettings,
    $afterSettings,
    $changes,
    200,
    '设备信息修改成功'
);

echo json_encode([
    'code' => 200,
    'msg'  => '设备信息修改成功',
    'data' => [
        'serial_number' => $device_id,
        'action' => 'save',
        'log_id' => $log_id,
        'changed_count' => count($changes)
    ]
], JSON_UNESCAPED_UNICODE);
exit;
?> 