<?php
require_once '../Database.php';   // 确保路径正确

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

$role_id = $user['role_id'];
$venue_id = $role_id != 1 ? $user['venue_id'] : null;

$date = $_GET['date'] ?? date('Y-m-d');
if ($date === 'yesterday') {
    $date = date('Y-m-d', strtotime('-1 day'));
}
$dateEnd = date('Y-m-d', strtotime($date . ' +1 day'));

// 获取营业中的场地（如果非管理员，仅返回用户自己的）
$venueSql = "SELECT id, venue_name, queue_length FROM venues WHERE venue_status = '营业中'";
$venueSql .= $venue_id ? " AND id = ?" : "";
$venueList = $database->query($venueSql, $venue_id ? [$venue_id] : []);
$venueMap = [];
foreach ($venueList as $venue) {
    $venueMap[$venue['id']] = [
        'venue_id' => $venue['id'],
        'venue_name' => $venue['venue_name'],
        'queue_length' => $venue['queue_length'],
        'orderCount' => 0,
        'reservationCount' => 0,
        'totalPayment' => 0.00,
    ];
}
if (empty($venueMap)) {
    echo json_encode(['code' => 0, 'msg' => '无数据', 'totalIncome' => '0.00', 'data' => []]);
    exit;
}

// 批量查订单数
$orderSql = "SELECT reservation_id, COUNT(*) AS order_count FROM orders WHERE end_time >= ? AND end_time < ? GROUP BY reservation_id";
$orderRows = $database->query($orderSql, [$date, $dateEnd]);
foreach ($orderRows as $row) {
    $rid = $row['reservation_id'];
    if (isset($venueMap[$rid])) {
        $venueMap[$rid]['orderCount'] = (int)$row['order_count'];
    }
}

// 批量查预约数
$reservationSql = "SELECT reservation_id, COUNT(*) AS reservation_count FROM Reservations WHERE reservation_time >= ? AND reservation_time < ? GROUP BY reservation_id";
$reservationRows = $database->query($reservationSql, [$date, $dateEnd]);
foreach ($reservationRows as $row) {
    $rid = $row['reservation_id'];
    if (isset($venueMap[$rid])) {
        $venueMap[$rid]['reservationCount'] = (int)$row['reservation_count'];
    }
}

// 批量查收入
$paymentSql = "SELECT reservation_id, SUM(payment_amount) AS total_payment FROM orders WHERE end_time >= ? AND end_time < ? AND pays_type != '能量' GROUP BY reservation_id";
$paymentRows = $database->query($paymentSql, [$date, $dateEnd]);
$totalIncome = 0;
foreach ($paymentRows as $row) {
    $rid = $row['reservation_id'];
    if (isset($venueMap[$rid])) {
        $venueMap[$rid]['totalPayment'] = number_format((float)$row['total_payment'], 2, '.', '');
        $totalIncome += (float)$row['total_payment'];
    }
}

// 转为数组并排序
$venueData = array_values($venueMap);
usort($venueData, fn($a, $b) => $b['totalPayment'] - $a['totalPayment']);

echo json_encode([
    'code' => 0,
    'msg' => '',
    'totalIncome' => number_format($totalIncome, 2, '.', ''),
    'data' => $venueData
]);

$database->close();
?>
