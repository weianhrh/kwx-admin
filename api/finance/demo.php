<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

$database = new Database();

// -------------------- 会话校验 --------------------
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------- 支持前端传入时间范围（最小改动：只影响注册用户筛选） --------------------
// GET: start_time, end_time
// 支持：2026-02-03 / 2026-02-03 00:00:00 / 2026-02-03T00:00
function parseDateTimeFlexible($s, $isEnd = false) {
    $s = trim((string)$s);
    if ($s === '') return null;
    $s = str_replace('T', ' ', $s);

    // 仅日期：补齐整天
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        $s .= $isEnd ? ' 23:59:59' : ' 00:00:00';
    }
    // 到分钟：补秒
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $s)) {
        $s .= ':00';
    }

    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s);
    if ($dt && $dt->format('Y-m-d H:i:s') === $s) return $s;
    return null;
}

// 默认还是"今天"
$today = date('Y-m-d');
$start_time = parseDateTimeFlexible($_GET['start_time'] ?? '', false);
$end_time   = parseDateTimeFlexible($_GET['end_time'] ?? '', true);

if (!$start_time || !$end_time) {
    $start_time = $today . ' 00:00:00';
    $end_time   = $today . ' 23:59:59';
}

if (strtotime($start_time) > strtotime($end_time)) {
    echo json_encode(['code' => 1003, 'msg' => '时间范围错误：start_time 不能大于 end_time', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取时间段注册的用户（替换原 DATE(created_at)=?）
$todayUsers = [];
$usersStmt = $database->prepare("SELECT uid FROM users WHERE created_at BETWEEN ? AND ?");
$usersStmt->bind_param("ss", $start_time, $end_time);


$usersStmt->execute();
$usersResult = $usersStmt->get_result();
while ($row = $usersResult->fetch_assoc()) {
    $todayUsers[] = $row['uid'];
}
$usersStmt->close();

if (empty($todayUsers)) {
    echo json_encode(['code' => 0, 'msg' => '查询成功', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// 构建用户ID占位符
$placeholders = implode(',', array_fill(0, count($todayUsers), '?'));
$types = str_repeat('i', count($todayUsers));

try {
    // 查询汇总信息 - 修改：加上extra_value赠送金额
    $summarySql = "SELECT
        uid,
        SUM(CASE WHEN status='支付成功' THEN IFNULL(payer_total,0) + IFNULL(extra_value,0) ELSE 0 END) AS paid_total,
        SUM(CASE WHEN status='支付成功' THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN status<>'支付成功' THEN IFNULL(payer_total,0) + IFNULL(extra_value,0) ELSE 0 END) AS pending_total,
        SUM(CASE WHEN status<>'支付成功' THEN 1 ELSE 0 END) AS pending_count
    FROM RechargeOrders
    WHERE uid IN ({$placeholders})
    GROUP BY uid";

    $summaryStmt = $database->prepare($summarySql);
    $summaryStmt->bind_param($types, ...$todayUsers);
    $summaryStmt->execute();
    $summaryResult = $summaryStmt->get_result();

    // 查询消费金额（已完成、等待驾驶、正在驾驶）
    $consumptionSql = "SELECT 
        uid,
        SUM(payment_amount) as total_consumption
    FROM orders 
    WHERE status IN ('已完成', '等待驾驶', '正在驾驶')
      AND pays_type != '能量'
      AND pays_type IS NOT NULL
      AND pays_type != ''
      AND uid IN ({$placeholders})
    GROUP BY uid";
    
    $consumptionStmt = $database->prepare($consumptionSql);
    $consumptionStmt->bind_param($types, ...$todayUsers);
    $consumptionStmt->execute();
    $consumptionResult = $consumptionStmt->get_result();
    
    $userConsumptions = [];
    while ($row = $consumptionResult->fetch_assoc()) {
        $userConsumptions[$row['uid']] = floatval($row['total_consumption'] ?? 0);
    }
    $consumptionStmt->close();
    
    // 查询礼物消费金额：gift_orders 金币 / 10
    // 注意：这里不要乘 0.6，0.6 是场地收益，不是用户消费核对
    $giftConsumptionSql = "SELECT 
        uid,
        SUM(payment_amount) / 10 AS gift_consumption
    FROM gift_orders
    WHERE status = '已完成'
      AND pays_type = '金币'
      AND uid IN ({$placeholders})
    GROUP BY uid";
    
    $giftConsumptionStmt = $database->prepare($giftConsumptionSql);
    $giftConsumptionStmt->bind_param($types, ...$todayUsers);
    $giftConsumptionStmt->execute();
    $giftConsumptionResult = $giftConsumptionStmt->get_result();
    
    $userGiftConsumptions = [];
    while ($row = $giftConsumptionResult->fetch_assoc()) {
        $userGiftConsumptions[$row['uid']] = floatval($row['gift_consumption'] ?? 0);
    }
    $giftConsumptionStmt->close();
    
    
    
    // 查询等待实消金额（来自Reservations表）
    $reservationsSql = "SELECT 
        user_id as uid,
        SUM(pay_money) as waiting_consumption
    FROM Reservations 
    WHERE order_status = '等待驾驶'
      AND pay_type != '能量'
      AND pay_type IS NOT NULL
      AND pay_type != ''
      AND user_id IN ({$placeholders})
    GROUP BY user_id";
    
    $reservationsStmt = $database->prepare($reservationsSql);
    $reservationsStmt->bind_param($types, ...$todayUsers);
    $reservationsStmt->execute();
    $reservationsResult = $reservationsStmt->get_result();
    
    $userWaitingConsumptions = [];
    while ($row = $reservationsResult->fetch_assoc()) {
        $userWaitingConsumptions[$row['uid']] = floatval($row['waiting_consumption'] ?? 0);
    }
    $reservationsStmt->close();

    // 查询用户余额
    // $walletSql = "SELECT uid, wallet FROM users WHERE uid IN ({$placeholders})";
    $walletSql = "SELECT uid, wallet, gold_balance FROM users WHERE uid IN ({$placeholders})";
    $walletStmt = $database->prepare($walletSql);
    $walletStmt->bind_param($types, ...$todayUsers);
    $walletStmt->execute();
    $walletResult = $walletStmt->get_result();
    
    $userWallets = [];
    $userGoldBalanceAmounts = [];
    
    while ($row = $walletResult->fetch_assoc()) {
        $uidKey = $row['uid'];
    
        // 余额钱包
        $userWallets[$uidKey] = floatval($row['wallet'] ?? 0);
    
        // 金币余额折算成人民币金额：金币 / 10
        $userGoldBalanceAmounts[$uidKey] = floatval($row['gold_balance'] ?? 0) / 10;
    }
    
$walletStmt->close();


// 查询苹果内购金币来源：gold_balance_changes 里的 iap_order
// 只有这种才会加：Apple IAP充值金币 product_id=com.rcwulian.gold.42
// 按当前页面口径：金币 / 10 折算金额
$iapGoldSql = "SELECT 
    uid,
    SUM(IFNULL(change_amount, 0)) / 10 AS iap_gold_amount
FROM gold_balance_changes
WHERE uid IN ({$placeholders})
  AND change_type = 'recharge'
  AND biz_type = 'iap_order'
  AND remark LIKE '%Apple IAP%'
  AND remark LIKE '%product_id=com.rcwulian.gold.%'
GROUP BY uid";

$iapGoldStmt = $database->prepare($iapGoldSql);
$iapGoldStmt->bind_param($types, ...$todayUsers);
$iapGoldStmt->execute();
$iapGoldResult = $iapGoldStmt->get_result();

$userIapGoldAmounts = [];
while ($row = $iapGoldResult->fetch_assoc()) {
    $userIapGoldAmounts[$row['uid']] = floatval($row['iap_gold_amount'] ?? 0);
}
$iapGoldStmt->close();


$results = [];
while ($row = $summaryResult->fetch_assoc()) {
$uid = $row['uid'];

// RechargeOrders 支付成功金额
$rechargeOrderPaidTotal = floatval($row['paid_total'] ?? 0);

// 苹果内购金币折算金额，来自 gold_balance_changes
$iapGoldAmount = $userIapGoldAmounts[$uid] ?? 0;

// 核对用充值来源 = RechargeOrders支付成功 + 苹果内购金币折算
$paidTotal = $rechargeOrderPaidTotal + $iapGoldAmount;
        // 普通订单消费
        $orderConsumption = $userConsumptions[$uid] ?? 0;
        
        // 礼物消费，金币 / 10
        $giftConsumption = $userGiftConsumptions[$uid] ?? 0;
        
        // 总消费 = 普通订单消费 + 礼物消费
        $consumption = $orderConsumption + $giftConsumption;
        
        $waitingConsumption = $userWaitingConsumptions[$uid] ?? 0;
        
        // 钱包余额
        $wallet = $userWallets[$uid] ?? 0;
        
        // 剩余金币折算金额，金币 / 10
        $goldBalanceAmount = $userGoldBalanceAmounts[$uid] ?? 0;
        
        // 当前可核对余额 = 钱包余额 + 金币余额折算
        $totalBalanceAmount = $wallet + $goldBalanceAmount;
        
        // 新公式：充值成功 = 总消费 + 待核销 + 当前余额折算
        $balanceCheck = abs(($consumption + $waitingConsumption + $totalBalanceAmount) - $paidTotal) < 0.01;
        
        $difference = round($consumption + $waitingConsumption + $totalBalanceAmount - $paidTotal, 2);
        
        // 只添加 balance_check 为 false 的记录
        if (!$balanceCheck) {
            $results[] = [
                'uid' => $uid,
                'paid_total' => $paidTotal,
                // 单独返回明细，方便前端显示
                'recharge_order_paid_total' => $rechargeOrderPaidTotal,
                'iap_gold_amount' => $iapGoldAmount,
                // 总消费 = 普通订单消费 + 礼物消费
                'total_consumption' => $consumption,
            
                // 单独返回，方便前端看明细
                'order_consumption' => $orderConsumption,
                'gift_consumption' => $giftConsumption,
            
                'waiting_consumption' => $waitingConsumption,
            
                // 原钱包余额
                'wallet_balance' => $wallet,
            
                // 金币余额折算
                'gold_balance_amount' => $goldBalanceAmount,
            
                // 当前余额合计 = 钱包余额 + 金币余额折算
                'user_wallet' => $totalBalanceAmount,
            
                'balance_check' => $balanceCheck,
                'difference' => $difference,
            ];
        }
    }
    $summaryStmt->close();

    // 对结果进行排序：按照差异金额的绝对值排序（差异大的在前）
    usort($results, function($a, $b) {
        return abs($b['difference']) <=> abs($a['difference']);
    });
    $count = count($results);
    
    echo json_encode([
        'code' => 0,
        'msg' => '查询成功',
        'count' => $count,
        'data' => $results
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'code' => 1002,
        'msg' => '查询失败：' . $e->getMessage(),
        'data' => []
    ], JSON_UNESCAPED_UNICODE);
}

$database->close();