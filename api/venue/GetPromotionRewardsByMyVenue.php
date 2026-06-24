<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut($code, $msg, $data = []) {
    echo json_encode(['code'=>$code,'msg'=>$msg,'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}

function isValidDateYmd($s) {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}

$database = new Database();

// 会话校验
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) jsonOut(1001, '用户未登录或会话已过期', []);

$user = $database->getUserBySessionToken($session_token);
if (!$user || !isset($user['venue_id'])) jsonOut(1001, '用户未登录或无权访问', []);

$venue_id = (int)$user['venue_id'];
if ($venue_id <= 0) jsonOut(1001, '当前账号未绑定场地', []);

// -------------------- 时间筛选参数 --------------------
// GET/POST 都支持
$start_date = trim($_REQUEST['start_date'] ?? '');
$end_date   = trim($_REQUEST['end_date'] ?? '');

$hasRange = isValidDateYmd($start_date) && isValidDateYmd($end_date);

// 不传则默认近30天
if (!$hasRange) {
    $end_date = date('Y-m-d');
    $start_date = date('Y-m-d', strtotime('-30 days'));
    $hasRange = true;
}

// start > end 交换
if (strtotime($start_date) > strtotime($end_date)) {
    $tmp = $start_date;
    $start_date = $end_date;
    $end_date = $tmp;
}

$start_dt = $start_date . ' 00:00:00';
$end_dt   = $end_date   . ' 23:59:59';

// 先拿到当前场地的邀请码（可选，但推荐：避免 join 走全表）
$vr = $database->query("SELECT id, venue_name, invite_code FROM venues WHERE id = ?", [(string)$venue_id]);
if (!$vr) jsonOut(500, '场地不存在', []);

$invite_code = (string)($vr[0]['invite_code'] ?? '');
$invite_code = preg_replace('/\D/', '', $invite_code);
if (strlen($invite_code) !== 4) jsonOut(500, '该场地未配置4位邀请码 invite_code', []);

// 只展示“推荐分成”：promotion_rewards 里 reward_amount
$sql = "SELECT
          pr.id, pr.order_id, pr.inviter_site_id, pr.consumer_site_id, pr.user_id,
          pr.order_amount, pr.reward_amount, pr.reward_rate, pr.created_at,
          v.id AS venue_id, v.venue_name, v.invite_code
        FROM promotion_rewards pr
        JOIN venues v ON v.invite_code = pr.inviter_site_id
        WHERE v.id = ?
          AND pr.created_at >= ?
          AND pr.created_at <= ?
        ORDER BY pr.created_at DESC";

$list = $database->query($sql, [(string)$venue_id, $start_dt, $end_dt]);
if ($list === false) jsonOut(500, '查询失败', []);

$total_reward = 0.0;
$total_order  = 0.0;
$reward_rate = 0.0;
if (count($list) > 0) {
// 通常每条一样，取第一条即可
    $reward_rate = (float)($list[0]['reward_rate'] ?? 0);
}
foreach ($list as $row) {

    $total_reward += (float)($row['reward_amount'] ?? 0);
    $total_order  += (float)($row['order_amount'] ?? 0);
}

jsonOut(200, 'ok', [
    'venue_id' => $venue_id,
    'invite_code' => $invite_code,
    'venue_name' => $vr[0]['venue_name'] ?? '',
    'summary' => [
        'start_date' => $start_date,
        'end_date' => $end_date,
        'total' => count($list),
        'total_reward_amount' => round($total_reward, 2),
        'total_order_amount'  => round($total_order, 2),
    // ✅ 新增：分成比例
    'reward_rate' => $reward_rate,                          // 0.1
    'reward_rate_percent' => round($reward_rate * 100, 2),  // 10
],
    'list' => $list
]);
