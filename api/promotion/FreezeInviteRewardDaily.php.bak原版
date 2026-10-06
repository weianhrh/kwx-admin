<?php
require_once dirname(__DIR__) . '/Database.php';

date_default_timezone_set('Asia/Shanghai');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo json_encode([
        'code' => 403,
        'msg' => 'This script can only be executed from CLI',
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

function argValue($name, $default = null) {
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

function hasFlag($name) {
    global $argv;
    return in_array('--' . $name, $argv, true);
}

function isValidDateYmd($value) {
    return is_string($value) && (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

function out($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}

$rewardDate = argValue('date', date('Y-m-d', strtotime('-1 day')));
$dryRun = hasFlag('dry-run');
$rewardRate = 0.10;

if (!isValidDateYmd($rewardDate)) {
    out([
        'code' => 400,
        'msg' => '日期格式不正确，请使用 --date=YYYY-MM-DD',
    ]);
    exit(1);
}

$startAt = $rewardDate . ' 00:00:00';
$endAt = $rewardDate . ' 23:59:59';

$database = new Database();
$conn = $database->getConnection();
$transactionStarted = false;

try {
    $eligibleSql = "
        SELECT
            COUNT(*) AS eligible_order_count,
            ROUND(COALESCE(SUM(o.payment_amount), 0), 2) AS eligible_order_amount,
            ROUND(COALESCE(SUM(ROUND(o.payment_amount * ?, 2)), 0), 2) AS eligible_reward_amount
        FROM orders o
        INNER JOIN users u
                ON u.uid = o.uid
        INNER JOIN venues promotion_venue
                ON promotion_venue.invite_code = u.invitation_code
        WHERE u.invitation_code IS NOT NULL
          AND u.invitation_code <> ''
          AND u.invitation_code <> '0000'
          AND o.reservation_id IS NOT NULL
          AND o.reservation_id > 0
          AND o.reservation_id <> promotion_venue.id
          AND o.status = '已完成'
          AND o.end_time >= ?
          AND o.end_time <= ?
          AND COALESCE(o.payment_amount, 0) > 0
          AND (o.pays_type IS NULL OR o.pays_type <> '能量')
          AND (o.note IS NULL OR o.note <> 'gift')
    ";
    $stmt = $conn->prepare($eligibleSql);
    if (!$stmt) {
        throw new Exception('Prepare eligible failed: ' . $conn->error);
    }
    $stmt->bind_param('dss', $rewardRate, $startAt, $endAt);
    if (!$stmt->execute()) {
        throw new Exception('Execute eligible failed: ' . $stmt->error);
    }
    $eligible = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $existsSql = "
        SELECT COUNT(*) AS existing_order_count
        FROM venue_promotion_reward_logs
        WHERE reward_date = ?
          AND source_type = 'daily_17_snapshot'
    ";
    $stmt = $conn->prepare($existsSql);
    if (!$stmt) {
        throw new Exception('Prepare existing failed: ' . $conn->error);
    }
    $stmt->bind_param('s', $rewardDate);
    if (!$stmt->execute()) {
        throw new Exception('Execute existing failed: ' . $stmt->error);
    }
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($dryRun) {
        out([
            'code' => 200,
            'msg' => 'dry-run ok',
            'date' => $rewardDate,
            'eligible_order_count' => (int)$eligible['eligible_order_count'],
            'eligible_order_amount' => (string)$eligible['eligible_order_amount'],
            'eligible_reward_amount' => (string)$eligible['eligible_reward_amount'],
            'existing_snapshot_count' => (int)$existing['existing_order_count'],
            'will_insert_at_most' => max(0, (int)$eligible['eligible_order_count'] - (int)$existing['existing_order_count']),
        ]);
        exit;
    }

    $database->beginTransaction();
    $transactionStarted = true;

    $insertSql = "
        INSERT INTO venue_promotion_reward_logs (
            order_id,
            reward_date,
            invite_code,
            promotion_venue_id,
            consumer_venue_id,
            invitee_uid,
            order_amount,
            reward_rate,
            reward_amount,
            reward_status,
            source_type,
            generated_at,
            remark,
            created_at,
            updated_at
        )
        SELECT
            o.order_id,
            DATE(o.end_time) AS reward_date,
            u.invitation_code AS invite_code,
            promotion_venue.id AS promotion_venue_id,
            o.reservation_id AS consumer_venue_id,
            o.uid AS invitee_uid,
            ROUND(o.payment_amount, 2) AS order_amount,
            ? AS reward_rate,
            ROUND(o.payment_amount * ?, 2) AS reward_amount,
            'pending' AS reward_status,
            'daily_17_snapshot' AS source_type,
            NOW() AS generated_at,
            '每日推广收益固化脚本生成' AS remark,
            NOW() AS created_at,
            NOW() AS updated_at
        FROM orders o
        INNER JOIN users u
                ON u.uid = o.uid
        INNER JOIN venues promotion_venue
                ON promotion_venue.invite_code = u.invitation_code
        WHERE u.invitation_code IS NOT NULL
          AND u.invitation_code <> ''
          AND u.invitation_code <> '0000'
          AND o.reservation_id IS NOT NULL
          AND o.reservation_id > 0
          AND o.reservation_id <> promotion_venue.id
          AND o.status = '已完成'
          AND o.end_time >= ?
          AND o.end_time <= ?
          AND COALESCE(o.payment_amount, 0) > 0
          AND (o.pays_type IS NULL OR o.pays_type <> '能量')
          AND (o.note IS NULL OR o.note <> 'gift')
          AND NOT EXISTS (
              SELECT 1
              FROM venue_promotion_reward_logs existed
              WHERE existed.order_id = o.order_id
          )
    ";
    $stmt = $conn->prepare($insertSql);
    if (!$stmt) {
        throw new Exception('Prepare insert failed: ' . $conn->error);
    }
    $stmt->bind_param('ddss', $rewardRate, $rewardRate, $startAt, $endAt);
    if (!$stmt->execute()) {
        throw new Exception('Execute insert failed: ' . $stmt->error);
    }
    $insertedRows = $stmt->affected_rows;
    $stmt->close();

    $summarySql = "
        SELECT
            COUNT(*) AS snapshot_order_count,
            ROUND(COALESCE(SUM(order_amount), 0), 2) AS snapshot_order_amount,
            ROUND(COALESCE(SUM(reward_amount), 0), 2) AS snapshot_reward_amount,
            COUNT(DISTINCT promotion_venue_id) AS promotion_venue_count
        FROM venue_promotion_reward_logs
        WHERE reward_date = ?
          AND source_type = 'daily_17_snapshot'
    ";
    $stmt = $conn->prepare($summarySql);
    if (!$stmt) {
        throw new Exception('Prepare summary failed: ' . $conn->error);
    }
    $stmt->bind_param('s', $rewardDate);
    if (!$stmt->execute()) {
        throw new Exception('Execute summary failed: ' . $stmt->error);
    }
    $summary = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $database->commit();
    $transactionStarted = false;

    out([
        'code' => 200,
        'msg' => '推广收益固化完成',
        'date' => $rewardDate,
        'eligible_order_count' => (int)$eligible['eligible_order_count'],
        'eligible_order_amount' => (string)$eligible['eligible_order_amount'],
        'eligible_reward_amount' => (string)$eligible['eligible_reward_amount'],
        'existing_before_count' => (int)$existing['existing_order_count'],
        'inserted_count' => (int)$insertedRows,
        'snapshot_order_count' => (int)$summary['snapshot_order_count'],
        'snapshot_order_amount' => (string)$summary['snapshot_order_amount'],
        'snapshot_reward_amount' => (string)$summary['snapshot_reward_amount'],
        'promotion_venue_count' => (int)$summary['promotion_venue_count'],
    ]);
} catch (Throwable $e) {
    if ($transactionStarted) {
        try {
            $database->rollBack();
        } catch (Throwable $ignored) {
        }
    }
    error_log('FreezeInviteRewardDaily error: ' . $e->getMessage());
    out([
        'code' => 500,
        'msg' => '推广收益固化失败',
        'error' => $e->getMessage(),
    ]);
    exit(1);
} finally {
    $database->close();
}
