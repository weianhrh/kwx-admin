<?php
require_once '../Database.php';
require_once '../lib/venue_scope.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function json_out($code, $msg, $data = [])
{
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function remark_amount($remarks, array $labels)
{
    $remarks = (string)$remarks;
    foreach ($labels as $label) {
        $pattern = '/' . preg_quote($label, '/') . '\s*[:：=]\s*([0-9]+(?:\.[0-9]+)?)/u';
        if (preg_match($pattern, $remarks, $match)) {
            return round((float)$match[1], 2);
        }
    }
    return 0.00;
}

function remark_ids($remarks, array $labels)
{
    $remarks = (string)$remarks;
    foreach ($labels as $label) {
        $pattern = '/' . preg_quote($label, '/') . '\s*[:：=]\s*([0-9,，\s]*)/u';
        if (!preg_match($pattern, $remarks, $match)) {
            continue;
        }

        $raw = str_replace('，', ',', $match[1]);
        $ids = [];
        foreach (preg_split('/[,\s]+/', $raw) ?: [] as $part) {
            if ($part !== '' && ctype_digit($part) && (int)$part > 0) {
                $ids[(int)$part] = true;
            }
        }
        return array_keys($ids);
    }
    return [];
}

function restore_refund_ids_for_legacy_record(Database $database, $venueId, $applicationTime, $refundAmount)
{
    if ($refundAmount <= 0 || !$applicationTime) {
        return [];
    }

    // 旧记录未保存退款ID时，只匹配提现申请事务附近被标记为已扣减的退款记录。
    $rows = $database->query(
        "SELECT id, refund_amount
         FROM refund_records
         WHERE reservation_id = ?
           AND is_reduced = 1
           AND updated_at BETWEEN DATE_SUB(?, INTERVAL 2 MINUTE) AND DATE_ADD(?, INTERVAL 10 MINUTE)
         ORDER BY id ASC
         FOR UPDATE",
        [$venueId, $applicationTime, $applicationTime]
    );

    if (!is_array($rows) || !$rows) {
        return [];
    }

    $sum = 0.0;
    $ids = [];
    foreach ($rows as $row) {
        $sum += (float)$row['refund_amount'];
        $ids[] = (int)$row['id'];
    }

    return abs(round($sum, 2) - round($refundAmount, 2)) <= 0.01 ? $ids : [];
}

$database = new Database();

try {
    $sessionToken = $_COOKIE['session_token'] ?? '';
    if ($sessionToken === '') {
        json_out(1001, '用户未登录或会话已过期');
    }

    $user = $database->getUserBySessionToken($sessionToken);
    if (!$user || empty($user['role_id'])) {
        json_out(1001, '用户未登录或无权访问');
    }

    $roleId = (int)$user['role_id'];
    $action = trim((string)($_POST['action'] ?? ''));
    $requestId = (int)($_POST['id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));

    if ($requestId <= 0 || !in_array($action, ['cancel', 'reject'], true)) {
        json_out(1002, '缺少必要参数');
    }

    if ($action === 'cancel' && $roleId !== 3) {
        json_out(1003, '仅加盟商可以取消自己的提现申请');
    }

    if ($action === 'reject' && $roleId !== 1) {
        json_out(1003, '仅平台管理员可以驳回提现申请');
    }

    if ($action === 'reject' && $reason === '') {
        json_out(1004, '请输入驳回原因');
    }

    if ($reason === '') {
        $reason = '加盟商主动取消提现申请';
    }
    if (function_exists('mb_substr')) {
        $reason = mb_substr($reason, 0, 200, 'UTF-8');
    } else {
        $reason = substr($reason, 0, 200);
    }

    $database->beginTransaction();

    $rows = $database->query(
        "SELECT id, venue_id, uid, withdrawal_amount, application_time,
                application_status, payout_status, withdrawal_type, remarks
         FROM withdrawal_requests
         WHERE id = ?
         LIMIT 1
         FOR UPDATE",
        [$requestId]
    );

    if (empty($rows)) {
        throw new RuntimeException('提现申请不存在');
    }

    $record = $rows[0];
    $venueId = (int)$record['venue_id'];
    $applicationStatus = (int)$record['application_status'];
    $payoutStatus = (int)$record['payout_status'];

    if ($action === 'cancel') {
        if (!venue_scope_can_access($database, $user, $venueId)) {
            throw new RuntimeException('无权取消该场地的提现申请');
        }
        if ($applicationStatus !== 0 || $payoutStatus !== 0) {
            throw new RuntimeException('仅待处理且未打款的申请可以取消');
        }
    } else {
        if (!in_array($applicationStatus, [0, 1], true) || $payoutStatus !== 0) {
            throw new RuntimeException('该申请已打款、已撤销或当前状态不允许驳回');
        }
    }

    $remarks = (string)($record['remarks'] ?? '');
    $withdrawalType = (($record['withdrawal_type'] ?? '') === 'gift' || strpos($remarks, '礼物提现记录') !== false)
        ? 'gift'
        : 'account';

    $returnedAmount = 0.00;
    $imageIds = [];
    $refundIds = [];

    if ($withdrawalType === 'gift') {
        $returnedAmount = round((float)$record['withdrawal_amount'], 2);
        if ($returnedAmount <= 0) {
            throw new RuntimeException('礼物提现退款金额异常');
        }

        $affected = $database->query(
            "UPDATE venues
             SET gift_balance = COALESCE(gift_balance, 0) + ?
             WHERE id = ?",
            [$returnedAmount, $venueId],
            true
        );
        if ($affected === false || (int)$affected !== 1) {
            throw new RuntimeException('退回礼物余额失败');
        }
    } else {
        $returnedAmount = remark_amount($remarks, ['账户余额扣减', '总扣款']);
        if ($returnedAmount <= 0) {
            $returnedAmount = round((float)$record['withdrawal_amount'], 2);
        }
        if ($returnedAmount <= 0) {
            throw new RuntimeException('提现退款金额异常');
        }

        $fundRows = $database->query(
            "SELECT account_balance
             FROM venue_funds
             WHERE venue_id = ?
             LIMIT 1
             FOR UPDATE",
            [$venueId]
        );
        if (empty($fundRows)) {
            throw new RuntimeException('场地资金账户不存在');
        }

        $affected = $database->query(
            "UPDATE venue_funds
             SET account_balance = account_balance + ?
             WHERE venue_id = ?",
            [$returnedAmount, $venueId],
            true
        );
        if ($affected === false || (int)$affected !== 1) {
            throw new RuntimeException('退回场地余额失败');
        }

        $imageFeeAmount = remark_amount($remarks, ['图传费用扣减', '未结算图传费用', '图传费用']);
        $imageIds = remark_ids($remarks, ['图传IDs', '图传费用IDs']);
        if ($imageFeeAmount > 0 && !$imageIds) {
            throw new RuntimeException('该旧提现记录缺少图传费用明细，无法安全自动撤销，请联系技术处理');
        }

        if ($imageIds) {
            $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
            $params = array_merge($imageIds, [$venueId]);
            $affected = $database->query(
                "UPDATE image_transmission_fee_daily
                 SET is_settlement = 0
                 WHERE id IN ($placeholders)
                   AND reservation_id = ?
                   AND is_settlement = 1",
                $params,
                true
            );
            if ($affected === false || (int)$affected !== count($imageIds)) {
                throw new RuntimeException('恢复图传费用结算状态失败，请刷新后重试');
            }
        }

        $refundAmount = remark_amount($remarks, ['退款扣减']);
        $refundIds = remark_ids($remarks, ['退款IDs', '退款记录IDs']);
        if ($refundAmount > 0 && !$refundIds) {
            $refundIds = restore_refund_ids_for_legacy_record(
                $database,
                $venueId,
                $record['application_time'] ?? '',
                $refundAmount
            );
            if (!$refundIds) {
                throw new RuntimeException('该旧提现记录包含退款扣减，但无法准确识别退款明细，请联系技术处理');
            }
        }

        if ($refundIds) {
            $placeholders = implode(',', array_fill(0, count($refundIds), '?'));
            $params = array_merge($refundIds, [$venueId]);
            $affected = $database->query(
                "UPDATE refund_records
                 SET is_reduced = 0
                 WHERE id IN ($placeholders)
                   AND reservation_id = ?
                   AND is_reduced = 1",
                $params,
                true
            );
            if ($affected === false || (int)$affected !== count($refundIds)) {
                throw new RuntimeException('恢复退款扣减状态失败，请刷新后重试');
            }
        }

        $balanceRows = $database->query(
            "SELECT account_balance FROM venue_funds WHERE venue_id = ? LIMIT 1",
            [$venueId]
        );
        $balanceAfter = round((float)($balanceRows[0]['account_balance'] ?? 0), 2);
        $operatorId = (int)($user['uid'] ?? 0);
        $changeReason = $action === 'cancel' ? '提现申请取消退款' : '提现申请驳回退款';
        $changeRemark = sprintf(
            '提现申请ID=%d，退回金额=%.2f，处理原因=%s',
            $requestId,
            $returnedAmount,
            $reason
        );

        $affected = $database->query(
            "INSERT INTO fund_changes (
                venue_id, change_type, change_amount, balance_after_change,
                change_reason, operator_id, remarks
             ) VALUES (?, 'revenue', ?, ?, ?, ?, ?)",
            [$venueId, $returnedAmount, $balanceAfter, $changeReason, $operatorId, $changeRemark],
            true
        );
        if ($affected === false || (int)$affected !== 1) {
            throw new RuntimeException('写入退款资金流水失败');
        }
    }

    $actionLabel = $action === 'cancel' ? '加盟商取消' : '平台驳回';
    $operatorName = trim((string)($user['username'] ?? $user['uid'] ?? ''));
    $handledAt = date('Y-m-d H:i:s');
    $appendRemark = sprintf(
        "\n提现撤销处理：处理类型=%s，处理原因=%s，处理人=%s，处理时间=%s，退回金额=%.2f",
        $actionLabel,
        $reason,
        $operatorName,
        $handledAt,
        $returnedAmount
    );

    $allowedStatusSql = $action === 'cancel' ? 'application_status = 0' : 'application_status IN (0, 1)';
    $affected = $database->query(
        "UPDATE withdrawal_requests
         SET application_status = 3,
             auditor = ?,
             remarks = CONCAT(COALESCE(remarks, ''), ?),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?
           AND payout_status = 0
           AND {$allowedStatusSql}",
        [$operatorName, $appendRemark, $requestId],
        true
    );

    if ($affected === false || (int)$affected !== 1) {
        throw new RuntimeException('提现申请状态已变化，请刷新后重试');
    }

    $database->commit();

    json_out(0, $action === 'cancel' ? '提现申请已取消，款项已退回' : '提现申请已驳回，款项已退回', [
        'id' => $requestId,
        'venue_id' => $venueId,
        'withdrawal_type' => $withdrawalType,
        'returned_amount' => $returnedAmount,
        'restored_image_ids' => $imageIds,
        'restored_refund_ids' => $refundIds
    ]);
} catch (Throwable $e) {
    try {
        $database->rollBack();
    } catch (Throwable $ignore) {
    }

    error_log('提现取消/驳回失败: ' . $e->getMessage());
    json_out(500, $e->getMessage());
} finally {
    if (isset($database)) {
        $database->close();
    }
}
