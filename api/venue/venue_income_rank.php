<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Taipei');

$db = new Database();

// 读取 session_token
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未登录', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取用户
$user = $db->getUserBySessionToken($session_token);
if (!$user) {
    echo json_encode(['code' => 1001, 'msg' => '会话无效', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// 必须是超级管理员 role_id = 1/2
if (!in_array((int)$user['role_id'], [1, 2], true)) {
    echo json_encode(['code' => 1001, 'msg' => '无权访问', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// 参数
$period     = $_GET['period'] ?? 'day'; // day|week|month
$start_date = trim($_GET['start_date'] ?? ''); // YYYY-MM-DD
$end_date   = trim($_GET['end_date'] ?? '');   // YYYY-MM-DD
$limit      = (int)($_GET['limit'] ?? 50);
if ($limit <= 0) $limit = 50;
if ($limit > 500) $limit = 500;

// 简单日期校验
function isYmd($s) {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}

$useCustom = false;
$start = '';
$endExclusive = '';
$label = '';

/**
 * ✅ 优先：自定义区间
 * - start_date 必须有
 * - end_date 可选：不传则默认等于 start_date（只查一天）
 * - 用 end_date+1day 作为 endExclusive，确保包含 end_date 当天
 */
if ($start_date !== '' && isYmd($start_date)) {
    $useCustom = true;

    if ($end_date === '' || !isYmd($end_date)) {
        $end_date = $start_date;
    }

    // 如果用户反着传（start > end），自动交换
    if (strtotime($start_date) > strtotime($end_date)) {
        $tmp = $start_date;
        $start_date = $end_date;
        $end_date = $tmp;
    }

    $start = $start_date . ' 00:00:00';
    $endExclusive = date('Y-m-d 00:00:00', strtotime($end_date . ' +1 day'));
    $label = '自定义区间';

} else {
    /**
     * ✅ 默认：period（日/周/月）
     */
    switch ($period) {
        case 'week':
            $start = date('Y-m-d 00:00:00', strtotime('monday this week'));
            $endExclusive = date('Y-m-d 00:00:00', strtotime('monday next week'));
            $label = '本周';
            break;

        case 'month':
            $start = date('Y-m-01 00:00:00');
            $endExclusive = date('Y-m-01 00:00:00', strtotime('first day of next month'));
            $label = '本月';
            break;

        case 'day':
        default:
            $start = date('Y-m-d 00:00:00');
            $endExclusive = date('Y-m-d 00:00:00', strtotime('+1 day'));
            $label = '今日';
            $period = 'day';
            break;
    }
}

$sql = "
SELECT
  v.id AS venue_id,
  v.venue_name,
  ROUND(IFNULL(SUM(o.payment_amount), 0), 2) AS total
FROM venues v
LEFT JOIN orders o
  ON o.reservation_id = v.id
 AND TRIM(IFNULL(o.pays_type, '')) <> '能量'
 AND (o.end_time IS NOT NULL OR o.start_time IS NOT NULL)
 AND COALESCE(o.end_time, o.start_time) >= ?
 AND COALESCE(o.end_time, o.start_time) <  ?
GROUP BY v.id, v.venue_name
ORDER BY total DESC, v.id ASC
LIMIT $limit
";

$data = $db->query($sql, [$start, $endExclusive]);
if ($data === false) {
    echo json_encode(['code' => 500, 'msg' => 'SQL执行失败', 'data' => []], JSON_UNESCAPED_UNICODE);
    $db->close();
    exit;
}

echo json_encode([
    'code' => 0,
    'msg'  => 'ok',
    'meta' => [
        'period' => $period,
        'use_custom' => $useCustom ? 1 : 0,
        'label'  => $label,
        'start'  => $start,
        'end_exclusive' => $endExclusive,
        'limit'  => $limit
    ],
    'data' => $data
], JSON_UNESCAPED_UNICODE);

$db->close();
