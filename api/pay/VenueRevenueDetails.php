<?php
require_once '../Database.php'; // 确保路径正确

// 创建数据库连接
$database = new Database();

// 获取传入的日期，如果没有传入，则使用当前日期

// 获取传入的日期，如果没有传入，则使用昨日的日期
$today = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d', strtotime('-1 day'));

// 获取所有场地的ID
$venues = $database->query("SELECT id FROM venues");

foreach ($venues as $venue) {
    $venue_id = $venue['id'];

    // 查询指定场地指定日期的收入详情
    // 统计每小时收入详情（00:00 ~ 23:00）
    for ($hour = 0; $hour < 24; $hour++) {
        $startTime = sprintf('%02d:00:00', $hour);
        $endTime = sprintf('%02d:59:59', $hour);
    
        $timePaymentSql = "SELECT SUM(payment_amount) AS hourly_payment FROM orders 
            WHERE DATE(end_time) = ? 
            AND TIME(end_time) BETWEEN ? AND ? 
            AND reservation_id = ? 
            AND pays_type != '能量'";
        $params = [$today, $startTime, $endTime, $venue_id];
        $result = $database->query($timePaymentSql, $params);
        $hourlyPayment = $result[0]['hourly_payment'] ?: '0.00';
    
        // 插入详情表
        $insertDetailSql = "INSERT INTO VenueRevenueDetails (venue_id, date, time_start, time_end, revenue) VALUES (?, ?, ?, ?, ?)";
        $database->query($insertDetailSql, [$venue_id, $today, $startTime, $endTime, $hourlyPayment]);
    }
}

// 关闭数据库连接
$database->close();

echo "收入数据已更新。";
?>