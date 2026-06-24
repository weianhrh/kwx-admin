<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function isValidDateYmd($value) {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

$database = new Database();

try {
    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        jsonOut(1001, 'Login required or session expired');
    }

    $user = $database->getUserBySessionToken($session_token);
    if (!$user || empty($user['uid'])) {
        jsonOut(1001, 'Current account has no user UID for invite reward preview');
    }

    $inviter_uid = (int)$user['uid'];
    $date = trim($_GET['date'] ?? date('Y-m-d'));
    if (!isValidDateYmd($date)) {
        jsonOut(400, 'Invalid date format');
    }

    $start_dt = $date . ' 00:00:00';
    $end_dt = $date . ' 23:59:59';

    $profile = $database->query(
        "SELECT invite_code, promotion_level FROM user_invite_codes WHERE uid = ? AND status = 'active' LIMIT 1",
        [(string)$inviter_uid]
    );

    if (!$profile) {
        jsonOut(200, 'ok', [
            'date' => $date,
            'inviter_uid' => $inviter_uid,
            'invite_code' => '',
            'promotion_level' => 'DEFAULT',
            'summary' => [
                'estimated_reward_amount' => 0,
                'valid_order_count' => 0,
                'valid_order_amount' => 0,
                'reward_rate_percent' => 0,
            ],
            'list' => [],
        ]);
    }

    $promotion_level = $profile[0]['promotion_level'] ?: 'DEFAULT';
    $invite_code = $profile[0]['invite_code'] ?? '';

    $rule = $database->query(
        "SELECT id, reward_rate, min_order_amount, max_reward_per_order, settle_days
         FROM invite_reward_rules
         WHERE level_code = ?
           AND status = 'active'
           AND (start_time IS NULL OR start_time <= ?)
           AND (end_time IS NULL OR end_time >= ?)
         ORDER BY id DESC
         LIMIT 1",
        [$promotion_level, $end_dt, $start_dt]
    );

    if (!$rule && $promotion_level !== 'DEFAULT') {
        $rule = $database->query(
            "SELECT id, reward_rate, min_order_amount, max_reward_per_order, settle_days
             FROM invite_reward_rules
             WHERE level_code = 'DEFAULT'
               AND status = 'active'
               AND (start_time IS NULL OR start_time <= ?)
               AND (end_time IS NULL OR end_time >= ?)
             ORDER BY id DESC
             LIMIT 1",
            [$end_dt, $start_dt]
        );
        $promotion_level = 'DEFAULT';
    }

    if (!$rule) {
        jsonOut(500, 'No active invite reward rule found');
    }

    $rule_id = (int)$rule[0]['id'];
    $reward_rate = (float)$rule[0]['reward_rate'];
    $min_order_amount = (float)$rule[0]['min_order_amount'];
    $max_reward_per_order = $rule[0]['max_reward_per_order'];

    $sql = "
        SELECT
            o.order_id,
            o.reservation_id AS venue_id,
            o.uid AS invitee_uid,
            u.nickname AS invitee_nickname,
            o.payment_amount AS order_amount,
            o.pays_type,
            o.end_time,
            irl.reward_status AS generated_status
        FROM orders o
        JOIN invite_relations ir
          ON ir.invitee_uid = o.uid
         AND ir.status = 'active'
         AND ir.inviter_uid = ?
        LEFT JOIN users u ON u.uid = o.uid
        LEFT JOIN invite_reward_logs irl ON irl.order_id = o.order_id
        WHERE HEX(o.status) = 'E5B7B2E5AE8CE68890'
          AND o.payment_amount >= ?
          AND o.end_time >= ?
          AND o.end_time <= ?
          AND o.uid IS NOT NULL
          AND o.reservation_id IS NOT NULL
          AND HEX(o.pays_type) <> 'E883BDE9878F'
        ORDER BY o.end_time DESC
    ";

    $orders = $database->query($sql, [(string)$inviter_uid, (string)$min_order_amount, $start_dt, $end_dt]);
    if ($orders === false) {
        jsonOut(500, 'Failed to query invite reward preview details');
    }

    $total_order_amount = 0.0;
    $total_reward_amount = 0.0;
    $list = [];

    foreach ($orders as $row) {
        $order_amount = (float)$row['order_amount'];
        $reward_amount = round($order_amount * $reward_rate, 2);
        if ($max_reward_per_order !== null && $max_reward_per_order !== '') {
            $reward_amount = min($reward_amount, (float)$max_reward_per_order);
        }

        $total_order_amount += $order_amount;
        $total_reward_amount += $reward_amount;

        $list[] = [
            'order_id' => $row['order_id'],
            'venue_id' => (int)$row['venue_id'],
            'invitee_uid' => (int)$row['invitee_uid'],
            'invitee_nickname' => $row['invitee_nickname'] ?? '',
            'order_amount' => number_format($order_amount, 2, '.', ''),
            'reward_rate' => number_format($reward_rate, 4, '.', ''),
            'reward_rate_percent' => round($reward_rate * 100, 2),
            'estimated_reward_amount' => number_format($reward_amount, 2, '.', ''),
            'pays_type' => $row['pays_type'],
            'end_time' => $row['end_time'],
            'generated_status' => $row['generated_status'] ?? null,
        ];
    }

    jsonOut(200, 'ok', [
        'date' => $date,
        'inviter_uid' => $inviter_uid,
        'invite_code' => $invite_code,
        'promotion_level' => $promotion_level,
        'rule_id' => $rule_id,
        'summary' => [
            'estimated_reward_amount' => round($total_reward_amount, 2),
            'valid_order_count' => count($list),
            'valid_order_amount' => round($total_order_amount, 2),
            'reward_rate' => $reward_rate,
            'reward_rate_percent' => round($reward_rate * 100, 2),
        ],
        'list' => $list,
    ]);
} catch (Throwable $e) {
    error_log('GetInviteRewardPreview error: ' . $e->getMessage());
    jsonOut(500, 'Server error');
} finally {
    $database->close();
}
