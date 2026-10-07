<?php
/**
 * One-off correction for checked 8899 daily revenue between 2026-10-01 and
 * the 2026-10-07 cutover. CLI only. Defaults to a read-only preview.
 *
 * php Backfill8899Historical.php --start=2026-10-01 --end=2026-10-05
 * php Backfill8899Historical.php --start=2026-10-01 --end=2026-10-05 --execute --operator-uid=123
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("历史资金补账仅允许 PHP CLI 执行\n");
}

require_once dirname(__DIR__) . '/Database.php';
require_once dirname(__DIR__) . '/lib/kwx_8899_policy.php';
date_default_timezone_set('Asia/Shanghai');

function historicalArg($name, $default) {
    global $argv;
    foreach ($argv as $arg) {
        if (strpos($arg, '--' . $name . '=') === 0) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return $default;
}

function historicalDate($value) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) return false;
    $parsed = DateTime::createFromFormat('!Y-m-d', $value);
    return $parsed && $parsed->format('Y-m-d') === $value;
}

function historicalCents($value) {
    return (int)round((float)$value * 100);
}

function historicalMoney($cents) {
    return number_format($cents / 100, 2, '.', '');
}

function historicalQuery(Database $db, $sql, $params = []) {
    $rows = $db->query($sql, $params);
    if ($rows === false) throw new RuntimeException('补账校验查询失败，请检查数据库日志');
    return $rows;
}

function historicalOrders(Database $db, $date, $venueId = null, $lock = false) {
    // Same attribution and eligibility as FreezeInviteRewardDaily.php.
    $sql = "SELECT o.order_id, o.uid, o.pays_type, o.reservation_id AS consumer_venue_id,
                   p.id AS promotion_venue_id, u.invitation_code,
                   ROUND(o.payment_amount, 2) AS order_amount,
                   ROUND(o.payment_amount * 0.10, 2) AS reward_amount,
                   ROUND(o.payment_amount * 0.20, 2) AS platform_amount
            FROM orders o
            JOIN users u ON u.uid = o.uid
            JOIN venues p ON p.venue_subtitle = '8899'
              AND p.venue_unique_subtitle = u.invitation_code
              AND p.venue_unique_subtitle IS NOT NULL
              AND p.venue_unique_subtitle NOT IN ('', '8899')
            JOIN venues c ON c.id = o.reservation_id AND c.venue_subtitle = '8899'
            WHERE o.reservation_id <> p.id
              AND u.invitation_code IS NOT NULL
              AND u.invitation_code NOT IN ('', '8899')
              AND o.status = '已完成'
              AND o.end_time >= ? AND o.end_time < DATE_ADD(?, INTERVAL 1 DAY)
              AND COALESCE(o.payment_amount, 0) > 0
              AND (o.pays_type IS NULL OR o.pays_type <> '能量')
              AND (o.note IS NULL OR o.note NOT IN ('gift', '场地礼物'))
              AND NOT EXISTS (SELECT 1 FROM refund_records rr WHERE rr.order_id = o.order_id)";
    $params = [$date . ' 00:00:00', $date . ' 00:00:00'];
    if ($venueId !== null) {
        $sql .= ' AND o.reservation_id = ?';
        $params[] = (int)$venueId;
    }
    $sql .= ' ORDER BY o.reservation_id, o.order_id';
    if ($lock) $sql .= ' FOR UPDATE';
    return historicalQuery($db, $sql, $params);
}

function historicalSummary($rows) {
    $seen = [];
    $gross = $reward = $platform = 0;
    foreach ($rows as $row) {
        $orderId = (string)$row['order_id'];
        if ($orderId === '' || isset($seen[$orderId])) {
            throw new RuntimeException('候选订单号为空或重复，先核对订单与推广码归属');
        }
        $seen[$orderId] = true;
        $gross += historicalCents($row['order_amount']);
        $reward += historicalCents($row['reward_amount']);
        $platform += historicalCents($row['platform_amount']);
    }
    return [
        'orders' => count($rows),
        'gross' => $gross,
        'reward' => $reward,
        'platform' => $platform,
        'consumer' => $gross - $reward - $platform,
    ];
}

function historicalInspect(Database $db, $date, $venueId, $rows, $lock) {
    $sum = historicalSummary($rows);
    $result = [
        'date' => $date,
        'consumer_venue_id' => (int)$venueId,
        'orders' => $sum['orders'],
        'cross_venue_gross' => historicalMoney($sum['gross']),
        'platform_20_percent' => historicalMoney($sum['platform']),
        'promotion_10_percent' => historicalMoney($sum['reward']),
        'consumer_70_percent' => historicalMoney($sum['consumer']),
    ];
    $skip = function ($reason) use (&$result) {
        $result['status'] = 'skipped';
        $result['reason'] = $reason;
        return $result;
    };
    if (!$rows || $sum['consumer'] <= 0 || $sum['platform'] + $sum['reward'] <= 0) {
        return $skip('无有效订单或分账金额不足一分，需人工核账');
    }
    foreach ($rows as $row) {
        if (historicalCents($row['reward_amount']) <= 0) {
            return $skip('有订单的推广10%不足一分，现有结算入口无法入账零元明细');
        }
        if ($row['pays_type'] === null) {
            return $skip('候选订单支付类型为空，旧日结统计未包含该订单');
        }
    }

    $daily = historicalQuery($db,
        'SELECT id, total_revenue, is_checked FROM DailyVenueRevenue WHERE `date` = ? AND venue_id = ?' . ($lock ? ' FOR UPDATE' : ''),
        [$date, $venueId]
    );
    if (count($daily) !== 1) return $skip('日结记录缺失或重复');
    $dailyId = (int)$daily[0]['id'];
    $result['daily_revenue_id'] = $dailyId;
    $result['old_daily_total'] = (string)$daily[0]['total_revenue'];
    if ((int)$daily[0]['is_checked'] !== 1) return $skip('日结未核对：不能从尚未入账的金额扣款');
    if (historicalCents($daily[0]['total_revenue']) < $sum['gross']) {
        return $skip('旧日结金额小于候选跨场地订单金额，需人工核账');
    }
    $legacyGross = historicalQuery($db,
        "SELECT ROUND(COALESCE(SUM(payment_amount), 0), 2) AS gross
         FROM orders WHERE DATE(end_time) = ? AND reservation_id = ? AND pays_type != '能量'",
        [$date, $venueId]
    );
    if (historicalCents($legacyGross[0]['gross']) !== historicalCents($daily[0]['total_revenue'])) {
        return $skip('旧日结金额与原脚本统计口径不一致，先核查历史订单');
    }

    $credits = historicalQuery($db,
        "SELECT id, venue_id, change_type, change_amount FROM fund_changes
         WHERE source_type = 'DailyVenueRevenue' AND source_id = ?" . ($lock ? ' FOR UPDATE' : ''),
        [$dailyId]
    );
    if (count($credits) !== 1 || (int)$credits[0]['venue_id'] !== (int)$venueId
        || $credits[0]['change_type'] !== 'revenue'
        || historicalCents($credits[0]['change_amount']) !== historicalCents($daily[0]['total_revenue'])) {
        return $skip('旧日结已核对，但原始资金流水不匹配');
    }

    $markers = historicalQuery($db,
        "SELECT id, change_type, change_amount FROM fund_changes
         WHERE venue_id = ? AND source_type = 'KWX8899Historical' AND source_id = ?" . ($lock ? ' FOR UPDATE' : ''),
        [$venueId, $dailyId]
    );
    $ledgers = historicalQuery($db,
        "SELECT id, amount FROM kwx_8899_fee_exempt_ledger
         WHERE venue_id = ? AND source_type = 'daily_revenue' AND source_id = ?" . ($lock ? ' FOR UPDATE' : ''),
        [$venueId, $dailyId]
    );
    $logs = historicalQuery($db,
        'SELECT order_id, reward_amount FROM venue_promotion_reward_logs WHERE reward_date = ? AND consumer_venue_id = ?',
        [$date, $venueId]
    );
    if ($markers || $ledgers) {
        $logged = [];
        $loggedReward = 0;
        foreach ($logs as $log) {
            $logged[(string)$log['order_id']] = true;
            $loggedReward += historicalCents($log['reward_amount']);
        }
        $expected = array_fill_keys(array_map(function ($row) { return (string)$row['order_id']; }, $rows), true);
        if (count($markers) === 1 && count($ledgers) === 1
            && $markers[0]['change_type'] === 'withdrawal'
            && historicalCents($markers[0]['change_amount']) === $sum['platform'] + $sum['reward']
            && historicalCents($ledgers[0]['amount']) === $sum['consumer']
            && !array_diff_key($logged, $expected) && !array_diff_key($expected, $logged)
            && count($logs) === $sum['orders']
            && $loggedReward === $sum['reward']) {
            $result['status'] = 'already_backfilled';
            return $result;
        }
        return $skip('发现部分历史补账记录或金额变化，禁止重复扣款；请人工核查');
    }
    if ($logs) return $skip('已有推广明细，但没有配套扣款和免重复扣费台账');
    foreach ($rows as $row) {
        $existing = historicalQuery($db,
            'SELECT id FROM venue_promotion_reward_logs WHERE order_id = ? LIMIT 1',
            [(string)$row['order_id']]
        );
        if ($existing) return $skip('候选订单已有其他日期或来源的推广明细');
    }
    $funds = historicalQuery($db,
        'SELECT account_balance FROM venue_funds WHERE venue_id = ?' . ($lock ? ' FOR UPDATE' : ''),
        [$venueId]
    );
    if (count($funds) !== 1) return $skip('场地提现账户不存在或重复');
    // Read after locking funds. A concurrent withdrawal must finish first;
    // FOR UPDATE is a current read even under MySQL's REPEATABLE READ.
    $withdrawals = historicalQuery($db,
        "SELECT id FROM withdrawal_requests WHERE venue_id = ?
         AND application_time >= '2026-10-01 00:00:00'
         AND (withdrawal_type IS NULL OR withdrawal_type = 'account') LIMIT 1"
         . ($lock ? ' FOR UPDATE' : ''),
        [$venueId]
    );
    if ($withdrawals) return $skip('该场地 10 月 1 日以来有提现申请，先人工核清余额与计费');
    $balance = historicalCents($funds[0]['account_balance']);
    $exemptRows = historicalQuery($db,
        'SELECT COALESCE(SUM(amount), 0) AS amount FROM kwx_8899_fee_exempt_ledger WHERE venue_id = ?',
        [$venueId]
    );
    $exempt = historicalCents($exemptRows[0]['amount']);
    $debit = $sum['platform'] + $sum['reward'];
    $result['current_balance'] = historicalMoney($balance);
    $result['balance_after_debit'] = historicalMoney($balance - $debit);
    if ($balance < $debit || $exempt < 0 || $balance - $debit < $exempt + $sum['consumer']) {
        return $skip('余额不足以完成补扣并保留已分账免重复扣费金额');
    }
    $result['status'] = 'ready';
    return $result;
}

function historicalExecute(Database $db, $date, $venueId, $initial, $operatorUid) {
    $db->beginTransaction();
    try {
        $rows = historicalOrders($db, $date, $venueId, true);
        if (json_encode($rows) !== json_encode($initial)) {
            throw new RuntimeException('候选订单在预览后变化；此场地日期已跳过');
        }
        $report = historicalInspect($db, $date, $venueId, $rows, true);
        if ($report['status'] !== 'ready') {
            $db->rollBack();
            return $report;
        }
        foreach ($rows as $row) {
            $refund = historicalQuery($db,
                'SELECT id FROM refund_records WHERE order_id = ? LIMIT 1 FOR UPDATE',
                [(string)$row['order_id']]
            );
            if ($refund) throw new RuntimeException('订单新增退款记录，已回滚整组补账');
        }
        $sum = historicalSummary($rows);
        $debit = historicalMoney($sum['platform'] + $sum['reward']);
        $after = $report['balance_after_debit'];
        $dailyId = (int)$report['daily_revenue_id'];
        $ok = $db->query(
            'UPDATE venue_funds SET account_balance = account_balance - ? WHERE venue_id = ? AND account_balance >= ?',
            [$debit, $venueId, $debit], true
        );
        if ($ok !== 1) throw new RuntimeException('补扣消费场地余额失败');
        $remarks = '历史账期=' . $date . '；旧日结ID=' . $dailyId . '；平台20%=' . historicalMoney($sum['platform'])
            . '；推广10%=' . historicalMoney($sum['reward']) . '；历史70/20/10分账补扣';
        $ok = $db->query(
            "INSERT INTO fund_changes
             (venue_id, change_type, change_amount, balance_after_change, change_reason,
              operator_id, remarks, source_type, source_id)
             VALUES (?, 'withdrawal', ?, ?, '8899历史跨场地分账补扣', ?, ?, 'KWX8899Historical', ?)",
            [$venueId, $debit, $after, $operatorUid, $remarks, $dailyId], true
        );
        if ($ok !== 1) throw new RuntimeException('补扣资金流水写入失败');
        kwx8899Ledger($db, $venueId, $sum['consumer'] / 100, 'daily_revenue', $dailyId);

        foreach ($rows as $row) {
            $ok = $db->query(
                "INSERT INTO venue_promotion_reward_logs
                 (order_id, reward_date, invite_code, promotion_venue_id, consumer_venue_id,
                  invitee_uid, order_amount, reward_rate, reward_amount, reward_status,
                  source_type, generated_at, remark, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0.10, ?, 'pending', 'daily_17_snapshot',
                         NOW(), '8899历史已核对日结分账补录', NOW(), NOW())",
                [(string)$row['order_id'], $date, (string)$row['invitation_code'],
                 (int)$row['promotion_venue_id'], $venueId, (int)$row['uid'],
                 (string)$row['order_amount'], (string)$row['reward_amount']], true
            );
            if ($ok !== 1) throw new RuntimeException('推广订单补录失败，整组已回滚');
        }
        $written = historicalInspect($db, $date, $venueId, $rows, true);
        $balanceRows = historicalQuery($db,
            'SELECT account_balance FROM venue_funds WHERE venue_id = ? FOR UPDATE', [$venueId]
        );
        if ($written['status'] !== 'already_backfilled' || count($balanceRows) !== 1
            || historicalCents($balanceRows[0]['account_balance']) !== historicalCents($after)) {
            throw new RuntimeException('补账后明细、资金流水、免重复扣费台账或余额校验失败，整组已回滚');
        }
        $db->commit();
        $report['status'] = 'backfilled_pending_settlement';
        return $report;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

$start = historicalArg('start', '2026-10-01');
$end = historicalArg('end', '2026-10-05');
$execute = in_array('--execute', $argv, true);
$operatorUid = (int)historicalArg('operator-uid', '0');
if (!historicalDate($start) || !historicalDate($end) || $start > $end
    || $start < '2026-10-01' || $end >= KWX_8899_CUTOVER_DATE || $end >= date('Y-m-d')) {
    fwrite(STDERR, "只允许补 2026-10-01 到 2026-10-06 中已经结束的自然日；请检查 --start / --end\n");
    exit(1);
}
foreach ($argv as $index => $arg) {
    if ($index === 0) continue;
    if ($arg !== '--execute' && strpos($arg, '--start=') !== 0
        && strpos($arg, '--end=') !== 0 && strpos($arg, '--operator-uid=') !== 0) {
        fwrite(STDERR, "未知参数：{$arg}\n");
        exit(1);
    }
}
if ($execute && $operatorUid <= 0) {
    fwrite(STDERR, "正式补账必须指定平台管理员 --operator-uid=数字；不加 --execute 只预览\n");
    exit(1);
}

$db = null;
$report = ['mode' => $execute ? 'execute' : 'preview', 'start' => $start, 'end' => $end, 'groups' => []];
$hasSkipped = false;
try {
    $db = new Database();
    if ($execute) {
        $tables = historicalQuery($db,
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('venue_funds', 'DailyVenueRevenue', 'fund_changes',
                                  'venue_promotion_reward_logs', 'kwx_8899_fee_exempt_ledger')"
        );
        $engines = [];
        foreach ($tables as $table) $engines[$table['TABLE_NAME']] = $table['ENGINE'];
        foreach (['venue_funds', 'DailyVenueRevenue', 'fund_changes',
                  'venue_promotion_reward_logs', 'kwx_8899_fee_exempt_ledger'] as $table) {
            if (strtolower((string)($engines[$table] ?? '')) !== 'innodb') {
                throw new RuntimeException($table . ' 不存在或不是 InnoDB，不能保证整组事务回滚');
            }
        }
        $operator = historicalQuery($db,
            'SELECT uid FROM admin_users WHERE uid = ? AND role_id IN (1, 2) LIMIT 1', [$operatorUid]
        );
        if (!$operator) throw new RuntimeException('指定的记账操作员必须是 role_id=1 或 2 的平台管理员');
    }
    $duplicate = historicalQuery($db,
        "SELECT venue_unique_subtitle FROM venues WHERE venue_subtitle = '8899'
         AND venue_unique_subtitle IS NOT NULL
         AND venue_unique_subtitle NOT IN ('', '8899')
         GROUP BY venue_unique_subtitle HAVING COUNT(*) > 1 LIMIT 1"
    );
    if ($duplicate) throw new RuntimeException('8899 二级推广码重复，禁止补账');

    $day = $start;
    while ($day <= $end) {
        if ($execute) kwx8899AcquireDailyLock($db, $day);
        $all = historicalOrders($db, $day);
        $groups = [];
        foreach ($all as $row) $groups[(int)$row['consumer_venue_id']][] = $row;
        ksort($groups);
        foreach ($groups as $venueId => $initial) {
            try {
                $item = $execute
                    ? historicalExecute($db, $day, $venueId, $initial, $operatorUid)
                    : historicalInspect($db, $day, $venueId, $initial, false);
                if ($item['status'] === 'skipped') $hasSkipped = true;
                $report['groups'][] = $item;
            } catch (Throwable $e) {
                $hasSkipped = true;
                $report['groups'][] = [
                    'date' => $day, 'consumer_venue_id' => (int)$venueId,
                    'status' => 'error', 'reason' => $e->getMessage(),
                ];
            }
        }
        $day = date('Y-m-d', strtotime($day . ' +1 day'));
    }
    $totals = [
        'ready' => 0, 'backfilled_pending_settlement' => 0,
        'already_backfilled' => 0, 'skipped' => 0, 'error' => 0,
        'ready_platform_cents' => 0, 'ready_promotion_cents' => 0,
        'written_platform_cents' => 0, 'written_promotion_cents' => 0,
    ];
    foreach ($report['groups'] as $item) {
        $status = $item['status'];
        $totals[$status]++;
        if ($status === 'ready') {
            $totals['ready_platform_cents'] += historicalCents($item['platform_20_percent']);
            $totals['ready_promotion_cents'] += historicalCents($item['promotion_10_percent']);
        } elseif ($status === 'backfilled_pending_settlement') {
            $totals['written_platform_cents'] += historicalCents($item['platform_20_percent']);
            $totals['written_promotion_cents'] += historicalCents($item['promotion_10_percent']);
        }
    }
    $report['summary'] = [
        'ready' => $totals['ready'],
        'backfilled_pending_settlement' => $totals['backfilled_pending_settlement'],
        'already_backfilled' => $totals['already_backfilled'],
        'skipped' => $totals['skipped'],
        'error' => $totals['error'],
        'ready_platform_20_percent' => historicalMoney($totals['ready_platform_cents']),
        'ready_promotion_10_percent' => historicalMoney($totals['ready_promotion_cents']),
        'written_platform_20_percent' => historicalMoney($totals['written_platform_cents']),
        'written_promotion_10_percent_pending_settlement' => historicalMoney($totals['written_promotion_cents']),
    ];
    $report['message'] = $hasSkipped
        ? '部分场地日期尚未补账；核对原因后可重复执行，已补记录不会重复扣款'
        : ($execute ? '消费场地补扣及待结算推广明细处理完成；B 场地尚需后台结算' : '预览完成，无资金写入');
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($hasSkipped ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, '补账中止：' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($db) $db->close();
}
