<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

$database = new Database();

// 会话校验
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'result' => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'result' => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

$sql = "
    SELECT COUNT(*) AS total
    FROM user_doll_detail
    WHERE doll_status = 1
      AND is_gift = 0
";

$res = $database->query($sql);
$total = 0;

if ($res && isset($res[0]['total'])) {
    $total = (int)$res[0]['total'];
}

echo json_encode([
    'code'   => 0,
    'msg'    => 'success',
    'result' => $total
], JSON_UNESCAPED_UNICODE);