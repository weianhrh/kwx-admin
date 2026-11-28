<?php
// /api/operat/venueLiveDuration.php
require_once '../Database.php';
require_once __DIR__ . '/../lib/voice_room_fee.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

$database = new Database();

function json_out($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_today_range() {
    return [
        date('Y-m-d 00:00:00'),
        date('Y-m-d 23:59:59')
    ];
}

// 会话校验
$sessionToken = $_COOKIE['session_token'] ?? '';
if (!$sessionToken) {
    json_out(1001, '用户未登录或会话已过期');
}

$user = $database->getUserBySessionToken($sessionToken);
if (!$user || empty($user['role_id'])) {
    json_out(1001, '用户未登录或无权访问');
}

$roleId = intval($user['role_id']);
$userVenueId = intval($user['venue_id'] ?? 0);

// 只允许管理员和场地方查看
if (!in_array($roleId, [1, 4], true)) {
    json_out(403, '当前角色无权查看开播统计');
}

$action = $_POST['action'] ?? ($_GET['action'] ?? 'get_today_live_duration');
if ($action !== 'get_today_live_duration') {
    json_out(400, '无效操作');
}

$venueId = trim($_POST['venue_id'] ?? ($_GET['venue_id'] ?? '0'));

// role_id=4 只能看自己绑定的场地
if ($roleId === 4) {
    if ($userVenueId <= 0) {
        json_out(403, '当前账号未绑定场地');
    }
    $venueId = (string)$userVenueId;
} else {
    if ($venueId !== '' && $venueId !== '0' && !ctype_digit($venueId)) {
        json_out(422, 'venue_id参数错误');
    }
}

list($todayStart, $todayEnd) = get_today_range();
$now = date('Y-m-d H:i:s');
$rangeEnd = $now < $todayEnd ? $now : $todayEnd;

$whereVenueOpen = '';
$params = [$rangeEnd, $rangeEnd];

if ($venueId !== '' && $venueId !== '0') {
    $whereVenueOpen = ' AND o.venue_id = ? ';
    $params[] = intval($venueId);
}
$params[] = $todayStart;

try {
    $openState = kwxVoiceRoomStateSql('o');
    $previousState = kwxVoiceRoomStateSql('p');
    $closeState = kwxVoiceRoomStateSql('c');
    // Use the same effective state as billing. Break ties by ID: a close and
    // a later reopen in the same second must still be separate transitions.
    $sql = "
        SELECT segments.* FROM (
            SELECT o.id AS open_log_id, o.venue_id,
                   IFNULL(v.venue_name, '') AS venue_name, o.created_at AS open_time,
                   (
                       SELECT c.created_at FROM venue_live_status_logs c
                       WHERE c.venue_id = o.venue_id AND c.created_at <= ?
                         AND c.created_at >= o.created_at
                         AND (c.created_at > o.created_at OR c.id > o.id)
                         AND ($closeState) = 0
                       ORDER BY c.created_at, c.id LIMIT 1
                   ) AS close_time
            FROM venue_live_status_logs o
            LEFT JOIN venues v ON v.id = o.venue_id
            WHERE o.created_at <= ? AND ($openState) = 1 $whereVenueOpen
              AND COALESCE((
                  SELECT $previousState FROM venue_live_status_logs p
                  WHERE p.venue_id = o.venue_id AND p.created_at <= o.created_at
                    AND (p.created_at < o.created_at OR p.id < o.id)
                    AND ($previousState) IS NOT NULL
                  ORDER BY p.created_at DESC, p.id DESC LIMIT 1
              ), 0) = 0
        ) segments
        WHERE segments.close_time IS NULL OR segments.close_time > ?
        ORDER BY segments.venue_id, segments.open_time, segments.open_log_id
    ";

    $rows = $database->query($sql, $params);
    if ($rows === false) {
        json_out(500, '查询开播区间失败');
    }

    $segments = [];
    $totalSeconds = 0;
    $venueTotals = [];

    foreach ($rows as $row) {
        $openTime = $row['open_time'];
        $closeTime = $row['close_time'];
        $isLiving = empty($closeTime);

        $calcStart = max(strtotime($openTime), strtotime($todayStart));
        $calcEnd = $isLiving
            ? strtotime($rangeEnd)
            : min(strtotime($closeTime), strtotime($rangeEnd));

        if ($calcEnd <= $calcStart) {
            continue;
        }

        $durationSeconds = $calcEnd - $calcStart;
        $totalSeconds += $durationSeconds;

        $vid = intval($row['venue_id']);

        if (!isset($venueTotals[$vid])) {
            $venueTotals[$vid] = [
                'venue_id' => $vid,
                'venue_name' => $row['venue_name'],
                'total_seconds' => 0,
                'segment_count' => 0
            ];
        }

        $venueTotals[$vid]['total_seconds'] += $durationSeconds;
        $venueTotals[$vid]['segment_count']++;

        $segments[] = [
            'venue_id' => $vid,
            'venue_name' => $row['venue_name'],
            'open_time' => $openTime,
            'close_time' => $isLiving ? null : $closeTime,
            'calc_start' => date('Y-m-d H:i:s', $calcStart),
            'calc_end' => date('Y-m-d H:i:s', $calcEnd),
            'duration_seconds' => $durationSeconds,
            'is_living' => $isLiving ? 1 : 0
        ];
    }

    json_out(0, 'success', [
        'role_id' => $roleId,
        'forced_venue_id' => $roleId === 4 ? $userVenueId : 0,
        'date' => date('Y-m-d'),
        'today_start' => $todayStart,
        'range_end' => $rangeEnd,
        'total_seconds' => $totalSeconds,
        'venue_totals' => array_values($venueTotals),
        'segments' => $segments
    ]);

} catch (Exception $e) {
    json_out(500, '服务器异常：' . $e->getMessage());
}