<?php
require_once '../Database.php'; // 确保路径正确
// api/venue/script.php
// 创建数据库连接
$database = new Database();

// 获取传入的日期，如果没有传入，则使用当前日期

// 获取传入的日期，如果没有传入，则使用昨日的日期
$today = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d', strtotime('-1 day'));

// 获取所有场地的ID
$venues = $database->query("SELECT id FROM venues");

foreach ($venues as $venue) {
    $venue_id = $venue['id'];

    // 查询指定场地指定日期的总收入
    $totalPaymentSql = "SELECT SUM(payment_amount) AS total_payment FROM orders WHERE DATE(end_time) = ? AND reservation_id = ? AND pays_type != '能量'";
    $paymentParams = [$today, $venue_id];
    $totalPaymentResult = $database->query($totalPaymentSql, $paymentParams);

    $totalPayment = $totalPaymentResult[0]['total_payment'] ?: '0.00'; // 如果没有收入则返回0.00

    // 检查是否已存在记录
    $checkExist = $database->query("SELECT id FROM DailyVenueRevenue WHERE date = ? AND venue_id = ?", [$today, $venue_id]);

    if ($checkExist) {
        // 更新已存在的记录
        $updateSql = "UPDATE DailyVenueRevenue SET total_revenue = ? WHERE date = ? AND venue_id = ?";
        $database->query($updateSql, [$totalPayment, $today, $venue_id]);
    } else {
        // 插入新记录
        $insertSql = "INSERT INTO DailyVenueRevenue (venue_id, date, total_revenue) VALUES (?, ?, ?)";
        $database->query($insertSql, [$venue_id, $today, $totalPayment]);
    }
}

// 关闭数据库连接
$database->close();

echo "收入数据已更新。";
?>