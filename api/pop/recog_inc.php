<?php
require_once '../Database.php';

$database = new Database();

// 参数
$time_day = $_POST['time_day'] ?? date('Y-m-d-H:i'); // 形如 2025-12-31-08:31
$label    = $_POST['label'] ?? '';
$inc      = intval($_POST['inc'] ?? 1);

// 检查 label
if (!in_array($label, ['safe','low','medium','high'])) {
    http_response_code(400);
    echo json_encode(['code'=>400,'msg'=>'invalid label']);
    exit;
}

$col = $label . '_cnt';
$now = date('Y-m-d H:i:s');

// SQL：插入或更新
$sql = "
INSERT INTO recog_day_counter (time_day, safe_cnt, low_cnt, medium_cnt, high_cnt, updated_at)
VALUES (?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  {$col} = {$col} + VALUES({$col}),
  updated_at = VALUES(updated_at)
";

// 4个计数字段初始化
$params = [
    $time_day,
    ($label === 'safe'   ? $inc : 0),
    ($label === 'low'    ? $inc : 0),
    ($label === 'medium' ? $inc : 0),
    ($label === 'high'   ? $inc : 0),
    $now
];

try {
    $stmt = $database->prepare($sql);
    $stmt->bind_param('siiiis', ...$params);
    if ($stmt->execute()) {
        echo json_encode(['code'=>200,'msg'=>'ok']);
    } else {
        http_response_code(500);
        echo json_encode(['code'=>500,'msg'=>$stmt->error]);
    }
    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['code'=>500,'msg'=>$e->getMessage()]);
}

$database->close();
