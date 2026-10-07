<?php
require_once dirname(__DIR__) . '/Database.php';
require_once dirname(__DIR__) . '/lib/kwx_8899_policy.php';
require_once dirname(__DIR__) . '/lib/kwx_8899_cron_access.php';

date_default_timezone_set('Asia/Shanghai');
kwx8899RequireCronWebAccess();

function argValue($name, $default = null) {
    global $argv;
    $prefix = '--' . $name . '=';
    foreach (($argv ?? []) as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

function hasFlag($name) {
    global $argv;
    return in_array('--' . $name, $argv ?? [], true);
}

function isValidDateYmd($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $parsed = DateTime::createFromFormat('!Y-m-d', $value);
    return $parsed && $parsed->format('Y-m-d') === $value;
}

function out($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
}

$rewardDate = argValue('date', date('Y-m-d', strtotime('-1 day')));
if (PHP_SAPI !== 'cli' && isset($_GET['date'])) {
    $rewardDate = (string)$_GET['date'];
}
$dryRun = hasFlag('dry-run');
$rewardRate = 0.10;
$promotionMainSubtitle = '8899';

if (!isValidDateYmd($rewardDate)) {
    out([
        'code' => 400,
        'msg' => '日期格式不正确，请使用 --date=YYYY-MM-DD',
    ]);
    exit(1);
}
if (!kwx8899Active($rewardDate)) {
    if (PHP_SAPI !== 'cli') {
        out(['code' => 0, 'date' => $rewardDate, 'msg' => '生效日前的账期无需冻结，已跳过']);
        exit;
    }
    out(['code' => 400, 'msg' => '新分账规则仅从 ' . KWX_8899_CUTOVER_DATE . ' 起生效；历史余额需要单独对账']);
    exit(1);
}
if ($rewardDate >= date('Y-m-d')) {
    out(['code' => 400, 'msg' => '只能固化已经结束的自然日账期，不能提前固化今天或未来日期']);
    exit(1);
}

$startAt = $rewardDate . ' 00:00:00';
$endAt = $rewardDate . ' 23:59:59';

$database = new Database();
$conn = $database->getConnection();
$transactionStarted = false;

/*
 * KWX 推广归属规则：
 * 1. 只认 venues.venue_subtitle = '8899' 的场地；
 * 2. 以 venue_unique_subtitle 作为二级推广码；
 * 3. venue_unique_subtitle 为 NULL / 空 / '8899' 的全部忽略；
 * 4. users.invitation_code 命中二级推广码时，该 venues.id 即推广场地；
 * 5. 消费场地 orders.reservation_id 与推广场地不同，才产生 10% 推广收益。
 * 6. 消费场地也必须是 8899 场地。
 */
$promotionJoin = "
    INNER JOIN venues promotion_venue
            ON promotion_venue.venue_subtitle = ?
           AND promotion_venue.venue_unique_subtitle IS NOT NULL
           AND promotion_venue.venue_unique_subtitle <> ''
           AND promotion_venue.venue_unique_subtitle <> ?
           AND promotion_venue.venue_unique_subtitle = u.invitation_code
    INNER JOIN venues consumer_venue
            ON consumer_venue.id = o.reservation_id
           AND consumer_venue.venue_subtitle = '8899'
";

try {
    kwx8899AcquireDailyLock($database, $rewardDate);
    $duplicateCodes = $database->query(
        "SELECT venue_unique_subtitle FROM venues
         WHERE venue_subtitle = '8899'
           AND venue_unique_subtitle IS NOT NULL
           AND venue_unique_subtitle <> '' AND venue_unique_subtitle <> '8899'
         GROUP BY venue_unique_subtitle HAVING COUNT(*) > 1 LIMIT 1"
    );
    if ($duplicateCodes === false || $duplicateCodes) {
        throw new RuntimeException('8899 二级推广码重复或校验失败，禁止固化');
    }
    $eligibleSql = "
        SELECT
            COUNT(*) AS eligible_order_count,
            ROUND(COALESCE(SUM(o.payment_amount), 0), 2) AS eligible_order_amount,
            ROUND(COALESCE(SUM(ROUND(o.payment_amount * ?, 2)), 0), 2) AS eligible_reward_amount
        FROM orders o
        INNER JOIN users u
                ON u.uid = o.uid
        {$promotionJoin}
        WHERE u.invitation_code IS NOT NULL
          AND u.invitation_code <> ''
          AND u.invitation_code <> ?
          AND o.reservation_id IS NOT NULL
          AND o.reservation_id > 0
          AND o.reservation_id <> promotion_venue.id
          AND o.status = '已完成'
          AND o.end_time >= ?
          AND o.end_time <= ?
          AND COALESCE(o.payment_amount, 0) > 0
          AND (o.pays_type IS NULL OR o.pays_type <> '能量')
          AND (o.note IS NULL OR o.note NOT IN ('gift', '场地礼物'))
          AND NOT EXISTS (SELECT 1 FROM refund_records rr WHERE rr.order_id = o.order_id)
    ";
    $stmt = $conn->prepare($eligibleSql);
    if (!$stmt) {
        throw new Exception('Prepare eligible failed: ' . $conn->error);
    }
    $stmt->bind_param(
        'dsssss',
        $rewardRate,
        $promotionMainSubtitle,
        $promotionMainSubtitle,
        $promotionMainSubtitle,
        $startAt,
        $endAt
    );
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
            'promotion_main_subtitle' => $promotionMainSubtitle,
            'promotion_code_source' => 'venues.venue_unique_subtitle',
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
            'KWX 8899二级引流推广收益固化脚本生成' AS remark,
            NOW() AS created_at,
            NOW() AS updated_at
        FROM orders o
        INNER JOIN users u
                ON u.uid = o.uid
        {$promotionJoin}
        WHERE u.invitation_code IS NOT NULL
          AND u.invitation_code <> ''
          AND u.invitation_code <> ?
          AND o.reservation_id IS NOT NULL
          AND o.reservation_id > 0
          AND o.reservation_id <> promotion_venue.id
          AND o.status = '已完成'
          AND o.end_time >= ?
          AND o.end_time <= ?
          AND COALESCE(o.payment_amount, 0) > 0
          AND (o.pays_type IS NULL OR o.pays_type <> '能量')
          AND (o.note IS NULL OR o.note NOT IN ('gift', '场地礼物'))
          AND NOT EXISTS (SELECT 1 FROM refund_records rr WHERE rr.order_id = o.order_id)
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
    $stmt->bind_param(
        'ddsssss',
        $rewardRate,
        $rewardRate,
        $promotionMainSubtitle,
        $promotionMainSubtitle,
        $promotionMainSubtitle,
        $startAt,
        $endAt
    );
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
    $platformRows = $database->query(
        "SELECT COALESCE(SUM(ROUND(order_amount * 0.20, 2)), 0) AS platform_amount
         FROM venue_promotion_reward_logs
         WHERE reward_date = ? AND source_type = 'daily_17_snapshot'",
        [$rewardDate]
    );
    if ($platformRows === false) {
        throw new RuntimeException('平台份额统计失败');
    }
    $platformAmount = round((float)$platformRows[0]['platform_amount'], 2);
    $consumerAmount = round((float)$summary['snapshot_order_amount'] - (float)$summary['snapshot_reward_amount'] - $platformAmount, 2);

    if ((int)$summary['snapshot_order_count'] !== (int)$eligible['eligible_order_count']
        || abs((float)$summary['snapshot_order_amount'] - (float)$eligible['eligible_order_amount']) > 0.005
        || abs((float)$summary['snapshot_reward_amount'] - (float)$eligible['eligible_reward_amount']) > 0.005) {
        throw new RuntimeException('推广订单与固化明细不一致，已回滚；检查重复订单或日期内新增订单');
    }

    $dayRows = $database->query(
        'SELECT order_count, order_amount, reward_amount, platform_amount, consumer_amount FROM kwx_8899_revenue_days WHERE revenue_date = ? FOR UPDATE',
        [$rewardDate]
    );
    if ($dayRows === false) {
        throw new RuntimeException('读取固化账期失败，请先执行迁移 SQL');
    }
    if ($dayRows) {
        if ((int)$dayRows[0]['order_count'] !== (int)$summary['snapshot_order_count']
            || abs((float)$dayRows[0]['order_amount'] - (float)$summary['snapshot_order_amount']) > 0.005
            || abs((float)$dayRows[0]['reward_amount'] - (float)$summary['snapshot_reward_amount']) > 0.005
            || abs((float)$dayRows[0]['platform_amount'] - $platformAmount) > 0.005
            || abs((float)$dayRows[0]['consumer_amount'] - $consumerAmount) > 0.005) {
            throw new RuntimeException('该账期已经固化且金额发生变化，禁止覆盖历史账');
        }
    } else {
        $written = $database->query(
            'INSERT INTO kwx_8899_revenue_days (revenue_date, order_count, order_amount, reward_amount, platform_amount, consumer_amount) VALUES (?, ?, ?, ?, ?, ?)',
            [$rewardDate, $summary['snapshot_order_count'], $summary['snapshot_order_amount'], $summary['snapshot_reward_amount'], $platformAmount, $consumerAmount],
            true
        );
        if ($written !== 1) {
            throw new RuntimeException('固化账期失败');
        }
    }

    $database->commit();
    $transactionStarted = false;

    out([
        'code' => 200,
        'msg' => '推广收益固化完成',
        'date' => $rewardDate,
        'promotion_main_subtitle' => $promotionMainSubtitle,
        'promotion_code_source' => 'venues.venue_unique_subtitle',
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
