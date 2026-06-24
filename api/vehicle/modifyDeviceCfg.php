<?php
require_once '../Database.php';

function logMessage($message) {
    $logFile = __DIR__ . '/operation_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function jerr($code, $msg) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();

// session
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) jerr(1001, '用户未登录或会话已过期');

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) jerr(1001, '用户未登录或无权访问');

$role_id = (int)$user['role_id'];

// input
$input = file_get_contents('php://input');
$data = json_decode($input, true);
logMessage("收到前端数据：" . json_encode($data, JSON_UNESCAPED_UNICODE));

if (!$data || empty($data['device_id'])) jerr(1005, '请求数据格式错误');

$device_id = trim($data['device_id']);

// ====== 先查一遍原始设置（给通道更新用）======
$selectSql = "SELECT ch1,ch2,ch3,ch4,ch5,ch6,
                     car_type, direction_mid, throttle_mid, throttle_max, throttle_min,
                     direction, throttle,
                     driver_type, is_display, cooldown, bullet_channel
              FROM vehicle_control_settings
              WHERE serial_number = ?";
$stmt = $database->getConnection()->prepare($selectSql);
$stmt->bind_param("s", $device_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) jerr(1006, '未找到对应的设备配置信息');
$settings = $result->fetch_assoc();

// ====== UPDATE（如果有传就更新）======

// 一些小工具：限制范围
$clamp = function($v, $min, $max) {
    $v = (int)$v;
    if ($v < $min) $v = $min;
    if ($v > $max) $v = $max;
    return $v;
};

// 普通字段
if (isset($data['car_type'])) {
    $car_type = (int)$data['car_type'];
    $q = "UPDATE vehicle_control_settings SET car_type = ? WHERE serial_number = ?";
    $st = $database->getConnection()->prepare($q);
    $st->bind_param("is", $car_type, $device_id);
    $st->execute();
}

if (isset($data['direction_mid'])) {
    $direction_mid = $clamp($data['direction_mid'], 900, 2100);
    $q = "UPDATE vehicle_control_settings SET direction_mid = ? WHERE serial_number = ?";
    $st = $database->getConnection()->prepare($q);
    $st->bind_param("is", $direction_mid, $device_id);
    $st->execute();
}

if (isset($data['throttle_mid'])) {
    $throttle_mid = $clamp($data['throttle_mid'], 900, 2100);
    $q = "UPDATE vehicle_control_settings SET throttle_mid = ? WHERE serial_number = ?";
    $st = $database->getConnection()->prepare($q);
    $st->bind_param("is", $throttle_mid, $device_id);
    $st->execute();
}

if (isset($data['throttle_max'])) {
    $throttle_max = $clamp($data['throttle_max'], 1500, 2000);
    $q = "UPDATE vehicle_control_settings SET throttle_max = ? WHERE serial_number = ?";
    $st = $database->getConnection()->prepare($q);
    $st->bind_param("is", $throttle_max, $device_id);
    $st->execute();
}

if (isset($data['throttle_min'])) {
    $throttle_min = $clamp($data['throttle_min'], 1000, 1500);
    $q = "UPDATE vehicle_control_settings SET throttle_min = ? WHERE serial_number = ?";
    $st = $database->getConnection()->prepare($q);
    $st->bind_param("is", $throttle_min, $device_id);
    $st->execute();
}

if (isset($data['direction'])) {
    $direction = ($data['direction'] === 'true') ? 'true' : 'false';
    $q = "UPDATE vehicle_control_settings SET direction = ? WHERE serial_number = ?";
    $st = $database->getConnection()->prepare($q);
    $st->bind_param("ss", $direction, $device_id);
    $st->execute();
}

if (isset($data['throttle'])) {
    $throttle = ($data['throttle'] === 'true') ? 'true' : 'false';
    $q = "UPDATE vehicle_control_settings SET throttle = ? WHERE serial_number = ?";
    $st = $database->getConnection()->prepare($q);
    $st->bind_param("ss", $throttle, $device_id);
    $st->execute();
}

// role=3 也允许改这几个（你前端规则就是这样）
if (isset($data['driver_type'])) {
    $driver_type = (int)$data['driver_type'];
    $q = "UPDATE vehicle_control_settings SET driver_type = ? WHERE serial_number = ?";
    $st = $database->getConnection()->prepare($q);
    $st->bind_param("is", $driver_type, $device_id);
    $st->execute();
}

if (isset($data['is_display'])) {
    $is_display = ((int)$data['is_display'] === 1) ? 1 : 0;
    $q = "UPDATE vehicle_control_settings SET is_display = ? WHERE serial_number = ?";
    $st = $database->getConnection()->prepare($q);
    $st->bind_param("is", $is_display, $device_id);
    $st->execute();
}

if (isset($data['cooldown'])) {
    $cooldown = $clamp($data['cooldown'], 0, 9999);
    $q = "UPDATE vehicle_control_settings SET cooldown = ? WHERE serial_number = ?";
    $st = $database->getConnection()->prepare($q);
    $st->bind_param("is", $cooldown, $device_id);
    $st->execute();
}

if (isset($data['bullet_channel'])) {
    $bullet_channel = strtoupper(trim($data['bullet_channel']));
    if (!in_array($bullet_channel, ['C1','C2','C3','C4','C5','C6'])) $bullet_channel = 'C1';
    $q = "UPDATE vehicle_control_settings SET bullet_channel = ? WHERE serial_number = ?";
    $st = $database->getConnection()->prepare($q);
    $st->bind_param("ss", $bullet_channel, $device_id);
    $st->execute();
}

// ====== 通道：同时支持 status + max/min/mid ======
$channels = ['ch1','ch2','ch3','ch4','ch5','ch6'];

foreach ($channels as $ch) {
    $status_key = $ch . '_status';
    $max_key = $ch . '_max';
    $min_key = $ch . '_min';
    $mid_key = $ch . '_mid';

    // 只有传了 status 或 max/min/mid 之一，才处理
    $has_any = isset($data[$status_key]) || isset($data[$max_key]) || isset($data[$min_key]) || isset($data[$mid_key]);
    if (!$has_any) continue;

    $original_value = trim($settings[$ch] ?? '');
    $parts = explode('#', $original_value);

    // 兜底：格式不对就给一个默认骨架，避免 explode 越界
    // 格式：label#min-max#status#mid
    if (count($parts) < 4) {
        $parts = [$ch, '1000-2000', 'false', '1500'];
    }

    // label 不让前端改（保持原样）
    $label = $parts[0];

    // 解析旧的 min/max/mid/status
    $range = explode('-', $parts[1] ?? '1000-2000');
    $oldMin = isset($range[0]) ? (int)$range[0] : 1000;
    $oldMax = isset($range[1]) ? (int)$range[1] : 2000;
    $oldStatus = ($parts[2] === 'true') ? 'true' : 'false';
    $oldMid = isset($parts[3]) ? (int)$parts[3] : 1500;

    $newStatus = $oldStatus;
    if (isset($data[$status_key])) {
        $newStatus = ($data[$status_key] === 'true') ? 'true' : 'false';
    }

    $newMin = $oldMin;
    $newMax = $oldMax;
    $newMid = $oldMid;

    if (isset($data[$min_key])) $newMin = $clamp($data[$min_key], 800, 2500);
    if (isset($data[$max_key])) $newMax = $clamp($data[$max_key], 800, 2500);
    if (isset($data[$mid_key])) $newMid = $clamp($data[$mid_key], 800, 2500);

    // 保证 min <= mid <= max（自动修正）
    if ($newMin > $newMax) { $tmp = $newMin; $newMin = $newMax; $newMax = $tmp; }
    if ($newMid < $newMin) $newMid = $newMin;
    if ($newMid > $newMax) $newMid = $newMax;

    $newValue = $label . '#' . ($newMin . '-' . $newMax) . '#' . $newStatus . '#' . $newMid;

    logMessage("通道更新 $ch: $original_value => $newValue");

    $q = "UPDATE vehicle_control_settings SET `$ch` = ? WHERE serial_number = ?";
    $st = $database->getConnection()->prepare($q);
    $st->bind_param("ss", $newValue, $device_id);
    $st->execute();
}

// ====== 更新后再查一次，返回最新数据 ======
$stmt = $database->getConnection()->prepare($selectSql);
$stmt->bind_param("s", $device_id);
$stmt->execute();
$result = $stmt->get_result();
$settings = $result->fetch_assoc();

$response = [
    'code' => 200,
    'msg'  => '成功获取设备配置信息',
    'data' => [
        'driver_type'    => (int)$settings['driver_type'],
        'is_display'     => (int)$settings['is_display'],
        'cooldown'       => (int)$settings['cooldown'],
        'bullet_channel' => $settings['bullet_channel'],

        'car_type'       => (int)$settings['car_type'],
        'direction_mid'  => (int)$settings['direction_mid'],
        'throttle_mid'   => (int)$settings['throttle_mid'],
        'throttle_max'   => (int)$settings['throttle_max'],
        'throttle_min'   => (int)$settings['throttle_min'],
        'direction'      => $settings['direction'],
        'throttle'       => $settings['throttle'],

        'ch1' => $settings['ch1'],
        'ch2' => $settings['ch2'],
        'ch3' => $settings['ch3'],
        'ch4' => $settings['ch4'],
        'ch5' => $settings['ch5'],
        'ch6' => $settings['ch6'],
    ]
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
