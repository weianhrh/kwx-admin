<?php
/**
 * /api/franchise/venue_feature_overview.php
 *
 * 加盟商场地总览专用接口。
 * 目的：不同功能页返回不同的场地卡片统计信息。
 * 例如：提现申请页不再展示“今日收入/今日订单/在线设备/离线设备”，
 * 而是展示“总余额/可提现金额”。
 */
declare(strict_types=1);

require_once __DIR__ . '/../auth/_common.php';
require_once __DIR__ . '/../lib/venue_scope.php';
require_once __DIR__ . '/../RedisHelper.php';

auth_json_headers();
auth_handle_options();

function fvo_placeholders(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

function fvo_str_ids(array $ids): array
{
    return array_map('strval', venue_scope_ints($ids));
}

function fvo_money(float $value): string
{
    return '¥' . number_format($value, 2, '.', '');
}

function fvo_feature_label(string $feature, string $module): string
{
    if ($module === 'withdraw') {
        return '提现申请';
    }

    if ($module === 'complaint') {
        return '投诉处理';
    }

    if ($module === 'refund') {
        return '退款记录';
    }

    if ($module === 'violation') {
        return '违规记录';
    }

    $labels = [
        'device' => '设备管理',
        'order' => '订单管理',
        'finance' => '财务管理',
        'venue' => '场地管理',
        'ops' => '运营管理',
        'tools' => '系统工具',
    ];

    return $labels[$feature] ?? '场地总览';
}

function fvo_fetch_map(Database $db, string $sql, array $params, string $keyField): array
{
    try {
        $rows = $db->query($sql, $params) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        $key = (int)($row[$keyField] ?? 0);
        if ($key > 0) {
            $map[$key] = $row;
        }
    }
    return $map;
}

function fvo_fetch_sum_map(Database $db, string $table, string $venueField, string $amountExpr, array $venueIds, string $extraWhere = ''): array
{
    if (!venue_scope_has_table($db, $table)) {
        return [];
    }

    $venueIds = venue_scope_ints($venueIds);
    if (!$venueIds) {
        return [];
    }

    $ph = fvo_placeholders($venueIds);
    $params = fvo_str_ids($venueIds);

    $sql = "
        SELECT {$venueField} AS venue_id, COALESCE(SUM({$amountExpr}), 0) AS amount
        FROM {$table}
        WHERE {$venueField} IN ({$ph})
        {$extraWhere}
        GROUP BY {$venueField}
    ";

    $rows = fvo_fetch_map($db, $sql, $params, 'venue_id');
    $map = [];
    foreach ($rows as $venueId => $row) {
        $map[$venueId] = (float)($row['amount'] ?? 0);
    }
    return $map;
}

function fvo_generic_rows(Database $db, array $visibleVenues, array $venueIds): array
{
    $venueIds = venue_scope_ints($venueIds);
    if (!$venueIds) {
        return [];
    }

    $todayStart = date('Y-m-d 00:00:00');
    $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));
    $ph = fvo_placeholders($venueIds);

    $params = array_merge(
        fvo_str_ids($venueIds),
        fvo_str_ids($venueIds),
        [$todayStart, $tomorrowStart],
        fvo_str_ids($venueIds)
    );

    $rows = $db->query("
        SELECT
            v.id,
            v.venue_name,
            v.image_url,
            v.venue_status,
            COALESCE(d.total_devices, 0) AS total_devices,
            COALESCE(d.online_devices, 0) AS online_devices,
            COALESCE(d.offline_devices, 0) AS offline_devices,
            COALESCE(o.today_order_count, 0) AS today_order_count,
            COALESCE(o.today_revenue, 0) AS today_revenue
        FROM venues v
        LEFT JOIN (
            SELECT
                CAST(bind_site AS UNSIGNED) AS venue_id,
                COUNT(*) AS total_devices,
                SUM(CASE WHEN status = '在线' THEN 1 ELSE 0 END) AS online_devices,
                SUM(CASE WHEN status <> '在线' OR status IS NULL THEN 1 ELSE 0 END) AS offline_devices
            FROM vehicles
            WHERE bind_site IN ({$ph})
            GROUP BY CAST(bind_site AS UNSIGNED)
        ) d ON d.venue_id = v.id
        LEFT JOIN (
            SELECT
                reservation_id AS venue_id,
                COUNT(*) AS today_order_count,
                COALESCE(ROUND(SUM(CASE WHEN pays_type <> '能量' THEN payment_amount ELSE 0 END), 2), 0) AS today_revenue
            FROM orders
            WHERE reservation_id IN ({$ph})
              AND end_time >= ?
              AND end_time < ?
            GROUP BY reservation_id
        ) o ON o.venue_id = v.id
        WHERE v.id IN ({$ph})
        ORDER BY v.id ASC
    ", $params) ?: [];

    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['total_devices'] = (int)$row['total_devices'];
        $row['online_devices'] = (int)$row['online_devices'];
        $row['offline_devices'] = (int)$row['offline_devices'];
        $row['today_order_count'] = (int)$row['today_order_count'];
        $row['today_revenue'] = (float)$row['today_revenue'];
    }
    unset($row);

    // 防止 SQL 因异常少返回，用 visibleVenues 补齐基础场地资料。
    $rowMap = [];
    foreach ($rows as $row) {
        $rowMap[(int)$row['id']] = $row;
    }

    foreach ($visibleVenues as $venue) {
        $venueId = (int)($venue['id'] ?? 0);
        if ($venueId <= 0 || isset($rowMap[$venueId])) {
            continue;
        }
        $rowMap[$venueId] = [
            'id' => $venueId,
            'venue_name' => $venue['venue_name'] ?? ('场地 ' . $venueId),
            'image_url' => $venue['image_url'] ?? '',
            'venue_status' => $venue['venue_status'] ?? '未设置',
            'total_devices' => 0,
            'online_devices' => 0,
            'offline_devices' => 0,
            'today_order_count' => 0,
            'today_revenue' => 0.0,
        ];
    }

    ksort($rowMap, SORT_NUMERIC);
    return array_values($rowMap);
}

function fvo_frozen_amounts(array $venueIds): array
{
    $amounts = [];
    foreach ($venueIds as $venueId) {
        $amounts[(int)$venueId] = 0.0;
    }

    if (!class_exists('Redis')) {
        return $amounts;
    }

    $redis = null;
    try {
        $redis = new RedisHelper();
        $redis->connect();
        $redis->selectDb(1);

        foreach ($venueIds as $venueIdRaw) {
            $venueId = (int)$venueIdRaw;
            if ($venueId <= 0) {
                continue;
            }

            $keys = method_exists($redis, 'scan')
                ? $redis->scan("venue:{$venueId}:frozen:*", 200)
                : $redis->getAllKeys("venue:{$venueId}:frozen:*");

            foreach ($keys as $key) {
                $value = $redis->get($key);
                if ($value !== false && $value !== null) {
                    $amounts[$venueId] += (float)$value;
                }
            }
        }
    } catch (Throwable $e) {
        // Redis 不可用时不让总览接口整体失败。
    } finally {
        if ($redis instanceof RedisHelper) {
            try {
                $redis->close();
            } catch (Throwable $ignore) {
            }
        }
    }

    return $amounts;
}

function fvo_unchecked_amounts(Database $db, array $venueIds): array
{
    // 优先沿用 CalculateSettlements.php 的口径：DailyVenueRevenue.is_checked=0。
    if (venue_scope_has_table($db, 'DailyVenueRevenue')
        && venue_scope_has_column($db, 'DailyVenueRevenue', 'venue_id')
        && venue_scope_has_column($db, 'DailyVenueRevenue', 'total_revenue')
        && venue_scope_has_column($db, 'DailyVenueRevenue', 'is_checked')) {
        return fvo_fetch_sum_map(
            $db,
            'DailyVenueRevenue',
            'venue_id',
            'total_revenue',
            $venueIds,
            'AND is_checked = 0'
        );
    }

    // 兼容新 orders.is_checked 口径。
    if (venue_scope_has_table($db, 'orders')
        && venue_scope_has_column($db, 'orders', 'reservation_id')
        && venue_scope_has_column($db, 'orders', 'payment_amount')
        && venue_scope_has_column($db, 'orders', 'is_checked')) {
        return fvo_fetch_sum_map(
            $db,
            'orders',
            'reservation_id',
            "CASE WHEN pays_type <> '能量' THEN payment_amount ELSE 0 END",
            $venueIds,
            'AND is_checked = 0'
        );
    }

    return [];
}

function fvo_apply_withdraw_overview(Database $db, array $rows, array $venueIds): array
{
    $venueIds = venue_scope_ints($venueIds);
    $ph = fvo_placeholders($venueIds);
    $params = fvo_str_ids($venueIds);

    $fundRows = fvo_fetch_map(
        $db,
        "SELECT venue_id, COALESCE(account_balance, 0) AS account_balance FROM venue_funds WHERE venue_id IN ({$ph})",
        $params,
        'venue_id'
    );

    // withdraw_ratio 沿用历史字段名，实际含义为平台扣除比例。
    // 未配置场地默认平台扣除20%、提现手续费0%，加盟商实际到账80%。
    $withdrawConfigRows = [];
    if (venue_scope_has_table($db, 'venue_withdrawal_configs')) {
        $withdrawConfigRows = fvo_fetch_map(
            $db,
            "SELECT venue_id, withdraw_ratio, withdrawal_fee_rate
             FROM venue_withdrawal_configs
             WHERE venue_id IN ({$ph})",
            $params,
            'venue_id'
        );
    }

    $refundAmounts = fvo_fetch_sum_map(
        $db,
        'refund_records',
        'reservation_id',
        'refund_amount',
        $venueIds,
        'AND is_reduced != 1'
    );

    $lockAmounts = fvo_fetch_sum_map(
        $db,
        'order_lock_records',
        'venue_id',
        'lock_amount',
        $venueIds,
        'AND status = 1'
    );

    $imageFeeAmounts = fvo_fetch_sum_map(
        $db,
        'image_transmission_fee_daily',
        'reservation_id',
        'image_transmission_fee',
        $venueIds,
        'AND is_settlement = 0'
    );

    $frozenAmounts = fvo_frozen_amounts($venueIds);

    $summaryTotalBalance = 0.0;
    $summaryAvailable = 0.0;

    foreach ($rows as &$row) {
        $venueId = (int)($row['id'] ?? 0);
        $accountBalance = (float)($fundRows[$venueId]['account_balance'] ?? 0);
        $frozenAmount = (float)($frozenAmounts[$venueId] ?? 0);
        $refundAmount = (float)($refundAmounts[$venueId] ?? 0);
        $lockAmount = (float)($lockAmounts[$venueId] ?? 0);
        $imageFeeAmount = (float)($imageFeeAmounts[$venueId] ?? 0);

        $settlementBalance = max(
            0.0,
            $accountBalance - $frozenAmount - $refundAmount - $lockAmount - $imageFeeAmount
        );
        $platformDeductionRate = max(
            0.0,
            min(1.0, (float)($withdrawConfigRows[$venueId]['withdraw_ratio'] ?? 20.00) / 100)
        );
        $withdrawalFeeRate = max(
            0.0,
            min(1.0, (float)($withdrawConfigRows[$venueId]['withdrawal_fee_rate'] ?? 0.00) / 100)
        );
        $actualPayoutRate = max(0.0, 1.0 - $platformDeductionRate - $withdrawalFeeRate);
        $availableBalance = round($settlementBalance * $actualPayoutRate, 2);

        $summaryTotalBalance += $accountBalance;
        $summaryAvailable += $availableBalance;

        $row['overview_layout'] = 'layout-2';
        $row['overview_stats'] = [
            [
                'label' => '总余额',
                'value' => round($accountBalance, 2),
                'value_text' => fvo_money($accountBalance),
            ],
            [
                'label' => '可提现金额（' . number_format($actualPayoutRate * 100, 2, '.', '') . '%）',
                'value' => round($availableBalance, 2),
                'value_text' => fvo_money($availableBalance),
            ],
        ];
        $row['finance_detail'] = [
            'account_balance' => round($accountBalance, 2),
            'settlement_balance' => round($settlementBalance, 2),
            'available_balance' => round($availableBalance, 2),
            'platform_deduction_rate' => $platformDeductionRate,
            'withdrawal_fee_rate' => $withdrawalFeeRate,
            'actual_payout_rate' => $actualPayoutRate,
            'frozen_amount' => round($frozenAmount, 2),
            'refund_amount' => round($refundAmount, 2),
            'lock_amount' => round($lockAmount, 2),
            'image_fee_amount' => round($imageFeeAmount, 2),
        ];
    }
    unset($row);

    return [
        'rows' => $rows,
        'summary_finance' => [
            'total_balance' => round($summaryTotalBalance, 2),
            'available_balance' => round($summaryAvailable, 2),
        ],
        'summary_items' => [
            ['label' => '场地', 'value_text' => count($rows) . ' 个'],
            ['label' => '总余额', 'value_text' => fvo_money($summaryTotalBalance)],
            ['label' => '可提现金额', 'value_text' => fvo_money($summaryAvailable)],
        ],
    ];
}

function fvo_apply_complaint_overview(Database $db, array $rows, array $venueIds): array
{
    $venueIds = venue_scope_ints($venueIds);

    $summaryHandled = 0;
    $summaryPending = 0;
    $summaryToday = 0;
    $complaintMap = [];

    foreach ($venueIds as $venueId) {
        $complaintMap[(int)$venueId] = [
            'handled_count' => 0,
            'pending_count' => 0,
            'today_count' => 0,
        ];
    }

    if ($venueIds
        && venue_scope_has_table($db, 'Reports')
        && venue_scope_has_table($db, 'vehicles')
        && venue_scope_has_column($db, 'Reports', 'device_id')
        && venue_scope_has_column($db, 'Reports', 'status')
        && venue_scope_has_column($db, 'Reports', 'insert_time')
        && venue_scope_has_column($db, 'vehicles', 'serial_number')
        && venue_scope_has_column($db, 'vehicles', 'bind_site')) {

        $ph = fvo_placeholders($venueIds);
        $todayStart = date('Y-m-d 00:00:00');
        $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $params = array_merge([$todayStart, $tomorrowStart], fvo_str_ids($venueIds));

        $sql = "
            SELECT
                CAST(v.bind_site AS UNSIGNED) AS venue_id,
                SUM(CASE WHEN r.status = '已处理' THEN 1 ELSE 0 END) AS handled_count,
                SUM(CASE WHEN r.status <> '已处理' OR r.status IS NULL THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN r.insert_time >= ? AND r.insert_time < ? THEN 1 ELSE 0 END) AS today_count
            FROM Reports r
            INNER JOIN vehicles v
                ON r.device_id = v.serial_number
            WHERE v.bind_site IN ({$ph})
            GROUP BY CAST(v.bind_site AS UNSIGNED)
        ";

        try {
            $statRows = $db->query($sql, $params) ?: [];
            foreach ($statRows as $statRow) {
                $venueId = (int)($statRow['venue_id'] ?? 0);
                if ($venueId <= 0) {
                    continue;
                }

                $complaintMap[$venueId] = [
                    'handled_count' => (int)($statRow['handled_count'] ?? 0),
                    'pending_count' => (int)($statRow['pending_count'] ?? 0),
                    'today_count' => (int)($statRow['today_count'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            // 投诉统计失败不影响场地总览整体展示，降级为 0。
        }
    }

    foreach ($rows as &$row) {
        $venueId = (int)($row['id'] ?? 0);
        $stat = $complaintMap[$venueId] ?? [
            'handled_count' => 0,
            'pending_count' => 0,
            'today_count' => 0,
        ];

        $handledCount = (int)($stat['handled_count'] ?? 0);
        $pendingCount = (int)($stat['pending_count'] ?? 0);
        $todayCount = (int)($stat['today_count'] ?? 0);

        $summaryHandled += $handledCount;
        $summaryPending += $pendingCount;
        $summaryToday += $todayCount;

        $row['overview_layout'] = 'layout-3';
        $row['overview_stats'] = [
            [
                'label' => '已处理投诉',
                'value' => $handledCount,
                'value_text' => $handledCount . ' 条',
            ],
            [
                'label' => '待处理投诉',
                'value' => $pendingCount,
                'value_text' => $pendingCount . ' 条',
            ],
            [
                'label' => '今日投诉',
                'value' => $todayCount,
                'value_text' => $todayCount . ' 条',
            ],
        ];
        $row['complaint_detail'] = [
            'handled_count' => $handledCount,
            'pending_count' => $pendingCount,
            'today_count' => $todayCount,
        ];
        $row['overview_extra'] = [];
    }
    unset($row);

    return [
        'rows' => $rows,
        'summary_complaint' => [
            'handled_count' => $summaryHandled,
            'pending_count' => $summaryPending,
            'today_count' => $summaryToday,
        ],
        'summary_items' => [
            ['label' => '场地', 'value_text' => count($rows) . ' 个'],
            ['label' => '已处理投诉', 'value_text' => $summaryHandled . ' 条'],
            ['label' => '待处理投诉', 'value_text' => $summaryPending . ' 条'],
            ['label' => '今日投诉', 'value_text' => $summaryToday . ' 条'],
        ],
    ];
}

function fvo_apply_refund_overview(Database $db, array $rows, array $venueIds): array
{
    $venueIds = venue_scope_ints($venueIds);

    $refundedAmounts = [];
    $pendingAmounts = [];
    foreach ($venueIds as $venueId) {
        $refundedAmounts[(int)$venueId] = 0.0;
        $pendingAmounts[(int)$venueId] = 0.0;
    }

    if ($venueIds
        && venue_scope_has_table($db, 'refund_records')
        && venue_scope_has_column($db, 'refund_records', 'reservation_id')
        && venue_scope_has_column($db, 'refund_records', 'refund_amount')
        && venue_scope_has_column($db, 'refund_records', 'is_reduced')) {

        $refundedAmounts = array_replace($refundedAmounts, fvo_fetch_sum_map(
            $db,
            'refund_records',
            'reservation_id',
            'refund_amount',
            $venueIds,
            'AND is_reduced = 1'
        ));

        $pendingAmounts = array_replace($pendingAmounts, fvo_fetch_sum_map(
            $db,
            'refund_records',
            'reservation_id',
            'refund_amount',
            $venueIds,
            'AND COALESCE(is_reduced, 0) = 0'
        ));
    }

    $summaryRefunded = 0.0;
    $summaryPending = 0.0;

    foreach ($rows as &$row) {
        $venueId = (int)($row['id'] ?? 0);
        $refundedAmount = (float)($refundedAmounts[$venueId] ?? 0);
        $pendingAmount = (float)($pendingAmounts[$venueId] ?? 0);

        $summaryRefunded += $refundedAmount;
        $summaryPending += $pendingAmount;

        $row['overview_layout'] = 'layout-2';
        $row['overview_stats'] = [
            [
                'label' => '已退款金额',
                'value' => round($refundedAmount, 2),
                'value_text' => fvo_money($refundedAmount),
            ],
            [
                'label' => '待退款金额',
                'value' => round($pendingAmount, 2),
                'value_text' => fvo_money($pendingAmount),
            ],
        ];
        $row['refund_detail'] = [
            'refunded_amount' => round($refundedAmount, 2),
            'pending_amount' => round($pendingAmount, 2),
        ];
        $row['overview_extra'] = [];
    }
    unset($row);

    return [
        'rows' => $rows,
        'summary_refund' => [
            'refunded_amount' => round($summaryRefunded, 2),
            'pending_amount' => round($summaryPending, 2),
        ],
        'summary_items' => [
            ['label' => '场地', 'value_text' => count($rows) . ' 个'],
            ['label' => '已退款金额', 'value_text' => fvo_money($summaryRefunded)],
            ['label' => '待退款金额', 'value_text' => fvo_money($summaryPending)],
        ],
    ];
}

function fvo_apply_violation_overview(Database $db, array $rows, array $venueIds): array
{
    $venueIds = venue_scope_ints($venueIds);
    $todayStart = date('Y-m-d 00:00:00');
    $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));

    $venueBanCounts = [];
    $deviceBanCounts = [];
    foreach ($venueIds as $venueId) {
        $venueBanCounts[(int)$venueId] = 0;
        $deviceBanCounts[(int)$venueId] = 0;
    }

    if ($venueIds
        && venue_scope_has_table($db, 'venue_bans')
        && venue_scope_has_column($db, 'venue_bans', 'venue_id')
        && venue_scope_has_column($db, 'venue_bans', 'created_at')) {

        $ph = fvo_placeholders($venueIds);
        $params = array_merge([$todayStart, $tomorrowStart], fvo_str_ids($venueIds));
        $sql = "
            SELECT venue_id, COUNT(*) AS total_count
            FROM venue_bans
            WHERE created_at >= ?
              AND created_at < ?
              AND venue_id IN ({$ph})
            GROUP BY venue_id
        ";

        try {
            $statRows = $db->query($sql, $params) ?: [];
            foreach ($statRows as $statRow) {
                $venueId = (int)($statRow['venue_id'] ?? 0);
                if ($venueId > 0) {
                    $venueBanCounts[$venueId] = (int)($statRow['total_count'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            // 违规统计失败不影响总览展示，降级为 0。
        }
    }

    if ($venueIds
        && venue_scope_has_table($db, 'device_bans')
        && venue_scope_has_column($db, 'device_bans', 'venue_id')
        && venue_scope_has_column($db, 'device_bans', 'created_at')) {

        $ph = fvo_placeholders($venueIds);
        $params = array_merge([$todayStart, $tomorrowStart], fvo_str_ids($venueIds));
        $sql = "
            SELECT venue_id, COUNT(*) AS total_count
            FROM device_bans
            WHERE created_at >= ?
              AND created_at < ?
              AND venue_id IN ({$ph})
            GROUP BY venue_id
        ";

        try {
            $statRows = $db->query($sql, $params) ?: [];
            foreach ($statRows as $statRow) {
                $venueId = (int)($statRow['venue_id'] ?? 0);
                if ($venueId > 0) {
                    $deviceBanCounts[$venueId] = (int)($statRow['total_count'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            // 违规统计失败不影响总览展示，降级为 0。
        }
    }

    $summaryVenueBan = 0;
    $summaryDeviceBan = 0;

    foreach ($rows as &$row) {
        $venueId = (int)($row['id'] ?? 0);
        $todayVenueBanCount = (int)($venueBanCounts[$venueId] ?? 0);
        $todayDeviceBanCount = (int)($deviceBanCounts[$venueId] ?? 0);

        $summaryVenueBan += $todayVenueBanCount;
        $summaryDeviceBan += $todayDeviceBanCount;

        $row['overview_layout'] = 'layout-2';
        $row['overview_stats'] = [
            [
                'label' => '当日场地违规次数',
                'value' => $todayVenueBanCount,
                'value_text' => $todayVenueBanCount . ' 次',
            ],
            [
                'label' => '当日设备违规次数',
                'value' => $todayDeviceBanCount,
                'value_text' => $todayDeviceBanCount . ' 次',
            ],
        ];
        $row['violation_detail'] = [
            'today_venue_violation_count' => $todayVenueBanCount,
            'today_device_violation_count' => $todayDeviceBanCount,
        ];
        $row['overview_extra'] = [];
    }
    unset($row);

    return [
        'rows' => $rows,
        'summary_violation' => [
            'today_venue_violation_count' => $summaryVenueBan,
            'today_device_violation_count' => $summaryDeviceBan,
        ],
        'summary_items' => [
            ['label' => '场地', 'value_text' => count($rows) . ' 个'],
            ['label' => '当日场地违规次数', 'value_text' => $summaryVenueBan . ' 次'],
            ['label' => '当日设备违规次数', 'value_text' => $summaryDeviceBan . ' 次'],
        ],
    ];
}



$token = (string)($_COOKIE[AUTH_COOKIE] ?? '');
if ($token === '') {
    auth_out(1001, '未登录或会话已过期');
}

$db = new Database();
if (auth_has_column($db, 'admin_users', 'session_expires')) {
    $users = $db->query('SELECT * FROM admin_users WHERE session_token = ? AND session_expires > NOW() LIMIT 1', [$token]);
} else {
    $users = $db->query('SELECT * FROM admin_users WHERE session_token = ? LIMIT 1', [$token]);
}

$user = $users[0] ?? null;
if (!$user || empty($user['role_id'])) {
    $db->close();
    auth_clear_cookie();
    auth_out(1001, '未登录或会话已过期');
}

$feature = strtolower(trim((string)($_GET['feature'] ?? 'venue')));
$module = strtolower(trim((string)($_GET['module'] ?? '')));
$visibleVenues = venue_scope_visible_venues($db, $user);
$venueIds = venue_scope_ints(array_column($visibleVenues, 'id'));

if (!$venueIds) {
    $db->close();
    auth_out(0, 'ok', [
        'feature' => $feature,
        'module' => $module,
        'feature_label' => fvo_feature_label($feature, $module),
        'card_layout' => in_array($module, ['withdraw', 'refund', 'violation'], true) ? 'layout-2' : ($module === 'complaint' ? 'layout-3' : 'layout-4'),
        'summary' => [
            'venue_count' => 0,
            'today_revenue' => 0,
            'today_order_count' => 0,
            'online_devices' => 0,
            'total_devices' => 0,
        ],
        'summary_items' => [['label' => '场地', 'value_text' => '0 个']],
        'venues' => [],
    ]);
}

$rows = fvo_generic_rows($db, $visibleVenues, $venueIds);

$summary = [
    'venue_count' => count($rows),
    'today_revenue' => 0.0,
    'today_order_count' => 0,
    'online_devices' => 0,
    'total_devices' => 0,
];

foreach ($rows as &$row) {
    $summary['today_revenue'] += (float)($row['today_revenue'] ?? 0);
    $summary['today_order_count'] += (int)($row['today_order_count'] ?? 0);
    $summary['online_devices'] += (int)($row['online_devices'] ?? 0);
    $summary['total_devices'] += (int)($row['total_devices'] ?? 0);

    $row['overview_layout'] = 'layout-4';
    $row['overview_stats'] = [
        ['label' => '今日收入', 'value' => (float)($row['today_revenue'] ?? 0), 'value_text' => fvo_money((float)($row['today_revenue'] ?? 0))],
        ['label' => '今日订单', 'value' => (int)($row['today_order_count'] ?? 0), 'value_text' => (int)($row['today_order_count'] ?? 0) . ' 单'],
        ['label' => '在线设备', 'value' => (int)($row['online_devices'] ?? 0), 'value_text' => (int)($row['online_devices'] ?? 0) . '/' . (int)($row['total_devices'] ?? 0)],
        ['label' => '离线设备', 'value' => (int)($row['offline_devices'] ?? 0), 'value_text' => (int)($row['offline_devices'] ?? 0) . ' 台'],
    ];
    $row['overview_extra'] = [];
}
unset($row);

$summary['today_revenue'] = round((float)$summary['today_revenue'], 2);
$summaryItems = [
    ['label' => '场地', 'value_text' => count($rows) . ' 个'],
    ['label' => '今日收入', 'value_text' => fvo_money($summary['today_revenue'])],
    ['label' => '今日订单', 'value_text' => (int)$summary['today_order_count'] . ' 单'],
    ['label' => '在线设备', 'value_text' => (int)$summary['online_devices'] . '/' . (int)$summary['total_devices']],
];
$cardLayout = 'layout-4';

if ($module === 'withdraw') {
    $withdrawOverview = fvo_apply_withdraw_overview($db, $rows, $venueIds);
    $rows = $withdrawOverview['rows'];
    $summary['finance'] = $withdrawOverview['summary_finance'];
    $summaryItems = $withdrawOverview['summary_items'];
    $cardLayout = 'layout-2';
} elseif ($module === 'complaint') {
    $complaintOverview = fvo_apply_complaint_overview($db, $rows, $venueIds);
    $rows = $complaintOverview['rows'];
    $summary['complaint'] = $complaintOverview['summary_complaint'];
    $summaryItems = $complaintOverview['summary_items'];
    $cardLayout = 'layout-3';
} elseif ($module === 'refund') {
    $refundOverview = fvo_apply_refund_overview($db, $rows, $venueIds);
    $rows = $refundOverview['rows'];
    $summary['refund'] = $refundOverview['summary_refund'];
    $summaryItems = $refundOverview['summary_items'];
    $cardLayout = 'layout-2';
} elseif ($module === 'violation') {
    $violationOverview = fvo_apply_violation_overview($db, $rows, $venueIds);
    $rows = $violationOverview['rows'];
    $summary['violation'] = $violationOverview['summary_violation'];
    $summaryItems = $violationOverview['summary_items'];
    $cardLayout = 'layout-2';
}

$db->close();

auth_out(0, 'ok', [
    'feature' => $feature,
    'module' => $module,
    'feature_label' => fvo_feature_label($feature, $module),
    'card_layout' => $cardLayout,
    'summary' => $summary,
    'summary_items' => $summaryItems,
    'venues' => $rows,
]);
