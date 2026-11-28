<?php
require_once '../Database.php';
require_once '../lib/venue_scope.php';
require_once __DIR__ . '/../lib/withdrawal_display.php';

header('Content-Type: application/json; charset=utf-8');

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

// 汇总查询页不指定场地；旧提现申请页仍保留单场地历史记录的行为。
$all_venues = ($_GET['all_venues'] ?? '') === '1';
$requested_venue_id = venue_scope_requested_id($_GET);
$venue_id = $all_venues
    ? $requested_venue_id
    : venue_scope_resolve_single_id($database, $user, $requested_venue_id);
if ((!$all_venues && $venue_id <= 0)
    || ($venue_id > 0 && !venue_scope_can_access($database, $user, $venue_id))) {
    echo json_encode(['code' => 1003, 'msg' => '未绑定或无权访问该场地', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

function extractRemarkText($remarks, $label) {
    $remarks = (string)$remarks;
    $pattern = '/' . preg_quote($label, '/') . '\s*[:：=]\s*([^，,\n\r]+)/u';
    return preg_match($pattern, $remarks, $m) ? trim($m[1]) : '';
}

$withdraw_type = $_GET['withdraw_type'] ?? $_POST['withdraw_type'] ?? 'account';
$withdraw_type = in_array($withdraw_type, ['account', 'gift', 'all'], true) ? $withdraw_type : 'account';

// 汇总查询默认当天（北京时间）；显式传空日期可查全部日期。
// 旧提现申请页没有日期参数时继续返回历史记录。
$date_zone = new DateTimeZone('Asia/Shanghai');
$default_date = $all_venues && !isset($_GET['start_date']) && !isset($_GET['end_date'])
    ? (new DateTimeImmutable('now', $date_zone))->format('Y-m-d') : '';
$dates = [];
foreach (['start_date', 'end_date'] as $key) {
    $value = $_GET[$key] ?? $default_date;
    $date = is_string($value) && $value !== ''
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, $date_zone) : false;
    if (!is_string($value) || ($value !== '' && (!$date || $date->format('Y-m-d') !== $value))) {
        echo json_encode(['code' => 1002, 'msg' => '日期格式不正确，请使用 YYYY-MM-DD', 'data' => []], JSON_UNESCAPED_UNICODE);
        $database->close();
        exit;
    }
    $dates[$key] = $date;
}
$start_date = $dates['start_date'];
$end_date = $dates['end_date'];
if ($start_date && $end_date && $start_date > $end_date) {
    echo json_encode(['code' => 1002, 'msg' => '开始日期不能晚于结束日期', 'data' => []], JSON_UNESCAPED_UNICODE);
    $database->close();
    exit;
}
$keyword = $_GET['keyword'] ?? '';
if (!is_string($keyword)) {
    echo json_encode(['code' => 1002, 'msg' => '搜索内容格式不正确', 'data' => []], JSON_UNESCAPED_UNICODE);
    $database->close();
    exit;
}
$keyword = trim($keyword);

// LEFT JOIN 保留场地已删除的历史提现记录。
$sql = "SELECT 
            wr.id,
            wr.account_name,
            wr.account_type,
            wr.withdrawal_amount,
            wr.application_time,
            wr.payout_time,
            wr.technical_service_fee,
            wr.withdrawal_fee,
            wr.application_status,
            wr.actual_amount,
            wr.venue_id,
            wr.uid,
            wr.payout_person,
            wr.auditor,
            wr.payout_status,
            wr.created_at,
            wr.updated_at,
            wr.remarks,
            wr.withdrawal_type,
            vc.fee_amount AS voice_room_fee_amount,
            v.venue_name
        FROM withdrawal_requests wr
        LEFT JOIN venues v ON v.id = wr.venue_id
        LEFT JOIN kwx_voice_room_fee_charges vc ON vc.withdrawal_request_id = wr.id
        WHERE 1=1";

$params = [];
// 管理员可查全部；加盟商、主播仍限制在绑定场地内。
$sql .= venue_scope_apply_filter($database, $user, 'wr.venue_id', $params, $venue_id);

if ($withdraw_type === 'gift') {
    // 礼物提现记录
    $sql .= " AND (
        wr.withdrawal_type = 'gift'
        OR wr.remarks LIKE '%礼物提现记录%'
    )";
} elseif ($withdraw_type === 'account') {
    // 普通账号余额提现记录
    // 兼容老数据：老记录 withdrawal_type 为空，也算普通提现
    $sql .= " AND (
        wr.withdrawal_type IS NULL
        OR wr.withdrawal_type = ''
        OR wr.withdrawal_type = 'account'
    )";
}

// 添加日期过滤条件
if ($start_date) {
    $sql .= " AND wr.application_time >= ?";
    $params[] = $start_date->format('Y-m-d 00:00:00');
}
if ($end_date) {
    // 使用次日零点之前，包含结束日期的整天记录。
    $sql .= " AND wr.application_time < ?";
    $params[] = $end_date->modify('+1 day')->format('Y-m-d 00:00:00');
}

if ($keyword !== '') {
    // 将通配符作为普通字符搜索，所有搜索值仍使用参数绑定。
    $like_keyword = '%' . strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $sql .= " AND (v.venue_name LIKE ? ESCAPE '!' OR CAST(wr.id AS CHAR) LIKE ? ESCAPE '!')";
    $params[] = $like_keyword;
    $params[] = $like_keyword;
}

$sql .= " ORDER BY wr.application_time DESC, wr.id DESC";

// 执行查询，绑定参数
$results = $database->query($sql, $params);
if ($results === false) {
    echo json_encode(['code' => 500, 'msg' => '获取提现记录失败', 'data' => []], JSON_UNESCAPED_UNICODE);
    $database->close();
    exit;
}

foreach ($results as &$row) {
    $remarks = $row['remarks'] ?? '';

    $row = kwxWithdrawalDisplayAmounts($row);

    $reversalAction = '';
    if ((int)($row['application_status'] ?? 0) === 3) {
        $handleType = extractRemarkText($remarks, '处理类型');
        $reversalAction = strpos($handleType, '取消') !== false ? 'cancelled' : 'rejected';
    }
    $row['reversal_action'] = $reversalAction;
    $row['reversal_reason'] = extractRemarkText($remarks, '处理原因');
    $row['can_cancel'] = (
        (int)($user['role_id'] ?? 0) === 3
        && (int)($row['application_status'] ?? 0) === 0
        && (int)($row['payout_status'] ?? 0) === 0
    ) ? 1 : 0;
}
unset($row);

echo json_encode([
    'code' => 0,
    'msg'  => '提现记录获取成功',
    'withdraw_type' => $withdraw_type,
    'data' => $results
], JSON_UNESCAPED_UNICODE);

$database->close();
?>
