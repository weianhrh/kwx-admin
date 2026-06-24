<?php
require_once '../Database.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_COOKIE['session_token'])) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !isset($user['role_id'])) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
$role_id = (int)$user['role_id']; // 只返回给前端显示，不做过滤

function getRange(string $mode): array {
    $mode = strtolower(trim($mode));
    if ($mode === 'week') {
        $start = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $end   = date('Y-m-d 00:00:00', strtotime('monday next week'));
    } elseif ($mode === 'month') {
        $start = date('Y-m-01 00:00:00');
        $end   = date('Y-m-01 00:00:00', strtotime('+1 month'));
    } else {
        $mode  = 'day';
        $start = date('Y-m-d 00:00:00');
        $end   = date('Y-m-d 00:00:00', strtotime('+1 day'));
    }
    return [$mode, $start, $end];
}
function normalizeDatetime(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;

    // 允许：2026-01-16 00:00:00 / 2026/01/16 00:00 / 2026-01-16 00:00
    $s = str_replace('/', '-', $s);
    $ts = strtotime($s);
    if ($ts === false) return null;

    return date('Y-m-d H:i:s', $ts);
}

function getRangeByMode(string $mode): array {
    $mode = strtolower(trim($mode));
    if ($mode === 'week') {
        $start = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $end   = date('Y-m-d 00:00:00', strtotime('monday next week'));
    } elseif ($mode === 'month') {
        $start = date('Y-m-01 00:00:00');
        $end   = date('Y-m-01 00:00:00', strtotime('+1 month'));
    } else {
        $mode  = 'day';
        $start = date('Y-m-d 00:00:00');
        $end   = date('Y-m-d 00:00:00', strtotime('+1 day'));
    }
    return [$mode, $start, $end];
}
function safeInt($v): int { return is_numeric($v) ? (int)$v : 0; }
function safeFloat($v): float { return is_numeric($v) ? (float)$v : 0.0; }
function safeDiv(float $a, float $b, int $scale = 4): float { return $b > 0 ? round($a / $b, $scale) : 0.0; }

// ——优先自定义 start/end——
$mode = $_GET['mode'] ?? 'day';
$startQ = normalizeDatetime($_GET['start'] ?? '');
$endQ   = normalizeDatetime($_GET['end'] ?? '');

if ($startQ && $endQ && strtotime($endQ) > strtotime($startQ)) {
    $mode = 'custom';
    $start = $startQ;
    $end   = $endQ;
} else {
    [$mode, $start, $end] = getRangeByMode($mode);
}

try {
    // 0) 周期内活跃总人数（last_active_at）
    $sqlActiveTotal = "SELECT COUNT(*) AS active_total
                       FROM users
                       WHERE last_active_at >= ? AND last_active_at < ?";
    $r0 = $database->query($sqlActiveTotal, [$start, $end])[0] ?? [];
    $active_total = safeInt($r0['active_total'] ?? 0);

    // 1) 周期内：活跃用户中有实际消费的人数（去重 uid）
    $sqlActivePayUsers = "SELECT COUNT(DISTINCT o.uid) AS active_users_with_consumption
                          FROM users u
                          JOIN orders o ON o.uid = u.uid
                          WHERE u.last_active_at >= ? AND u.last_active_at < ?
                            AND o.end_time >= ? AND o.end_time < ?
                            AND (o.pays_type IS NULL OR o.pays_type <> '能量')";
    // 如只算已完成，加： AND o.status='已完成'
    $r1 = $database->query($sqlActivePayUsers, [$start, $end, $start, $end])[0] ?? [];
    $active_users_with_consumption = safeInt($r1['active_users_with_consumption'] ?? 0);

    // 2) 周期内新注册用户数（created_at）
    $sqlNewUsers = "SELECT COUNT(*) AS new_users
                    FROM users
                    WHERE created_at >= ? AND created_at < ?";
    $r2 = $database->query($sqlNewUsers, [$start, $end])[0] ?? [];
    $new_users = safeInt($r2['new_users'] ?? 0);

    // 2.1) 周期内新注册用户消费金额（订单 end_time 落在周期内 + 排除能量）
    $sqlNewUsersAmount = "SELECT COALESCE(SUM(o.payment_amount), 0) AS new_users_consumption
                          FROM users u
                          JOIN orders o ON o.uid = u.uid
                          WHERE u.created_at >= ? AND u.created_at < ?
                            AND o.end_time >= ? AND o.end_time < ?
                            AND (o.pays_type IS NULL OR o.pays_type <> '能量')";
    $r21 = $database->query($sqlNewUsersAmount, [$start, $end, $start, $end])[0] ?? [];
    $new_users_consumption = safeFloat($r21['new_users_consumption'] ?? 0);

    // 3) 新/老用户拆分（周期内订单）+ 占比(=金额/人数) + 客单价(=金额/订单数) + 金额占比
    $sqlSplit = "SELECT
        COUNT(DISTINCT CASE WHEN u.created_at >= ? AND u.created_at < ? THEN o.uid END) AS new_user_cnt,
        COUNT(           CASE WHEN u.created_at >= ? AND u.created_at < ? THEN 1 END)    AS new_order_cnt,
        COALESCE(SUM(     CASE WHEN u.created_at >= ? AND u.created_at < ? THEN o.payment_amount ELSE 0 END), 0) AS new_amount,

        COUNT(DISTINCT CASE WHEN NOT (u.created_at >= ? AND u.created_at < ?) THEN o.uid END) AS old_user_cnt,
        COUNT(           CASE WHEN NOT (u.created_at >= ? AND u.created_at < ?) THEN 1 END)    AS old_order_cnt,
        COALESCE(SUM(     CASE WHEN NOT (u.created_at >= ? AND u.created_at < ?) THEN o.payment_amount ELSE 0 END), 0) AS old_amount
      FROM orders o
      JOIN users u ON u.uid = o.uid
      WHERE o.end_time >= ? AND o.end_time < ?
        AND (o.pays_type IS NULL OR o.pays_type <> '能量')";
    $params = [
        $start, $end,  $start, $end,  $start, $end,
        $start, $end,  $start, $end,  $start, $end,
        $start, $end
    ];
    $r3 = $database->query($sqlSplit, $params)[0] ?? [];

    $new_user_cnt  = safeInt($r3['new_user_cnt'] ?? 0);
    $new_order_cnt = safeInt($r3['new_order_cnt'] ?? 0);
    $new_amount    = safeFloat($r3['new_amount'] ?? 0);

    $old_user_cnt  = safeInt($r3['old_user_cnt'] ?? 0);
    $old_order_cnt = safeInt($r3['old_order_cnt'] ?? 0);
    $old_amount    = safeFloat($r3['old_amount'] ?? 0);

    $total_amount = $new_amount + $old_amount;

    // 你定义：占比/平均日消费 = 总金额 / 总人数（这里“总人数”按“消费去重人数”口径）
    $new_avg_per_user = safeDiv($new_amount, (float)$new_user_cnt, 6);
    $old_avg_per_user = safeDiv($old_amount, (float)$old_user_cnt, 6);

    // 客单价 = 总金额 / 订单数
    $new_aov = safeDiv($new_amount, (float)$new_order_cnt, 6);
    $old_aov = safeDiv($old_amount, (float)$old_order_cnt, 6);

    // 新老金额占比（金额share）
    $new_amount_share = safeDiv($new_amount, (float)$total_amount, 6);
    $old_amount_share = safeDiv($old_amount, (float)$total_amount, 6);

    echo json_encode([
        'code' => 0,
        'msg'  => 'ok',
        'data' => [
            'role_id' => $role_id,
            'mode' => $mode,
            'range' => ['start' => $start, 'end' => $end],

            // 0) 活跃总人数
            'active_total' => $active_total,

            // 1) 活跃中有消费的人数
            'active_users_with_consumption' => $new_user_cnt + $old_user_cnt,

            // 2) 新注册用户数 + 新注册用户消费金额
            'new_users' => $new_users,
            'new_users_consumption' => round($new_users_consumption, 2),

            // 3) 新/老消费拆分 + 占比/平均日消费 + 客单价 + 新老占比
            'new_user_cnt' => $new_user_cnt,
            'new_order_cnt' => $new_order_cnt,
            'new_amount' => round($new_amount, 2),
            'new_avg_per_user' => $new_avg_per_user,   // 占比/平均日消费(=金额/人数)
            'new_aov' => $new_aov,                     // 客单价(=金额/订单数)
            'new_amount_share' => $new_amount_share,   // 新用户金额占比（对总金额）

            'old_user_cnt' => $old_user_cnt,
            'old_order_cnt' => $old_order_cnt,
            'old_amount' => round($old_amount, 2),
            'old_avg_per_user' => $old_avg_per_user,
            'old_aov' => $old_aov,
            'old_amount_share' => $old_amount_share,

            'total_amount' => round($total_amount, 2),
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[stats] ' . $e->getMessage());
    echo json_encode(['code' => 1002, 'msg' => '服务器错误', 'data' => []], JSON_UNESCAPED_UNICODE);
} finally {
    $database->close();
}
?>
