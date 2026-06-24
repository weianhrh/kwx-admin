<?php
// /api/operat/venueLiveDuration.php
require_once '../Database.php';

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
$params = [$rangeEnd, $todayStart];

if ($venueId !== '' && $venueId !== '0') {
    $whereVenueOpen = ' AND o.venue_id = ? ';
    $params[] = intval($venueId);
}

try {
    /*
     * 统计逻辑：
     * 1. action=1 或 new_is_live=1 视为开播
     * 2. action=0 或 new_is_live=0 或 remark='系统关播' 视为关播
     * 3. 找每条开播后面第一条关播
     * 4. 如果没有关播，结束时间按当前时间算
     * 5. 只统计今天 00:00:00 到当前时间的交集
     */

    $sql = "
        SELECT
            o.id AS open_log_id,
            o.venue_id,
            IFNULL(v.venue_name, '') AS venue_name,
            o.created_at AS open_time,
            (
                SELECT MIN(c.created_at)
                FROM venue_live_status_logs c
                WHERE c.venue_id = o.venue_id
                  AND (
                        c.action = 0
                        OR c.new_is_live = 0
                        OR c.remark = '系统关播'
                  )
                  AND c.created_at > o.created_at
            ) AS close_time
        FROM venue_live_status_logs o
        LEFT JOIN venues v ON v.id = o.venue_id
        WHERE (
                o.action = 1
                OR o.new_is_live = 1
        )
          AND IFNULL((
                SELECT
                    CASE
                        WHEN (p.action = 1 OR p.new_is_live = 1) THEN 1
                        ELSE 0
                    END
                FROM venue_live_status_logs p
                WHERE p.venue_id = o.venue_id
                  AND (
                        p.created_at < o.created_at
                        OR (p.created_at = o.created_at AND p.id < o.id)
                  )
                ORDER BY p.created_at DESC, p.id DESC
                LIMIT 1
          ), 0) = 0
          AND o.created_at <= ?
          AND (
                (
                    SELECT MIN(c2.created_at)
                    FROM venue_live_status_logs c2
                    WHERE c2.venue_id = o.venue_id
                      AND (
                            c2.action = 0
                            OR c2.new_is_live = 0
                            OR c2.remark = '系统关播'
                      )
                      AND c2.created_at > o.created_at
                ) IS NULL
                OR
                (
                    SELECT MIN(c3.created_at)
                    FROM venue_live_status_logs c3
                    WHERE c3.venue_id = o.venue_id
                      AND (
                            c3.action = 0
                            OR c3.new_is_live = 0
                            OR c3.remark = '系统关播'
                      )
                      AND c3.created_at > o.created_at
                ) >= ?
          )
          $whereVenueOpen
        ORDER BY o.venue_id ASC, o.created_at ASC
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