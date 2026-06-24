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

$user = $database->getUserBySessionToken($session_token);

// 检查用户是否存在和权限获取
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

// $venue_id = $_GET['venue_id'] ?? $user['venue_id'];  // 绑定的场地ID
if ((int)$user['role_id'] === 1) {
    $venue_id = isset($_GET['venue_id']) ? (int)$_GET['venue_id'] : (int)$user['venue_id'];
} else {
    $venue_id = (int)$user['venue_id'];
}

header('Content-Type: application/json');
$withdraw_type = $_GET['withdraw_type'] ?? $_POST['withdraw_type'] ?? 'account';
$withdraw_type = ($withdraw_type === 'gift') ? 'gift' : 'account';
// 获取请求参数
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

// 定义 SQL 查询，根据场地ID和日期过滤，并按创建时间降序排序
$sql = "SELECT 
            id, 
            account_name, 
            account_type, 
            withdrawal_amount, 
            application_time, 
            payout_time, 
            technical_service_fee, 
            withdrawal_fee, 
            application_status, 
            actual_amount, 
            venue_id, 
            uid, 
            payout_person, 
            auditor, 
            payout_status, 
            created_at, 
            updated_at, 
            remarks,
            withdrawal_type
        FROM withdrawal_requests 
        WHERE venue_id = ?";

// 添加日期过滤条件
// 添加提现类型过滤
$params = [$venue_id];

if ($withdraw_type === 'gift') {
    // 礼物提现记录
    $sql .= " AND (
        withdrawal_type = 'gift'
        OR remarks LIKE '%礼物提现记录%'
    )";
} else {
    // 普通账号余额提现记录
    // 兼容老数据：老记录 withdrawal_type 为空，也算普通提现
    $sql .= " AND (
        withdrawal_type IS NULL
        OR withdrawal_type = ''
        OR withdrawal_type = 'account'
    )";
}

// 添加日期过滤条件
if ($start_date) {
    $sql .= " AND application_time >= ?";
    $params[] = $start_date;
}
if ($end_date) {
    $sql .= " AND application_time <= ?";
    $params[] = $end_date;
}

$sql .= " ORDER BY created_at DESC";

// 执行查询，绑定参数
$results = $database->query($sql, $params);

if ($results === false) {
    echo json_encode(['code' => 500, 'msg' => '获取提现记录失败', 'data' => []]);
    $database->close();
    exit;
}

echo json_encode([
    'code' => 0,
    'msg'  => '提现记录获取成功',
    'withdraw_type' => $withdraw_type,
    'data' => $results
], JSON_UNESCAPED_UNICODE);

$database->close();
?> 