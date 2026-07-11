<?php
require_once '../Database.php';
require_once '../lib/venue_scope.php';
require_once '../RedisHelper.php';   // ★ 新增
// api/pay/ApplyWithdrawal.php
// 日志记录函数
// 日志记录函数：按年月日生成日志文件
// 日志记录函数：按日期生成日志文件，自动创建 logs 目录
function logMessage_frozen($message) {
    $date = date('Y-m-d');

    $logDir = __DIR__ . '/logs';

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . "/pay_log_{$date}.txt";

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";

    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

$database = new Database();

// 会话校验
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

$venue_id = venue_scope_resolve_single_id($database, $user, venue_scope_requested_id(array_merge($_GET, $_POST)));
if ($venue_id <= 0) {
    echo json_encode(['code' => 1003, 'msg' => '未绑定或无权访问该场地', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
$uid      = (int)$user['uid'];

$withdraw_type = $_POST['withdraw_type'] ?? $_GET['withdraw_type'] ?? 'account';
$withdraw_type = ($withdraw_type === 'gift') ? 'gift' : 'account';

$amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.0;
if ($amount <= 0) {
    echo json_encode(['code' => 1007, 'msg' => '提现金额必须大于0', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// 图传费用扣减参数
/*
$unsettled_image_fee_total = isset($_POST['unsettled_image_transmission_fee'])
    ? (float)$_POST['unsettled_image_transmission_fee']
    : 0.0;

$unsettled_image_ids = [];
if (!empty($_POST['unsettled_image_ids'])) {
    $tmpIds = json_decode($_POST['unsettled_image_ids'], true);
    if (is_array($tmpIds)) {
        $unsettled_image_ids = array_values(array_filter(array_map('intval', $tmpIds)));
    }
}
*/

// 场地评级对应提现比例 / 技术服务费
// A级：提现比例80%，技术服务费20%
// C级：提现比例60%，技术服务费40%
// S/B/D 预留，暂按A级处理
$venue_level = 'A';
$withdraw_ratio = 0.80;
$technical_service_rate = 0.20;

$levelSql = "SELECT venue_level FROM venues WHERE id = ? LIMIT 1";
$levelResult = $database->query($levelSql, [$venue_id]);

if (!empty($levelResult)) {
    $venue_level = strtoupper(trim($levelResult[0]['venue_level'] ?? 'A'));

    if ($venue_level === 'C') {
        $withdraw_ratio = 0.60;
        $technical_service_rate = 0.40;
    } else {
        // A / S / B / D / 异常值，当前都按A级处理
        if (!in_array($venue_level, ['S', 'A', 'B', 'C', 'D'], true)) {
            $venue_level = 'A';
        }

        $withdraw_ratio = 0.80;
        $technical_service_rate = 0.20;
    }
}

// 资金账号
$fundsSql = "SELECT withdrawal_account, account_type, account_balance, account_name FROM venue_funds WHERE venue_id = ?";
$fundsResult = $database->query($fundsSql, [$venue_id]);
if (empty($fundsResult)) {
    echo json_encode(['code' => 1002, 'msg' => '请先绑定提现账号', 'data' => []]); exit;
}
$account_pay     = $fundsResult[0]['account_type'];
$account_balance = (float)$fundsResult[0]['account_balance'];
$account_name    = $fundsResult[0]['account_name'];

// ==========================
// 礼物余额提现
// ==========================
if ($withdraw_type === 'gift') {
    try {
        $database->beginTransaction();

        // 锁定当前场地礼物余额
        $giftRows = $database->query("
            SELECT COALESCE(gift_balance, 0) AS gift_balance
            FROM venues
            WHERE id = ?
            FOR UPDATE
        ", [$venue_id]);

        if (empty($giftRows)) {
            throw new Exception('场地不存在');
        }

        $gift_balance = (float)($giftRows[0]['gift_balance'] ?? 0);

        if ($amount > $gift_balance) {
            $database->rollBack();
            echo json_encode([
                'code' => 1008,
                'msg'  => '超过礼物可提现额度',
                'data' => [
                    'gift_balance' => $gift_balance,
                    'available_balance' => $gift_balance
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 礼物余额已经是 金币/10*60% 后的收益，这里不再扣技术服务费
        $technical_service_fee = 0.00;
        $withdrawal_fee        = 0.00;
        $actual_amount         = round($amount, 2);

        $withdrawal_remark = sprintf(
            '礼物提现记录：申请金额=%.2f，技术服务费=%.2f，提现手续费=%.2f，实际到账=%.2f，提现前礼物余额=%.2f',
            $amount,
            $technical_service_fee,
            $withdrawal_fee,
            $actual_amount,
            $gift_balance
        );

        // 写提现申请记录，复用 withdrawal_requests
        $insertQuery = "INSERT INTO withdrawal_requests (
            account_name, account_type, withdrawal_amount, technical_service_fee,
            withdrawal_fee, actual_amount, application_time, payout_time, application_status,
            venue_id, uid, payout_person, auditor, payout_status, withdrawal_type, remarks
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NULL, 0, ?, ?, NULL, NULL, 0, 'gift', ?)";

        $ok = $database->query($insertQuery, [
            $account_name,
            $account_pay,
            $amount,
            $technical_service_fee,
            $withdrawal_fee,
            $actual_amount,
            $venue_id,
            $uid,
            $withdrawal_remark
        ], true);

        if ($ok === false) {
            throw new Exception('礼物提现申请记录插入失败');
        }

        // 扣 venues.gift_balance
        $updateGiftBalanceQuery = "
            UPDATE venues
            SET gift_balance = gift_balance - ?
            WHERE id = ? AND gift_balance >= ?
        ";

        $ok = $database->query($updateGiftBalanceQuery, [
            $amount,
            $venue_id,
            $amount
        ], true);

        if ($ok === false) {
            throw new Exception('扣减礼物余额失败');
        }

        // 查询扣完后的礼物余额
        $newGiftRows = $database->query("
            SELECT COALESCE(gift_balance, 0) AS gift_balance
            FROM venues
            WHERE id = ?
            LIMIT 1
        ", [$venue_id]);

        $newGiftBalance = (float)($newGiftRows[0]['gift_balance'] ?? 0);

        $database->commit();

        logMessage_frozen(
            "礼物提现申请成功 | 场地ID: {$venue_id}, UID: {$uid}, 申请金额: {$amount}, 实际到账: {$actual_amount}, 提现前礼物余额: {$gift_balance}, 提现后礼物余额: {$newGiftBalance}"
        );

        echo json_encode([
            'code' => 0,
            'msg'  => '礼物提现申请成功',
            'data' => [
                'withdraw_type' => 'gift',
                'venue_id' => $venue_id,
                'account_name' => $account_name,
                'account_type' => $account_pay,
                'withdrawal_amount' => $amount,
                'technical_service_fee' => $technical_service_fee,
                'withdrawal_fee' => $withdrawal_fee,
                'actual_amount' => $actual_amount,
                'new_balance' => $newGiftBalance
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {
        try {
            $database->rollBack();
        } catch (Exception $ignore) {
        }

        logMessage_frozen("礼物提现失败 | 场地ID: {$venue_id}, UID: {$uid}, 错误: " . $e->getMessage());

        echo json_encode([
            'code' => 500,
            'msg' => '礼物提现失败：' . $e->getMessage(),
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ★ 读取 Redis 冻结总额
$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(1);
$frozen_amount = 0.0;
if (method_exists($redis, 'scan')) {
    $keys = $redis->scan("venue:{$venue_id}:frozen:*", 200);
} else {
    $keys = $redis->getAllKeys("venue:{$venue_id}:frozen:*");
}
foreach ($keys as $k) {
    $v = $redis->get($k);
    if ($v !== false && $v !== null) $frozen_amount += (float)$v;
}
$redis->close();

// 获取所有未减去的退款记录
$refundSql = "SELECT id, refund_amount
              FROM refund_records
              WHERE reservation_id = ? AND is_reduced != 1";
$refundRecords = $database->query($refundSql, [$venue_id]);

// 累计退款金额
$totalRefundToDeduct = 0;
foreach ($refundRecords as $record) {
    $totalRefundToDeduct += (float)$record['refund_amount'];
}

// 锁定金额
$lockSql = "SELECT COALESCE(SUM(lock_amount), 0) AS total_lock_amount
            FROM order_lock_records
            WHERE venue_id = ? AND status = 1";
$lockResult = $database->query($lockSql, [$venue_id]);
$totalLockAmount = (float)($lockResult[0]['total_lock_amount'] ?? 0);

// 计算可提现余额
$available_balance = max(0.0, $account_balance - $frozen_amount - $totalRefundToDeduct - $totalLockAmount);
logMessage_frozen("场地ID: {$venue_id}, 账户余额: {$account_balance}, 冻结金额: {$frozen_amount}, 未减去的退款金额: {$totalRefundToDeduct}, 锁定金额: {$totalLockAmount}, 基础可提现余额: {$available_balance}");
/*
// 如果账户余额等于退款金额，直接从账户余额中扣除退款金额
if ($account_balance === $totalRefundToDeduct) {
    $account_balance -= $totalRefundToDeduct; // 账户余额扣除退款金额
    $available_balance = 0.0;  // 账户余额扣除退款后为 0
    logMessage_frozen("账户余额和退款金额相等，场地ID: {$venue_id}, 账户余额: {$account_balance}, 退款金额: {$totalRefundToDeduct}");

    // 更新退款记录为已减去
    foreach ($refundRecords as $record) {
        // 更新退款记录状态为已减去
        $updateRefundSql = "UPDATE refund_records SET is_reduced = 1 WHERE id = ?";
        $database->query($updateRefundSql, [$record['id']]);
        // 记录每条退款更新日志
        logMessage_frozen("已更新退款记录ID: {$record['id']} 为已减去状态。");
    }
}
*/

// 进行提现操作
// $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.0;
// if ($amount <= 0) {
//     echo json_encode(['code' => 1007, 'msg' => '提现金额必须大于0', 'data' => []]); exit;
// }

// 校验提现金额是否大于可提现余额
if ($amount > $available_balance) {
    echo json_encode([
        'code' => 1008,
        'msg'  => '超过可提现额度（部分余额处于违规冻结期，部分已退款）',
        'data' => [
            'account_balance'   => $account_balance,
            'frozen_amount'     => $frozen_amount,
            'total_refund'      => $totalRefundToDeduct,
            'lock_amount'       => $totalLockAmount,
            'available_balance' => $available_balance
        ]
    ]);
    exit;
}

// 计算各种费用
// $technical_service_fee = round($amount * 0.20, 2);
// $withdrawal_fee        = 0.00;
// $actual_amount         = round($amount - ($technical_service_fee + $withdrawal_fee), 2);

// 计算各种费用
// 技术服务费根据场地评级计算：A级20%，C级40%
$technical_service_fee = round($amount * $technical_service_rate, 2);

// 不再收取提现手续费
$withdrawal_fee = 0.00;

// 实际到账 = 提现金额 - 技术服务费 - 提现手续费
$actual_amount = round($amount - ($technical_service_fee + $withdrawal_fee), 2);
// 本次提现评级快照，必须跟提现申请一起保存，避免后续评级变化影响历史记录
$withdrawal_remark = sprintf(
    '提现评级快照：场地评级=%s级，提现比例=%s%%，技术服务费率=%s%%，提现手续费率=0%%，申请金额=%.2f，技术服务费=%.2f，提现手续费=%.2f，实际到账=%.2f',
    $venue_level,
    $withdraw_ratio * 100,
    $technical_service_rate * 100,
    $amount,
    $technical_service_fee,
    $withdrawal_fee,
    $actual_amount
);

// 文件日志也记录一份，方便排查
logMessage_frozen(
    "提现评级快照 | 场地ID: {$venue_id}, UID: {$uid}, 评级: {$venue_level}级, " .
    "提现比例: " . ($withdraw_ratio * 100) . "%, " .
    "技术服务费率: " . ($technical_service_rate * 100) . "%, " .
    "提现手续费率: 0%, " .
    "申请金额: {$amount}, 技术服务费: {$technical_service_fee}, 提现手续费: {$withdrawal_fee}, 实际到账: {$actual_amount}, " .
    "账户余额: {$account_balance}, 基础可提现余额: {$available_balance}, 冻结金额: {$frozen_amount}, 退款扣减: {$totalRefundToDeduct}, 锁定金额: {$totalLockAmount}"
);
try {
    $database->beginTransaction();

    // ✅ 最小并发锁：事务内重新锁定资金行，避免并发提现读取旧余额
    $fundsLockRows = $database->query("
        SELECT account_balance
        FROM venue_funds
        WHERE venue_id = ?
        FOR UPDATE
    ", [$venue_id]);

    if (empty($fundsLockRows)) {
        throw new Exception('资金账号不存在');
    }

    // 覆盖事务外读取的旧余额
    $account_balance = (float)$fundsLockRows[0]['account_balance'];
    // ✅ 事务内重新锁定退款记录，避免并发重复扣同一批退款
    $refundSql = "
        SELECT id, refund_amount
        FROM refund_records
        WHERE reservation_id = ?
          AND is_reduced != 1
        FOR UPDATE
    ";
    $refundRecords = $database->query($refundSql, [$venue_id]);

    $totalRefundToDeduct = 0.0;
    foreach ($refundRecords as $record) {
        $totalRefundToDeduct += (float)$record['refund_amount'];
    }

    // ✅ 事务内重新读取并锁定待复核订单金额
    $lockSql = "
        SELECT id, lock_amount
        FROM order_lock_records
        WHERE venue_id = ?
          AND status = 1
        FOR UPDATE
    ";
    $lockRows = $database->query($lockSql, [$venue_id]);

    $totalLockAmount = 0.0;
    foreach ($lockRows as $row) {
        $totalLockAmount += (float)$row['lock_amount'];
    }
    // 重新计算基础可提现
    $available_balance = max(
        0.0,
        $account_balance - $frozen_amount - $totalRefundToDeduct - $totalLockAmount
    );

    if ($amount > $available_balance + 0.01) {
        $database->rollBack();

        echo json_encode([
            'code' => 1008,
            'msg'  => '超过可提现额度，请刷新后重试',
            'data' => [
                'account_balance'   => round($account_balance, 2),
                'frozen_amount'     => round($frozen_amount, 2),
                'total_refund'      => round($totalRefundToDeduct, 2),
                'lock_amount'       => round($totalLockAmount, 2),
                'available_balance' => round($available_balance, 2)
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

// 申请记录
$insertQuery = "INSERT INTO withdrawal_requests (
    account_name, account_type, withdrawal_amount, technical_service_fee,
    withdrawal_fee, actual_amount, application_time, payout_time, application_status,
    venue_id, uid, payout_person, auditor, payout_status, withdrawal_type, remarks
) VALUES (?, ?, ?, ?, ?, ?, NOW(), NULL, 0, ?, ?, NULL, NULL, 0, 'account', ?)";
$ok = $database->query($insertQuery, [
    $account_name, $account_pay, $amount,
    $technical_service_fee, $withdrawal_fee, $actual_amount,
    $venue_id, $uid,  $withdrawal_remark
], true);
if ($ok === false) {
    $database->rollBack();
    logMessage_frozen(
        "提现申请记录插入失败 | 场地ID: {$venue_id}, UID: {$uid}, 评级: {$venue_level}级, " .
        "提现比例: " . ($withdraw_ratio * 100) . "%, 技术服务费率: " . ($technical_service_rate * 100) . "%, " .
        "申请金额: {$amount}, 技术服务费: {$technical_service_fee}, 提现手续费: {$withdrawal_fee}, 实际到账: {$actual_amount}"
    );

    echo json_encode(['code' => 500, 'msg' => '提现申请记录插入失败', 'data' => []]);
    exit;
}
$withdrawalRequestIdRows = $database->query("SELECT LAST_INSERT_ID() AS id");
$withdrawalRequestId = (int)($withdrawalRequestIdRows[0]['id'] ?? 0);
// ✅ 就加在这里：提现申请记录插入成功后，扣余额之前
logMessage_frozen(
    "提现申请记录插入成功 | 场地ID: {$venue_id}, UID: {$uid}, 评级: {$venue_level}级, " .
    "提现比例: " . ($withdraw_ratio * 100) . "%, 技术服务费率: " . ($technical_service_rate * 100) . "%, " .
    "申请金额: {$amount}, 技术服务费: {$technical_service_fee}, 提现手续费: {$withdrawal_fee}, 实际到账: {$actual_amount}"
);


// 图传费用扣减校验
$actualImageFeeToDeduct = 0.0;

// ✅ 最小补丁：后端自己补查未结算图传费用，避免前端参数被删掉绕过
$imageFeeRows = $database->query("
    SELECT id, image_transmission_fee
    FROM image_transmission_fee_daily
    WHERE reservation_id = ?
      AND is_settlement = 0
    FOR UPDATE
", [$venue_id]);

$unsettled_image_fee_total = 0.0;
$unsettled_image_ids = [];

foreach ($imageFeeRows as $row) {
    $unsettled_image_fee_total += (float)$row['image_transmission_fee'];
    $unsettled_image_ids[] = (int)$row['id'];
}

if ($unsettled_image_fee_total > 0 && !empty($unsettled_image_ids)) {
    $placeholders = implode(',', array_fill(0, count($unsettled_image_ids), '?'));

    $checkImageFeeSql = "
        SELECT COALESCE(SUM(image_transmission_fee), 0) AS total_fee
        FROM image_transmission_fee_daily
        WHERE id IN ($placeholders)
          AND reservation_id = ?
          AND is_settlement = 0
    ";

    $checkParams = array_merge($unsettled_image_ids, [$venue_id]);
    $checkRows = $database->query($checkImageFeeSql, $checkParams);

    $dbImageFeeTotal = (float)($checkRows[0]['total_fee'] ?? 0);

    if (abs($dbImageFeeTotal - $unsettled_image_fee_total) > 0.01) {
        $database->rollBack();
        logMessage_frozen(
            "图传费用校验失败 | 场地ID: {$venue_id}, UID: {$uid}, 前端金额: {$unsettled_image_fee_total}, 数据库金额: {$dbImageFeeTotal}, IDs: " . implode(',', $unsettled_image_ids)
        );

        echo json_encode([
            'code' => 1010,
            'msg' => '图传费用校验失败，请刷新页面后重试',
            'data' => [
                'client_fee' => $unsettled_image_fee_total,
                'db_fee' => $dbImageFeeTotal
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $actualImageFeeToDeduct = $dbImageFeeTotal;

    // logMessage_frozen(
    //     "图传费用校验通过 | 场地ID: {$venue_id}, UID: {$uid}, 扣减金额: {$actualImageFeeToDeduct}, IDs: " . implode(',', $unsettled_image_ids)
    // );
    $realAvailableBalance = max(
    0.0,
    $available_balance - $actualImageFeeToDeduct
);
    logMessage_frozen(
        "最终可提现校验 | 场地ID: {$venue_id}, UID: {$uid}, " .
        "基础可提现: {$available_balance}, 图传费用: {$actualImageFeeToDeduct}, " .
        "最终可提现: {$realAvailableBalance}, 申请金额: {$amount}"
    );

    if ($amount > $realAvailableBalance + 0.01) {
        $database->rollBack();
        logMessage_frozen(
            "提现金额超过最终可提现额度 | 场地ID: {$venue_id}, UID: {$uid}, 申请金额: {$amount}, " .
            "基础可提现: {$available_balance}, 图传费用: {$actualImageFeeToDeduct}, 最终可提现: {$realAvailableBalance}"
        );
    
        echo json_encode([
            'code' => 1008,
            'msg'  => '超过可提现额度（包含未结算图传费用扣减）',
            'data' => [
                'account_balance'   => round($account_balance, 2),
                'frozen_amount'     => round($frozen_amount, 2),
                'total_refund'      => round($totalRefundToDeduct, 2),
                'lock_amount'       => round($totalLockAmount, 2),
                'image_fee'         => round($actualImageFeeToDeduct, 2),
                'available_balance' => round($realAvailableBalance, 2)
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}


// 扣余额：从账户余额中扣除提现金额和退款金额
// $updateBalanceQuery = "UPDATE venue_funds 
//                       SET account_balance = account_balance  - ? - ? 
//                       WHERE venue_id = ?";
// $ok = $database->query($updateBalanceQuery, [$amount, $totalRefundToDeduct, $venue_id], true);

// ==========================
// 展示口径快照：用于提现记录列表 / 查看详情
// 当前系统里 $amount 是扣除图传后的“参与提现结算金额”
// 展示给老板看的“提现金额” = 参与提现结算金额 + 图传费用
// ==========================
$settlementAmount = round($amount, 2); // 参与提现结算金额，例如 27.55
$displayWithdrawalAmount = round($amount + $actualImageFeeToDeduct, 2); // 展示提现金额，例如 100.00
$totalDebit = round($amount + $totalRefundToDeduct + $actualImageFeeToDeduct, 2); // 实际扣余额，例如 100.00

$withdrawal_remark = sprintf(
    '提现评级快照：场地评级=%s级，提现比例=%s%%，技术服务费率=%s%%，提现手续费率=0%%，提现金额=%.2f，图传费用扣减=%.2f，参与提现结算金额=%.2f，退款扣减=%.2f，账户余额扣减=%.2f，技术服务费=%.2f，提现手续费=%.2f，实际到账=%.2f，图传IDs=%s',
    $venue_level,
    $withdraw_ratio * 100,
    $technical_service_rate * 100,
    $displayWithdrawalAmount,
    $actualImageFeeToDeduct,
    $settlementAmount,
    $totalRefundToDeduct,
    $totalDebit,
    $technical_service_fee,
    $withdrawal_fee,
    $actual_amount,
    implode(',', $unsettled_image_ids)
);

if ($withdrawalRequestId > 0) {
    $ok = $database->query(
        "UPDATE withdrawal_requests SET remarks = ? WHERE id = ? AND venue_id = ?",
        [$withdrawal_remark, $withdrawalRequestId, $venue_id],
        true
    );

    if ($ok === false) {
        $database->rollBack();
        echo json_encode(['code' => 500, 'msg' => '更新提现记录详情失败', 'data' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
}


// $totalDebit = round($amount + $totalRefundToDeduct + $actualImageFeeToDeduct, 2);

$updateBalanceQuery = "UPDATE venue_funds 
                       SET account_balance = account_balance - ?
                       WHERE venue_id = ?
                         AND account_balance >= ?";

$ok = $database->query($updateBalanceQuery, [
    $totalDebit,
    $venue_id,
    $totalDebit
], true);

if ($ok === false || (int)$ok < 1) {
    $database->rollBack();
    logMessage_frozen(
        "更新账户余额失败 | 场地ID: {$venue_id}, UID: {$uid}, 评级: {$venue_level}级, " .
        "提现比例: " . ($withdraw_ratio * 100) . "%, 技术服务费率: " . ($technical_service_rate * 100) . "%, " .
        "申请金额: {$amount}, 技术服务费: {$technical_service_fee}, 提现手续费: {$withdrawal_fee}, 实际到账: {$actual_amount}, " .
        "原账户余额: {$account_balance}, 退款扣减: {$totalRefundToDeduct}"
    );

    echo json_encode(['code' => 500, 'msg' => '更新账户余额失败', 'data' => []]);
    exit;
}

// 图传费用记录标记为已结算
if ($actualImageFeeToDeduct > 0 && !empty($unsettled_image_ids)) {
    $placeholders = implode(',', array_fill(0, count($unsettled_image_ids), '?'));

    $updateImageFeeSql = "
        UPDATE image_transmission_fee_daily
        SET is_settlement = 1
        WHERE id IN ($placeholders)
          AND reservation_id = ?
          AND is_settlement = 0
    ";

    $updateParams = array_merge($unsettled_image_ids, [$venue_id]);
    $ok = $database->query($updateImageFeeSql, $updateParams, true);

    if ($ok === false) {
        $database->rollBack();
        logMessage_frozen(
            "图传费用记录更新失败 | 场地ID: {$venue_id}, UID: {$uid}, 扣减金额: {$actualImageFeeToDeduct}, IDs: " . implode(',', $unsettled_image_ids)
        );

        echo json_encode([
            'code' => 1011,
            'msg' => '图传费用记录更新失败',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    logMessage_frozen(
        "图传费用扣减完成 | 场地ID: {$venue_id}, UID: {$uid}, 扣减金额: {$actualImageFeeToDeduct}, IDs: " . implode(',', $unsettled_image_ids)
    );
}
// 最新余额
$newBalanceResult = $database->query("SELECT account_balance FROM venue_funds WHERE venue_id = ?", [$venue_id]);
$newBalance = (float)$newBalanceResult[0]['account_balance'];

// 资金变动台账
$insertChangeQuery = "INSERT INTO fund_changes (
    venue_id, change_type, change_amount, balance_after_change, change_reason, operator_id, remarks
) VALUES (?, 'withdrawal', ?, ?, '提现申请出账', ?, NULL)";
$operator_id = $uid;
$ok = $database->query($insertChangeQuery, [$venue_id, $amount, $newBalance, $operator_id], true);
if ($ok === false) {
    $database->rollBack();
    logMessage_frozen(
        "插入余额变动记录失败 | 场地ID: {$venue_id}, UID: {$uid}, 评级: {$venue_level}级, " .
        "提现比例: " . ($withdraw_ratio * 100) . "%, 技术服务费率: " . ($technical_service_rate * 100) . "%, " .
        "申请金额: {$amount}, 技术服务费: {$technical_service_fee}, 提现手续费: {$withdrawal_fee}, 实际到账: {$actual_amount}, " .
        "扣款后余额: {$newBalance}"
    );

    echo json_encode(['code' => 500, 'msg' => '插入余额变动记录失败', 'data' => []]);
    exit;
}

// 更新退款记录为已减去
foreach ($refundRecords as $record) {
    $updateRefundSql = "
        UPDATE refund_records 
        SET is_reduced = 1 
        WHERE id = ? 
          AND is_reduced != 1
    ";

    $ok = $database->query($updateRefundSql, [$record['id']], true);

    if ($ok === false || (int)$ok < 1) {
        $database->rollBack();

        logMessage_frozen("退款记录更新失败，已回滚 | 场地ID: {$venue_id}, 退款记录ID: {$record['id']}");

        echo json_encode([
            'code' => 1012,
            'msg'  => '退款记录更新失败，请稍后重试',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    logMessage_frozen("已更新退款记录ID: {$record['id']} 为已减去状态。");
}
$database->commit();
echo json_encode([
    'code' => 0,
    'msg'  => '提现申请成功',
    'data' => [
        'venue_id'           => $venue_id,
        'account_name'       => $account_name,
        'account_type'       => $account_pay,
        'withdrawal_amount'  => $amount,
        'technical_service_fee' => $technical_service_fee,
        'withdrawal_fee'     => $withdrawal_fee,
        'actual_amount'      => $actual_amount,
        'new_balance'        => $newBalance,
        'venue_level'        => $venue_level,
        'withdraw_ratio'     => $withdraw_ratio,
        'withdraw_ratio_text'=> ($withdraw_ratio * 100) . '%',
        'technical_service_rate' => $technical_service_rate
    ]
], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    try {
        $database->rollBack();
    } catch (Exception $ignore) {
    }

    logMessage_frozen(
        "普通提现失败 | 场地ID: {$venue_id}, UID: {$uid}, 错误: " . $e->getMessage()
    );

    echo json_encode([
        'code' => 500,
        'msg'  => '提现申请失败：' . $e->getMessage(),
        'data' => []
    ], JSON_UNESCAPED_UNICODE);
}

$database->close();
?>
