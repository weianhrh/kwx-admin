<?php
require_once '../Database.php';
require_once '../lib/venue_scope.php';

header('Content-Type: application/json; charset=utf-8');

function logSql($sql, $params = []) {
    $logFile = __DIR__ . '/sql_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $param_str = json_encode($params, JSON_UNESCAPED_UNICODE);
    $logEntry = "[$timestamp] SQL: $sql\nParams: $param_str\n\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->getUserBySessionToken($session_token);

if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * venue_id 支持：
 * - 不传：非 get_default 时默认查当前用户场地
 * - all / 空字符串：查全部场地
 * - 数字：查指定场地
 */
$role_id = intval($user['role_id'] ?? 0);
$user_venue_id = intval($user['venue_id'] ?? 0);
$requestedVenueId = venue_scope_requested_id($_GET);

$raw_venue_id = $_GET['venue_id'] ?? null;
$venue_id = null;

if ($raw_venue_id !== null && $raw_venue_id !== '' && $raw_venue_id !== 'all') {
    $venue_id = intval($raw_venue_id);
}

$get_default = isset($_GET['get_default']) && $_GET['get_default'] == '1';

// ✅ 非平台管理员只能看自己可管理的场地
if (!in_array($role_id, [1, 2], true)) {
    $user_venue_id = venue_scope_resolve_single_id($database, $user, $requestedVenueId);
    if ($user_venue_id <= 0) {
        echo json_encode([
            'code' => 403,
            'msg' => '当前账号未绑定或无权访问该场地，无法查看违规记录',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $venue_id = $user_venue_id;
}

$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

// ✅ 默认时间区间：近一周，含今天在内 7 天
if (!$start_date) {
    $start_date = date('Y-m-d 00:00:00', strtotime('-6 days'));
}
if (!$end_date) {
    $end_date = date('Y-m-d 23:59:59');
}

/**
 * 查询范围：
 * - get_default=1 且 venue_id=all/不传：查全部场地
 * - get_default=1 且 venue_id=数字：查指定场地
 * - 不传 get_default 但传 venue_id：查指定场地
 * - 都不传：查当前登录用户绑定场地
 */
$selectedVenueId = null;

if (!in_array($role_id, [1, 2], true)) {
    // ✅ 非平台管理员使用前面解析后的可访问场地
    $selectedVenueId = $user_venue_id;
} else {
    if ($get_default) {
        // 管理员等角色：venue_id 为空表示全部场地
        $selectedVenueId = $venue_id;
    } elseif ($venue_id !== null) {
        $selectedVenueId = $venue_id;
    } else {
        $selectedVenueId = intval($user['venue_id'] ?? 0);
    }

    // 非 role_id=3 or 4，如果没有有效场地 ID，则表示全部场地
    if ($selectedVenueId <= 0) {
        $selectedVenueId = null;
    }
}
$device_details = [];
$venue_details = [];
$venue_stats = [];
$device_count = 0;
$venue_count = 0;

try {
    // =========================
    // 设备封禁记录
    // =========================
    $deviceWhere = "db.created_at BETWEEN ? AND ?";
    $deviceParams = [$start_date, $end_date];

    if ($selectedVenueId !== null) {
        $deviceWhere .= " AND db.venue_id = ?";
        $deviceParams[] = $selectedVenueId;
    }

    $sql_device_detail = "
        SELECT 
            v.venue_name,
            vh.name,
            db.venue_id,
            db.serial_number,
            db.created_at,
            db.image_url,
            db.ban_duration,
            db.ban_end_time,
            db.ban_reason
        FROM device_bans db
        LEFT JOIN vehicles vh ON db.serial_number = vh.serial_number
        LEFT JOIN venues v ON v.id = db.venue_id
        WHERE {$deviceWhere}
        ORDER BY db.created_at DESC
    ";
    logSql($sql_device_detail, $deviceParams);
    $device_details = $database->query($sql_device_detail, $deviceParams);

    $sql_device_count = "
        SELECT COUNT(*) AS total
        FROM device_bans db
        WHERE {$deviceWhere}
    ";
    $device_count_result = $database->query($sql_device_count, $deviceParams);
    $device_count = intval($device_count_result[0]['total'] ?? 0);

    // =========================
    // 场地封禁记录
    // =========================
    $venueWhere = "vb.created_at BETWEEN ? AND ?";
    $venueParams = [$start_date, $end_date];

    if ($selectedVenueId !== null) {
        $venueWhere .= " AND vb.venue_id = ?";
        $venueParams[] = $selectedVenueId;
    }

$sql_venue_detail = "
    SELECT 
        v.venue_name,
        vb.venue_id,
        NULL AS serial_number,
        vb.created_at,

        -- 兼容旧字段
        vb.image_url,
        vb.image_url_2,
        vb.image_url_3,

        -- 前端按 image1 / image2 / image3 使用
        vb.image_url AS image1,
        vb.image_url_2 AS image2,
        vb.image_url_3 AS image3,

        vb.ban_duration,
        vb.ban_end_time,
        vb.ban_reason
    FROM venue_bans vb
    LEFT JOIN venues v ON vb.venue_id = v.id
    WHERE {$venueWhere}
    ORDER BY vb.created_at DESC
";
    logSql($sql_venue_detail, $venueParams);
    $venue_details = $database->query($sql_venue_detail, $venueParams);

    $sql_venue_count = "
        SELECT COUNT(*) AS total
        FROM venue_bans vb
        WHERE {$venueWhere}
    ";
    $venue_count_result = $database->query($sql_venue_count, $venueParams);
    $venue_count = intval($venue_count_result[0]['total'] ?? 0);

    // =========================
    // 当前时间区间内，每个场地违规次数
    // 统计：设备封禁 + 场地封禁
    // =========================
    $statsDeviceWhere = "db.created_at BETWEEN ? AND ?";
    $statsVenueWhere = "vb.created_at BETWEEN ? AND ?";
    $statsDeviceParams = [$start_date, $end_date];
    $statsVenueParams = [$start_date, $end_date];

    if ($selectedVenueId !== null) {
        $statsDeviceWhere .= " AND db.venue_id = ?";
        $statsVenueWhere .= " AND vb.venue_id = ?";
        $statsDeviceParams[] = $selectedVenueId;
        $statsVenueParams[] = $selectedVenueId;
    }

    $sql_venue_stats = "
        SELECT
            x.venue_id,
            COALESCE(v.venue_name, '未知场地') AS venue_name,
            SUM(CASE WHEN x.ban_type = 'device' THEN 1 ELSE 0 END) AS device_count,
            SUM(CASE WHEN x.ban_type = 'venue' THEN 1 ELSE 0 END) AS venue_count,
            COUNT(*) AS total_count
        FROM (
            SELECT
                db.venue_id,
                'device' AS ban_type
            FROM device_bans db
            WHERE {$statsDeviceWhere}

            UNION ALL

            SELECT
                vb.venue_id,
                'venue' AS ban_type
            FROM venue_bans vb
            WHERE {$statsVenueWhere}
        ) x
        LEFT JOIN venues v ON v.id = x.venue_id
        GROUP BY x.venue_id, v.venue_name
        ORDER BY total_count DESC
    ";
    $statsParams = array_merge($statsDeviceParams, $statsVenueParams);
    logSql($sql_venue_stats, $statsParams);
    $venue_stats = $database->query($sql_venue_stats, $statsParams);

    $total_count = $device_count + $venue_count;

    echo json_encode([
        'code' => 0,
        'msg' => '查询成功',
        'data' => [
            'total_bans' => $total_count,
            'device_count' => $device_count,
            'venue_count' => $venue_count,
            'device_bans' => $device_details ?: [],
            'venue_bans' => $venue_details ?: [],
            'venue_stats' => $venue_stats ?: [],
            'start_date' => $start_date,
            'end_date' => $end_date,
            'selected_venue_id' => $selectedVenueId,
            'role_id' => $role_id,
            'is_venue_limited' => ($role_id === 3 || $role_id === 4)
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    echo json_encode([
        'code' => 500,
        'msg' => '服务器异常：' . $e->getMessage(),
        'data' => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
