<?php
require_once '../Database.php'; 
require_once '../RedisHelper.php';
require_once __DIR__ . '/../lib/kwx_8899_policy.php';
require_once __DIR__ . '/../lib/withdrawal_display.php';
require_once __DIR__ . '/../lib/voice_room_fee.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function getAllVenueFrozenAmounts(array $venueIds): array
{
    $amounts = [];
    foreach ($venueIds as $venueId) {
        $amounts[(int)$venueId] = 0.0;
    }

    if (!$amounts) {
        return ['amounts' => [], 'available' => true];
    }

    if (!class_exists('Redis')) {
        return ['amounts' => $amounts, 'available' => false];
    }

    $redis = null;
    try {
        $redis = new RedisHelper();
        $redis->connect();
        $redis->selectDb(1);

        $pattern = count($venueIds) === 1 ? 'venue:' . (int)$venueIds[0] . ':frozen:*' : 'venue:*:frozen:*';
        // 稀疏匹配可能出现空扫描页，继续到游标结束。
        $redis->getNative()->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
        $keys = method_exists($redis, 'scan')
            ? $redis->scan($pattern, 500)
            : $redis->getAllKeys($pattern);

        foreach (array_unique($keys) as $key) {
            if (!preg_match('/^venue:(\d+):frozen:/', (string)$key, $matches)) {
                continue;
            }

            $venueId = (int)$matches[1];
            if (!array_key_exists($venueId, $amounts)) {
                continue;
            }

            $value = $redis->get($key);
            if ($value !== false && $value !== null) {
                $amounts[$venueId] += (float)$value;
            }
        }

        return ['amounts' => $amounts, 'available' => true];
    } catch (Throwable $e) {
        error_log('提现审批冻结金额汇总失败: ' . $e->getMessage());
        return ['amounts' => $amounts, 'available' => false];
    } finally {
        if ($redis instanceof RedisHelper) {
            try {
                $redis->close();
            } catch (Throwable $ignore) {
            }
        }
    }
}

// 创建数据库连接
$database = new Database();

// 从会话中获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

// 验证 session_token 并获取用户信息
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}
if (!in_array((int)$user['role_id'], [1, 2], true)) {
    echo json_encode(['code' => 1003, 'msg' => '无权查看提现审批列表', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

function paylistError($code, $message)
{
    echo json_encode(['code' => $code, 'msg' => $message, 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

function paylistDate($key)
{
    $value = $_GET[$key] ?? '';
    if ($value === '') return null;
    if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
        paylistError(1002, '日期格式应为 YYYY-MM-DD');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Shanghai'));
    if (!$date || $date->format('Y-m-d') !== $value || (int)$date->format('Y') < 1000) {
        paylistError(1002, '日期无效');
    }
    return $date;
}

// 金额按各自业务时间归属，结束日整天计入。
function paylistRange($alias, $timeColumn, $venueId, $start, $end)
{
    $where = '';
    $params = [];
    if ($venueId) {
        $where .= " AND {$alias}.venue_id = ?";
        $params[] = $venueId;
    }
    if ($start) {
        $where .= " AND {$alias}.{$timeColumn} >= ?";
        $params[] = $start->format('Y-m-d 00:00:00');
        if ($end->format('Y-m-d') !== '9999-12-31') {
            $where .= " AND {$alias}.{$timeColumn} < ?";
            $params[] = $end->modify('+1 day')->format('Y-m-d 00:00:00');
        }
    }
    return [$where, $params];
}

$start = paylistDate('start_date');
$end = paylistDate('end_date');
if (($start === null) !== ($end === null) || ($start && $start > $end)) {
    paylistError(1002, '请选择完整、有效的起止日期');
}

$rawVenue = $_GET['venue_id'] ?? '';
$selectedVenue = $rawVenue === '' ? 0 : filter_var($rawVenue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($selectedVenue === false) paylistError(1002, '场地参数无效');
// 提现会在同一事务内同时变更余额和语音房扣费记录，两者必须取自同一个读取快照。
try {
    kwxVoiceRoomBeginRead($database);
} catch (Throwable $e) {
    error_log('提现审批读取快照失败: ' . $e->getMessage());
    paylistError(500, '读取提现汇总失败，请重新查询');
}
$venues = $database->query('SELECT id, venue_name FROM venues ORDER BY venue_name, id');
if (!is_array($venues)) paylistError(500, '场地列表读取失败');
if ($selectedVenue && !in_array($selectedVenue, array_map('intval', array_column($venues, 'id')), true)) {
    paylistError(1002, '所选场地不存在');
}

[$requestWhere, $requestParams] = paylistRange('wr', 'application_time', $selectedVenue, $start, $end);
[$fundWhere, $fundParams] = paylistRange('fc', 'change_date', $selectedVenue, $start, $end);

// 查询未打款且仍可处理的申请列表，并关联 venue_funds 表获取 withdrawal_account，关联 venues 表获取 venue_name
$query = "SELECT
    wr.*,
    vc.fee_amount AS voice_room_fee_amount,
    vf.withdrawal_account,
    vf.bank_name,
    vf.bank_number, 
    v.venue_name
FROM
    withdrawal_requests wr
JOIN
    venue_funds vf ON wr.venue_id  = vf.venue_id 
JOIN
    venues v ON vf.venue_id  = v.id 
LEFT JOIN kwx_voice_room_fee_charges vc ON vc.withdrawal_request_id = wr.id
WHERE
    wr.payout_status = 0
    AND wr.application_status IN (0, 1)";

$result = $database->query($query . $requestWhere, $requestParams);

if ($result === false) {
    echo json_encode(['code' => 500, 'msg' => '查询提现申请列表失败', 'data' => []]);
    exit;
}

$canReject = (int)$user['role_id'] === 1 ? 1 : 0;
foreach ($result as &$row) {
    $row = kwxWithdrawalDisplayAmounts($row);
    $row['can_reject'] = $canReject;
}
unset($row);

$withdrawTotals = $database->query(
    "SELECT COUNT(*) AS request_count,
            COALESCE(SUM(CASE WHEN wr.application_status IN (0, 1, 2) THEN wr.withdrawal_amount END), 0) AS requested_settlement,
            COALESCE(SUM(CASE WHEN wr.payout_status = 0 AND wr.application_status IN (0, 1) THEN wr.actual_amount END), 0) AS pending_amount,
            COALESCE(SUM(CASE WHEN wr.payout_status = 1 THEN wr.actual_amount END), 0) AS paid_amount,
            COALESCE(SUM(CASE WHEN wr.payout_status = 1 THEN wr.technical_service_fee END), 0) AS paid_platform_fee,
            COALESCE(SUM(CASE WHEN wr.payout_status = 1 THEN wr.withdrawal_fee END), 0) AS paid_withdrawal_fee,
            COALESCE(SUM(CASE WHEN wr.application_status IN (0, 1, 2) AND vc.released_at IS NULL THEN vc.fee_amount END), 0) AS voice_fees_charged
     FROM withdrawal_requests wr
     LEFT JOIN kwx_voice_room_fee_charges vc ON vc.withdrawal_request_id = wr.id
     WHERE 1=1" . $requestWhere, $requestParams
);
$fundTotals = $database->query(
    "SELECT COALESCE(SUM(CASE WHEN fc.change_type = 'revenue' THEN fc.change_amount END), 0) AS inflow,
            COALESCE(SUM(CASE WHEN fc.change_type = 'withdrawal' THEN fc.change_amount END), 0) AS outflow
     FROM fund_changes fc WHERE 1=1" . $fundWhere, $fundParams
);
$activity = array_merge($withdrawTotals[0] ?? [], $fundTotals[0] ?? []);
$venueIds = $selectedVenue ? [$selectedVenue] : array_map('intval', array_column($venues, 'id'));
if ($start) {
    // 总费用按实际直播时间归属；已扣费用则跟随提现申请的申请时间。
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
    $rangeEnd = $end >= $now->setTime(0, 0) ? $now : $end->modify('+1 day');
    try {
        $periodFees = kwxVoiceRoomRangeFees($database, $venueIds, $start->format('Y-m-d H:i:s'), $rangeEnd->format('Y-m-d H:i:s'));
        $activity['voice_fees_accrued'] = round(array_sum($periodFees), 2);
    } catch (Throwable $e) {
        error_log('提现审批时段语音房费用读取失败: ' . $e->getMessage());
        $activity['voice_fees_accrued'] = null;
    }
}

// 有日期时使用历史流水和申请记录，避免用实时余额代替历史数据。
// 全部加盟商资金汇总，完整沿用提现申请页 CalculateSettlements.php 的口径。
// 先扣除各场地冻结、退款、锁定及两项未结算费用，再按免重复扣费台账分摊结算金额。
// 推广收益和已分账日收益已在账户余额中，不能在汇总时再次加减。
$venueWhere = $selectedVenue ? ' WHERE v.id = ?' : '';
$venueParams = $selectedVenue ? [$selectedVenue] : [];
$summaryRows = $start ? null : $database->query(
    "SELECT
        v.id AS venue_id,
        COALESCE(f.account_balance, 0) AS account_balance,
        COALESCE(c.withdraw_ratio, 20.00) AS withdraw_ratio,
        COALESCE(c.withdrawal_fee_rate, 0.00) AS withdrawal_fee_rate,
        COALESCE(r.refund_amount, 0) AS refund_amount,
        COALESCE(l.lock_amount, 0) AS lock_amount,
        COALESCE(l.lock_order_count, 0) AS lock_order_count,
        COALESCE(i.unsettled_image_amount, 0) AS unsettled_image_amount,
        COALESCE(d.not_checked_amount, 0) AS not_checked_amount
     FROM venues v
     LEFT JOIN (
         SELECT venue_id, MAX(COALESCE(account_balance, 0)) AS account_balance
         FROM venue_funds
         GROUP BY venue_id
     ) f ON f.venue_id = v.id
     LEFT JOIN (
         SELECT venue_id,
                MAX(COALESCE(withdraw_ratio, 20.00)) AS withdraw_ratio,
                MAX(COALESCE(withdrawal_fee_rate, 0.00)) AS withdrawal_fee_rate
         FROM venue_withdrawal_configs
         GROUP BY venue_id
     ) c ON c.venue_id = v.id
     LEFT JOIN (
         SELECT reservation_id AS venue_id, SUM(COALESCE(refund_amount, 0)) AS refund_amount
         FROM refund_records
         WHERE is_reduced != 1
         GROUP BY reservation_id
     ) r ON r.venue_id = v.id
     LEFT JOIN (
         SELECT venue_id,
                SUM(COALESCE(lock_amount, 0)) AS lock_amount,
                COUNT(*) AS lock_order_count
         FROM order_lock_records
         WHERE status = 1
         GROUP BY venue_id
     ) l ON l.venue_id = v.id
     LEFT JOIN (
         SELECT reservation_id AS venue_id,
                SUM(COALESCE(image_transmission_fee, 0)) AS unsettled_image_amount
         FROM image_transmission_fee_daily
         WHERE is_settlement = 0
         GROUP BY reservation_id
     ) i ON i.venue_id = v.id
     LEFT JOIN (
         SELECT venue_id, SUM(COALESCE(total_revenue, 0)) AS not_checked_amount
         FROM DailyVenueRevenue
         WHERE is_checked = 0
         GROUP BY venue_id
     ) d ON d.venue_id = v.id
     {$venueWhere} ORDER BY v.id ASC", $venueParams
);

$summary = null;
if (is_array($summaryRows)) {
    $frozenSummary = getAllVenueFrozenAmounts($venueIds);
    $voiceFees = null;
    try {
        $voiceFees = kwxVoiceRoomFees($database, $venueIds);
    } catch (Throwable $e) {
        error_log('提现审批实时语音房费读取失败: ' . $e->getMessage());
    }
    $feeExemptBalances = [];
    $feeCalculationError = '';
    try {
        // 一次读取所有场地的台账净额，包含入账、提现扣减及退回记录。
        $feeExemptRows = $summaryRows ? $database->query(
            'SELECT venue_id, COALESCE(SUM(amount), 0) AS amount
             FROM kwx_8899_fee_exempt_ledger' . ($selectedVenue ? ' WHERE venue_id = ?' : '') . ' GROUP BY venue_id', $venueParams
        ) : [];
        if (!is_array($feeExemptRows)) {
            throw new RuntimeException('读取免重复扣费余额失败');
        }
        foreach ($feeExemptRows as $feeExemptRow) {
            $feeExemptBalances[(int)$feeExemptRow['venue_id']] = round((float)$feeExemptRow['amount'], 2);
        }
    } catch (Throwable $e) {
        error_log('提现审批免重复扣费余额汇总失败: ' . $e->getMessage());
        $feeCalculationError = '免重复扣费金额暂时读取失败，请稍后刷新';
    }
    $feeCalculationAvailable = $feeCalculationError === '';
    $deductionsAvailable = $frozenSummary['available'] && $voiceFees !== null;
    $quoteAvailable = $deductionsAvailable && $feeCalculationAvailable;

    $summary = [
        'venue_count' => count($summaryRows),
        'total_balance' => 0.0,
        'available_balance' => $quoteAvailable ? 0.0 : null,
        'platform_deduction_amount' => $quoteAvailable ? 0.0 : null,
        'withdrawal_fee_amount' => $quoteAvailable ? 0.0 : null,
        'settlement_balance' => $deductionsAvailable ? 0.0 : null,
        'frozen_amount' => $frozenSummary['available'] ? 0.0 : null,
        'frozen_amount_available' => $frozenSummary['available'],
        'lock_order_count' => 0,
        'lock_amount' => 0.0,
        'not_checked_amount' => 0.0,
        'refund_amount' => 0.0,
        'unsettled_image_amount' => 0.0,
        'total_voice_room_fee' => $voiceFees === null ? null : array_sum(array_column($voiceFees, 'total_fee')),
        'unsettled_voice_room_fee' => $voiceFees === null ? null : array_sum(array_column($voiceFees, 'unsettled_fee')),
    ];

    foreach ($summaryRows as $summaryRow) {
        $venueId = (int)($summaryRow['venue_id'] ?? 0);
        $accountBalance = (float)($summaryRow['account_balance'] ?? 0);
        $refundAmount = (float)($summaryRow['refund_amount'] ?? 0);
        $lockAmount = (float)($summaryRow['lock_amount'] ?? 0);
        $imageAmount = (float)($summaryRow['unsettled_image_amount'] ?? 0);

        $summary['total_balance'] += $accountBalance;
        $summary['lock_order_count'] += (int)($summaryRow['lock_order_count'] ?? 0);
        $summary['lock_amount'] += $lockAmount;
        $summary['not_checked_amount'] += (float)($summaryRow['not_checked_amount'] ?? 0);
        $summary['refund_amount'] += $refundAmount;
        $summary['unsettled_image_amount'] += $imageAmount;

        if ($frozenSummary['available']) {
            $frozenAmount = (float)($frozenSummary['amounts'][$venueId] ?? 0);
            $summary['frozen_amount'] += $frozenAmount;
            if ($voiceFees === null) continue;
            $voiceAmount = $voiceFees[$venueId]['unsettled_fee'];
            $deductions = $frozenAmount + $refundAmount + $lockAmount + $imageAmount + $voiceAmount;
            $settlementBalance = max(
                0.0,
                round($accountBalance - $deductions, 2)
            );
            $platformRate = max(0.0, min(1.0, (float)($summaryRow['withdraw_ratio'] ?? 20.00) / 100));
            $withdrawalFeeRate = max(0.0, min(1.0, (float)($summaryRow['withdrawal_fee_rate'] ?? 0.00) / 100));

            $summary['settlement_balance'] += $settlementBalance;
            if ($feeCalculationAvailable) {
                try {
                    $quote = kwx8899Allocation(
                        $accountBalance, $feeExemptBalances[$venueId] ?? 0.0,
                        $deductions,
                        $settlementBalance, 0, $platformRate, $withdrawalFeeRate
                    );
                    $summary['platform_deduction_amount'] += $quote['technical_fee'];
                    $summary['withdrawal_fee_amount'] += $quote['withdrawal_fee'];
                    $summary['available_balance'] += $quote['actual_amount'];
                } catch (Throwable $e) {
                    error_log('提现审批场地 ' . $venueId . ' 结算金额汇总失败: ' . $e->getMessage());
                    // 不能把异常场地当作零元，也不能退回对整笔余额扣费的旧算法。
                    $feeCalculationAvailable = false;
                    $feeCalculationError = '部分场地结算金额校验失败，请先对账';
                    $summary['platform_deduction_amount'] = null;
                    $summary['withdrawal_fee_amount'] = null;
                    $summary['available_balance'] = null;
                }
            }
        }
    }
    $summary['fee_calculation_available'] = $feeCalculationAvailable && $voiceFees !== null;
    $summary['fee_calculation_error'] = $voiceFees === null ? '语音房费用读取失败，请重新查询' : $feeCalculationError;

    foreach ($summary as $key => $value) {
        if (is_float($value)) {
            $summary[$key] = round($value, 2);
        }
    }
}

$database->commit();
echo json_encode([
    'code' => 0,
    'msg' => '查询提现申请列表成功',
    'data' => $result,
    'venues' => $venues,
    'activity' => $activity,
    'summary' => $summary
], JSON_UNESCAPED_UNICODE);

$database->close();
?>
