<?php
// /api/venue/syncVenueFullToDr.php
// RC端：把指定场地的完整资料同步到 DR

header('Content-Type: application/json; charset=utf-8');

require_once '../Database.php';

date_default_timezone_set('Asia/Shanghai');

// ================================
// DR 同步配置
// ================================
// DR 接收接口地址
define('DR_SYNC_FULL_URL', 'https://open.nodistanceco.com/api/venue/receive_rc_venue_full.php');

// 和 DR 接收端保持一致，建议用你之前场地同步那串密钥
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
    $file = __DIR__ . '/sync_venue_full_to_dr.log';
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
        CURLOPT_URL            => DR_SYNC_FULL_URL,
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

    if ($httpCode !== 200) {
        return [
            'ok'        => false,
            'http_code' => $httpCode,
            'error'     => 'DR接口HTTP状态异常',
            'response'  => $response,
        ];
    }

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
    // 1. 登录校验
    // ================================
    $sessionToken = $_COOKIE['session_token'] ?? 'd051cddb5a8ca9792b30bdddda431abb572a32638f9b36e2e90503e1535ecefd';

    if (!$sessionToken) {
        jsonOut(1001, '未登录或会话已过期');
    }

    $admin = fetchOne(
        $conn,
        "SELECT id, uid, username, role_id, venue_id FROM admin_users WHERE session_token = ? LIMIT 1",
        [$sessionToken]
    );

    if (!$admin) {
        jsonOut(1001, '用户未登录或无权访问');
    }

    $roleId = intval($admin['role_id']);

    if (!in_array($roleId, [1, 2], true)) {
        jsonOut(1003, '权限不足，仅role_id=1或2可同步');
    }

    // ================================
    // 2. 获取 venue_id
    // ================================
    $input = getInput();
    $rcVenueId = intval($input['venue_id'] ?? $input['id'] ?? 0);

    if ($rcVenueId <= 0) {
        jsonOut(400, '缺少 venue_id');
    }

    // ================================
    // 3. 查询 RC 场地
    // ================================
    $venue = fetchOne(
        $conn,
        "SELECT
            id,
            dr_venue_id,
            venue_name,
            image_url,
            venue_description,
            venue_tags,
            venue_type,
            event_id,
            start_time,
            queue_length,
            venue_status,
            venue_status_num,
            live_stream_url,
            show_live_stream,
            is_banned,
            is_pinned,
            zone_id,
            image_status,
            venue_room_id
        FROM venues
        WHERE id = ?
        LIMIT 1",
        [$rcVenueId]
    );

    if (!$venue) {
        jsonOut(404, 'RC场地不存在');
    }

    $drVenueId = intval($venue['dr_venue_id'] ?? 0);

    if ($drVenueId < 1 || $drVenueId > 9999) {
        jsonOut(400, '该场地没有有效的 dr_venue_id，请先绑定海外DR场地ID', [
            'rc_venue_id' => $rcVenueId,
            'dr_venue_id' => $venue['dr_venue_id'] ?? null,
        ]);
    }

    // ================================
    // 4. 查询 RC 加盟账号
    // 这里复制 password hash，不传明文
    // ================================
    $adminUsers = fetchAll(
        $conn,
        "SELECT
            id,
            username,
            password,
            role_id,
            venue_id,
            venue_name,
            uid
        FROM admin_users
        WHERE venue_id = ?",
        [$rcVenueId]
    );

    // ================================
    // 5. 查询 RC 车辆
    // bind_site 有些项目存ID，有些项目可能存场地名，这里兼容两种
    // ================================
    $vehicles = fetchAll(
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
        WHERE bind_site = ? OR bind_site = ?",
        [(string)$rcVenueId, (string)$venue['venue_name']]
    );

    $serialNumbers = [];
    foreach ($vehicles as $v) {
        $sn = trim((string)($v['serial_number'] ?? ''));
        if ($sn !== '') {
            $serialNumbers[$sn] = $sn;
        }
    }

    $serialNumbers = array_values($serialNumbers);

    // ================================
    // 6. 查询 RC vehicle_control_settings
    // 注意：如果 RC 表不存在，或者你现在传的表结构不是这个表，就跳过
    // ================================
    $vehicleControlSettings = [];

    if (!empty($serialNumbers) && tableExists($conn, 'vehicle_control_settings')) {
        $placeholders = implode(',', array_fill(0, count($serialNumbers), '?'));

        $vehicleControlSettings = fetchAll(
            $conn,
            "SELECT * FROM vehicle_control_settings WHERE serial_number IN ($placeholders)",
            $serialNumbers
        );
    }

    // ================================
    // 7. 组装同步包
    // ================================
    $payload = [
        'source'       => 'RC',
        'sync_type'    => 'venue_full',
        'sync_time'    => date('c'),
        'operator'     => [
            'uid'      => intval($admin['uid'] ?? 0),
            'username' => $admin['username'] ?? '',
            'role_id'  => $roleId,
        ],

        'rc_venue_id'  => intval($rcVenueId),
        'dr_venue_id'  => intval($drVenueId),

        'venue'        => [
            'venue_name'        => $venue['venue_name'],
            'image_url'         => $venue['image_url'],
            'venue_description' => $venue['venue_description'],
            'venue_tags'        => $venue['venue_tags'],
            'venue_type'        => intval($venue['venue_type']),
            'event_id'          => intval($venue['event_id']),
            'start_time'        => $venue['start_time'],
            'queue_length'      => intval($venue['queue_length']),
            'venue_status'      => $venue['venue_status'],
            'venue_status_num'  => intval($venue['venue_status_num']),
            'live_stream_url'   => $venue['live_stream_url'],
            'show_live_stream'  => intval($venue['show_live_stream']),
            'is_banned'         => intval($venue['is_banned']),
            'is_pinned'         => intval($venue['is_pinned']),
            'zone_id'           => intval($venue['zone_id']),
            'image_status'      => $venue['image_status'],
            'venue_room_id'     => $venue['venue_room_id'] === null ? null : intval($venue['venue_room_id']),
        ],

        'admin_users'              => $adminUsers,
        'vehicles'                 => $vehicles,
        'vehicle_control_settings' => $vehicleControlSettings,
    ];

    logSync("开始同步 RC venue_id={$rcVenueId}, DR venue_id={$drVenueId}, 账号数=" . count($adminUsers) . ", 车辆数=" . count($vehicles) . ", 控制配置数=" . count($vehicleControlSettings));

    // ================================
    // 8. CURL 到 DR
    // ================================
    $syncResult = syncToDr($payload);

    logSync("DR返回：" . json_encode([
        'ok'        => $syncResult['ok'] ?? false,
        'http_code' => $syncResult['http_code'] ?? 0,
        'msg'       => $syncResult['msg'] ?? '',
        'error'     => $syncResult['error'] ?? '',
        'data'      => $syncResult['data'] ?? null,
    ], JSON_UNESCAPED_UNICODE));

    if (!$syncResult['ok']) {
        jsonOut(500, '同步到DR失败', [
            'rc_venue_id' => $rcVenueId,
            'dr_venue_id' => $drVenueId,
            'result'      => $syncResult,
        ]);
    }

    jsonOut(0, '同步到DR成功', [
        'rc_venue_id' => $rcVenueId,
        'dr_venue_id' => $drVenueId,
        'sent' => [
            'admin_users'              => count($adminUsers),
            'vehicles'                 => count($vehicles),
            'vehicle_control_settings' => count($vehicleControlSettings),
        ],
        'dr_result' => $syncResult['data'] ?? null,
    ]);

} catch (Throwable $e) {
    logSync("异常：" . $e->getMessage());
    jsonOut(500, 'RC同步接口异常：' . $e->getMessage());
}