<?php
require_once '../Database.php';
require_once '../lib/venue_scope.php';
require_once '../lib/kwx_8899_policy.php';
require_once __DIR__ . '/../lib/voice_room_fee.php';
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
    $logEntry = "[$timestamp] " . implode("\n[$timestamp] ", (array)$message) . "\n";

    return file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// 文件日志写入失败时仍返回本次提现在数据库中的真实结果。
function logAccountWithdrawalAudit(array $lines) {
    try {
        if (@logMessage_frozen($lines) === false) {
            error_log('普通提现文件日志写入失败 | ' . $lines[0]);
        }
    } catch (Throwable $ignored) {
        // 某些运行环境会把文件系统警告转成异常。
    }
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

$amount = round((float)($_POST['amount'] ?? 0), 2);
if ($amount <= 0) {
    echo json_encode(['code' => 1007, 'msg' => '提现金额必须大于0', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// 按场地读取提现配置；未配置时：平台扣除20%，手续费0%。
// withdraw_ratio 字段沿用旧名，实际含义是平台技术服务费扣除比例。
$venue_level = '动态配置';
$withdraw_ratio = 0.20;
$withdrawal_fee_rate = 0.00;
$technical_service_rate = 0.20;

// 兼容先从加盟商提现页进入、尚未打开管理配置页的情况。
$database->query("CREATE TABLE IF NOT EXISTS venue_withdrawal_configs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    venue_id INT NOT NULL,
    withdraw_ratio DECIMAL(5,2) NOT NULL DEFAULT 20.00,
    withdrawal_fee_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    updated_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uk_venue_id (venue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", [], true);

$configResult = $database->query(
    "SELECT withdraw_ratio, withdrawal_fee_rate FROM venue_withdrawal_configs WHERE venue_id = ? LIMIT 1",
    [$venue_id]
);
if (!empty($configResult)) {
    $withdraw_ratio = max(0, min(1, (float)$configResult[0]['withdraw_ratio'] / 100));
    $withdrawal_fee_rate = max(0, min(1, (float)$configResult[0]['withdrawal_fee_rate'] / 100));
    $technical_service_rate = $withdraw_ratio;
}

// 资金账号
$fundsSql = "SELECT withdrawal_account, account_type, account_balance, account_name FROM venue_funds WHERE venue_id = ?";
$fundsResult = $database->query($fundsSql, [$venue_id]);
// 只允许使用本场地已绑定的账号，资金行存在但卡号为空时不能提交提现。
if (empty($fundsResult) || trim((string)($fundsResult[0]['withdrawal_account'] ?? '')) === '') {
    echo json_encode(['code' => 1002, 'msg' => '请先绑定本场地提现账号', 'data' => [], 'venue_id' => $venue_id], JSON_UNESCAPED_UNICODE); exit;
}
$account_pay     = $fundsResult[0]['account_type'];
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

// 所有余额与费用以本次事务的数据为准，先锁本场地资金行以串行处理申请与撤销。
try {
    $database->beginTransaction();

    $fundsRows = $database->query(
        'SELECT withdrawal_account, account_type, account_name, account_balance
         FROM venue_funds WHERE venue_id = ? FOR UPDATE',
        [$venue_id]
    );
    if (empty($fundsRows) || trim((string)$fundsRows[0]['withdrawal_account']) === '') {
        throw new RuntimeException('本场地提现账号不存在或尚未绑定');
    }
    $account_balance = (float)$fundsRows[0]['account_balance'];
    $account_name = $fundsRows[0]['account_name'];
    $account_pay = $fundsRows[0]['account_type'];

    $refundRecords = $database->query(
        'SELECT id, refund_amount FROM refund_records
         WHERE reservation_id = ? AND is_reduced != 1 FOR UPDATE',
        [$venue_id]
    );
    $lockRows = $database->query(
        'SELECT lock_amount FROM order_lock_records
         WHERE venue_id = ? AND status = 1 FOR UPDATE',
        [$venue_id]
    );
    $imageFeeRows = $database->query(
        'SELECT id, image_transmission_fee FROM image_transmission_fee_daily
         WHERE reservation_id = ? AND is_settlement = 0 FOR UPDATE',
        [$venue_id]
    );
    if ($refundRecords === false || $lockRows === false || $imageFeeRows === false) {
        throw new RuntimeException('查询场地退款、锁定金额或图传费用失败');
    }

    $refundIds = array_map('intval', array_column($refundRecords, 'id'));
    $totalRefundToDeduct = round(array_sum(array_column($refundRecords, 'refund_amount')), 2);
    $totalLockAmount = round(array_sum(array_column($lockRows, 'lock_amount')), 2);
    $actualImageFeeToDeduct = round(array_sum(array_column($imageFeeRows, 'image_transmission_fee')), 2);
    $unsettled_image_ids = $actualImageFeeToDeduct > 0
        ? array_map('intval', array_column($imageFeeRows, 'id'))
        : [];

    $voiceQuote = kwxVoiceRoomFees($database, [$venue_id])[$venue_id];
    $voiceFee = $voiceQuote['unsettled_fee'];
    // 图传、语音、退款在本次申请时一次扣清；冻结和待复核金额只限制可提额度。
    $otherDebit = round($totalRefundToDeduct + $actualImageFeeToDeduct + $voiceFee, 2);
    $held = round($frozen_amount + $totalLockAmount + $otherDebit, 2);
    $availableBalance = max(0.00, round($account_balance - $held, 2));
    $feeExemptBalance = kwx8899ExemptBalance($database, $venue_id);

    // 沿用旧日志的“基础可提现余额”口径，尚未扣图传费和语音房费。
    $baseForLog = max(0.00, round($account_balance - $frozen_amount - $totalRefundToDeduct - $totalLockAmount, 2));
    $balanceLog = sprintf(
        '账户余额: %.2f, 冻结金额: %.2f, 未减去的退款金额: %.2f, 锁定金额: %.2f, 基础可提现余额: %.2f',
        $account_balance, $frozen_amount, $totalRefundToDeduct, $totalLockAmount, $baseForLog
    );
    $limitLog = sprintf(
        '基础可提现: %.2f, 图传费用: %.2f, 语音房费用: %.2f, 最终可提现（参与结算上限）: %.2f, 申请金额: %.2f',
        $baseForLog, $actualImageFeeToDeduct, $voiceFee, $availableBalance, $amount
    );

    if ((int)round($amount * 100) > (int)round($availableBalance * 100)) {
        $maximumQuote = kwx8899Allocation(
            $account_balance, $feeExemptBalance, $held, $availableBalance, 0,
            $technical_service_rate, $withdrawal_fee_rate
        );
        $database->rollBack();
        logAccountWithdrawalAudit([
            "场地ID: {$venue_id}, UID: {$uid}, {$balanceLog}",
            "提现金额超过最终可提现额度 | 场地ID: {$venue_id}, UID: {$uid}, {$limitLog}"
        ]);
        echo json_encode([
            'code' => 1008,
            'msg' => '本场地可提现金额已变化，请刷新后重试',
            'data' => [
                'account_balance' => $account_balance,
                'frozen_amount' => round($frozen_amount, 2),
                'total_refund' => $totalRefundToDeduct,
                'lock_amount' => $totalLockAmount,
                'image_fee' => $actualImageFeeToDeduct,
                'voice_room_fee' => $voiceFee,
                'settlement_balance' => $availableBalance,
                'available_balance' => $maximumQuote['actual_amount']
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $settlementAmount = $amount;
    $displayWithdrawalAmount = round($amount + $actualImageFeeToDeduct + $voiceFee, 2);
    $totalDebit = round($amount + $otherDebit, 2);
    $finalQuote = kwx8899Allocation(
        $account_balance, $feeExemptBalance, $held, $amount, $otherDebit,
        $technical_service_rate, $withdrawal_fee_rate
    );
    $technical_service_fee = $finalQuote['technical_fee'];
    $withdrawal_fee = $finalQuote['withdrawal_fee'];
    $actual_amount = $finalQuote['actual_amount'];
    $feeExemptSpent = $finalQuote['exempt_spent'];

    $withdrawal_remark = sprintf(
        '提现配置快照：配置来源=%s，提现比例=%s%%，技术服务费率=%s%%，提现手续费率=%s%%，提现金额=%.2f，图传费用扣减=%.2f，语音房费用扣减=%.2f，参与提现结算金额=%.2f，退款扣减=%.2f，账户余额扣减=%.2f，技术服务费=%.2f，提现手续费=%.2f，实际到账=%.2f，图传IDs=%s，退款IDs=%s',
        $venue_level, $withdraw_ratio * 100, $technical_service_rate * 100, $withdrawal_fee_rate * 100,
        $displayWithdrawalAmount, $actualImageFeeToDeduct, $voiceFee, $settlementAmount,
        $totalRefundToDeduct, $totalDebit, $technical_service_fee, $withdrawal_fee, $actual_amount,
        implode(',', $unsettled_image_ids), implode(',', $refundIds)
    );
    $ok = $database->query(
        "INSERT INTO withdrawal_requests (
            account_name, account_type, withdrawal_amount, technical_service_fee,
            withdrawal_fee, actual_amount, application_time, payout_time, application_status,
            venue_id, uid, payout_person, auditor, payout_status, withdrawal_type, remarks
         ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NULL, 0, ?, ?, NULL, NULL, 0, 'account', ?)",
        [
            $account_name, $account_pay, $amount, $technical_service_fee, $withdrawal_fee,
            $actual_amount, $venue_id, $uid, $withdrawal_remark
        ],
        true
    );
    if ($ok !== 1) {
        throw new RuntimeException('提现申请记录插入失败');
    }
    $idRows = $database->query('SELECT LAST_INSERT_ID() AS id');
    $withdrawalRequestId = (int)($idRows[0]['id'] ?? 0);
    if ($withdrawalRequestId <= 0) {
        throw new RuntimeException('未取得提现申请ID，已停止出账');
    }
    kwxVoiceRoomRecordCharge($database, $venue_id, $withdrawalRequestId, $voiceQuote);

    $updateBalanceQuery = "UPDATE venue_funds 
                           SET account_balance = account_balance - ?
                           WHERE venue_id = ?
                             AND account_balance >= ?";

    $ok = $database->query($updateBalanceQuery, [
        $totalDebit,
        $venue_id,
        $totalDebit
    ], true);

    if ($ok !== 1) {
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

        if ($ok === false || (int)$ok !== count($unsettled_image_ids)) {
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

    }
    // 最新余额
    $newBalanceResult = $database->query("SELECT account_balance FROM venue_funds WHERE venue_id = ?", [$venue_id]);
    if (empty($newBalanceResult)) {
        throw new RuntimeException('读取扣费后的账户余额失败');
    }
    $newBalance = (float)$newBalanceResult[0]['account_balance'];

    // 资金变动台账：记录本次账户实际扣减总额，确保与 venue_funds.account_balance 一致
    $insertChangeQuery = "INSERT INTO fund_changes (
        venue_id, change_type, change_amount, balance_after_change, change_reason, operator_id, remarks
    ) VALUES (?, 'withdrawal', ?, ?, '提现申请出账', ?, ?)";
    $operator_id = $uid;
    $changeRemarks = sprintf(
        '提现申请ID=%d，提现金额=%.2f，图传费用=%.2f，语音房费用=%.2f，退款扣减=%.2f，账户实际扣减=%.2f',
        $withdrawalRequestId,
        $displayWithdrawalAmount,
        $actualImageFeeToDeduct,
        $voiceFee,
        $totalRefundToDeduct,
        $totalDebit
    );
    $ok = $database->query(
        $insertChangeQuery,
        [$venue_id, $totalDebit, $newBalance, $operator_id, $changeRemarks],
        true
    );
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

    kwx8899Ledger($database, $venue_id, -$feeExemptSpent, 'withdrawal_requests', $withdrawalRequestId);

    // 更新退款记录为已减去
    foreach ($refundRecords as $record) {
        $updateRefundSql = "
            UPDATE refund_records 
            SET is_reduced = 1 
            WHERE id = ? 
              AND is_reduced != 1
        ";

        $ok = $database->query($updateRefundSql, [$record['id']], true);

        if ($ok !== 1) {
            $database->rollBack();

            logMessage_frozen("退款记录更新失败，已回滚 | 场地ID: {$venue_id}, 退款记录ID: {$record['id']}");

            echo json_encode([
                'code' => 1012,
                'msg'  => '退款记录更新失败，请稍后重试',
                'data' => []
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

    }

    $identity = "场地ID: {$venue_id}, UID: {$uid}, 提现申请ID: {$withdrawalRequestId}";
    $feesLog = sprintf(
        '申请金额: %.2f, 技术服务费: %.2f, 提现手续费: %.2f, 实际到账: %.2f',
        $amount, $technical_service_fee, $withdrawal_fee, $actual_amount
    );
    $auditLines = [
        "{$identity}, {$balanceLog}",
        sprintf('提现配置快照 | %s, 配置来源: %s, 提现比例: %s%%, 技术服务费率: %s%%, 提现手续费率: %s%%, %s',
            $identity, $venue_level, $withdraw_ratio * 100, $technical_service_rate * 100, $withdrawal_fee_rate * 100, $feesLog),
        sprintf('提现申请记录插入成功 | %s, %s, 语音房费用扣减: %.2f, 账户实际扣减: %.2f, 扣后余额: %.2f',
            $identity, $feesLog, $voiceFee, $totalDebit, $newBalance),
        "最终可提现校验 | {$identity}, {$limitLog}"
    ];
    if ($unsettled_image_ids) {
        $auditLines[] = "图传费用扣减完成 | {$identity}, 扣减金额: {$actualImageFeeToDeduct}, IDs: " . implode(',', $unsettled_image_ids);
    }
    foreach ($refundIds as $refundId) {
        $auditLines[] = "已更新退款记录ID: {$refundId} 为已减去状态。 | {$identity}";
    }
    $database->commit();
    logAccountWithdrawalAudit($auditLines);
    echo json_encode([
        'code' => 0,
        'msg'  => '提现申请成功',
        'data' => [
            'venue_id'           => $venue_id,
            'account_name'       => $account_name,
            'account_type'       => $account_pay,
            'withdrawal_amount'  => $amount,
            'voice_room_fee'      => $voiceFee,
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

} catch (Throwable $e) {
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
