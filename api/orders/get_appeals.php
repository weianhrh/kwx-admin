<?php
require_once '../Database.php';
header('Content-Type: application/json');

$database = new Database();

// 登录验证
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未登录']);
    exit;
}
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1002, 'msg' => '权限不足']);
    exit;
}

// 处理状态过滤参数（默认查 status=0）
$status = isset($_GET['status']) ? intval($_GET['status']) : 0;

$sql = "
    SELECT 
        a.*, 
        o.reservation_id, 
        o.payment_amount,
        o.start_time,
        o.end_time,
        o.pays_type,
        v.venue_name 
    FROM order_appeals a
    LEFT JOIN orders o ON a.order_id = o.order_id
    LEFT JOIN venues v ON o.reservation_id = v.id
    WHERE a.status = ?
    ORDER BY a.created_at DESC
    LIMIT 100
";

$appeals = $database->query($sql, [$status]);

echo json_encode([
    'code' => 0,
    'msg' => '查询成功',
    'data' => $appeals
]);
