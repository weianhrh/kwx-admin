<?php
require_once '../Database.php';
$database = new Database();

header('Content-Type: application/json; charset=utf-8');

// 1) 登录校验
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
$role_id = (int)$user['role_id'];
// 2) 解析参数：默认 all
$reqVenueId = $_GET['venue_id'] ?? 'all';

// 3) 可见场地范围
if (in_array((int)$role_id, [1, 2], true)) {
    $visibleVenues = $database->query("SELECT id, venue_name, image_url FROM venues ORDER BY id ASC");
} else {
    $venue_id = (int)($user['venue_id'] ?? 0);
    if ($venue_id <= 0) {
        echo json_encode(['code' => 1003, 'msg' => '未绑定场地', 'data' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $visibleVenues = $database->query("SELECT id, venue_name, image_url FROM venues WHERE id = ? LIMIT 1", [$venue_id]);
}
if (empty($visibleVenues)) {
    echo json_encode(['code' => 0, 'msg' => '', 'data' => ['options' => [], 'summary' => [], 'rows' => []]], JSON_UNESCAPED_UNICODE);
    exit;
}

// 4) 下拉选项
$options = array_map(function ($v) {
    return ['id' => (int)$v['id'], 'venue_name' => (string)$v['venue_name']];
}, $visibleVenues);

// 5) IN 条件
$ids = array_column($visibleVenues, 'id');
$inPlaceholders = implode(',', array_fill(0, count($ids), '?'));
$params = array_map('intval', $ids);

// 6) 聚合：资金 + 未核对 + 图片 + 近一月未打款提现
$sqlRows = "
SELECT v.id AS venue_id,
       v.venue_name,
       v.image_url,
       COALESCE(f.account_balance, 0) AS account_balance,
       COALESCE(b.not_settled, 0)     AS notSettledAmount,
       COALESCE(w.pending_30d, 0)     AS pending_withdraw_30d,  -- ⭐ 改名
       f.account_type  AS account_pay,
       f.account_name,
       f.withdrawal_account
FROM venues v
LEFT JOIN (
    SELECT venue_id,
           MAX(account_type) AS account_type,
           MAX(account_name) AS account_name,
           MAX(account_balance) AS account_balance,
           MAX(withdrawal_account) AS withdrawal_account
    FROM venue_funds
    GROUP BY venue_id
) f ON f.venue_id = v.id
LEFT JOIN (
    SELECT venue_id, SUM(total_revenue) AS not_settled
    FROM DailyVenueRevenue
    WHERE is_checked = 0
    GROUP BY venue_id
) b ON b.venue_id = v.id
LEFT JOIN (
    SELECT venue_id,
           SUM(COALESCE(actual_amount, 0)) AS pending_30d
    FROM withdrawal_requests
    WHERE application_time >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
      AND (payout_status IS NULL OR payout_status <> 1)   
      AND venue_id IN ($inPlaceholders)
    GROUP BY venue_id
) w ON w.venue_id = v.id
WHERE v.id IN ($inPlaceholders)
ORDER BY v.id ASC
";

/* 两处 IN → 参数传两份 */
$allParams = array_merge($params, $params);
$rows = $database->query($sqlRows, $allParams);

// 7) 单场地筛选
if ($reqVenueId !== 'all') {
    $reqId = (int)$reqVenueId;
    $rows = array_values(array_filter($rows, function ($r) use ($reqId) {
        return (int)$r['venue_id'] === $reqId;
    }));
    if (empty($rows)) {
        echo json_encode([
            'code' => 0, 'msg' => '无数据（无权或不存在）',
            'data' => ['options' => $options, 'summary' => [], 'rows' => []]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 8) 掩码账号
foreach ($rows as &$r) {
    $acc = $r['withdrawal_account'] ?? '';
    $r['account_number'] = ($acc !== '' && strlen($acc) > 4)
        ? str_repeat('*', strlen($acc) - 4) . substr($acc, -4)
        : ($acc ?: '');
    unset($r['withdrawal_account']);
}
unset($r);

// 9) 映射输出
$rows = array_map(function ($r) {
    return [
        'venue_id'             => (int)$r['venue_id'],
        'image_url'            => (string)($r['image_url'] ?? ''),
        'venue_name'           => (string)$r['venue_name'],
        'account_balance'      => (float)$r['account_balance'],
        'notSettledAmount'     => (float)$r['notSettledAmount'],
        'pending_withdraw_30d' => (float)$r['pending_withdraw_30d'],   // ⭐ 改名
        'account_pay'          => $r['account_pay'] ?? '',
        'account_name'         => $r['account_name'] ?? '',
        'account_number'       => $r['account_number'] ?? '',
    ];
}, $rows);

// 10) 汇总（兜底）
if (!empty($rows)) {
    $summary = [
        'venue_id'             => ($reqVenueId === 'all') ? 'all' : (int)$rows[0]['venue_id'],
        'venue_name'           => ($reqVenueId === 'all') ? '全部场地' : $rows[0]['venue_name'],
        'account_balance'      => array_sum(array_column($rows, 'account_balance')),
        'notSettledAmount'     => array_sum(array_column($rows, 'notSettledAmount')),
        'pending_withdraw_30d' => array_sum(array_column($rows, 'pending_withdraw_30d')), // ⭐ 改名
    ];
} else {
    $summary = [
        'venue_id'             => $reqVenueId,
        'venue_name'           => ($reqVenueId === 'all') ? '全部场地' : '',
        'account_balance'      => 0.0,
        'notSettledAmount'     => 0.0,
        'pending_withdraw_30d' => 0.0,
    ];
}

// 11) 输出
echo json_encode([
    'code' => 0,
    'msg'  => '',
    'data' => [
        'filters' => ['venue_id' => $reqVenueId],
        'options' => $options,
        'summary' => $summary,
        'rows'    => $rows
    ]
], JSON_UNESCAPED_UNICODE);

$database->close();
