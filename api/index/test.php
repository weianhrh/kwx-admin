<?php
require_once '../Database.php';   // 确保路径正确

if (!isset($_COOKIE['session_token'])) {
    header("Location: login.html");  
    exit;
}

// 创建数据库连接
$database = new Database();
// 从会话中获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;

// 验证 session_token 并获取用户信息
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $database->getUserBySessionToken($session_token);

// 检查用户是否存在和权限获取
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

$role_id = $user['role_id'];
$today = date('Y-m-d');
$venue_id = $role_id != 1 ? $user['venue_id'] : null;
$new_venue_id =  $user['venue_id'];

// 如果是管理员（role_id = 1），不限制场地
$reportCountSql = $role_id == 1 
    ? "SELECT COUNT(r.device_id) AS report_count
       FROM Reports r
       JOIN vehicles v ON r.device_id = v.serial_number  
       WHERE (r.status = '未处理' OR r.status = '处理中')"
    : "SELECT COUNT(r.device_id) AS report_count
       FROM Reports r
       JOIN vehicles v ON r.device_id = v.serial_number  
       WHERE v.bind_site = ? AND (r.status = '未处理' OR r.status = '处理中')";

// 根据角色执行不同的查询
$reportCountResult = $role_id == 1 
    ? $database->query($reportCountSql)
    : $database->query($reportCountSql, [$user['venue_id']]);
// 输出JSON格式的数据
echo json_encode([
    'code' => 0,
    'msg' => '',
    'data' => [
        'registerCount' => $registerCount,
        'totalRechargeFromBalanceChanges' => $totalRechargeFromBalanceChanges,
        'totalUserConsumption' => $totalUserConsumption,
        'activeUserCount' => $activeUserCount,
        'reportCount' => $reportCountResult[0]['report_count'], // Ensure the proper field
        'totalPendingwithdrawalapproval' =>$totalPendingwithdrawalapproval[0]['total_count'],
        'appeal_count' => $appeals[0]['appeal_count']
    ]
]);

?>