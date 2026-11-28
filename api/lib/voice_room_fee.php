<?php
// All log DATETIMEs and ledger cutoffs are interpreted as Beijing local time.
const KWX_VOICE_FEE_START = '2026-11-01 00:00:00';

function kwxVoiceRoomTime($value)
{
    $zone = new DateTimeZone('Asia/Shanghai');
    if ($value === null) {
        return new DateTimeImmutable('now', $zone);
    }
    $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone);
    if (!$time || $time->format('Y-m-d H:i:s') !== $value) {
        throw new InvalidArgumentException('语音房计费日期无效');
    }
    return $time;
}

// Use one DB snapshot for status logs, settled charges and account balances on read-only pages.
function kwxVoiceRoomBeginRead($database)
{
    $connection = $database->getConnection();
    if (!$connection->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')
        || !$connection->begin_transaction()) {
        throw new RuntimeException('无法开始语音房费查询，请重试');
    }
}

function kwxVoiceRoomQuery($database, $sql, $params)
{
    $rows = $database->query($sql, $params);
    if (!is_array($rows)) {
        throw new RuntimeException('读取语音房计费记录失败，请检查开关播日志表及语音房费迁移');
    }
    return $rows;
}

// At 3 cents per minute, a cent accrues every 20 seconds. Round the lifetime
// amount once, so neither frequent withdrawals nor the number of sessions alters the fee.
function kwxVoiceRoomCents($seconds)
{
    return intdiv($seconds + 10, 20);
}

// Internal SQL aliases only. Use the resulting state rather than the permission switch
// (an administrator can grant permission while the venue is still offline).
function kwxVoiceRoomStateSql($alias)
{
    return "CASE WHEN $alias.remark = '系统关播' THEN 0
                 WHEN $alias.new_is_live IN (0,1) THEN $alias.new_is_live
                 WHEN $alias.new_is_live IS NULL AND $alias.action IN (0,1) THEN $alias.action
                 ELSE NULL END";
}

// Yield positive-duration sessions in ascending closing-time order. The time range
// is half-open. A carried session starts at the lower bound for duration purposes.
function kwxVoiceRoomSegments($database, array $ids, $from, $cutoff)
{
    if (!$ids || $cutoff <= $from) {
        return;
    }

    $origin = kwxVoiceRoomTime($from)->getTimestamp();
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $state = kwxVoiceRoomStateSql('l');
    $priorState = kwxVoiceRoomStateSql('p');
    $anchors = kwxVoiceRoomQuery($database,
        "SELECT v.id AS venue_id, ($state) AS live_state
         FROM venues v JOIN venue_live_status_logs l ON l.id = (
             SELECT p.id FROM venue_live_status_logs p
             WHERE p.venue_id = v.id AND p.created_at < ? AND ($priorState) IS NOT NULL
             ORDER BY p.created_at DESC, p.id DESC LIMIT 1
         ) WHERE v.id IN ($marks)", array_merge([$from], $ids));
    $open = [];
    foreach ($anchors as $row) {
        if ((int)$row['live_state'] === 1) {
            $open[(int)$row['venue_id']] = [$origin, true];
        }
    }

    $lastTime = $from;
    $lastId = 0;
    do {
        $events = kwxVoiceRoomQuery($database,
            "SELECT l.id, l.venue_id, l.created_at, ($state) AS live_state,
                    TIMESTAMPDIFF(SECOND, ?, l.created_at) AS event_second
             FROM venue_live_status_logs l
             WHERE l.created_at >= ? AND l.created_at < ?
               AND (l.created_at > ? OR l.id > ?)
               AND l.venue_id IN ($marks)
             ORDER BY l.created_at, l.id LIMIT 4000",
            array_merge([$from, $lastTime, $cutoff, $lastTime, $lastId], $ids));
        foreach ($events as $row) {
            $lastTime = $row['created_at'];
            $lastId = $row['id'];
            if ($row['live_state'] === null) {
                continue;
            }
            $venueId = (int)$row['venue_id'];
            $time = $origin + (int)$row['event_second'];
            if ((int)$row['live_state'] === 1) {
                if (!isset($open[$venueId])) {
                    $open[$venueId] = [$time, false];
                }
            } elseif (isset($open[$venueId])) {
                [$start, $carried] = $open[$venueId];
                unset($open[$venueId]);
                if ($time > $start) {
                    yield [$venueId, $start, $time, false, $carried];
                }
            }
        }
    } while (count($events) === 4000);

    $end = kwxVoiceRoomTime($cutoff)->getTimestamp();
    ksort($open, SORT_NUMERIC);
    foreach ($open as $venueId => [$start, $carried]) {
        if ($end > $start) {
            yield [$venueId, $start, $end, true, $carried];
        }
    }
}

// Compute cumulative seconds at the cutoff and (for date reports) at one intermediate boundary.
function kwxVoiceRoomAccrual($database, array $venueIds, $cutoff, $split = null)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $venueIds), function ($id) {
        return $id > 0;
    })));
    $usage = array_fill_keys($ids, ['total_seconds' => 0, 'before_seconds' => 0]);
    $splitSecond = $split === null ? 0 : kwxVoiceRoomTime($split)->getTimestamp();
    foreach (kwxVoiceRoomSegments($database, $ids, KWX_VOICE_FEE_START, $cutoff) as [$id, $start, $end]) {
        $usage[$id]['total_seconds'] += $end - $start;
        $usage[$id]['before_seconds'] += max(0, min($end, $splitSecond) - $start);
    }
    return $usage;
}

// For a withdrawal this must run in the same transaction as the fund debit, after
// locking venue_funds for that venue and before any earlier consistent-read snapshot.
function kwxVoiceRoomFees($database, array $venueIds, $asOf = null)
{
    $now = kwxVoiceRoomTime(null)->format('Y-m-d H:i:s');
    $cutoff = $asOf === null ? $now : min(kwxVoiceRoomTime($asOf)->format('Y-m-d H:i:s'), $now);
    $usage = kwxVoiceRoomAccrual($database, $venueIds, $cutoff);
    if (!$usage) {
        return [];
    }
    $ids = array_keys($usage);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $rows = kwxVoiceRoomQuery($database,
        "SELECT venue_id, CAST(SUM(fee_amount) * 100 AS SIGNED) AS cents
         FROM kwx_voice_room_fee_charges WHERE released_at IS NULL AND venue_id IN ($marks)
         GROUP BY venue_id", $ids);
    $paidCents = array_column($rows, 'cents', 'venue_id');
    $quotes = [];
    foreach ($usage as $id => $times) {
        $earned = kwxVoiceRoomCents($times['total_seconds']);
        $paid = (int)($paidCents[$id] ?? 0);
        $quotes[$id] = [
            'total_seconds' => $times['total_seconds'],
            'total_fee' => $earned / 100,
            'settled_fee' => $paid / 100,
            'unsettled_fee' => max(0, $earned - $paid) / 100,
            'as_of' => $cutoff
        ];
    }
    return $quotes;
}

// [start, endExclusive), in Beijing time. Differences of rounded cumulative cents
// make consecutive daily or monthly reports add up to the same overall total.
function kwxVoiceRoomRangeFees($database, array $venueIds, $start, $endExclusive)
{
    $start = kwxVoiceRoomTime($start)->format('Y-m-d H:i:s');
    $end = min(kwxVoiceRoomTime($endExclusive)->format('Y-m-d H:i:s'), kwxVoiceRoomTime(null)->format('Y-m-d H:i:s'));
    if ($start >= $end) {
        return array_fill_keys($venueIds, 0.0);
    }
    $usage = kwxVoiceRoomAccrual($database, $venueIds, $end, $start);
    $fees = [];
    foreach ($usage as $id => $times) {
        $fees[$id] = (kwxVoiceRoomCents($times['total_seconds']) - kwxVoiceRoomCents($times['before_seconds'])) / 100;
    }
    return $fees;
}

// An existing snapshot for the same request ID must never be overwritten.
function kwxVoiceRoomRecordCharge($database, $venueId, $requestId, array $quote)
{
    $affected = $database->query(
        'INSERT INTO kwx_voice_room_fee_charges
         (withdrawal_request_id, venue_id, calculated_through, cumulative_seconds, fee_amount)
         VALUES (?, ?, ?, ?, ?)',
        [$requestId, $venueId, $quote['as_of'], $quote['total_seconds'], number_format($quote['unsettled_fee'], 2, '.', '')], true);
    if ($affected !== 1) {
        throw new RuntimeException('保存本次提现语音房费失败');
    }
}

// Called under the same fund lock during reversal. Legacy requests have no snapshot.
function kwxVoiceRoomReleaseCharge($database, $venueId, $requestId)
{
    $rows = kwxVoiceRoomQuery($database,
        'SELECT fee_amount, released_at FROM kwx_voice_room_fee_charges
         WHERE withdrawal_request_id = ? AND venue_id = ? FOR UPDATE', [$requestId, $venueId]);
    if (!$rows || $rows[0]['released_at'] !== null) {
        return 0.0;
    }
    $affected = $database->query(
        'UPDATE kwx_voice_room_fee_charges SET released_at = ?
         WHERE withdrawal_request_id = ? AND venue_id = ? AND released_at IS NULL',
        [kwxVoiceRoomTime(null)->format('Y-m-d H:i:s'), $requestId, $venueId], true);
    if ($affected !== 1) {
        throw new RuntimeException('释放本次提现语音房费失败');
    }
    return (float)$rows[0]['fee_amount'];
}
