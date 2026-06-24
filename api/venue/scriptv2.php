<?php
require_once '../Database.php'; // 确保路径正确

// api/venue/script.php
$database = new Database();

header('Content-Type: application/json; charset=utf-8');

// 获取传入日期；没有传则默认昨日
$today = isset($_GET['date']) && $_GET['date'] !== ''
    ? $_GET['date']
    : date('Y-m-d', strtotime('-1 day'));

// 临时固定只统计 2 号场地
$venue_id = 2;

// 当天开始和结束时间
$dayStart = $today . ' 00:00:00';
$dayEnd   = $today . ' 23:59:59';


/**
 * 设备订单收益
 * 只统计 orders 表，排除能量，排除旧礼物订单
 */
$devicePaymentSql = "
    SELECT COALESCE(SUM(payment_amount), 0) AS total_payment
    FROM orders
    WHERE DATE(end_time) = ?
      AND reservation_id = ?
      AND pays_type != '能量'
      AND (note <> 'gift' OR note IS NULL)
";

$devicePaymentResult = $database->query($devicePaymentSql, [$today, $venue_id]);
$devicePayment = $devicePaymentResult[0]['total_payment'] ?? '0.00';


/**
 * 礼物订单收益
 * gift_orders.payment_amount / 10 * 60%
 */
$giftPaymentSql = "
    SELECT COALESCE(SUM(payment_amount), 0) AS venue_gift_total
    FROM gift_orders
    WHERE reservation_id = ?
      AND send_time >= ?
      AND send_time <= ?
";

$giftPaymentResult = $database->query($giftPaymentSql, [$venue_id, $dayStart, $dayEnd]);
$venueGiftTotal = $giftPaymentResult[0]['venue_gift_total'] ?? '0.00';

// 礼物收益 = 礼物 payment_amount / 10 * 60%
$giftRevenue = ((float)$venueGiftTotal / 10) * 0.6;

// 总收益 = 设备订单收益 + 礼物收益
$totalRevenue = (float)$devicePayment + (float)$giftRevenue;

// 格式化
$devicePaymentFormat = number_format((float)$devicePayment, 2, '.', '');
$venueGiftTotalFormat = number_format((float)$venueGiftTotal, 2, '.', '');
$giftRevenueFormat = number_format((float)$giftRevenue, 2, '.', '');
$totalRevenueFormat = number_format((float)$totalRevenue, 2, '.', '');


// 检查 DailyVenueRevenue 是否已有记录
$checkExist = $database->query(
    "SELECT id FROM DailyVenueRevenue WHERE date = ? AND venue_id = ?",
    [$today, $venue_id]
);

if ($checkExist) {
    // 更新已有记录
    $updateSql = "
        UPDATE DailyVenueRevenue 
        SET total_revenue = ? 
        WHERE date = ? AND venue_id = ?
    ";
    $database->query($updateSql, [$totalRevenueFormat, $today, $venue_id]);
} else {
    // 插入新记录
    $insertSql = "
        INSERT INTO DailyVenueRevenue 
            (venue_id, date, total_revenue) 
        VALUES 
            (?, ?, ?)
    ";
    $database->query($insertSql, [$venue_id, $today, $totalRevenueFormat]);
}

$database->close();

echo json_encode([
    'code' => 0,
    'msg' => '2号场地收入数据已更新',
    'data' => [
        'venue_id' => $venue_id,
        'date' => $today,
        'device_payment' => $devicePaymentFormat,
        'gift_payment_raw' => $venueGiftTotalFormat,
        'gift_revenue' => $giftRevenueFormat,
        'total_revenue' => $totalRevenueFormat
    ]
], JSON_UNESCAPED_UNICODE);
?>