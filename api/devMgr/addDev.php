<?php
require_once '../Database.php';
require_once __DIR__ . '/VehicleTypeDefaults.php';
header('Content-Type: application/json; charset=utf-8');

$database = new Database();

function jsonOut($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function insertAssoc(Database $database, string $table, array $data): void
{
    if (empty($data)) {
        throw new Exception($table . ' 插入数据不能为空');
    }

    $allowTables = [
        'vehicle_control_settings',
    ];

    if (!in_array($table, $allowTables, true)) {
        throw new Exception('不允许插入该表：' . $table);
    }

    $columns = array_keys($data);

    foreach ($columns as $column) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new Exception('非法字段名：' . $column);
        }
    }

    $columnSql = '`' . implode('`,`', $columns) . '`';
    $placeholderSql = implode(',', array_fill(0, count($columns), '?'));

    $sql = "INSERT INTO `{$table}` ({$columnSql}) VALUES ({$placeholderSql})";

    $stmt = $database->prepare($sql);
    if (!$stmt) {
        throw new Exception($table . ' 插入语句准备失败');
    }

    $types = '';
    $values = [];

    foreach ($data as $value) {
        if (is_int($value)) {
            $types .= 'i';
            $values[] = $value;
        } elseif (is_float($value)) {
            $types .= 'd';
            $values[] = $value;
        } else {
            $types .= 's';
            $values[] = $value === null ? null : (string)$value;
        }
    }

    $refs = [];
    foreach ($values as $key => $value) {
        $refs[$key] = &$values[$key];
    }

    if (!$stmt->bind_param($types, ...$refs)) {
        throw new Exception($table . ' 参数绑定失败');
    }

    if (!$stmt->execute()) {
        throw new Exception($table . ' 插入失败：' . $stmt->error);
    }
}
/**
 * 保留空行位置拆分
 */
function splitLinesKeepPosition($text) {
    $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
    return explode("\n", $text);
}

/**
 * 构建逐行数据
 * 规则：
 * - serial_number 是主列，只有 serial_number 非空才算一条有效设备
 * - image_device_serial / rtc_user_id 按原始行号与 serial_number 对齐
 * - 如果某行 serial 为空，但 image/rtc 有值，则视为错位输入
 */
function buildInputRows($serialText, $imageText, $rtcText) {
    $serialLines = splitLinesKeepPosition($serialText);
    $imageLines  = splitLinesKeepPosition($imageText);
    $rtcLines    = splitLinesKeepPosition($rtcText);

    $maxLines = max(count($serialLines), count($imageLines), count($rtcLines));

    $rows = [];
    $orphanLines = [];

    for ($i = 0; $i < $maxLines; $i++) {
        $serial = trim($serialLines[$i] ?? '');
        $image  = trim($imageLines[$i] ?? '');
        $rtc    = trim($rtcLines[$i] ?? '');

        if ($serial === '') {
            if ($image !== '' || $rtc !== '') {
                $orphanLines[] = [
                    'line_no' => $i + 1,
                    'image_device_serial' => $image,
                    'rtc_user_id' => $rtc
                ];
            }
            continue;
        }

        $rows[] = [
            'line_no' => $i + 1,
            'serial_number' => $serial,
            'image_device_serial_raw' => $image,
            'rtc_user_id_raw' => $rtc
        ];
    }

    return [
        'rows' => $rows,
        'orphan_lines' => $orphanLines
    ];
}

/**
 * DN 同步过来的图像设备序列号解析规则
 * 1. 先按 device_information.id 查
 * 2. 查不到再按 device_information.room_id 查
 * 3. 查到则统一返回对应 id
 * 4. 两边都查不到，则保持原值（兼容旧逻辑）
 */
function resolveImageDeviceSerialForAdd(Database $database, string $inputValue): array {
    $inputValue = trim($inputValue);

    if ($inputValue === '') {
        return [
            'original' => '',
            'final' => '',
            'matched' => false,
            'matched_by' => null,
            'resolved_id' => null,
            'room_id' => null
        ];
    }

    $byId = $database->query(
        "SELECT id, room_id FROM device_information WHERE CAST(id AS CHAR) = ? LIMIT 1",
        [$inputValue]
    );

    if (!empty($byId)) {
        return [
            'original' => $inputValue,
            'final' => (string)$byId[0]['id'],
            'matched' => true,
            'matched_by' => 'id',
            'resolved_id' => (string)$byId[0]['id'],
            'room_id' => (string)$byId[0]['room_id']
        ];
    }

    $byRoomId = $database->query(
        "SELECT id, room_id FROM device_information WHERE CAST(room_id AS CHAR) = ? LIMIT 1",
        [$inputValue]
    );

    if (!empty($byRoomId)) {
        return [
            'original' => $inputValue,
            'final' => (string)$byRoomId[0]['id'],
            'matched' => true,
            'matched_by' => 'room_id',
            'resolved_id' => (string)$byRoomId[0]['id'],
            'room_id' => (string)$byRoomId[0]['room_id']
        ];
    }

    return [
        'original' => $inputValue,
        'final' => $inputValue,
        'matched' => false,
        'matched_by' => null,
        'resolved_id' => null,
        'room_id' => null
    ];
}
/**
 * 根据图像设备序列号判断图传类型
 * vehicles.image_transmission_type：0=unknown; 1=zego; 2=yl; 3=ch; 4=xm
 * 规则优先级：YL/ch 前缀 > ZEGO 数字房间号 > XM 字母数字混合乱码
 */
function detectImageTransmissionTypeForAdd(string $rawImage, array $resolved = []): int
{
    $rawImage = trim($rawImage);

    // 如果匹配到了 device_information，优先用 room_id 判断
    // 因为 final/resolved_id 只是数据库 id，不一定是真正图传房间号
    $roomId = isset($resolved['room_id']) ? trim((string)$resolved['room_id']) : '';

    if ($roomId !== '') {
        $value = $roomId;
    } else {
        $value = $rawImage;
    }

    if ($value === '') {
        return 0;
    }

    if (preg_match('/^YL/i', $value)) {
        return 2; // yl
    }

    if (preg_match('/^ch/i', $value)) {
        return 3; // ch
    }

    if (preg_match('/^[0-9]+$/', $value) && strlen($value) < 8) {
        return 1; // zego
    }

    if (preg_match('/[A-Za-z]/', $value) && preg_match('/[0-9]/', $value)) {
        return 4; // xm
    }

    return 0;
}

function imageTransmissionTypeNameForAdd(int $type): string
{
    $map = [
        0 => 'unknown',
        1 => 'zego',
        2 => 'yl',
        3 => 'ch',
        4 => 'xm',
    ];
    return $map[$type] ?? 'unknown';
}
/**
 * 提取重复设备序列号
 */
function findDuplicateSerials(array $rows): array {
    $counter = [];
    foreach ($rows as $row) {
        $sn = $row['serial_number'];
        $counter[$sn] = ($counter[$sn] ?? 0) + 1;
    }

    $duplicates = [];
    foreach ($counter as $sn => $count) {
        if ($count > 1) {
            $duplicates[] = $sn;
        }
    }
    return $duplicates;
}

// 接收前端输入
$serial_number_str      = $_POST['serial_number'] ?? '';
$image_device_serial_str = $_POST['image_device_serial'] ?? '';
$rtc_user_id_str        = $_POST['rtc_user_id'] ?? '';

$bind_site      = isset($_POST['bind_site']) && $_POST['bind_site'] !== '' ? (int)$_POST['bind_site'] : 0;
$sharing_status = $_POST['sharing_status'] ?? '正在共享';
$name           = trim($_POST['name'] ?? '');
$photo_url      = $_POST['photo_url'] ?? 'https://app.kwxapp.cn/img/m1282.jpg';
$car_type       = isset($_POST['car_type']) && $_POST['car_type'] !== '' ? (int)$_POST['car_type'] : 1;
$uid            = isset($_POST['uid']) && $_POST['uid'] !== '' ? (int)$_POST['uid'] : 10015;
$throttle_max   = isset($_POST['throttle_max']) && $_POST['throttle_max'] !== '' ? (int)$_POST['throttle_max'] : 1640;
$throttle_min   = isset($_POST['throttle_min']) && $_POST['throttle_min'] !== '' ? (int)$_POST['throttle_min'] : 1305;
$direction      = $_POST['direction'] ?? 'true';

// 基础必填检查
if ($name === '' || $bind_site <= 0) {
    jsonOut(1002, '缺少必要参数', []);
}

// 构建逐行数据
$built = buildInputRows($serial_number_str, $image_device_serial_str, $rtc_user_id_str);
$rows = $built['rows'];
$orphanLines = $built['orphan_lines'];

if (empty($rows)) {
    jsonOut(1002, '至少需要填写一行有效的设备序列号', []);
}

if (!empty($orphanLines)) {
    $msgs = array_map(function ($item) {
        return '第 ' . $item['line_no'] . ' 行：设备序列号为空，但填写了图像设备序列号或 rtc_user_id';
    }, array_slice($orphanLines, 0, 10));

    jsonOut(1002, "存在未对齐的填写：\n" . implode("\n", $msgs), [
        'orphan_lines' => $orphanLines
    ]);
}

// 检查本次输入里设备序列号自己有没有重复
$duplicateSerials = findDuplicateSerials($rows);
if (!empty($duplicateSerials)) {
    jsonOut(1002, '你本次输入的设备序列号里有重复：' . implode(', ', $duplicateSerials), [
        'duplicate_serials' => $duplicateSerials
    ]);
}

// 检查数据库里 vehicles 是否已存在相同 serial_number
$existingSerials = [];
$checkVehicleSerialStmt = $database->prepare("SELECT serial_number FROM vehicles WHERE serial_number = ? LIMIT 1");
if (!$checkVehicleSerialStmt) {
    jsonOut(1003, '检查 vehicles 序列号失败', []);
}

foreach ($rows as $row) {
    $serialNumber = $row['serial_number'];
    $checkVehicleSerialStmt->bind_param("s", $serialNumber);
    $checkVehicleSerialStmt->execute();
    $result = $checkVehicleSerialStmt->get_result();
    if ($result && $result->num_rows > 0) {
        $existingSerials[] = $serialNumber;
    }
}
$existingSerials = array_values(array_unique($existingSerials));

if (!empty($existingSerials)) {
    jsonOut(1003, '以下设备序列号已存在：' . implode(', ', $existingSerials), [
        'existing_serials' => $existingSerials
    ]);
}

// 先做图像设备序列号解析，并验证 rtc_user_id 的可入库性
$preparedRows = [];
$resolvedImageDetails = [];
$unresolvedImageInputs = [];
$rtcWriteErrors = [];

foreach ($rows as $row) {
    $rawImage = $row['image_device_serial_raw'];
    $rawRtc   = $row['rtc_user_id_raw'];

    $resolved = resolveImageDeviceSerialForAdd($database, $rawImage);
    $imageTransmissionType = detectImageTransmissionTypeForAdd($rawImage, $resolved);

    if ($rawRtc !== '') {
        if ($rawImage === '') {
            $rtcWriteErrors[] = [
                'line_no' => $row['line_no'],
                'serial_number' => $row['serial_number'],
                'rtc_user_id' => $rawRtc,
                'reason' => '该行填写了 rtc_user_id，但未填写图像设备序列号'
            ];
        } elseif (!$resolved['matched']) {
            $rtcWriteErrors[] = [
                'line_no' => $row['line_no'],
                'serial_number' => $row['serial_number'],
                'image_device_serial' => $rawImage,
                'rtc_user_id' => $rawRtc,
                'reason' => '图像设备序列号未匹配到 device_information.id 或 room_id，无法回写 rtc_user_id'
            ];
        }
    }

    if ($rawImage !== '' && !$resolved['matched']) {
        $unresolvedImageInputs[] = $rawImage;
    }

    $preparedRows[] = [
        'line_no' => $row['line_no'],
        'serial_number' => $row['serial_number'],
        'image_device_serial_raw' => $rawImage,
        'image_device_serial_final' => $resolved['final'],
        'rtc_user_id_raw' => $rawRtc,
        'resolved_image' => $resolved,
        'image_transmission_type' => $imageTransmissionType,
        'image_transmission_type_name' => imageTransmissionTypeNameForAdd($imageTransmissionType),
    ];

    $resolvedImageDetails[] = [
        'line_no' => $row['line_no'],
        'serial_number' => $row['serial_number'],
        'original' => $resolved['original'],
        'final' => $resolved['final'],
        'matched' => $resolved['matched'],
        'matched_by' => $resolved['matched_by'],
        'resolved_id' => $resolved['resolved_id'],
        'room_id' => $resolved['room_id'],
        'rtc_user_id_input' => $rawRtc
    ];
}

if (!empty($rtcWriteErrors)) {
    $msgs = array_map(function ($item) {
        return '第 ' . $item['line_no'] . ' 行：' . $item['reason'];
    }, array_slice($rtcWriteErrors, 0, 10));

    jsonOut(1002, "以下行的 rtc_user_id 无法入库：\n" . implode("\n", $msgs), [
        'rtc_write_errors' => $rtcWriteErrors,
        'resolved_image_details' => $resolvedImageDetails
    ]);
}

try {
    $database->beginTransaction();

    // vehicles
    $vehiclesStmt = $database->prepare("
        INSERT INTO vehicles
        (serial_number, name, sharing_status, share_name, photo_url, bind_site, uid, image_device_serial, image_transmission_type)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");
    if (!$vehiclesStmt) {
        throw new Exception('vehicles 插入语句准备失败');
    }

    // vehicle_control_settings
    /*
    $vehicleControlSettingsStmt = $database->prepare("
        INSERT INTO vehicle_control_settings
        (serial_number, car_type, topic, throttle_max, throttle_min, direction)
        VALUES (?,?,?,?,?,?)
    ");
    if (!$vehicleControlSettingsStmt) {
        throw new Exception('vehicle_control_settings 插入语句准备失败');
    }*/
    
    // 回写 device_information.rtc_user_id
    $updateRtcStmt = $database->prepare("
        UPDATE device_information
        SET rtc_user_id = ?
        WHERE id = ?
    ");
    if (!$updateRtcStmt) {
        throw new Exception('device_information rtc_user_id 更新语句准备失败');
    }

    $startIndex = 1;

    foreach ($preparedRows as $index => $row) {
        $serialNumber = $row['serial_number'];
        $currentName = $name . ($startIndex + $index);
        $imageDeviceSerial = $row['image_device_serial_final'];
        $rtcUserId = $row['rtc_user_id_raw'];
        $imageTransmissionType = (int)$row['image_transmission_type'];
        // vehicles
        $vehiclesStmt->bind_param(
            "sssssiisi",
            $serialNumber,
            $currentName,
            $sharing_status,
            $currentName,
            $photo_url,
            $bind_site,
            $uid,
            $imageDeviceSerial,
            $imageTransmissionType
        );

        if (!$vehiclesStmt->execute()) {
            throw new Exception('插入 vehicles 失败：' . $vehiclesStmt->error);
        }

        // vehicle_control_settings
        /*$topic = $serialNumber;
        $vehicleControlSettingsStmt->bind_param(
            "sisiis",
            $serialNumber,
            $car_type,
            $topic,
            $throttle_max,
            $throttle_min,
            $direction
        );

        if (!$vehicleControlSettingsStmt->execute()) {
            throw new Exception('插入 vehicle_control_settings 失败：' . $vehicleControlSettingsStmt->error);
        }*/
        $controlSettingsData = buildVehicleControlSettingsData(
            $serialNumber,
            $car_type,
            [
                'throttle_max' => $throttle_max,
                'throttle_min' => $throttle_min,
                'direction'    => $direction,
            ]
        );
        
        insertAssoc($database, 'vehicle_control_settings', $controlSettingsData);
        // 如果该行 rtc_user_id 非空，且图像设备序列号已成功匹配到 device_information，则回写 rtc_user_id
        if ($rtcUserId !== '' && !empty($row['resolved_image']['resolved_id'])) {
            $resolvedId = (int)$row['resolved_image']['resolved_id'];

            $updateRtcStmt->bind_param("si", $rtcUserId, $resolvedId);
            if (!$updateRtcStmt->execute()) {
                throw new Exception('回写 device_information.rtc_user_id 失败：' . $updateRtcStmt->error);
            }
        }
    }

    $database->commit();

    $msg = '新设备已成功添加！';

    if (!empty($unresolvedImageInputs)) {
        $msg .= ' 以下图像设备序列号未在 device_information.id 或 room_id 中匹配到，已按原值保存到 vehicles.image_device_serial：'
             . implode(', ', array_values(array_unique($unresolvedImageInputs)));
    }

    jsonOut(200, $msg, [
        'resolved_image_details' => $resolvedImageDetails,
        'unresolved_image_inputs' => array_values(array_unique($unresolvedImageInputs))
    ]);

} catch (Exception $e) {
    $database->rollBack();
    jsonOut(1003, '添加设备时发生错误：' . $e->getMessage(), []);
}
?>