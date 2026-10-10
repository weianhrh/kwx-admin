<?php
declare(strict_types=1);

// 加盟商/场地方只读推广账本。管理员的结算接口不对这两个角色开放。
require_once __DIR__ . '/../auth/_common.php';
require_once __DIR__ . '/../lib/venue_scope.php';

auth_json_headers();
auth_handle_options();

function promotionHistoryQuery(Database $db, string $sql, array $params = []): array
{
    $rows = $db->query($sql, $params);
    if ($rows === false) {
        throw new RuntimeException('推广历史查询失败');
    }
    return $rows;
}

function promotionHistoryAmounts(Database $db, array $venueIds): array
{
    $marks = implode(',', array_fill(0, count($venueIds), '?'));
    $rows = promotionHistoryQuery($db,
        "SELECT venue_id, COALESCE(SUM(amount), 0) AS credited_total
         FROM kwx_8899_fee_exempt_ledger
         WHERE source_type = 'promotion_batch' AND venue_id IN ({$marks})
         GROUP BY venue_id",
        $venueIds
    );
    $amounts = [];
    foreach ($rows as $row) {
        $amounts[(int)$row['venue_id']] = (float)$row['credited_total'];
    }
    return $amounts;
}

function promotionHistoryValidDate(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

$token = (string)($_COOKIE[AUTH_COOKIE] ?? '');
if ($token === '') {
    auth_out(1001, '未登录或会话已过期');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    auth_out(405, '该接口仅支持查询');
}

$db = new Database();
try {
    $users = auth_has_column($db, 'admin_users', 'session_expires')
        ? promotionHistoryQuery($db,
            'SELECT * FROM admin_users WHERE session_token = ? AND session_expires > NOW() LIMIT 1', [$token])
        : promotionHistoryQuery($db,
            'SELECT * FROM admin_users WHERE session_token = ? LIMIT 1', [$token]);
    $user = $users[0] ?? null;
    if (!$user || !in_array((int)($user['role_id'] ?? 0), [3, 4], true)) {
        auth_out(1003, '当前账号无权查看推广历史');
    }

    $boundIds = venue_scope_user_ids($db, $user);
    if (!$boundIds) {
        auth_out(1003, '当前账号没有关联的 8899 场地');
    }
    $marks = implode(',', array_fill(0, count($boundIds), '?'));
    $venues = promotionHistoryQuery($db,
        "SELECT id, venue_name, venue_unique_subtitle
         FROM venues WHERE id IN ({$marks}) AND venue_subtitle = '8899'
         ORDER BY id ASC",
        $boundIds
    );
    if (!$venues) {
        auth_out(1003, '当前账号没有关联的 8899 场地');
    }
    $eligibleIds = array_map('intval', array_column($venues, 'id'));
    $credited = promotionHistoryAmounts($db, $eligibleIds);
    $rawAction = $_GET['action'] ?? 'overview';
    $action = is_string($rawAction) ? $rawAction : '';

    // 仅按登录账号的关联场地计算，不采信前端传入的场地列表。
    if ($action === 'overview') {
        $eligibleMarks = implode(',', array_fill(0, count($eligibleIds), '?'));
        $counts = promotionHistoryQuery($db,
            "SELECT l.promotion_venue_id, COUNT(*) AS order_count,
                    MIN(l.reward_date) AS first_date, MAX(l.reward_date) AS last_date
             FROM venue_promotion_reward_logs l
             WHERE l.promotion_venue_id IN ({$eligibleMarks})
               AND l.source_type = 'daily_17_snapshot' AND l.reward_status = 'settled'
               AND EXISTS (
                   SELECT 1 FROM kwx_8899_fee_exempt_ledger e
                   WHERE e.venue_id = l.promotion_venue_id
                     AND e.source_type = 'promotion_batch'
                     AND e.source_id = l.settlement_batch_id AND e.amount > 0
               )
             GROUP BY l.promotion_venue_id",
            $eligibleIds
        );
        $countMap = [];
        foreach ($counts as $count) {
            $countMap[(int)$count['promotion_venue_id']] = $count;
        }
        $list = [];
        $totalCents = 0;
        $totalOrders = 0;
        foreach ($venues as $venue) {
            $id = (int)$venue['id'];
            $amount = round($credited[$id] ?? 0.0, 2);
            $orderCount = (int)($countMap[$id]['order_count'] ?? 0);
            $totalCents += (int)round($amount * 100);
            $totalOrders += $orderCount;
            $list[] = [
                'venue_id' => $id,
                'venue_name' => (string)$venue['venue_name'],
                'promotion_code' => (string)($venue['venue_unique_subtitle'] ?? ''),
                'credited_total' => number_format($amount, 2, '.', ''),
                'order_count' => $orderCount,
                'first_date' => (string)($countMap[$id]['first_date'] ?? ''),
                'last_date' => (string)($countMap[$id]['last_date'] ?? ''),
            ];
        }
        $db->close();
        auth_out(0, 'ok', [
            'venue_count' => count($list),
            'credited_total' => number_format($totalCents / 100, 2, '.', ''),
            'order_count' => $totalOrders,
            'venues' => $list,
        ]);
    }

    if ($action !== 'detail') {
        auth_out(400, '未知查询类型');
    }
    $venueId = venue_scope_requested_id($_GET);
    if (!in_array($venueId, $eligibleIds, true)) {
        auth_out(1003, '无权查看该场地推广历史');
    }
    $venue = $venues[array_search($venueId, $eligibleIds, true)];
    // 打开场地明细时默认查看昨日完整账期；date='' 明确表示查看全部历史。
    $defaultDate = (new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai')))
        ->modify('-1 day')->format('Y-m-d');
    $rawDate = $_GET['date'] ?? null;
    $date = $rawDate === null ? $defaultDate : (is_string($rawDate) ? trim($rawDate) : 'invalid');
    if ($date !== '' && !promotionHistoryValidDate($date)) {
        auth_out(400, '账期日期格式不正确');
    }
    $requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
    $page = $requestedPage === false ? 1 : max(1, (int)$requestedPage);
    $pageSize = 20;

    // 只有已经结算并写入资金台账的订单才属于“累计已入账”历史。
    $settledWhere = "l.promotion_venue_id = ?
        AND l.source_type = 'daily_17_snapshot' AND l.reward_status = 'settled'
        AND EXISTS (
            SELECT 1 FROM kwx_8899_fee_exempt_ledger e
            WHERE e.venue_id = l.promotion_venue_id
              AND e.source_type = 'promotion_batch'
              AND e.source_id = l.settlement_batch_id AND e.amount > 0
        )";
    $daily = promotionHistoryQuery($db,
        "SELECT l.reward_date, COUNT(*) AS order_count,
                COALESCE(SUM(l.order_amount), 0) AS order_amount,
                COALESCE(SUM(l.reward_amount), 0) AS reward_amount
         FROM venue_promotion_reward_logs l
         WHERE {$settledWhere}
         GROUP BY l.reward_date ORDER BY l.reward_date DESC",
        [$venueId]
    );
    $allOrders = 0;
    foreach ($daily as $day) {
        $allOrders += (int)$day['order_count'];
    }

    $filteredWhere = $settledWhere . ($date !== '' ? ' AND l.reward_date = ?' : '');
    $params = $date !== '' ? [$venueId, $date] : [$venueId];
    $countRows = promotionHistoryQuery($db,
        "SELECT COUNT(*) AS total FROM venue_promotion_reward_logs l WHERE {$filteredWhere}",
        $params
    );
    $total = (int)($countRows[0]['total'] ?? 0);
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = min($page, $pages);
    $offset = ($page - 1) * $pageSize;
    $rows = promotionHistoryQuery($db,
        "SELECT l.id, l.order_id, l.reward_date, l.consumer_venue_id,
                COALESCE(cv.venue_name, '') AS consumer_venue_name,
                l.invitee_uid, COALESCE(u.nickname, '') AS invitee_nickname,
                l.order_amount, l.reward_amount, l.settled_at
         FROM venue_promotion_reward_logs l
         LEFT JOIN venues cv ON cv.id = l.consumer_venue_id
         LEFT JOIN users u ON u.uid = l.invitee_uid
         WHERE {$filteredWhere}
         ORDER BY l.reward_date DESC, l.id DESC
         LIMIT {$pageSize} OFFSET {$offset}",
        $params
    );
    $orders = [];
    foreach ($rows as $row) {
        $orders[] = [
            'id' => (int)$row['id'],
            'order_id' => (string)$row['order_id'],
            'reward_date' => (string)$row['reward_date'],
            'consumer_venue_id' => (int)$row['consumer_venue_id'],
            'consumer_venue_name' => (string)$row['consumer_venue_name'],
            'invitee_uid' => (int)$row['invitee_uid'],
            'invitee_nickname' => (string)$row['invitee_nickname'],
            'order_amount' => number_format((float)$row['order_amount'], 2, '.', ''),
            'reward_amount' => number_format((float)$row['reward_amount'], 2, '.', ''),
            'settled_at' => (string)($row['settled_at'] ?? ''),
        ];
    }
    $days = [];
    foreach ($daily as $day) {
        $days[] = [
            'reward_date' => (string)$day['reward_date'],
            'order_count' => (int)$day['order_count'],
            'order_amount' => number_format((float)$day['order_amount'], 2, '.', ''),
            'reward_amount' => number_format((float)$day['reward_amount'], 2, '.', ''),
        ];
    }
    $db->close();
    auth_out(0, 'ok', [
        'venue' => [
            'venue_id' => $venueId,
            'venue_name' => (string)$venue['venue_name'],
            'promotion_code' => (string)($venue['venue_unique_subtitle'] ?? ''),
        ],
        'summary' => [
            'credited_total' => number_format((float)($credited[$venueId] ?? 0), 2, '.', ''),
            'order_count' => $allOrders,
        ],
        'days' => $days,
        'orders' => $orders,
        'date' => $date,
        'default_date' => $defaultDate,
        'pagination' => [
            'page' => $page, 'pages' => $pages, 'page_size' => $pageSize, 'total' => $total,
        ],
    ]);
} catch (Throwable $e) {
    error_log('FranchisePromotionHistory: ' . $e->getMessage());
    $db->close();
    auth_out(500, '推广历史暂时无法读取，请稍后重试');
}
