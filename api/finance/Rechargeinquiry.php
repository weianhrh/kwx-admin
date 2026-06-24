<?php
require_once '../Database.php';
// api/finance/Rechargeinquiry.php

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

// -------------------- 参数 --------------------
$order_number = trim($_GET['order_number'] ?? '');
$uid          = trim($_GET['uid'] ?? '');
$status       = trim($_GET['status'] ?? ''); // '' | paid | pending

// 兼容老板的"支付成功/待支付"
$statusMap = [
    'paid'    => '支付成功',
    'pending' => '待支付',
];

$limit = 10000;

// -------------------- 构建 WHERE --------------------
$where = [];
$whereTypes = "";
$whereParams = [];

$isSingleUser = false;
$singleUid = 0;

if ($uid !== '') {
    $where[] = "uid = ?";
    $whereTypes .= "i";
    $whereParams[] = intval($uid);
    $isSingleUser = true;
    $singleUid = intval($uid);
}

if ($order_number !== '') {
    $where[] = "order_number = ?";
    $whereTypes .= "s";
    $whereParams[] = $order_number;
}

if ($status !== '' && isset($statusMap[$status])) {
    $where[] = "status = ?";
    $whereTypes .= "s";
    $whereParams[] = $statusMap[$status];
}

$whereSql = "";
if (!empty($where)) {
    $whereSql = " WHERE " . implode(" AND ", $where);
}

// -------------------- 修改列表SQL，获取所有用户 --------------------
$listSql = "SELECT DISTINCT uid
            FROM RechargeOrders
            {$whereSql}
            ORDER BY uid";

// -------------------- 汇总 SQL --------------------
$summaryWhere = [];
$sumTypes = "";
$sumParams = [];

if ($uid !== '') {
    $summaryWhere[] = "uid = ?";
    $sumTypes .= "i";
    $sumParams[] = intval($uid);
}
if ($order_number !== '') {
    $summaryWhere[] = "order_number = ?";
    $sumTypes .= "s";
    $sumParams[] = $order_number;
}

$summaryWhereSql = "";
if (!empty($summaryWhere)) {
    $summaryWhereSql = " WHERE " . implode(" AND ", $summaryWhere);
}

$summarySql = "SELECT
  SUM(CASE WHEN status='支付成功' THEN IFNULL(payer_total,0) ELSE 0 END) AS paid_total,
  SUM(CASE WHEN status='支付成功' THEN 1 ELSE 0 END) AS paid_count,
  SUM(CASE WHEN status<>'支付成功' THEN IFNULL(payer_total,0) ELSE 0 END) AS pending_total,
  SUM(CASE WHEN status<>'支付成功' THEN 1 ELSE 0 END) AS pending_count
FROM RechargeOrders
{$summaryWhereSql}";

try {
    // 1) 汇总
    $sumStmt = $database->prepare($summarySql);
    if (!$sumStmt) {
        throw new Exception("汇总SQL prepare 失败");
    }
    if (!empty($sumParams)) {
        $sumStmt->bind_param($sumTypes, ...$sumParams);
    }
    if (!$sumStmt->execute()) {
        throw new Exception("汇总SQL execute 失败");
    }
    $sumRes = $sumStmt->get_result();
    if ($sumRes === false) {
        throw new Exception("汇总SQL get_result 失败");
    }
    $sumRow = $sumRes->fetch_assoc() ?: [];
    $sumStmt->close();

    // 2) 获取所有符合条件的用户UID列表
    $stmt = $database->prepare($listSql);
    if (!$stmt) {
        throw new Exception("UID列表SQL prepare 失败");
    }
    if (!empty($whereParams)) {
        $stmt->bind_param($whereTypes, ...$whereParams);
    }
    if (!$stmt->execute()) {
        throw new Exception("UID列表SQL execute 失败");
    }
    $result = $stmt->get_result();
    if ($result === false) {
        throw new Exception("UID列表SQL get_result 失败");
    }

    $userIds = [];
    while ($row = $result->fetch_assoc()) {
        $userIds[] = intval($row['uid']);
    }
    $stmt->close();

    // 如果是按单个 uid 查询，即使这个用户没有 RechargeOrders，也要加入统计
    // 这样 iOS 只有苹果内购金币的用户，也能查到 gold_balance_changes
    if ($isSingleUser && $singleUid > 0 && !in_array($singleUid, $userIds, true)) {
        $userIds[] = $singleUid;
    }
    
    $userIds = array_values(array_unique($userIds));


    // 3) 为每个用户查询实际消费金额
    $userConsumptions = [];
    $userGiftConsumptions = [];        // 礼物消费，gift_orders 金币 / 10
    $userWaitingConsumptions = [];     // 等待实消金额
    $userIapGoldAmounts = [];          // 苹果内购金币折算金额，gold_balance_changes 金币 / 10
    
    if (!empty($userIds)) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));

        // 查询已完成的消费金额
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
        if (!$consumptionStmt) {
            throw new Exception("消费统计SQL prepare 失败");
        }

        $inTypes = str_repeat('i', count($userIds));
        $consumptionStmt->bind_param($inTypes, ...$userIds);

        if (!$consumptionStmt->execute()) {
            throw new Exception("消费统计SQL execute 失败");
        }
        $consumptionRes = $consumptionStmt->get_result();
        if ($consumptionRes === false) {
            throw new Exception("消费统计SQL get_result 失败");
        }

        while ($row = $consumptionRes->fetch_assoc()) {
            $userConsumptions[intval($row['uid'])] = floatval($row['total_consumption'] ?? 0);
        }
        $consumptionStmt->close();
        
        
        // 查询礼物消费金额：gift_orders.payment_amount / 10
        // 注意：这里不要乘 0.6，0.6 是场地收益，不是用户充值核对
        $giftConsumptionSql = "SELECT
            uid,
            SUM(payment_amount) / 10 AS gift_consumption
        FROM gift_orders
        WHERE status = '已完成'
          AND pays_type = '金币'
          AND uid IN ({$placeholders})
        GROUP BY uid";
        
        $giftConsumptionStmt = $database->prepare($giftConsumptionSql);
        if (!$giftConsumptionStmt) {
            throw new Exception("礼物消费统计SQL prepare 失败");
        }
        
        $giftConsumptionStmt->bind_param($inTypes, ...$userIds);
        
        if (!$giftConsumptionStmt->execute()) {
            throw new Exception("礼物消费统计SQL execute 失败");
        }
        
        $giftConsumptionRes = $giftConsumptionStmt->get_result();
        if ($giftConsumptionRes === false) {
            throw new Exception("礼物消费统计SQL get_result 失败");
        }
        
        while ($row = $giftConsumptionRes->fetch_assoc()) {
            $userGiftConsumptions[intval($row['uid'])] = floatval($row['gift_consumption'] ?? 0);
        }
        
        $giftConsumptionStmt->close();
        
        
        // 新增：查询等待实消金额（来自Reservations表）
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
        if (!$reservationsStmt) {
            throw new Exception("等待实消SQL prepare 失败");
        }

        $reservationsStmt->bind_param($inTypes, ...$userIds);

        if (!$reservationsStmt->execute()) {
            throw new Exception("等待实消SQL execute 失败");
        }
        $reservationsRes = $reservationsStmt->get_result();
        if ($reservationsRes === false) {
            throw new Exception("等待实消SQL get_result 失败");
        }

        while ($row = $reservationsRes->fetch_assoc()) {
            $userWaitingConsumptions[intval($row['uid'])] = floatval($row['waiting_consumption'] ?? 0);
        }
        $reservationsStmt->close();
        // 查询真正的苹果内购金币来源
        // 注意：这里只补 Apple IAP，不补微信/支付宝金币充值，避免 RechargeOrders 重复计算
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
        if (!$iapGoldStmt) {
            throw new Exception("苹果内购金币统计SQL prepare 失败");
        }
        
        $iapGoldStmt->bind_param($inTypes, ...$userIds);
        
        if (!$iapGoldStmt->execute()) {
            throw new Exception("苹果内购金币统计SQL execute 失败");
        }
        
        $iapGoldRes = $iapGoldStmt->get_result();
        if ($iapGoldRes === false) {
            throw new Exception("苹果内购金币统计SQL get_result 失败");
        }
        
        while ($row = $iapGoldRes->fetch_assoc()) {
            $userIapGoldAmounts[intval($row['uid'])] = floatval($row['iap_gold_amount'] ?? 0);
        }
        
        $iapGoldStmt->close();
    }

    // 4) 查询单个用户的余额（仅当查询单个用户时）
    $userWallet = 0;
    $userGoldBalanceAmount = 0;
    $userTotalBalanceAmount = 0;
    $balanceCheckResult = false;
    
    if ($isSingleUser && $singleUid > 0) {
        $walletSql = "SELECT wallet, gold_balance FROM users WHERE uid = ?";
        $walletStmt = $database->prepare($walletSql);
        if (!$walletStmt) {
            throw new Exception("钱包SQL prepare 失败");
        }

        $walletStmt->bind_param("i", $singleUid);
        if (!$walletStmt->execute()) {
            throw new Exception("钱包SQL execute 失败");
        }
        $walletRes = $walletStmt->get_result();
        if ($walletRes === false) {
            throw new Exception("钱包SQL get_result 失败");
        }

        if ($walletRow = $walletRes->fetch_assoc()) {
            $userWallet = floatval($walletRow['wallet'] ?? 0);
        
            // 金币余额折算成人民币金额：金币 / 10
            $userGoldBalanceAmount = floatval($walletRow['gold_balance'] ?? 0) / 10;
        
            // 当前余额合计 = 钱包余额 + 金币余额折算
            $userTotalBalanceAmount = $userWallet + $userGoldBalanceAmount;
        }
        $walletStmt->close();

        // RechargeOrders 支付成功金额
        $rechargeOrderPaidTotal = floatval($sumRow['paid_total'] ?? 0);
        
        // 苹果内购金币折算金额
        $singleIapGoldAmount = $userIapGoldAmounts[$singleUid] ?? 0;
        
        // 核对用充值来源 = RechargeOrders支付成功 + 苹果内购金币折算
        $totalRecharge = $rechargeOrderPaidTotal + $singleIapGoldAmount;
        
        // 订单消费
        $singleOrderConsumption = $userConsumptions[$singleUid] ?? 0;
        
        // 礼物消费：金币 / 10
        $singleGiftConsumption = $userGiftConsumptions[$singleUid] ?? 0;
        
        // 总消费 = 订单消费 + 礼物消费
        $singleConsumption = $singleOrderConsumption + $singleGiftConsumption;
        
        $singleWaitingConsumption = $userWaitingConsumptions[$singleUid] ?? 0;
        
        // 允许小数点后两位的精度误差
        $balanceCheckResult = abs(($singleConsumption + $singleWaitingConsumption + $userTotalBalanceAmount) - $totalRecharge) < 0.01;
    }

    // 5) 重新查询详细列表
    $detailSql = "SELECT id, uid, order_number, product_name, shop_id, shop_type,
                         value, extra_value, payer_total, status,
                         created_at, paid_at, reservation_id, payment_channel
                  FROM RechargeOrders
                  {$whereSql}
                  ORDER BY created_at DESC
                  LIMIT {$limit}";

    $detailStmt = $database->prepare($detailSql);
    if (!$detailStmt) {
        throw new Exception("明细SQL prepare 失败");
    }
    if (!empty($whereParams)) {
        $detailStmt->bind_param($whereTypes, ...$whereParams);
    }
    if (!$detailStmt->execute()) {
        throw new Exception("明细SQL execute 失败");
    }
    $detailResult = $detailStmt->get_result();
    if ($detailResult === false) {
        throw new Exception("明细SQL get_result 失败");
    }

    $results = [];
    while ($row = $detailResult->fetch_assoc()) {
        $rowUid = intval($row['uid']);
    
        $orderConsumption = $userConsumptions[$rowUid] ?? 0;
        $giftConsumption  = $userGiftConsumptions[$rowUid] ?? 0;
    
        // 总消费 = 订单消费 + 礼物消费
        $row['total_consumption'] = $orderConsumption + $giftConsumption;
    
        // 单独返回，方便前端展示
        $row['order_consumption'] = $orderConsumption;
        $row['gift_consumption']  = $giftConsumption;
    
        $row['waiting_consumption'] = $userWaitingConsumptions[$rowUid] ?? 0;
    
        $results[] = $row;
        if (count($results) >= $limit) break;
    }
    $detailStmt->close();

    // 6) 计算总消费金额和总等待实消金额
    $totalOrderConsumptionAllUsers = array_sum($userConsumptions);
    $totalGiftConsumptionAllUsers  = array_sum($userGiftConsumptions);
    $totalConsumptionAllUsers      = $totalOrderConsumptionAllUsers + $totalGiftConsumptionAllUsers;
    
    $totalWaitingConsumptionAllUsers = array_sum($userWaitingConsumptions);
    
    $rechargeOrderPaidTotalAll = floatval($sumRow['paid_total'] ?? 0);
    $totalIapGoldAmountAllUsers = array_sum($userIapGoldAmounts);
    $totalPaidSourceAll = $rechargeOrderPaidTotalAll + $totalIapGoldAmountAllUsers;
    // 7) 构建返回结果
    $responseData = [
        'code' => 0,
        'msg' => '查询成功',
        'summary' => [
            // 核对用充值来源合计：RechargeOrders + 苹果内购金币折算
            'paid_total' => floatval($totalPaidSourceAll),
            
            // 单独返回明细
            'recharge_order_paid_total' => floatval($rechargeOrderPaidTotalAll),
            'iap_gold_amount' => floatval($totalIapGoldAmountAllUsers),
            'paid_count' => intval($sumRow['paid_count'] ?? 0),
            'pending_total' => floatval($sumRow['pending_total'] ?? 0),
            'pending_count' => intval($sumRow['pending_count'] ?? 0),
            'total_consumption' => floatval($totalConsumptionAllUsers),  // 总消费 = 订单消费 + 礼物消费
            'order_consumption' => floatval($totalOrderConsumptionAllUsers),
            'gift_consumption' => floatval($totalGiftConsumptionAllUsers),
            'total_waiting_consumption' => floatval($totalWaitingConsumptionAllUsers),
        ],
        'data' => $results
    ];

    // 8) 如果是单个用户查询，添加额外字段
    if ($isSingleUser && $singleUid > 0) {
        $responseData['summary']['wallet_balance'] = floatval($userWallet);
        $responseData['summary']['gold_balance_amount'] = floatval($userGoldBalanceAmount);
        
        // 为了兼容前端原来的 user_wallet 字段，这里返回 wallet + gold_balance/10
        $responseData['summary']['user_wallet'] = floatval($userTotalBalanceAmount);
        
        $responseData['summary']['balance_check'] = (bool)$balanceCheckResult;

        // 在summary中添加计算公式详情
        $responseData['summary']['calculation'] = [
            'total_recharge' => floatval($totalRecharge),
            'recharge_order_paid_total' => floatval($rechargeOrderPaidTotal),
            'iap_gold_amount' => floatval($singleIapGoldAmount),
        
            // 总消费 = 订单消费 + 礼物消费
            'total_consumption' => floatval($singleConsumption),
            'order_consumption' => floatval($singleOrderConsumption),
            'gift_consumption' => floatval($singleGiftConsumption),
        
            'waiting_consumption' => floatval($singleWaitingConsumption),
        
            // 原钱包余额
            'wallet_balance' => floatval($userWallet),
        
            // 金币余额折算
            'gold_balance_amount' => floatval($userGoldBalanceAmount),
        
            // 当前余额合计
            'user_wallet' => floatval($userTotalBalanceAmount),
        
            'formula' => "total_recharge = recharge_order_paid_total + iap_gold_amount = total_consumption + waiting_consumption + wallet_balance + gold_balance/10"
        ];
    }

    echo json_encode($responseData, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'code' => 1002,
        'msg' => '查询失败：' . $e->getMessage(),
        'data' => []
    ], JSON_UNESCAPED_UNICODE);
}

$database->close();