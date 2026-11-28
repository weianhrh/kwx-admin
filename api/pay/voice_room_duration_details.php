<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/venue_scope.php';
require_once __DIR__ . '/../lib/voice_room_fee.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
date_default_timezone_set('Asia/Shanghai');

function kwxDurationResponse($code, $message, $data = [])
{
    echo json_encode(['code' => $code, 'msg' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function kwxDurationDate($key, $default)
{
    $value = $_GET[$key] ?? '';
    if ($value === '') {
        return $default;
    }
    if (!is_string($value) || !preg_match('/^([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})$/D', $value, $m)
        || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        kwxDurationResponse(422, '日期格式应为 YYYY-MM-DD');
    }
    return $value;
}

function kwxDurationInteger($key, $default, $min, $max)
{
    $value = $_GET[$key] ?? '';
    if ($value === '') {
        return $default;
    }
    $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if ($n === false) {
        kwxDurationResponse(422, '分页或场地编号无效');
    }
    return $n;
}

// Only page-visible sessions already open at the scan boundary need their original
// start recovered. The first open after the last close is unaffected by duplicate opens.
function kwxDurationOriginalStarts($database, array $ids, $boundary)
{
    if (!$ids) {
        return [];
    }
    $openState = kwxVoiceRoomStateSql('o');
    $closeState = kwxVoiceRoomStateSql('c');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $rows = kwxVoiceRoomQuery($database,
        "SELECT v.id, (
             SELECT o.created_at FROM venue_live_status_logs o
             WHERE o.venue_id = v.id AND o.created_at < ? AND ($openState) = 1
               AND (p.id IS NULL OR o.created_at > p.created_at OR (o.created_at = p.created_at AND o.id > p.id))
             ORDER BY o.created_at, o.id LIMIT 1
         ) AS open_time
         FROM venues v LEFT JOIN venue_live_status_logs p ON p.id = (
             SELECT c.id FROM venue_live_status_logs c
             WHERE c.venue_id = v.id AND c.created_at < ? AND ($closeState) = 0
             ORDER BY c.created_at DESC, c.id DESC LIMIT 1
         ) WHERE v.id IN ($marks)", array_merge([$boundary, $boundary], $ids));
    return array_column($rows, 'open_time', 'id');
}

try {
    $database = new Database();
    $token = $_COOKIE['session_token'] ?? '';
    $user = is_string($token) && $token !== '' ? $database->getUserBySessionToken($token) : null;
    if (!$user) {
        kwxDurationResponse(1001, '请先登录后台');
    }
    $admin = venue_scope_is_platform_admin($user);
    if (!$admin && (int)($user['role_id'] ?? 0) !== 3) {
        kwxDurationResponse(1003, '当前账号无权查看开关播时长');
    }

    $now = kwxVoiceRoomTime(null);
    $startDate = kwxDurationDate('start_date', substr(KWX_VOICE_FEE_START, 0, 10));
    $endDate = kwxDurationDate('end_date', $now->format('Y-m-d'));
    if ($startDate > $endDate) {
        kwxDurationResponse(422, '开始日期不能晚于结束日期');
    }
    $page = kwxDurationInteger('page', 1, 1, 2147483647);
    $size = kwxDurationInteger('page_size', 30, 1, 100);
    $venueId = kwxDurationInteger('venue_id', 0, 0, 4294967295);

    $asOf = $now->format('Y-m-d H:i:s');
    if (isset($_GET['as_of']) && $_GET['as_of'] !== '') {
        if (!is_string($_GET['as_of'])) {
            kwxDurationResponse(422, '查询截止时间格式错误');
        }
        $asOf = min($asOf, kwxVoiceRoomTime($_GET['as_of'])->format('Y-m-d H:i:s'));
    }
    $from = $startDate . ' 00:00:00';
    $cutoff = $endDate < substr($asOf, 0, 10)
        ? kwxVoiceRoomTime($endDate . ' 00:00:00')->modify('+1 day')->format('Y-m-d H:i:s')
        : $asOf;

    kwxVoiceRoomBeginRead($database);
    if ($admin) {
        $venues = kwxVoiceRoomQuery($database, 'SELECT id, venue_name FROM venues ORDER BY venue_name, id', []);
    } else {
        $venueId = venue_scope_resolve_single_id($database, $user, $venueId);
        if (!$venueId) {
            kwxDurationResponse(1003, '未绑定或无权查看该场地');
        }
        $venues = kwxVoiceRoomQuery($database, 'SELECT id, venue_name FROM venues WHERE id = ?', [$venueId]);
    }
    $venues = array_map(static function ($row) {
        return ['id' => (int)$row['id'], 'venue_name' => (string)$row['venue_name']];
    }, $venues);
    $names = array_column($venues, 'venue_name', 'id');
    if ($venueId && !array_key_exists($venueId, $names)) {
        kwxDurationResponse(1003, '场地不存在或无权查看');
    }
    $ids = $venueId ? [$venueId] : array_keys($names);
    $totals = [];
    foreach ($ids as $id) {
        $totals[$id] = ['venue_id' => $id, 'venue_name' => $names[$id],
            'total_seconds' => 0, 'total_fee' => 0, 'segment_count' => 0];
    }

    $feeStart = kwxVoiceRoomTime(KWX_VOICE_FEE_START)->getTimestamp();
    $rangeStart = kwxVoiceRoomTime($from)->getTimestamp();
    $historyStart = min($from, KWX_VOICE_FEE_START);
    $before = array_fill_keys($ids, 0);
    $through = $before;
    $count = 0;
    $latest = [];
    $seconds = 0;
    if ($from < $cutoff) {
        foreach (kwxVoiceRoomSegments($database, $ids, $historyStart, $cutoff) as $segment) {
            [$id, $start, $end] = $segment;
            $billableStart = max($feeStart, $start);
            $through[$id] += max(0, $end - $billableStart);
            $before[$id] += max(0, min($end, $rangeStart) - $billableStart);
            $duration = $end - max($rangeStart, $start);
            if ($duration <= 0) {
                continue;
            }
            $totals[$id]['total_seconds'] += $duration;
            $totals[$id]['segment_count']++;
            $seconds += $duration;
            $latest[$count % $size] = $segment;
            $count++;
        }
    }
    $cents = 0;
    foreach ($ids as $id) {
        $fee = kwxVoiceRoomCents($through[$id]) - kwxVoiceRoomCents($before[$id]);
        $totals[$id]['total_fee'] = $fee / 100;
        $cents += $fee;
    }

    // The first pass retains only the latest page and the per-venue totals. For an
    // older page replay only the selected range, still keeping at most page_size rows.
    $pages = max(1, (int)ceil($count / $size));
    $page = min($page, $pages);
    $segments = [];
    $boundary = $historyStart;
    if ($page === 1) {
        for ($idx = $count - 1; $idx >= max(0, $count - $size); $idx--) {
            $segments[] = $latest[$idx % $size];
        }
    } else {
        $boundary = $from;
        $lower = max(0, $count - $page * $size);
        $upper = $count - ($page - 1) * $size;
        $idx = 0;
        foreach (kwxVoiceRoomSegments($database, $ids, $from, $cutoff) as $segment) {
            if ($idx >= $lower) {
                $segments[] = $segment;
            }
            if (++$idx >= $upper) {
                break;
            }
        }
        $segments = array_reverse($segments);
    }

    $carriedIds = [];
    foreach ($segments as $s) {
        if ($s[4]) {
            $carriedIds[$s[0]] = $s[0];
        }
    }
    $original = kwxDurationOriginalStarts($database, array_values($carriedIds), $boundary);
    $rows = [];
    foreach ($segments as [$id, $start, $end, $live, $carried]) {
        $finish = date('Y-m-d H:i:s', $end);
        $rows[] = [
            'venue_id' => $id, 'venue_name' => $names[$id],
            'open_time' => $carried ? $original[$id] : date('Y-m-d H:i:s', $start),
            'close_time' => $live ? null : $finish,
            'calc_start' => date('Y-m-d H:i:s', max($rangeStart, $start)),
            'calc_end' => $finish, 'duration_seconds' => $end - max($rangeStart, $start),
            'is_living' => $live ? 1 : 0
        ];
    }
    $database->commit();
    kwxDurationResponse(0, 'success', [
        'can_select_venue' => $admin, 'selected_venue_id' => $venueId, 'venues' => $venues,
        'start_date' => $startDate, 'end_date' => $endDate,
        'as_of' => $asOf, 'total_seconds' => $seconds, 'total_fee' => $cents / 100,
        'segment_count' => $count, 'venue_totals' => array_values($totals),
        'segments' => $rows, 'page' => $page, 'page_size' => $size, 'total_pages' => $pages
    ]);
} catch (InvalidArgumentException $e) {
    kwxDurationResponse(422, $e->getMessage());
} catch (Throwable $e) {
    error_log('开关播时长明细读取失败: ' . $e->getMessage());
    kwxDurationResponse(500, '开关播时长明细读取失败，请稍后重试');
}
