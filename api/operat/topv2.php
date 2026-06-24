<?php
require_once '../Database.php';   // 确保路径正确

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

function out_json($code, $msg, $data = [], $extra = []) {
    echo json_encode(array_merge([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$session_token) {
    out_json(1001, '用户未登录或会话已过期', []);
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || empty($user['role_id'])) {
    out_json(1001, '用户未登录或无权访问', []);
}

$role_id = (int)$user['role_id'];
$venue_id = $role_id != 1 ? ($user['venue_id'] ?? null) : null;

// ===== 日期处理：支持单日，也支持 start_date / end_date 区间 =====
function is_valid_date_str($date) {
    return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

$period = $_GET['period'] ?? 'day';
if (!in_array($period, ['day', 'week', 'month'], true)) {
    $period = 'day';
}

$startParam = $_GET['start_date'] ?? '';
$endParam = $_GET['end_date'] ?? '';

if (
    is_valid_date_str($startParam)
    && is_valid_date_str($endParam)
    && strtotime($endParam) > strtotime($startParam)
) {
    // 周/月/天卡片跳转过来的范围
    $date = $startParam;
    $dateEnd = $endParam;
} else {
    // 兼容旧的 ?date=today / yesterday / YYYY-MM-DD
    $dateParam = $_GET['date'] ?? date('Y-m-d');

    if ($dateParam === 'yesterday') {
        $date = date('Y-m-d', strtotime('-1 day'));
    } elseif ($dateParam === 'today') {
        $date = date('Y-m-d');
    } elseif (is_valid_date_str($dateParam)) {
        $date = $dateParam;
    } else {
        $date = date('Y-m-d');
    }

    $dateEnd = date('Y-m-d', strtotime($date . ' +1 day'));
    $period = 'day';
}

// ===== 排行方式：total 总收入 / drive 驾驶收入 / gift 礼物收入 =====
$rankType = $_GET['rank_type'] ?? 'total';
$rankFieldMap = [
    'total' => 'totalPayment',
    'drive' => 'driveIncome',
    'gift'  => 'giftIncome',
];
if (!isset($rankFieldMap[$rankType])) {
    $rankType = 'total';
}
$rankField = $rankFieldMap[$rankType];

// 获取营业中的场地（如果非管理员，仅返回用户自己的）
$venueSql = "SELECT id, venue_name, queue_length FROM venues WHERE 1=1";
$venueParams = [];
if ($venue_id) {
    $venueSql .= " AND id = ?";
    $venueParams[] = $venue_id;
}

$venueList = $database->query($venueSql, $venueParams);

$venueMap = [];
foreach ($venueList as $venue) {
    $venueMap[$venue['id']] = [
        'venue_id' => $venue['id'],
        'venue_name' => $venue['venue_name'],
        'queue_length' => $venue['queue_length'],
        'orderCount' => 0,
        'reservationCount' => 0,

        // 驾驶收入：orders 表
        'driveIncome' => '0.00',

        // 礼物收入：gift_orders 金币 / 10 * 60%
        'giftIncome' => '0.00',

        // 总收入：驾驶收入 + 礼物收入
        'totalPayment' => '0.00',
    ];
}

if (empty($venueMap)) {
    echo json_encode([
        'code' => 0,
        'msg' => '无数据',
        'totalIncome' => '0.00',
        'totalDriveIncome' => '0.00',
        'totalGiftIncome' => '0.00',
        'rankType' => $rankType,
        'rankField' => $rankField,
        'rankTotal' => '0.00',
        'data' => []
    ], JSON_UNESCAPED_UNICODE);
    $database->close();
    exit;
}

// 批量查订单数
$orderSql = "
    SELECT reservation_id, COUNT(*) AS order_count
    FROM orders
    WHERE end_time >= ?
      AND end_time < ?
    GROUP BY reservation_id
";
$orderRows = $database->query($orderSql, [$date, $dateEnd]);

foreach ($orderRows as $row) {
    $rid = $row['reservation_id'];
    if (isset($venueMap[$rid])) {
        $venueMap[$rid]['orderCount'] = (int)$row['order_count'];
    }
}

// 批量查预约数
$reservationSql = "
    SELECT reservation_id, COUNT(*) AS reservation_count
    FROM Reservations
    WHERE reservation_time >= ?
      AND reservation_time < ?
    GROUP BY reservation_id
";
$reservationRows = $database->query($reservationSql, [$date, $dateEnd]);

foreach ($reservationRows as $row) {
    $rid = $row['reservation_id'];
    if (isset($venueMap[$rid])) {
        $venueMap[$rid]['reservationCount'] = (int)$row['reservation_count'];
    }
}

// 批量查驾驶收入：orders 表
$paymentSql = "
    SELECT reservation_id, COALESCE(SUM(payment_amount), 0) AS drive_income
    FROM orders
    WHERE end_time >= ?
      AND end_time < ?
      AND pays_type != '能量'
    GROUP BY reservation_id
";
$paymentRows = $database->query($paymentSql, [$date, $dateEnd]);

foreach ($paymentRows as $row) {
    $rid = $row['reservation_id'];
    if (isset($venueMap[$rid])) {
        $driveIncome = (float)$row['drive_income'];
        $venueMap[$rid]['driveIncome'] = number_format($driveIncome, 2, '.', '');
    }
}

// 批量查礼物收入：gift_orders 金币 / 10 * 60%
// 用 send_time >= ? AND send_time < ?，避免 DATE(send_time) 导致索引失效
$giftSql = "
    SELECT reservation_id, COALESCE(SUM(payment_amount) / 10 * 0.6, 0) AS gift_income
    FROM gift_orders
    WHERE send_time >= ?
      AND send_time < ?
      AND status = '已完成'
    GROUP BY reservation_id
";
$giftRows = $database->query($giftSql, [$date, $dateEnd]);

foreach ($giftRows as $row) {
    $rid = $row['reservation_id'];
    if (isset($venueMap[$rid])) {
        $giftIncome = (float)$row['gift_income'];
        $venueMap[$rid]['giftIncome'] = number_format($giftIncome, 2, '.', '');
    }
}

// 计算每个场地总收入：驾驶收入 + 礼物收入，同时计算三种累计合计
$totalDriveIncome = 0.0;
$totalGiftIncome = 0.0;
$totalIncome = 0.0;

foreach ($venueMap as $rid => $venue) {
    $driveIncome = (float)$venue['driveIncome'];
    $giftIncome  = (float)$venue['giftIncome'];
    $totalPayment = $driveIncome + $giftIncome;

    $venueMap[$rid]['totalPayment'] = number_format($totalPayment, 2, '.', '');

    $totalDriveIncome += $driveIncome;
    $totalGiftIncome += $giftIncome;
    $totalIncome += $totalPayment;
}

// 转为数组并按指定排行方式排序
$venueData = array_values($venueMap);
usort($venueData, function ($a, $b) use ($rankField) {
    $rankCmp = (float)$b[$rankField] <=> (float)$a[$rankField];
    if ($rankCmp !== 0) return $rankCmp;

    // 当前排行字段相同时，用总收入做二级排序
    $totalCmp = (float)$b['totalPayment'] <=> (float)$a['totalPayment'];
    if ($totalCmp !== 0) return $totalCmp;

    // 最后用 venue_id 排序，避免同金额时顺序随机
    return (int)$a['venue_id'] <=> (int)$b['venue_id'];
});

$rankTotalMap = [
    'total' => $totalIncome,
    'drive' => $totalDriveIncome,
    'gift'  => $totalGiftIncome,
];

echo json_encode([
    'code' => 0,
    'msg' => '',
    'date' => $date,
    'period' => $period,
    'startDate' => $date,
    'endDate' => $dateEnd,
    'rankType' => $rankType,
    'rankField' => $rankField,

    // 兼容原来的总收入字段
    'totalIncome' => number_format($totalIncome, 2, '.', ''),

    // 新增：三种收入累计
    'totalDriveIncome' => number_format($totalDriveIncome, 2, '.', ''),
    'totalGiftIncome' => number_format($totalGiftIncome, 2, '.', ''),
    'rankTotal' => number_format($rankTotalMap[$rankType], 2, '.', ''),

    'data' => $venueData
], JSON_UNESCAPED_UNICODE);

$database->close();
?>
