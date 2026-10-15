<?php
/** CLI-only, default read-only. See the accompanying deployment instructions. */
require_once dirname(__DIR__) . '/Database.php';
require_once dirname(__DIR__) . '/lib/kwx_8899_policy.php';
date_default_timezone_set('Asia/Shanghai');
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('仅允许服务器终端运行'); }
$execute = in_array('--execute', $argv, true);
$operator = 0;
foreach ($argv as $arg) if (strpos($arg, '--operator=') === 0) $operator = (int)substr($arg, 11);
function revRows($db, $sql, $args = []) {
    $rows = $db->query($sql, $args);
    if ($rows === false) throw new RuntimeException('返还查询失败，请查看PHP错误日志');
    return $rows;
}
function revWrite($db, $sql, $args = [], $expected = null) {
    $n = $db->query($sql, $args, true);
    if ($n === false || ($expected !== null && $n !== $expected)) {
        throw new RuntimeException('返还写入失败或受影响行数不符，整笔回滚');
    }
}
function revCents($value) { return (int)round((float)$value * 100); }
function revMoney($cents) { return number_format($cents / 100, 2, '.', ''); }
function revPrint($value) { echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL; }
$db = new Database();
$inTransaction = false;
try {
    if (!kwx8899Stopped()) throw new RuntimeException('停止日期尚未到达');
    kwx8899AcquireDailyLock($db, 'rollback');
    if (kwx8899HistoricalReverted($db)) {
        revPrint(['code' => 0, 'status' => 'already_completed', 'msg' => '历史返还已完成，本次未重复加减任何金额']);
        exit;
    }
    // DDL is outside the money transaction. Preview never creates a table.
    if ($execute) {
        $who = revRows($db, 'SELECT uid FROM admin_users WHERE uid = ? AND role_id IN (1, 2)', [$operator]);
        if (!$who) throw new RuntimeException('正式执行必须指定有效管理员 --operator=管理员UID');
        $conn = $db->getConnection();
        if (!$conn->query("CREATE TABLE IF NOT EXISTS kwx_8899_rollback_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            run_key VARCHAR(40) NOT NULL UNIQUE,
            status VARCHAR(20) NOT NULL,
            operator_uid BIGINT NOT NULL,
            report_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) throw new RuntimeException('无法创建返还审计表');
    }
    $tables = ['venue_funds', 'DailyVenueRevenue', 'venue_promotion_reward_logs',
               'venue_promotion_reward_settlements', 'kwx_8899_fee_exempt_ledger', 'fund_changes', 'withdrawal_requests'];
    if ($execute) $tables[] = 'kwx_8899_rollback_runs';
    $marks = implode(',', array_fill(0, count($tables), '?'));
    $engines = revRows($db, "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($marks)", $tables);
    $byName = [];
    foreach ($engines as $t) $byName[strtolower($t['TABLE_NAME'])] = strtolower($t['ENGINE']);
    foreach ($tables as $t) if (($byName[strtolower($t)] ?? '') !== 'innodb') {
        throw new RuntimeException($t . '不是InnoDB或不存在，不能进行原子返还');
    }
    if (!$db->getConnection()->begin_transaction()) throw new RuntimeException('事务开启失败');
    $inTransaction = true;
    // Use the same locking reads for preview and execute; preview rolls everything back.
    $funds = revRows($db, 'SELECT venue_id, account_balance FROM venue_funds ORDER BY venue_id FOR UPDATE');
    $balances = [];
    foreach ($funds as $f) $balances[(int)$f['venue_id']] = revCents($f['account_balance']);
    $logs = revRows($db, "SELECT * FROM venue_promotion_reward_logs
        WHERE source_type = 'daily_17_snapshot' ORDER BY id FOR UPDATE");
    $dailies = revRows($db, "SELECT d.* FROM DailyVenueRevenue d
        WHERE (d.date >= ? AND d.date < ? AND d.revenue_before_deduction IS NOT NULL)
           OR EXISTS (SELECT 1 FROM venue_promotion_reward_logs l
               WHERE l.source_type = 'daily_17_snapshot' AND l.consumer_venue_id = d.venue_id AND l.reward_date = d.date)
        ORDER BY d.venue_id, d.date, d.id FOR UPDATE", [KWX_8899_CUTOVER_DATE, KWX_8899_STOP_DATE]);
    $ledger = revRows($db, 'SELECT * FROM kwx_8899_fee_exempt_ledger ORDER BY id FOR UPDATE');
    $batches = revRows($db, 'SELECT * FROM venue_promotion_reward_settlements ORDER BY id FOR UPDATE');
    $batchMap = [];
    foreach ($batches as $b) $batchMap[(int)$b['id']] = $b;
    $problems = [];
    $consumer = []; $creditedBatches = []; $affected = []; $dailyMap = []; $fundDelta = []; $originalCredits = []; $aCredits = []; $bDebits = [];
    foreach ($logs as $l) {
        $a = (int)$l['consumer_venue_id']; $b = (int)$l['promotion_venue_id'];
        $affected[$a] = true; $affected[$b] = true;
        if ($a <= 0 || $b <= 0 || $a === $b || $l['reward_date'] >= KWX_8899_STOP_DATE) {
            $problems[] = '异常推广明细ID=' . $l['id']; continue;
        }
        if (!in_array($l['reward_status'], ['pending', 'settled'], true)) {
            $problems[] = '明细ID=' . $l['id'] . '状态不是pending/settled，需先人工核对';
        }
        $gross = revCents($l['order_amount']); $reward = revCents($l['reward_amount']);
        $platform = (int)round($gross * 0.20);
        if ($gross < 0 || $reward < 0 || $reward !== (int)round($gross * 0.10)) $problems[] = '明细金额异常ID=' . $l['id'];
        $key = $a . ':' . $l['reward_date'];
        if (!isset($consumer[$key])) $consumer[$key] = ['venue_id' => $a, 'date' => $l['reward_date'], 'gross' => 0, 'reward' => 0, 'platform' => 0];
        $consumer[$key]['gross'] += $gross; $consumer[$key]['reward'] += $reward; $consumer[$key]['platform'] += $platform;
        if ($l['reward_status'] === 'settled') {
            $batchId = (int)$l['settlement_batch_id'];
            if (!isset($creditedBatches[$batchId])) $creditedBatches[$batchId] = ['venue_id' => $b, 'reward' => 0];
            if ($creditedBatches[$batchId]['venue_id'] !== $b) $problems[] = '结算批次混入不同场地ID=' . $batchId;
            $creditedBatches[$batchId]['reward'] += $reward;
        }
    }
    foreach ($dailies as $d) {
        $key = (int)$d['venue_id'] . ':' . $d['date'];
        if (isset($dailyMap[$key])) $problems[] = '重复日结=' . $key;
        $dailyMap[$key] = $d;
    }
    $dailyChanges = [];
    foreach ($consumer as $key => $part) {
        $a = $part['venue_id']; $day = $part['date']; $d = $dailyMap[$key] ?? null;
        if (!$d) {
            // No daily has been generated/credited: create a gross, unchecked row.
            $grossRows = revRows($db, "SELECT ROUND(COALESCE(SUM(payment_amount), 0), 2) AS amount FROM orders
                WHERE DATE(end_time) = ? AND reservation_id = ? AND status = '已完成'
                  AND (pays_type IS NULL OR pays_type <> '能量')
                  AND (note IS NULL OR note NOT IN ('gift', '场地礼物'))", [$day, $a]);
            $gross = revCents($grossRows[0]['amount']);
            if ($gross < $part['gross']) $problems[] = '订单收入小于推广订单原额=' . $key;
            $dailyChanges[] = ['id' => 0, 'venue_id' => $a, 'date' => $day, 'checked' => false,
                'before' => null, 'after' => revMoney($gross), 'balance_credit' => '0.00'];
            continue;
        }
        if ($d['revenue_before_deduction'] === null) { $problems[] = '日结缺少扣前原额=' . $key; continue; }
        $gross = revCents($d['revenue_before_deduction']); $net = revCents($d['total_revenue']);
        $deduction = $part['reward'] + $part['platform'];
        if ($gross - $net !== $deduction) $problems[] = '日结扣减与推广快照不符=' . $key;
        $checked = (int)$d['is_checked'] === 1;
        $dailyChanges[] = ['id' => (int)$d['id'], 'venue_id' => $a, 'date' => $day, 'checked' => $checked,
            'before' => revMoney($net), 'after' => revMoney($gross), 'balance_credit' => revMoney($checked ? $deduction : 0)];
        if ($checked) {
            $fundDelta[$a] = ($fundDelta[$a] ?? 0) + $deduction;
            $aCredits[$a] = ($aCredits[$a] ?? 0) + $deduction;
            $originalCredits['daily_revenue:' . $d['id']] = ['venue_id' => $a, 'amount' => $part['gross'] - $deduction];
            $fc = revRows($db, "SELECT change_amount FROM fund_changes WHERE venue_id = ? AND source_type = 'DailyVenueRevenue' AND source_id = ?", [$a, $d['id']]);
            $hist = revRows($db, "SELECT change_amount FROM fund_changes WHERE venue_id = ? AND source_type = 'KWX8899Historical' AND source_id = ? AND change_type = 'withdrawal'", [$a, $d['id']]);
            $priorDebit = 0;
            foreach ($hist as $h) $priorDebit += revCents($h['change_amount']);
            if (count($fc) !== 1 || revCents($fc[0]['change_amount']) - $priorDebit !== $net) $problems[] = '日结已核对但资金流水不符=' . $key;
        }
    }
    foreach ($batches as $b) {
        if ($b['settlement_status'] === 'settled' && !isset($creditedBatches[(int)$b['id']]) && revCents($b['reward_amount']) > 0) {
            $problems[] = '存在没有完整明细的已结算批次=' . $b['id'];
        }
    }
    foreach ($creditedBatches as $id => $part) {
        $b = $batchMap[$id] ?? null; $v = $part['venue_id'];
        if (!$b || (int)$b['promotion_venue_id'] !== $v || $b['settlement_status'] !== 'settled'
            || revCents($b['reward_amount']) !== $part['reward']) $problems[] = '推广结算批次金额/状态不符=' . $id;
        $originalCredits['promotion_batch:' . $id] = ['venue_id' => $v, 'amount' => $part['reward']];
        $fundDelta[$v] = ($fundDelta[$v] ?? 0) - $part['reward'];
        $bDebits[$v] = ($bDebits[$v] ?? 0) + $part['reward'];
    }
    // Credits must be intact. Spent exemptions are never silently erased.
    $ledgerSum = []; $creditSum = []; $seen = []; $ledgerChanges = [];
    foreach ($ledger as $e) {
        $v = (int)$e['venue_id']; $amount = revCents($e['amount']);
        $ledgerSum[$v] = ($ledgerSum[$v] ?? 0) + $amount;
        if (in_array($e['source_type'], ['daily_revenue', 'promotion_batch'], true)) {
            $affected[$v] = true;
            $key = $e['source_type'] . ':' . $e['source_id']; $expected = $originalCredits[$key] ?? null;
            if (!$expected || $expected['venue_id'] !== $v || $expected['amount'] !== $amount || isset($seen[$key])) {
                $problems[] = '免扣入账台账不匹配ID=' . $e['id'];
            }
            $seen[$key] = true;
            $creditSum[$v] = ($creditSum[$v] ?? 0) + $amount;
            $ledgerChanges[] = $e;
        }
    }
    foreach ($originalCredits as $key => $e) if ($e['amount'] !== 0 && !isset($seen[$key])) $problems[] = '缺少免扣原始入账=' . $key;
    $venueReport = [];
    ksort($affected);
    foreach ($affected as $v => $_) {
        $balance = $balances[$v] ?? null; $delta = $fundDelta[$v] ?? 0;
        $exempt = $ledgerSum[$v] ?? 0; $credit = $creditSum[$v] ?? 0;
        if ($exempt !== $credit) $problems[] = '场地' . $v . '免扣金额已使用或有其他调整，需先冲回对应提现（差额' . revMoney($exempt - $credit) . '）';
        if ($balance === null || $balance + $delta < 0) $problems[] = '场地' . $v . '缺少资金账户或冲回后余额不足';
        $open = revRows($db, "SELECT id FROM withdrawal_requests WHERE venue_id = ?
            AND application_status IN (0, 1) AND payout_status = 0
            AND (withdrawal_type IS NULL OR withdrawal_type <> 'gift')", [$v]);
        if ($open) $problems[] = '场地' . $v . '有未结束提现申请：' . implode(',', array_column($open, 'id'));
        $venueReport[] = ['venue_id' => $v, 'balance_before' => $balance === null ? null : revMoney($balance),
            'consumer_return' => revMoney($aCredits[$v] ?? 0), 'promotion_reclaim' => revMoney($bDebits[$v] ?? 0),
            'balance_change' => revMoney($delta), 'balance_after' => $balance === null ? null : revMoney($balance + $delta),
            'exempt_before' => revMoney($exempt), 'exempt_reversal' => revMoney(-$credit), 'exempt_after' => revMoney($exempt - $credit)];
    }
    $report = ['code' => $problems ? 409 : 0, 'mode' => $execute ? 'execute' : 'preview',
        'stop_date' => KWX_8899_STOP_DATE, 'log_count' => count($logs), 'venues' => $venueReport,
        'daily_changes' => $dailyChanges, 'blockers' => array_values(array_unique($problems))];
    if (!$execute || $problems) {
        $db->rollBack(); $inTransaction = false;
        revPrint($report); if ($problems) exit(1); exit;
    }
    // Record complete before/after plan inside the same transaction as all money.
    revWrite($db, "INSERT INTO kwx_8899_rollback_runs (run_key, status, operator_uid, report_json)
        VALUES ('stop_20261015', 'completed', ?, ?)", [$operator, json_encode($report, JSON_UNESCAPED_UNICODE)], 1);
    $last = revRows($db, 'SELECT LAST_INSERT_ID() AS id'); $runId = (int)$last[0]['id'];
    foreach ($venueReport as $v) {
        $id = $v['venue_id']; $delta = $fundDelta[$id] ?? 0;
        if ($delta !== 0) {
            revWrite($db, 'UPDATE venue_funds SET account_balance = account_balance + ? WHERE venue_id = ?', [revMoney($delta), $id], 1);
            revWrite($db, "INSERT INTO fund_changes (venue_id, change_type, change_amount, balance_after_change,
                change_reason, operator_id, remarks, source_type, source_id)
                VALUES (?, ?, ?, ?, '8899历史推广撤销返还', ?, ?, 'KWX8899Stop20261015', ?)",
                [$id, $delta > 0 ? 'revenue' : 'withdrawal', revMoney(abs($delta)), $v['balance_after'], $operator, '停止日=2026-10-15；返还批次=' . $runId . '；逐日依据见kwx_8899_rollback_runs', $id], 1);
        }
    }
    foreach ($dailyChanges as $d) {
        if ($d['id']) {
            revWrite($db, 'UPDATE DailyVenueRevenue SET total_revenue = ? WHERE id = ?', [$d['after'], $d['id']]);
        } else {
            revWrite($db, 'INSERT INTO DailyVenueRevenue (venue_id, date, total_revenue, revenue_before_deduction) VALUES (?, ?, ?, ?)',
                [$d['venue_id'], $d['date'], $d['after'], $d['after']], 1);
        }
    }
    foreach ($ledgerChanges as $e) {
        kwx8899Ledger($db, (int)$e['venue_id'], -revCents($e['amount']) / 100, 'stop_reversal', (int)$e['id']);
    }
    // Cancel reward rows while retaining original amounts, batches and snapshots as evidence.
    foreach ($logs as $l) {
        revWrite($db, "UPDATE venue_promotion_reward_logs SET reward_status = 'cancelled',
            remark = CONCAT(COALESCE(remark, ''), '；2026-10-15规则撤销，返还批次=', ?), updated_at = NOW() WHERE id = ?",
            [$runId, $l['id']], 1);
    }
    // Preserve settlement_status/amount as original evidence; remarks identify the reversal.
    foreach ($creditedBatches as $id => $_) {
        revWrite($db, "UPDATE venue_promotion_reward_settlements
            SET remark = CONCAT(COALESCE(remark, ''), '；已冲回，返还批次=', ?), updated_at = NOW() WHERE id = ?", [$runId, $id], 1);
    }
    if (!$db->getConnection()->commit()) throw new RuntimeException('提交返还事务失败');
    $inTransaction = false;
    $report['status'] = 'completed'; $report['run_id'] = $runId;
    revPrint($report);
} catch (Throwable $e) {
    if ($inTransaction) $db->rollBack();
    revPrint(['code' => 500, 'msg' => $e->getMessage(), 'money_committed' => false]);
    exit(1);
} finally { $db->close(); }
