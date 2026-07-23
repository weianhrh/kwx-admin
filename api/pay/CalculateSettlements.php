<?php
require_once '../Database.php';
require_once '../lib/venue_scope.php';
require_once '../RedisHelper.php';   // ★ 新增

// api/pay/CalculateSettlements.php
function formatTtl($ttl, $mode = 'dhms') {
    $ttl = (int)$ttl;

    if ($ttl === -1) return '无到期';
    if ($ttl === -2) return '不存在';
    if ($ttl <= 0)  return '已到期';

    $days = intdiv($ttl, 86400);
    $ttl %= 86400;
    $hours = intdiv($ttl, 3600);
    $ttl %= 3600;
    $mins = intdiv($ttl, 60);
    $secs = $ttl % 60;

    if ($mode === 'hms') {
        // 可能超过24小时，把天折算进小时
        $totalHours = $days * 24 + $hours;
        return sprintf('%02d:%02d:%02d', $totalHours, $mins, $secs);
    }

    // 默认：Xd Xh Xm Xs（有的单位为0就不显示）
    $parts = [];
    if ($days > 0)  $parts[] = $days . '天';
    if ($hours > 0 || $days > 0) $parts[] = $hours . '小时';
    if ($mins > 0 || $hours > 0 || $days > 0) $parts[] = $mins . '分';
    $parts[] = $secs . '秒';
    return implode('', $parts);
}
$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
  echo json_encode(['code'=>1001,'msg'=>'用户未登录或会话已过期','data'=>[]]); exit;
}
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
  echo json_encode(['code'=>1001,'msg'=>'用户未登录或无权访问','data'=>[]]); exit;
}
$venue_id = venue_scope_resolve_single_id($database, $user, venue_scope_requested_id(array_merge($_GET, $_POST)));
if ($venue_id <= 0) {
  echo json_encode(['code'=>1003,'msg'=>'未绑定或无权访问该场地','data'=>[]], JSON_UNESCAPED_UNICODE); exit;
}
$withdraw_type = $_GET['withdraw_type'] ?? $_POST['withdraw_type'] ?? 'account';
$withdraw_type = ($withdraw_type === 'gift') ? 'gift' : 'account';
// 业务金额
$checkedNotSettledSql = "SELECT SUM(total_revenue) AS total_amount FROM DailyVenueRevenue WHERE venue_id=? AND is_checked=1 AND is_settled=0";
$checkedNotSettledAmount = (float)($database->query($checkedNotSettledSql, [$venue_id])[0]['total_amount'] ?? 0);

$notSettledSql = "SELECT SUM(total_revenue) AS total_amount FROM DailyVenueRevenue WHERE venue_id=? AND is_checked=0";
$notSettledAmount = (float)($database->query($notSettledSql, [$venue_id])[0]['total_amount'] ?? 0);

$fundsSql = "SELECT withdrawal_account, account_type, account_balance, account_name FROM venue_funds WHERE venue_id = ?";
$funds = $database->query($fundsSql, [$venue_id]);
if (empty($funds)) {
  echo json_encode(['code'=>1002,'msg'=>'请先绑定提现账号','data'=>[]]); exit;
}
$account_balance = (float)$funds[0]['account_balance'];

// withdraw_ratio 表示平台扣除比例，不是加盟商到账比例。
// 未配置时默认平台扣除20%，提现手续费0%，加盟商实际到账80%。
$platformDeductionRate = 0.20;
$withdrawalFeeRate = 0.00;
$configRows = $database->query(
    "SELECT withdraw_ratio, withdrawal_fee_rate
     FROM venue_withdrawal_configs
     WHERE venue_id = ?
     LIMIT 1",
    [$venue_id]
);
if (!empty($configRows)) {
    $platformDeductionRate = max(0, min(1, (float)$configRows[0]['withdraw_ratio'] / 100));
    $withdrawalFeeRate = max(0, min(1, (float)$configRows[0]['withdrawal_fee_rate'] / 100));
}
$actualPayoutRate = max(0, 1 - $platformDeductionRate - $withdrawalFeeRate);

// 账号掩码，普通提现和礼物提现都要显示收款账号
$withdrawal_account = $funds[0]['withdrawal_account'];
$masked_account = str_repeat('*', max(0, strlen($withdrawal_account) - 4)) . substr($withdrawal_account, -4);

// 礼物提现：直接读取 venues.gift_balance
if ($withdraw_type === 'gift') {
    $giftSql = "SELECT COALESCE(gift_balance, 0) AS gift_balance FROM venues WHERE id = ? LIMIT 1";
    $giftRows = $database->query($giftSql, [$venue_id]);

    if (empty($giftRows)) {
        echo json_encode(['code' => 1003, 'msg' => '场地不存在', 'data' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $gift_balance = (float)($giftRows[0]['gift_balance'] ?? 0);

    echo json_encode([
        'code' => 0,
        'msg'  => '',
        'withdraw_type' => 'gift',
        'venue_id' => $venue_id,

        // 收款账号信息还是复用 venue_funds
        'account_number' => $masked_account,
        'account_pay' => $funds[0]['account_type'],
        'account_name' => $funds[0]['account_name'],

        // 礼物提现余额
        'gift_balance' => $gift_balance,
        'available_balance' => $gift_balance,

        // 兼容前端字段
        'account_balance' => $account_balance,
        'frozen_amount' => 0,
        'lock_amount' => 0,
        'lock_order_count' => 0,
        'total_refund' => 0,
        'checkedNotSettledAmount' => 0,
        'notSettledAmount' => 0
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


// ★ 读取 Redis 冻结总额
$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(1);
$frozen_amount = 0.0;
// ✅新增：该venue下冻结的“最短剩余TTL(秒)”（最近一次解冻）
$ttlMin = null;

if (method_exists($redis, 'scan')) {
    $keys = $redis->scan("venue:{$venue_id}:frozen:*", 200);
} else {
    $keys = $redis->getAllKeys("venue:{$venue_id}:frozen:*");
}
foreach ($keys as $k) {
    $v = $redis->get($k);
    if ($v !== false && $v !== null) $frozen_amount += (float)$v;
    //同时返回$venue_id的ttl
    // ✅核心：拿每个冻结key的剩余TTL
    $ttl = $redis->ttl($k);  // -1 永不过期，-2 不存在，>0 剩余秒

    // 只统计 >0 的 TTL，取最小的作为“最近一次解冻”
    if ($ttl > 0) {
        $ttlMin = ($ttlMin === null) ? $ttl : max($ttlMin, $ttl);
    }
    // 如果你想：有任何一个永不过期就直接显示“无到期”
    if ($ttl === -1) {
        $ttlMin = -1;
        break;
    }
    
}
// ✅最终TTL文本
$ttlSeconds = ($ttlMin === null) ? 0 : (int)$ttlMin;   // 没有冻结key时返回0
$ttlText = formatTtl($ttlSeconds);
$redis->close();

// 当前场地未结算图传费用总和，并获取对应的主键ID
$imageFeeSql = "
    SELECT id, image_transmission_fee 
    FROM image_transmission_fee_daily
    WHERE reservation_id = ?
      AND is_settlement = 0
";

$imageFeeRows = $database->query($imageFeeSql, [$venue_id]);

$unsettledImageTransmissionFee = 0.0;
$unsettledImageIds = [];

foreach ($imageFeeRows as $row) {
    $unsettledImageTransmissionFee += (float)$row['image_transmission_fee'];
    $unsettledImageIds[] = (int)$row['id'];  // 收集主键ID
}

// 获取退款金额
$refundSql = "SELECT COALESCE(SUM(refund_amount), 0) AS total_refund
              FROM refund_records
              WHERE reservation_id = ? AND is_reduced != 1";
$totalRefund = (float)($database->query($refundSql, [$venue_id])[0]['total_refund'] ?? 0);
// 获取锁定金额 / 锁定订单数
$lockSql = "SELECT
                COALESCE(SUM(lock_amount), 0) AS total_lock_amount,
                COUNT(*) AS lock_order_count
            FROM order_lock_records
            WHERE venue_id = ? AND status = 1";
$lockRow = $database->query($lockSql, [$venue_id]);
$lockAmount = (float)($lockRow[0]['total_lock_amount'] ?? 0);
$lockOrderCount = (int)($lockRow[0]['lock_order_count'] ?? 0);

// $available_balance = max(0.0, $account_balance - $frozen_amount - $totalRefund - $lockAmount);
$settlement_balance = max(
    0.0,
    $account_balance - $frozen_amount - $totalRefund - $lockAmount - $unsettledImageTransmissionFee
);
$available_balance = round($settlement_balance * $actualPayoutRate, 2);

// 账号掩码
$withdrawal_account = $funds[0]['withdrawal_account'];
$masked_account = str_repeat('*', max(0, strlen($withdrawal_account) - 4)) . substr($withdrawal_account, -4);

echo json_encode([
  'code' => 0,
  'msg'  => '',
  "ttl"  => $ttlText,
  'venue_id' => $venue_id,
  'unsettled_image_transmission_fee' => $unsettledImageTransmissionFee,
  'unsettled_image_ids' => $unsettledImageIds,
  'account_number' => $masked_account,
  'account_pay' => $funds[0]['account_type'],
  'checkedNotSettledAmount' => $checkedNotSettledAmount,
  'notSettledAmount' => $notSettledAmount,
  'account_balance' => $account_balance,     // 原始余额
  'settlement_balance' => round($settlement_balance, 2), // 扣除冻结/退款等后的结算基数
  'platform_deduction_rate' => $platformDeductionRate,
  'platform_deduction_rate_text' => ($platformDeductionRate * 100) . '%',
  'withdrawal_fee_rate' => $withdrawalFeeRate,
  'actual_payout_rate' => $actualPayoutRate,
  'actual_payout_rate_text' => ($actualPayoutRate * 100) . '%',
  'frozen_amount' => $frozen_amount,         // ★ 新增
  'lock_amount' => $lockAmount,
  'lock_order_count' => $lockOrderCount,
  'available_balance' => $available_balance, // 扣除平台比例和手续费后的实际可提现金额
  'total_refund' => $totalRefund,           // 新增退款金额
  'account_name' => $funds[0]['account_name']
]);
$database->close();
