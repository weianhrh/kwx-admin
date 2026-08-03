<?php
require_once '../Database.php'; 

// 创建数据库连接
$database = new Database();

// 从会话中获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

// 验证 session_token 并获取用户信息
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}
if (!in_array((int)$user['role_id'], [1, 2], true)) {
    echo json_encode(['code' => 1003, 'msg' => '无权查看提现审批列表', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// 查询未打款且仍可处理的申请列表，并关联 venue_funds 表获取 withdrawal_account，关联 venues 表获取 venue_name
$query = "SELECT
    wr.*,
    vf.withdrawal_account, 
    v.venue_name
FROM
    withdrawal_requests wr
JOIN
    venue_funds vf ON wr.venue_id  = vf.venue_id 
JOIN
    venues v ON vf.venue_id  = v.id 
WHERE
    wr.payout_status = 0
    AND wr.application_status IN (0, 1)";

$result = $database->query($query);

if ($result === false) {
    echo json_encode(['code' => 500, 'msg' => '查询提现申请列表失败', 'data' => []]);
    exit;
}

$canReject = (int)$user['role_id'] === 1 ? 1 : 0;
foreach ($result as &$row) {
    $row['can_reject'] = $canReject;
}
unset($row);

// 返回成功信息
echo json_encode([
    'code' => 0,
    'msg' => '查询提现申请列表成功',
    'data' => $result
]);

$database->close();
?>