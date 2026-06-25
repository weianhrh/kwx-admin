<?php
require_once '../Database.php';
require_once '../lib/venue_scope.php';

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

$venue_id = venue_scope_resolve_single_id($database, $user, venue_scope_requested_id($_GET));
if ($venue_id <= 0) {
    echo json_encode(['code' => 1003, 'msg' => '未绑定或无权访问该场地', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json');

function extractRemarkAmount($remarks, $labels) {
    $remarks = (string)$remarks;

    foreach ($labels as $label) {
        $pattern = '/' . preg_quote($label, '/') . '\s*[:：=]\s*([0-9]+(?:\.[0-9]+)?)/u';
        if (preg_match($pattern, $remarks, $m)) {
            return round((float)$m[1], 2);
        }
    }

    return 0.00;
}

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

foreach ($results as &$row) {
    $remarks = $row['remarks'] ?? '';

    $imageFeeAmount = extractRemarkAmount($remarks, [
        '图传费用扣减',
        '未结算图传费用',
        '图传费用'
    ]);

    $settlementAmount = extractRemarkAmount($remarks, [
        '参与提现结算金额',
        '结算金额'
    ]);

    $displayWithdrawalAmount = extractRemarkAmount($remarks, [
        '提现金额',
        '展示提现金额'
    ]);

    $refundDeductAmount = extractRemarkAmount($remarks, [
        '退款扣减'
    ]);

    $totalDebitAmount = extractRemarkAmount($remarks, [
        '账户余额扣减',
        '总扣款'
    ]);

    // 老数据兼容：没有新快照字段时，按原字段展示
    if ($settlementAmount <= 0) {
        $settlementAmount = (float)($row['withdrawal_amount'] ?? 0);
    }

    if ($displayWithdrawalAmount <= 0) {
        $displayWithdrawalAmount = $imageFeeAmount > 0
            ? round($settlementAmount + $imageFeeAmount, 2)
            : (float)($row['withdrawal_amount'] ?? 0);
    }

    if ($totalDebitAmount <= 0) {
        $totalDebitAmount = round($displayWithdrawalAmount + $refundDeductAmount, 2);
    }

    $row['image_fee_amount'] = round($imageFeeAmount, 2);
    $row['settlement_amount'] = round($settlementAmount, 2);
    $row['display_withdrawal_amount'] = round($displayWithdrawalAmount, 2);
    $row['refund_deduct_amount'] = round($refundDeductAmount, 2);
    $row['total_debit_amount'] = round($totalDebitAmount, 2);
}
unset($row);

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
