<?php
require_once '../Database.php';
header('Content-Type: application/json');

$database = new Database();

// 登录校验
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

// 查询已处理(1)和驳回(2)记录
$sql = "
SELECT 
    order_id,
    user_id,
    reason,
    status,
    CASE 
        WHEN status = 1 THEN '已处理'
        WHEN status = 2 THEN '驳回'
        ELSE '其他'
    END AS status_text,
    result,
    handler_uid,
    handled_at
FROM order_appeals
WHERE status IN (1, 2)
ORDER BY handled_at DESC
";

$data = $database->query($sql);
if ($data === false) {
    echo json_encode(['code' => 500, 'msg' => '查询失败']);
    exit;
}

echo json_encode([
    'code' => 200,
    'msg'  => 'ok',
    'data' => $data
]);
