<?php
// /api/devMgr/syncSingleVehicleToDr.php
// RC端：单独同步某一个设备 vehicles + vehicle_control_settings 到 DR

header('Content-Type: application/json; charset=utf-8');

require_once '../Database.php';

date_default_timezone_set('Asia/Shanghai');

// ================================
// DR 单设备同步配置
// ================================
define('DR_SYNC_SINGLE_VEHICLE_URL', 'https://open.nodistanceco.com/api/devMgr/receive_rc_single_vehicle.php');
define('DR_SYNC_TOKEN', 'd051cddb5a8ca9792b30bdddda431abb572a32638f9b36e2e90503e1535ecefd');

function jsonOut($code, $msg, $data = null)
{
    $res = [
        'code' => $code,
        'msg'  => $msg,
    ];

    if ($data !== null) {
        $res['data'] = $data;
    }

    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

function logSync($message)
{
    $file = __DIR__ . '/sync_single_vehicle_to_dr.log';
    $time = date('Y-m-d H:i:s');
    file_put_contents($file, "[$time] $message\n", FILE_APPEND);
}

function getInput()
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);

    if (is_array($json)) {
        return $json;
    }

    return $_POST;
}

function bindParams($stmt, $params)
{
    if (empty($params)) {
        return;
    }

    $types = str_repeat('s', count($params));
    $refs = [];

    foreach ($params as $k => $v) {
        $refs[$k] = &$params[$k];
    }

    $stmt->bind_param($types, ...$refs);
}

function fetchAll($conn, $sql, $params = [])
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception('SQL准备失败：' . $conn->error . ' SQL=' . $sql);
    }

    bindParams($stmt, $params);

    if (!$stmt->execute()) {
        throw new Exception('SQL执行失败：' . $stmt->error . ' SQL=' . $sql);
    }

    $result = $stmt->get_result();
    $rows = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();
    return $rows;
}

function fetchOne($conn, $sql, $params = [])
{
    $rows = fetchAll($conn, $sql, $params);
    return $rows[0] ?? null;
}

function tableExists($conn, $table)
{
    $safeTable = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $res && $res->num_rows > 0;
}

function syncToDr($payload)
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => DR_SYNC_SINGLE_VEHICLE_URL,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . DR_SYNC_TOKEN,
        ],
        CURLOPT_POSTFIELDS     => $json,
    ]);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($curlErr) {
        return [
            'ok'        => false,
            'http_code' => $httpCode,
            'error'     => $curlErr,
            'response'  => $response,
        ];
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        return [
            'ok'        => false,
            'http_code' => $httpCode,
            'error'     => 'DR返回不是JSON',
            'response'  => $response,
        ];
    }

    return [
        'ok'        => isset($decoded['code']) && intval($decoded['code']) === 0,
        'http_code' => $httpCode,
        'code'      => intval($decoded['code'] ?? -1),
        'msg'       => $decoded['msg'] ?? '',
        'data'      => $decoded['data'] ?? null,
        'response'  => $decoded,
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonOut(405, '仅支持POST请求');
    }

    $database = new Database();
    $conn = $database->getConnection();

    if (!$conn) {
        jsonOut(500, 'RC数据库连接失败');
    }

    // ================================
    // 1. 登录校验：只允许 role_id=1/2
    // ================================
    $sessionToken = $_COOKIE['session_token'] ?? '';

    if (!$sessionToken) {
        jsonOut(1001, '未登录或会话已过期');
    }

    $admin = fetchOne(
        $conn,
        "SELECT id, uid, username, role_id FROM admin_users WHERE session_token = ? LIMIT 1",
        [$sessionToken]
    );

    if (!$admin) {
        jsonOut(1001, '用户未登录或无权访问');
    }

    $roleId = intval($admin['role_id']);

    if (!in_array($roleId, [1, 2], true)) {
        jsonOut(1003, '权限不足，仅 role_id=1 或 2 可同步设备');
    }

    // ================================
    // 2. 获取参数
    // ================================
    $input = getInput();

    $serialNumber = trim((string)($input['serial_number'] ?? ''));
    $drVenueId = intval($input['dr_venue_id'] ?? $input['venue_id'] ?? 0);

    if ($serialNumber === '') {
        jsonOut(400, '缺少 serial_number');
    }

    if ($drVenueId < 1 || $drVenueId > 9999) {
        jsonOut(400, '海外场地ID非法，必须在 1~9999 内', [
            'dr_venue_id' => $drVenueId
        ]);
    }

    // ================================
    // 3. 查询 RC vehicles 单设备
    // ================================
    $vehicle = fetchOne(
        $conn,
        "SELECT
            id,
            serial_number,
            photo_url,
            name,
            status,
            battery_level,
            voltage,
            vehicle_status,
            uid,
            bind_site,
            bind_city,
            sharing_status,
            driver,
            driver_id,
            start_status,
            billing_rules,
            share_password,
            Reservation_lock,
            ReservationCode,
            share_name,
            image_device_serial,
            bk_image_device_serial,
            vehicle_level,
            is_banned,
            image_transmission_type
        FROM vehicles
        WHERE serial_number = ?
        LIMIT 1",
        [$serialNumber]
    );

    if (!$vehicle) {
        jsonOut(404, 'RC设备不存在', [
            'serial_number' => $serialNumber
        ]);
    }

    // ================================
    // 4. 查询 RC vehicle_control_settings 单设备配置
    // ================================
    $vehicleControlSetting = null;

    if (tableExists($conn, 'vehicle_control_settings')) {
        $vehicleControlSetting = fetchOne(
            $conn,
            "SELECT * FROM vehicle_control_settings WHERE serial_number = ? LIMIT 1",
            [$serialNumber]
        );
    }

    // ================================
    // 5. 组装同步包
    // ================================
    $payload = [
        'source'        => 'RC',
        'sync_type'     => 'single_vehicle',
        'sync_time'     => date('c'),
        'operator'      => [
            'uid'      => intval($admin['uid'] ?? 0),
            'username' => $admin['username'] ?? '',
            'role_id'  => $roleId,
        ],

        // DR海外场地ID
        'dr_venue_id'   => $drVenueId,

        // 单设备序列号
        'serial_number' => $serialNumber,

        // 两个分支：vehicles + vehicle_control_settings
        'vehicle'       => $vehicle,
        'vehicle_control_settings' => $vehicleControlSetting,
    ];

    logSync("开始同步单设备 SN={$serialNumber}, DR venue_id={$drVenueId}");

    // ================================
    // 6. CURL 到 DR
    // ================================
    $syncResult = syncToDr($payload);

    logSync("DR返回：" . json_encode([
        'ok'        => $syncResult['ok'] ?? false,
        'http_code' => $syncResult['http_code'] ?? 0,
        'code'      => $syncResult['code'] ?? -1,
        'msg'       => $syncResult['msg'] ?? '',
        'error'     => $syncResult['error'] ?? '',
        'data'      => $syncResult['data'] ?? null,
    ], JSON_UNESCAPED_UNICODE));

    // DR明确返回重复
    if (($syncResult['code'] ?? 0) === 409) {
        jsonOut(409, $syncResult['msg'] ?: '该序列号已在海外存在', $syncResult['data'] ?? null);
    }

    if (!$syncResult['ok']) {
        jsonOut(500, '同步到DR失败：' . ($syncResult['msg'] ?: ($syncResult['error'] ?? '未知错误')), [
            'serial_number' => $serialNumber,
            'dr_venue_id'   => $drVenueId,
            'result'        => $syncResult,
        ]);
    }

    jsonOut(0, '设备同步到DR成功', [
        'serial_number' => $serialNumber,
        'dr_venue_id'   => $drVenueId,
        'dr_result'     => $syncResult['data'] ?? null,
    ]);

} catch (Throwable $e) {
    logSync("异常：" . $e->getMessage());
    jsonOut(500, 'RC单设备同步接口异常：' . $e->getMessage());
}