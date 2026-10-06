<?php
declare(strict_types=1);

// 临时隐藏推广预估卡片和结算菜单；闭环完成后只需将 false 改为 true。
const KWX_8899_PROMOTION_UI_VISIBLE = true;

/**
 * 仅统计当前加盟商已绑定且 venue_subtitle=8899 的场地。
 * 二级邀请码取 venue_unique_subtitle；同场地消费不产生推广收益。
 */
function kwx_promotion_preview_for_venues(Database $db, array $boundVenueIds, string $todayStart, string $tomorrowStart): array
{
    $boundVenueIds = array_values(array_unique(array_filter(array_map('intval', $boundVenueIds), static function ($id) {
        return $id > 0;
    })));
    if (!$boundVenueIds) {
        return ['visible' => false, 'venues' => []];
    }

    $boundMarks = implode(',', array_fill(0, count($boundVenueIds), '?'));
    $venues = $db->query(
        "SELECT id, venue_name, venue_unique_subtitle
         FROM venues
         WHERE id IN ({$boundMarks})
           AND venue_subtitle = '8899'
           AND venue_unique_subtitle IS NOT NULL
           AND TRIM(venue_unique_subtitle) <> ''
           AND TRIM(venue_unique_subtitle) <> '8899'
         ORDER BY id ASC",
        $boundVenueIds
    );
    if ($venues === false) {
        throw new RuntimeException('查询 8899 场地失败');
    }
    if (!$venues) {
        return ['visible' => false, 'venues' => []];
    }

    $eligibleIds = array_map('intval', array_column($venues, 'id'));
    $marks = implode(',', array_fill(0, count($eligibleIds), '?'));

    // 本场地真实驾驶订单；若用户由其他 8899 场地二级邀请码引流，扣除该订单的 10%。
    $driving = $db->query(
        "SELECT o.reservation_id AS venue_id,
                ROUND(COALESCE(SUM(o.payment_amount), 0), 2) AS order_amount,
                ROUND(COALESCE(SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM venues promotion_venue
                    WHERE promotion_venue.venue_subtitle = '8899'
                      AND promotion_venue.venue_unique_subtitle IS NOT NULL
                      AND TRIM(promotion_venue.venue_unique_subtitle) <> ''
                      AND TRIM(promotion_venue.venue_unique_subtitle) <> '8899'
                      AND promotion_venue.venue_unique_subtitle = u.invitation_code
                      AND promotion_venue.id <> o.reservation_id
                ) THEN ROUND(o.payment_amount * 0.10, 2) ELSE 0 END), 0), 2) AS promotion_deduction
         FROM orders o
         LEFT JOIN users u ON u.uid = o.uid
         WHERE o.end_time >= ? AND o.end_time < ?
           AND o.reservation_id IN ({$marks})
           AND o.status = '已完成'
           AND COALESCE(o.payment_amount, 0) > 0
           AND (o.pays_type IS NULL OR o.pays_type <> '能量')
           AND (o.note IS NULL OR o.note NOT IN ('gift', '场地礼物'))
         GROUP BY o.reservation_id",
        array_merge([$todayStart, $tomorrowStart], $eligibleIds)
    );
    if ($driving === false) {
        throw new RuntimeException('查询预估驾驶收益失败');
    }

    $gifts = $db->query(
        "SELECT reservation_id AS venue_id,
                ROUND(COALESCE(SUM(payment_amount) / 10 * 0.6, 0), 2) AS gift_income
         FROM gift_orders
         WHERE send_time >= ? AND send_time < ?
           AND reservation_id IN ({$marks})
           AND status = '已完成'
         GROUP BY reservation_id",
        array_merge([$todayStart, $tomorrowStart], $eligibleIds)
    );
    if ($gifts === false) {
        throw new RuntimeException('查询预估礼物收益失败');
    }

    // 与现有 KWX FreezeInviteRewardDaily.php 的 8899 归因和订单排除条件一致。
    $rewards = $db->query(
        "SELECT promotion_venue.id AS venue_id,
                ROUND(COALESCE(SUM(ROUND(o.payment_amount * 0.10, 2)), 0), 2) AS promotion_income
         FROM orders o
         INNER JOIN users u ON u.uid = o.uid
         INNER JOIN venues promotion_venue
                 ON promotion_venue.venue_subtitle = '8899'
                AND promotion_venue.venue_unique_subtitle IS NOT NULL
                AND TRIM(promotion_venue.venue_unique_subtitle) <> ''
                AND TRIM(promotion_venue.venue_unique_subtitle) <> '8899'
                AND promotion_venue.venue_unique_subtitle = u.invitation_code
         WHERE o.end_time >= ? AND o.end_time < ?
           AND promotion_venue.id IN ({$marks})
           AND o.reservation_id IS NOT NULL AND o.reservation_id > 0
           AND o.reservation_id <> promotion_venue.id
           AND o.status = '已完成'
           AND COALESCE(o.payment_amount, 0) > 0
           AND (o.pays_type IS NULL OR o.pays_type <> '能量')
           AND (o.note IS NULL OR o.note NOT IN ('gift', '场地礼物'))
         GROUP BY promotion_venue.id",
        array_merge([$todayStart, $tomorrowStart], $eligibleIds)
    );
    if ($rewards === false) {
        throw new RuntimeException('查询预估推广收益失败');
    }

    $byVenue = static function (array $rows): array {
        $index = [];
        foreach ($rows as $row) {
            $index[(int)$row['venue_id']] = $row;
        }
        return $index;
    };
    $drivingByVenue = $byVenue($driving);
    $giftsByVenue = $byVenue($gifts);
    $rewardsByVenue = $byVenue($rewards);

    $venuePreviews = [];
    $totalPromotion = 0.0;
    $totalTodayIncome = 0.0;
    foreach ($venues as $venue) {
        $id = (int)$venue['id'];
        $orderAmount = (float)($drivingByVenue[$id]['order_amount'] ?? 0);
        $promotionDeduction = (float)($drivingByVenue[$id]['promotion_deduction'] ?? 0);
        $giftIncome = (float)($giftsByVenue[$id]['gift_income'] ?? 0);
        $promotionIncome = (float)($rewardsByVenue[$id]['promotion_income'] ?? 0);
        $todayIncome = round($orderAmount - $promotionDeduction + $giftIncome, 2);
        $totalPromotion += $promotionIncome;
        $totalTodayIncome += $todayIncome;

        $venuePreviews[] = [
            'id' => $id,
            'venue_name' => (string)$venue['venue_name'],
            'promotion_income' => number_format($promotionIncome, 2, '.', ''),
            'today_income' => number_format($todayIncome, 2, '.', ''),
        ];
    }

    return [
        'visible' => true,
        'eligible_venue_count' => count($venuePreviews),
        'total_promotion_income' => number_format($totalPromotion, 2, '.', ''),
        'total_today_income' => number_format($totalTodayIncome, 2, '.', ''),
        'venues' => $venuePreviews,
    ];
}
