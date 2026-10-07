<?php
// Date is the first complete business day settled under the 70/20/10 rule.
// Never backdate this over an already checked DailyVenueRevenue row.
const KWX_8899_CUTOVER_DATE = '2026-10-07';

function kwx8899Active($date) {
    return (string)$date >= KWX_8899_CUTOVER_DATE;
}

function kwx8899AcquireDailyLock(Database $database, $date) {
    $rows = $database->query('SELECT GET_LOCK(?, 30) AS acquired', ['kwx_8899_daily_' . $date]);
    if ($rows === false || (int)($rows[0]['acquired'] ?? 0) !== 1) {
        throw new RuntimeException($date . ' 账期已有任务执行或锁定失败，请稍后重试');
    }
    // MySQL releases this connection-scoped lock when Database::close() runs.
}

function kwx8899IsVenue(Database $database, $venueId) {
    $rows = $database->query(
        "SELECT 1 FROM venues WHERE id = ? AND venue_subtitle = '8899' LIMIT 1",
        [(int)$venueId]
    );
    if ($rows === false) {
        throw new RuntimeException('无法核实 8899 场地资格');
    }
    return !empty($rows);
}

function kwx8899RequireFrozenDay(Database $database, $date) {
    $rows = $database->query(
        'SELECT order_count, order_amount, reward_amount FROM kwx_8899_revenue_days WHERE revenue_date = ? LIMIT 1',
        [$date]
    );
    if ($rows === false || !$rows) {
        throw new RuntimeException($date . ' 推广快照尚未固化，禁止生成或入账每日收益');
    }
    return $rows[0];
}

function kwx8899NetConsumerRevenue(Database $database, $venueId, $date) {
    if (!kwx8899Active($date) || !kwx8899IsVenue($database, $venueId)) {
        return 0.0;
    }
    kwx8899RequireFrozenDay($database, $date);
    $rows = $database->query(
        "SELECT COALESCE(SUM(ROUND(order_amount - reward_amount - ROUND(order_amount * 0.20, 2), 2)), 0) AS net_amount
         FROM venue_promotion_reward_logs
         WHERE consumer_venue_id = ? AND reward_date = ? AND source_type = 'daily_17_snapshot'",
        [(int)$venueId, $date]
    );
    if ($rows === false) {
        throw new RuntimeException('读取跨场地净收益失败');
    }
    return round((float)$rows[0]['net_amount'], 2);
}

function kwx8899AssertDailyAmount(Database $database, $venueId, $date, $amount, $beforeDeduction = null) {
    if (!kwx8899Active($date) || !kwx8899IsVenue($database, $venueId)) {
        return 0.0;
    }
    kwx8899RequireFrozenDay($database, $date);
    $gross = $database->query(
        "SELECT ROUND(COALESCE(SUM(payment_amount), 0), 2) AS amount FROM orders
         WHERE DATE(end_time) = ? AND reservation_id = ?
           AND status = '已完成'
           AND (pays_type IS NULL OR pays_type <> '能量')
           AND (note IS NULL OR note NOT IN ('gift', '场地礼物'))",
        [$date, (int)$venueId]
    );
    $parts = $database->query(
        "SELECT COUNT(*) AS n, COALESCE(SUM(order_amount), 0) AS order_amount,
                COALESCE(SUM(reward_amount), 0) AS reward,
                COALESCE(SUM(ROUND(order_amount * 0.20, 2)), 0) AS platform
         FROM venue_promotion_reward_logs
         WHERE consumer_venue_id = ? AND reward_date = ? AND source_type = 'daily_17_snapshot'",
        [(int)$venueId, $date]
    );
    if ($gross === false || $parts === false) {
        throw new RuntimeException('每日收益入账前校验失败');
    }
    if ($beforeDeduction === null
        || abs((float)$beforeDeduction - (float)$gross[0]['amount']) > 0.005) {
        throw new RuntimeException('日结扣前收入与已完成订单金额不一致，禁止自动核对');
    }
    $live = $database->query(
        "SELECT COUNT(*) AS n, COALESCE(SUM(o.payment_amount), 0) AS order_amount,
                COALESCE(SUM(ROUND(o.payment_amount * 0.10, 2)), 0) AS reward
         FROM orders o
         JOIN users u ON u.uid = o.uid
         JOIN venues p ON p.venue_subtitle = '8899'
           AND p.venue_unique_subtitle = u.invitation_code
           AND p.venue_unique_subtitle IS NOT NULL
           AND p.venue_unique_subtitle NOT IN ('', '8899')
         WHERE o.reservation_id = ? AND o.reservation_id <> p.id
           AND o.status = '已完成'
           AND o.end_time >= ? AND o.end_time < DATE_ADD(?, INTERVAL 1 DAY)
           AND COALESCE(o.payment_amount, 0) > 0
           AND (o.pays_type IS NULL OR o.pays_type <> '能量')
           AND (o.note IS NULL OR o.note NOT IN ('gift', '场地礼物'))
           AND NOT EXISTS (SELECT 1 FROM refund_records rr WHERE rr.order_id = o.order_id)",
        [(int)$venueId, $date . ' 00:00:00', $date . ' 00:00:00']
    );
    if ($live === false || (int)$live[0]['n'] !== (int)$parts[0]['n']
        || abs((float)$live[0]['order_amount'] - (float)$parts[0]['order_amount']) > 0.005
        || abs((float)$live[0]['reward'] - (float)$parts[0]['reward']) > 0.005) {
        throw new RuntimeException('跨场地订单与已固化推广明细不一致，禁止入账');
    }
    $expected = round((float)$gross[0]['amount'] - (float)$parts[0]['reward'] - (float)$parts[0]['platform'], 2);
    if (abs($expected - (float)$amount) > 0.005) {
        throw new RuntimeException('每日收益不是 70/20/10 固化金额，禁止入账');
    }
    return kwx8899NetConsumerRevenue($database, $venueId, $date);
}

function kwx8899ExemptBalance(Database $database, $venueId) {
    $rows = $database->query(
        'SELECT COALESCE(SUM(amount), 0) AS amount FROM kwx_8899_fee_exempt_ledger WHERE venue_id = ?',
        [(int)$venueId]
    );
    if ($rows === false) {
        throw new RuntimeException('读取免重复扣费余额失败');
    }
    return round((float)$rows[0]['amount'], 2);
}

function kwx8899Ledger(Database $database, $venueId, $amount, $sourceType, $sourceId) {
    if (abs($amount) < 0.005) {
        return;
    }
    $result = $database->query(
        'INSERT INTO kwx_8899_fee_exempt_ledger (venue_id, amount, source_type, source_id) VALUES (?, ?, ?, ?)',
        [(int)$venueId, number_format($amount, 2, '.', ''), $sourceType, (int)$sourceId],
        true
    );
    if ($result !== 1) {
        throw new RuntimeException('写入免重复扣费台账失败或重复入账');
    }
}

// All values are cents so that split and refund arithmetic cannot drift by a cent.
function kwx8899Allocation($balance, $exemptBalance, $held, $settlement, $otherDebit, $platformRate, $feeRate) {
    $b = (int)round($balance * 100);
    $n = (int)round($exemptBalance * 100);
    $h = max(0, (int)round($held * 100));
    $a = (int)round($settlement * 100);
    $c = max(0, (int)round($otherDebit * 100));
    if ($n < 0 || $n > $b) {
        throw new RuntimeException('免重复扣费台账与场地余额不一致，请先对账');
    }
    $legacy = $b - $n;
    $legacyAvailable = max(0, $legacy - $h);
    $exemptAvailable = max(0, min($n, $b - $h - $legacyAvailable));
    if ($a < 0 || $a > $legacyAvailable + $exemptAvailable) {
        throw new RuntimeException('超过可提现结算基数');
    }
    $exemptSettlement = min($a, $exemptAvailable);
    $legacySettlement = $a - $exemptSettlement;
    $technicalFee = (int)round($legacySettlement * $platformRate);
    $withdrawalFee = (int)round($legacySettlement * $feeRate);
    $exemptOtherDebit = max(0, $c - ($legacy - $legacySettlement));
    $exemptSpent = $exemptSettlement + $exemptOtherDebit;
    if ($exemptSpent > $n || $a + $c > $b) {
        throw new RuntimeException('余额分摊异常，请刷新后重试');
    }
    return [
        'exempt_available' => $exemptAvailable / 100,
        'legacy_available' => $legacyAvailable / 100,
        'exempt_spent' => $exemptSpent / 100,
        'technical_fee' => $technicalFee / 100,
        'withdrawal_fee' => $withdrawalFee / 100,
        'actual_amount' => ($a - $technicalFee - $withdrawalFee) / 100,
    ];
}
