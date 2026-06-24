<?php
require_once '../Database.php';

$db = new Database();

// 获取当天日期
$today = date('Y-m-d');

// 查询当天数据
$sql = "SELECT safe_cnt, low_cnt, medium_cnt, high_cnt 
        FROM recog_day_counter 
        WHERE time_day = ?";

$result = $db->query($sql, [$today]);

if ($result && count($result) > 0) {
    // 有记录
    $row = $result[0];
    echo json_encode([
        'code' => 200,
        'msg'  => 'success',
        'data' => $row
    ], JSON_UNESCAPED_UNICODE);
} else {
    // 无记录
    echo json_encode([
        'code' => 404,
        'msg'  => 'no data',
        'data' => null
    ], JSON_UNESCAPED_UNICODE);
}

$db->close();
