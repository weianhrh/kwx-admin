<?php
require_once '../Database.php'; // 确保路径正确
$database = new Database();

// 设置起始日期和结束日期
$startDate = '2024-12-01';
$endDate = date('Y-m-d'); // 或者直接设定为 '2024-12-15'，如果你要固定日期

// 转换为DateTime对象
$begin = new DateTime($startDate);
$end = new DateTime($endDate);

// 创建一个DateInterval，表示每一天
$interval = new DateInterval('P1D');
$daterange = new DatePeriod($begin, $interval ,$end);

foreach($daterange as $date){
    $currentDate = $date->format("Y-m-d");
    echo "Updating data for: " . $currentDate . "<br>";

    // 以下代码模拟对每天的数据执行更新操作
    // 你需要将其替换为实际调用更新脚本的代码，比如通过CURL请求你的API
    // 下面的代码仅用于演示如何格式化和输出日期

    // 你的更新脚本 URL
    $url = "https://open.kwxapp.cn/api/venue/script.php?date=" . $currentDate;

    // 使用CURL发起请求
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    curl_close($ch);

    // 打印每天的更新状态
    echo "Response for " . $currentDate . ": " . $response . "<br>";
}

echo "All data updated.";
?>