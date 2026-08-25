<?php
require_once '../Database.php'; 
require_once '../RedisHelper.php';

header('Content-Type: application/json; charset=utf-8');

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

        $keys = method_exists($redis, 'scan')
            ? $redis->scan('venue:*:frozen:*', 500)
            : $redis->getAllKeys('venue:*:frozen:*');

        foreach ($keys as $key) {
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

// 查询未打款且仍可处理的申请列表，并关联 venue_funds 表获取 withdrawal_account，关联 venues 表获取 venue_name
$query = "SELECT
    wr.*,
    vf.withdrawal_account, 
    v.venue_name
FROM
    withdrawal_requests wr
JOIN
    venue_funds vf ON wr.venue_id  = vf.venue_id 
JOIN
    venues v ON vf.venue_id  = v.id 
WHERE
    wr.payout_status = 0
    AND wr.application_status IN (0, 1)";

$result = $database->query($query);

if ($result === false) {
    echo json_encode(['code' => 500, 'msg' => '查询提现申请列表失败', 'data' => []]);
    exit;
}

$canReject = (int)$user['role_id'] === 1 ? 1 : 0;
foreach ($result as &$row) {
    $row['can_reject'] = $canReject;
}
unset($row);

// 全部加盟商资金汇总，完整沿用提现申请页 CalculateSettlements.php 的口径。
// 先按场地分别扣除冻结、退款、锁定和图传费用，再按各场地自己的费率计算可提现与平台扣除。
$summaryRows = $database->query(
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
     ORDER BY v.id ASC"
);

$summary = null;
if (is_array($summaryRows)) {
    $venueIds = array_map(static function ($row) {
        return (int)($row['venue_id'] ?? 0);
    }, $summaryRows);
    $frozenSummary = getAllVenueFrozenAmounts($venueIds);

    $summary = [
        'venue_count' => count($summaryRows),
        'total_balance' => 0.0,
        'available_balance' => $frozenSummary['available'] ? 0.0 : null,
        'platform_deduction_amount' => $frozenSummary['available'] ? 0.0 : null,
        'settlement_balance' => $frozenSummary['available'] ? 0.0 : null,
        'frozen_amount' => $frozenSummary['available'] ? 0.0 : null,
        'frozen_amount_available' => $frozenSummary['available'],
        'lock_order_count' => 0,
        'lock_amount' => 0.0,
        'not_checked_amount' => 0.0,
        'refund_amount' => 0.0,
        'unsettled_image_amount' => 0.0,
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
            $settlementBalance = max(
                0.0,
                $accountBalance - $frozenAmount - $refundAmount - $lockAmount - $imageAmount
            );
            $platformRate = max(0.0, min(1.0, (float)($summaryRow['withdraw_ratio'] ?? 20.00) / 100));
            $withdrawalFeeRate = max(0.0, min(1.0, (float)($summaryRow['withdrawal_fee_rate'] ?? 0.00) / 100));
            $actualPayoutRate = max(0.0, 1.0 - $platformRate - $withdrawalFeeRate);

            $summary['frozen_amount'] += $frozenAmount;
            $summary['settlement_balance'] += $settlementBalance;
            $summary['platform_deduction_amount'] += round($settlementBalance * $platformRate, 2);
            $summary['available_balance'] += round($settlementBalance * $actualPayoutRate, 2);
        }
    }

    foreach ($summary as $key => $value) {
        if (is_float($value)) {
            $summary[$key] = round($value, 2);
        }
    }
}

// 返回成功信息
echo json_encode([
    'code' => 0,
    'msg' => '查询提现申请列表成功',
    'data' => $result,
    'summary' => $summary
], JSON_UNESCAPED_UNICODE);

$database->close();
?>
