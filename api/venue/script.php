<?php
require_once dirname(__DIR__) . '/Database.php';
require_once dirname(__DIR__) . '/lib/kwx_8899_policy.php';
require_once dirname(__DIR__) . '/lib/kwx_8899_cron_access.php';

date_default_timezone_set('Asia/Shanghai');
kwx8899RequireCronWebAccess();
$date = date('Y-m-d', strtotime('-1 day'));
foreach (($argv ?? []) as $arg) {
    if (strpos($arg, '--date=') === 0) {
        $date = substr($arg, 7);
    }
}
if (PHP_SAPI !== 'cli' && isset($_GET['date'])) {
    $date = (string)$_GET['date'];
}
$parsed = DateTime::createFromFormat('!Y-m-d', $date);
if (!$parsed || $parsed->format('Y-m-d') !== $date || $date >= date('Y-m-d')) {
    http_response_code(400);
    exit("日期必须是已经结束的 YYYY-MM-DD 自然日\n");
}
// Only the 8899 group needs the promotion snapshot. Ordinary venues commit
// separately so a broken promotion freeze cannot delay their legacy daily rows.
function kwx8899VerifyFrozenRevenueDay(Database $database, $date) {
    kwx8899AcquireDailyLock($database, $date);
    $day = kwx8899RequireFrozenDay($database, $date);
    // A completed order added after the freeze must not be settled at a stale split.
    $live = $database->query(
        "SELECT COUNT(*) AS n,
                ROUND(COALESCE(SUM(o.payment_amount), 0), 2) AS gross,
                ROUND(COALESCE(SUM(ROUND(o.payment_amount * 0.10, 2)), 0), 2) AS reward
         FROM orders o
         JOIN users u ON u.uid = o.uid
         JOIN venues p ON p.venue_subtitle = '8899'
           AND p.venue_unique_subtitle = u.invitation_code
           AND p.venue_unique_subtitle IS NOT NULL
           AND p.venue_unique_subtitle <> '' AND p.venue_unique_subtitle <> '8899'
         JOIN venues c ON c.id = o.reservation_id AND c.venue_subtitle = '8899'
         WHERE o.reservation_id <> p.id AND u.invitation_code IS NOT NULL
           AND u.invitation_code <> '' AND u.invitation_code <> '8899'
           AND o.status = '已完成' AND o.end_time >= ? AND o.end_time < DATE_ADD(?, INTERVAL 1 DAY)
           AND COALESCE(o.payment_amount, 0) > 0
           AND (o.pays_type IS NULL OR o.pays_type <> '能量')
           AND (o.note IS NULL OR o.note NOT IN ('gift', '场地礼物'))
           AND NOT EXISTS (SELECT 1 FROM refund_records rr WHERE rr.order_id = o.order_id)",
        [$date . ' 00:00:00', $date . ' 00:00:00']
    );
    if ($live === false || (int)$live[0]['n'] !== (int)$day['order_count']
        || abs((float)$live[0]['gross'] - (float)$day['order_amount']) > 0.005
        || abs((float)$live[0]['reward'] - (float)$day['reward_amount']) > 0.005) {
        throw new RuntimeException('当日跨场地订单与推广快照不一致，禁止生成8899场地日结');
    }
}

function kwx8899WriteRevenueGroup(Database $database, $date, $venues, $isNew8899) {
    if (!$venues) return 0;
    $transactionStarted = false;
    try {
        $database->beginTransaction();
        $transactionStarted = true;
        $processed = 0;
        foreach ($venues as $venue) {
        $venueId = (int)$venue['id'];
        $paymentFilter = $isNew8899
            ? "(pays_type IS NULL OR pays_type <> '能量')
               AND status = '已完成'
               AND (note IS NULL OR note NOT IN ('gift', '场地礼物'))"
            : "pays_type != '能量'";
        $grossRows = $database->query(
            "SELECT ROUND(COALESCE(SUM(payment_amount), 0), 2) AS gross
             FROM orders WHERE DATE(end_time) = ? AND reservation_id = ? AND {$paymentFilter}",
            [$date, $venueId]
        );
        if ($grossRows === false) {
            throw new RuntimeException("场地 {$venueId} 收益查询失败");
        }
        $gross = round((float)$grossRows[0]['gross'], 2);
        $amount = $gross;
        if ($isNew8899) {
            $parts = $database->query(
                "SELECT COALESCE(SUM(reward_amount), 0) AS reward,
                        COALESCE(SUM(ROUND(order_amount * 0.20, 2)), 0) AS platform
                 FROM venue_promotion_reward_logs
                 WHERE consumer_venue_id = ? AND reward_date = ?
                   AND source_type = 'daily_17_snapshot'",
                [$venueId, $date]
            );
            if ($parts === false) {
                throw new RuntimeException("场地 {$venueId} 推广扣减查询失败");
            }
            $amount = round($gross - (float)$parts[0]['reward'] - (float)$parts[0]['platform'], 2);
            if ($amount < 0) {
                throw new RuntimeException("场地 {$venueId} 收益小于零，禁止写入");
            }
        }
        $current = $database->query(
            'SELECT id, total_revenue, revenue_before_deduction, is_checked FROM DailyVenueRevenue WHERE date = ? AND venue_id = ? FOR UPDATE',
            [$date, $venueId]
        );
        if ($current === false) {
            throw new RuntimeException('查询日结记录失败');
        }
        $amountText = number_format($amount, 2, '.', '');
        if ($current) {
            if ((int)$current[0]['is_checked'] === 1) {
                if (abs((float)$current[0]['total_revenue'] - $amount) > 0.005
                    || ($isNew8899 && (
                        $current[0]['revenue_before_deduction'] === null
                        || abs((float)$current[0]['revenue_before_deduction'] - $gross) > 0.005
                    ))) {
                    throw new RuntimeException("场地 {$venueId} 已入账金额和新规则不符，必须先对账，禁止覆盖");
                }
                continue;
            }
            if ($isNew8899) {
                $ok = $database->query(
                    'UPDATE DailyVenueRevenue SET revenue_before_deduction = ?, total_revenue = ? WHERE id = ? AND is_checked = 0',
                    [number_format($gross, 2, '.', ''), $amountText, $current[0]['id']], true
                );
            } else {
                $ok = $database->query(
                    'UPDATE DailyVenueRevenue SET total_revenue = ? WHERE id = ? AND is_checked = 0',
                    [$amountText, $current[0]['id']], true
                );
            }
        } else {
            if ($isNew8899) {
                $ok = $database->query(
                    'INSERT INTO DailyVenueRevenue (venue_id, date, revenue_before_deduction, total_revenue) VALUES (?, ?, ?, ?)',
                    [$venueId, $date, number_format($gross, 2, '.', ''), $amountText], true
                );
            } else {
                $ok = $database->query(
                    'INSERT INTO DailyVenueRevenue (venue_id, date, total_revenue) VALUES (?, ?, ?)',
                    [$venueId, $date, $amountText], true
                );
            }
        }
        if ($ok === false) {
            throw new RuntimeException("场地 {$venueId} 写入日结失败");
        }
        $processed++;
        }
        $database->commit();
        $transactionStarted = false;
        return $processed;
    } catch (Throwable $e) {
        if ($transactionStarted) $database->rollBack();
        throw $e;
    }
}

$database = new Database();
try {
    $venues = $database->query('SELECT id, venue_subtitle FROM venues');
    if ($venues === false) throw new RuntimeException('读取场地失败');
    $newPeriod = kwx8899Active($date);
    $ordinaryVenues = [];
    $promotionVenues = [];
    foreach ($venues as $venue) {
        if ($newPeriod && (string)$venue['venue_subtitle'] === '8899') {
            $promotionVenues[] = $venue;
        } else {
            $ordinaryVenues[] = $venue;
        }
    }

    $result = [
        'code' => 0, 'date' => $date, 'processed' => 0,
        'ordinary' => ['status' => 'not_applicable', 'processed' => 0],
        'venue_8899' => ['status' => 'not_applicable', 'processed' => 0],
    ];
    $errors = [];
    if ($ordinaryVenues) {
        try {
            $count = kwx8899WriteRevenueGroup($database, $date, $ordinaryVenues, false);
            $result['ordinary'] = ['status' => 'completed', 'processed' => $count];
            $result['processed'] += $count;
        } catch (Throwable $e) {
            $result['ordinary'] = ['status' => 'failed', 'processed' => 0, 'reason' => $e->getMessage()];
            $errors[] = '普通场地：' . $e->getMessage();
        }
    }
    if ($promotionVenues) {
        try {
            kwx8899VerifyFrozenRevenueDay($database, $date);
            $count = kwx8899WriteRevenueGroup($database, $date, $promotionVenues, true);
            $result['venue_8899'] = ['status' => 'completed', 'processed' => $count];
            $result['processed'] += $count;
        } catch (Throwable $e) {
            $result['venue_8899'] = ['status' => 'failed', 'processed' => 0, 'reason' => $e->getMessage()];
            $errors[] = '8899场地：' . $e->getMessage();
        }
    }
    if ($errors) {
        $result['code'] = 500;
        $result['msg'] = implode('；', $errors) . '。已完成的场地组保留，可在修复后按日期重跑。';
        http_response_code(500);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if ($errors) exit(1);
} catch (Throwable $e) {
    error_log('KWX daily revenue: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['code' => 500, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
} finally {
    $database->close();
}
