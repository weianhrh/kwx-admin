
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
 
$device_id = $data['device_id']; 
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

// 先默认全部是允许字段，后面遇到无权限字段再挪到 deniedPayload
$allowedPayload = $data;
$deniedPayload = [];
//  echo json_encode(array('msg' => isset($data['direction_max'])));
// 后续处理前端回传的变更参数 

if (isset($data['car_type'])) { 
    $car_type = (int) $data['car_type']; // 强制转换为整数
    $query = "UPDATE vehicle_control_settings SET car_type = ? WHERE serial_number = ?"; 
    $stmt = $database->getConnection()->prepare($query); 
    $stmt->bind_param("is", $car_type, $device_id); 
    $stmt->execute();
} 

if (isset($data['direction_mid'])) { 
    $direction_mid = (int) $data['direction_mid']; // 强制转换为整数
    $query = "UPDATE vehicle_control_settings SET direction_mid = ? WHERE serial_number = ?"; 
    $stmt = $database->getConnection()->prepare($query); 
    $stmt->bind_param("is", $direction_mid, $device_id); 
    $stmt->execute();
} 

if (isset($data['throttle_mid'])) { 
    $throttle_mid = (int) $data['throttle_mid']; // 强制转换为整数
    $query = "UPDATE vehicle_control_settings SET throttle_mid = ? WHERE serial_number = ?"; 
    $stmt = $database->getConnection()->prepare($query); 
    $stmt->bind_param("is", $throttle_mid, $device_id); 
    $stmt->execute();
}


if (isset($data['throttle_max'])) { 
    $throttle_max = (int) $data['throttle_max']; // 强制转换为整数
    $query = "UPDATE vehicle_control_settings SET throttle_max = ? WHERE serial_number = ?"; 
    $stmt = $database->getConnection()->prepare($query); 
    $stmt->bind_param("is", $throttle_max, $device_id); 
    $stmt->execute();
} 



if (isset($data['throttle_min'])) { 
    $throttle_min = (int) $data['throttle_min']; // 强制转换为整数
    $query = "UPDATE vehicle_control_settings SET throttle_min = ? WHERE serial_number = ?"; 
    $stmt = $database->getConnection()->prepare($query); 
    $stmt->bind_param("is", $throttle_min, $device_id); 
    $stmt->execute();
}

 
if (isset($data['direction'])) { 
    $direction = $data['direction']; 
    $query = "UPDATE vehicle_control_settings SET direction = ? WHERE serial_number = ?"; 
    $stmt = $database->getConnection()->prepare($query); 
    $stmt->bind_param("ss", $direction, $device_id); 
    $stmt->execute();
} 
 
if (isset($data['throttle'])) { 
    $throttle = $data['throttle']; 
    $query = "UPDATE vehicle_control_settings SET throttle = ? WHERE serial_number = ?"; 
    $stmt = $database->getConnection()->prepare($query); 
    $stmt->bind_param("ss", $throttle, $device_id); 
    $stmt->execute();
} 
if (isset($data['driver_type'])) { 
    $driver_type = (int)$data['driver_type']; 
    $query = "UPDATE vehicle_control_settings SET driver_type = ? WHERE serial_number = ?"; 
    $stmt = $database->getConnection()->prepare($query); 
    $stmt->bind_param("is", $driver_type, $device_id); 
    $stmt->execute();
} 

if (isset($data['is_display'])) { 
    $is_display = $data['is_display']; 
    $query = "UPDATE vehicle_control_settings SET is_display = ? WHERE serial_number = ?"; 
    $stmt = $database->getConnection()->prepare($query); 
    $stmt->bind_param("is", $is_display, $device_id); 
    $stmt->execute();
} 
if (isset($data['cooldown'])) { 
    $cooldown = $data['cooldown']; 
    $query = "UPDATE vehicle_control_settings SET cooldown = ? WHERE serial_number = ?"; 
    $stmt = $database->getConnection()->prepare($query); 
    $stmt->bind_param("is", $cooldown, $device_id); 
    $stmt->execute();
} 
if (isset($data['bullet_channel'])) { 
    $bullet_channel = $data['bullet_channel']; 
    $query = "UPDATE vehicle_control_settings SET bullet_channel = ? WHERE serial_number = ?"; 
    $stmt = $database->getConnection()->prepare($query); 
    $stmt->bind_param("ss", $bullet_channel, $device_id); 
    $stmt->execute();
} 

if (isset($data['audio_source_type'])) {
    if (in_array((int)$role_id, [1, 2], true)) {
        $audio_source_type = (int)$data['audio_source_type'];

        if (in_array($audio_source_type, [0, 1], true)) {
            $query = "UPDATE vehicle_control_settings SET audio_source_type = ? WHERE serial_number = ?";
            $stmt = $database->getConnection()->prepare($query);
            $stmt->bind_param("is", $audio_source_type, $device_id);
            $stmt->execute();
        }
    } else {
        $deniedPayload['audio_source_type'] = [
            'value' => $data['audio_source_type'],
            'reason' => '无权限修改音频来源'
        ];
        unset($allowedPayload['audio_source_type']);
    }
}
if (isset($data['is_show_achievements'])) {
    if (in_array((int)$role_id, [1, 2], true)) {
        $is_show_achievements = ((int)$data['is_show_achievements'] === 1) ? 1 : 0;

        $query = "UPDATE vehicle_control_settings 
                  SET is_show_achievements = ? 
                  WHERE serial_number = ?";
        $stmt = $database->getConnection()->prepare($query);
        $stmt->bind_param("is", $is_show_achievements, $device_id);
        $stmt->execute();
    } else {
        $deniedPayload['is_show_achievements'] = [
            'value' => $data['is_show_achievements'],
            'reason' => '无权限修改驾驶页成绩展示'
        ];
        unset($allowedPayload['is_show_achievements']);
    }
}
/*
$channels = ['ch1','ch2','ch3','ch4','ch5','ch6'];

foreach ($channels as $ch) {
    $status_key = $ch . '_status';
    $max_key    = $ch . '_max';
    $min_key    = $ch . '_min';
    $mid_key    = $ch . '_mid';

    if (isset($data[$status_key]) || isset($data[$max_key]) || isset($data[$min_key]) || isset($data[$mid_key])) {

        $query = "SELECT `$ch` FROM vehicle_control_settings WHERE serial_number = ?";
        $stmt  = $database->getConnection()->prepare($query);
        $stmt->bind_param("s", $device_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $original = trim($row[$ch] ?? '');

            $old = parseChannelValue($original, $ch);

            $newStatus = isset($data[$status_key]) ? (($data[$status_key] === 'true') ? 'true' : 'false') : $old['status'];
            $newMax    = isset($data[$max_key]) ? (int)$data[$max_key] : $old['max'];
            $newMin    = isset($data[$min_key]) ? (int)$data[$min_key] : $old['min'];
            $newMid    = isset($data[$mid_key]) ? (int)$data[$mid_key] : $old['mid'];

            // 合法性夹紧
            $newMin = max(800, min($newMin, 2499));
            $newMax = max($newMin + 1, min($newMax, 2500));
            $newMid = max($newMin, min($newMid, $newMax));

            $newRaw = buildChannelValue($old['label'], $newMin, $newMax, $newStatus, $newMid);

            $new = [
                'raw'    => $newRaw,
                'label'  => $old['label'],
                'min'    => $newMin,
                'max'    => $newMax,
                'status' => $newStatus,
                'mid'    => $newMid,
            ];

            $update = $database->getConnection()->prepare(
                "UPDATE vehicle_control_settings SET `$ch` = ? WHERE serial_number = ?"
            );
            $update->bind_param("ss", $newRaw, $device_id);
            $update->execute();

            logChannelDiff($device_id, $ch, $old, $new, $update->affected_rows);
            logMessage("-----------结束本轮日志记录-----------");
        } else {
            logMessage("未找到设备 {$device_id} 的字段 {$ch}");
        }
    }
}
*/
$isAdmin = in_array((int)$role_id, [1, 2], true);
$isRole3 = ((int)$role_id === 3 || (int)$role_id === 4);
$oldCarType = (int)($settings['car_type'] ?? 0);

// ✅ RC：管理员都能改；加盟商 role=3 只有 7/9 类型车能改 CH
$canEditChannels = $isAdmin || ($isRole3 && in_array($oldCarType, [7, 9], true));

$channels = ['ch1','ch2','ch3','ch4','ch5','ch6'];

foreach ($channels as $ch) {
    $status_key = $ch . '_status';
    $max_key    = $ch . '_max';
    $min_key    = $ch . '_min';
    $mid_key    = $ch . '_mid';

    $hasStatus = array_key_exists($status_key, $data);
    $hasMax    = array_key_exists($max_key, $data);
    $hasMin    = array_key_exists($min_key, $data);
    $hasMid    = array_key_exists($mid_key, $data);

    if (!$hasStatus && !$hasMax && !$hasMin && !$hasMid) {
        continue;
    }

    // ✅ 后台兜底：没权限就不更新，并写进 deniedPayload
    if (!$canEditChannels) {
        foreach ([$status_key, $max_key, $min_key, $mid_key] as $key) {
            if (array_key_exists($key, $data)) {
                $deniedPayload[$key] = [
                    'value' => $data[$key],
                    'reason' => '无权限修改通道配置'
                ];
                unset($allowedPayload[$key]);
            }
        }

        logMessage("跳过 {$ch}：role_id={$role_id} 无权限修改通道配置");
        continue;
    }

    $query = "SELECT `$ch` FROM vehicle_control_settings WHERE serial_number = ?";
    $stmt  = $database->getConnection()->prepare($query);
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$row = $result->fetch_assoc()) {
        logMessage("未找到设备 {$device_id} 的字段 {$ch}");
        continue;
    }

    $original = trim($row[$ch] ?? '');
    $old = parseChannelValue($original, $ch);

    $newStatus = $hasStatus ? (($data[$status_key] === 'true') ? 'true' : 'false') : $old['status'];
    $newMax    = $hasMax ? (int)$data[$max_key] : $old['max'];
    $newMin    = $hasMin ? (int)$data[$min_key] : $old['min'];
    $newMid    = $hasMid ? (int)$data[$mid_key] : $old['mid'];

    // ✅ 只有真正变化才更新，单纯保存不重写
    $actuallyChanged =
        ($hasStatus && $newStatus !== $old['status']) ||
        ($hasMax && $newMax !== (int)$old['max']) ||
        ($hasMin && $newMin !== (int)$old['min']) ||
        ($hasMid && $newMid !== (int)$old['mid']);

    if (!$actuallyChanged) {
        logMessage("跳过 {$ch}：提交值与原值一致，避免无意义重写");
        continue;
    }

    // ✅ 只改启用/禁用时，保留原来的 2000-1000 和 mid，不做夹紧
    if ($hasStatus && !$hasMax && !$hasMin && !$hasMid) {
        $newRaw = buildChannelValue($old['label'], $old['min'], $old['max'], $newStatus, $old['mid']);
    } else {
        // ✅ 真正改数值时，只限制范围，不强制 min < max
        // 防止 ch2#2000-1000 被自动改成 ch2#2000-2001
        $newMin = max(800, min($newMin, 2500));
        $newMax = max(800, min($newMax, 2500));
        $newMid = max(800, min($newMid, 2500));

        $newRaw = buildChannelValue($old['label'], $newMin, $newMax, $newStatus, $newMid);
    }

    if ($newRaw === $original) {
        logMessage("跳过 {$ch}：最终值未变化");
        continue;
    }

    $new = parseChannelValue($newRaw, $ch);

    $update = $database->getConnection()->prepare(
        "UPDATE vehicle_control_settings SET `$ch` = ? WHERE serial_number = ?"
    );
    $update->bind_param("ss", $newRaw, $device_id);
    $update->execute();

    logChannelDiff($device_id, $ch, $old, $new, $update->affected_rows);
}


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