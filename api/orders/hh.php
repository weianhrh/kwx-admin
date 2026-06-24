<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../Database.php';
$database  = new Database();

/** ---------- 工具：统一 JSON 输出 ---------- */
function json_exit($code, $msg, $extra = []) {
    $out = array_merge(['code' => $code, 'msg' => $msg], $extra);
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

/** ---------- 入参与鉴权 ---------- */
$orderID = $_GET['order_id'] ?? '';

if (!$orderID) {
    json_exit(400, '缺少必要的参数：order_id');
}


$mysqli = $database->getConnection();

try {
    /* ===========================
     * 事务开始
     * =========================== */
    $mysqli->begin_transaction();

    /** ---------- 1) 锁定订单行 ---------- */
    $stmt = $database->prepare(
        "SELECT status, start_time, billing_rules,uid, payment_amount, pays_type 
         FROM orders 
         WHERE order_id = ? 
         FOR UPDATE"
    );
    $stmt->bind_param("s", $orderID);
    $stmt->execute();
    $orderRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$orderRow) {
        $mysqli->rollback();
        json_exit(400, '订单不存在');
    }

    $orderStatus = $orderRow['status'];
    $uid = $orderRow['uid'];
    
    if ($orderStatus !== '正在驾驶') {
        $mysqli->rollback();
        if ($orderStatus === '已完成') {
            json_exit(400, '订单已完成');
        }
        json_exit(400, '只有进行中的订单可以结束');
    }

    $pays_type              = $orderRow['pays_type'];
    $existingPaymentAmount  = isset($orderRow['payment_amount']) ? (float)$orderRow['payment_amount'] : 0.0;
    $existingTimeMinutes    = isset($orderRow['billing_rules'])  ? (int)$orderRow['billing_rules']    : 0;

    $ratePerMinute = 0.0;
    if ($existingTimeMinutes > 0) {
        $ratePerMinute = (float)$existingPaymentAmount / (float)$existingTimeMinutes;
    }

    /** ---------- 2) 锁定进行中的预约记录 ---------- */
    $stmt = $database->prepare(
        "SELECT reservation_id, driving_start_time, order_number, driving_duration 
         FROM Reservations 
         WHERE user_id = ? AND order_number = ? AND order_status = '正在驾驶'
         FOR UPDATE"
    );
    $stmt->bind_param("is", $uid, $orderID);
    $stmt->execute();
    $resv = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$resv) {
        // 并发下可能刚被其他请求改为已完成，再确认订单是否已被完成
        $stmt = $database->prepare("SELECT status FROM orders WHERE order_id = ? FOR UPDATE");
        $stmt->bind_param("s", $orderID);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($r && $r['status'] === '已完成') {
            $mysqli->commit();
            json_exit(200, '订单已完成');
        }
        $mysqli->rollback();
        json_exit(404, '没有找到相应的预约记录');
    }

    $venueId             = (int)$resv['reservation_id']; // 你的业务里把它当“场地ID”
    $drivingStartTimeTs  = strtotime($resv['driving_start_time']);
    $drivingDurationMin  = (int)$resv['driving_duration'];
    $nowTs               = time();
    $endTs               = $drivingStartTimeTs + $drivingDurationMin * 60;

    $remainingSec = $endTs - $nowTs;
    $usedSec      = $nowTs - $drivingStartTimeTs;
    if ($usedSec < 0) $usedSec = 0;

    // 用时>0 则至少按1分钟计费；否则为0
    $usedMinutes = ($usedSec > 0) ? max(1, (int)ceil($usedSec / 60)) : 0;

    // 实际扣费（向上取整）
    $usedTimePay = ($ratePerMinute > 0) ? (float)ceil($usedMinutes * $ratePerMinute) : 0.0;
    $refund      = $existingPaymentAmount - $usedTimePay;

    /** ---------- 3) 将预约置为已完成（仅从“正在驾驶”置换） ---------- */
    $stmt = $database->prepare(
        "UPDATE Reservations 
         SET order_status = '已完成', driving_end_time = NOW() 
         WHERE order_number = ? AND order_status = '正在驾驶'"
    );
    $stmt->bind_param("s", $orderID);
    $stmt->execute();
    $stmt->close();

    /** ---------- 4) 超时：直接完成订单（无返还） ---------- */
    if ($remainingSec < 0) {
        $stmt = $database->prepare(
            "UPDATE orders 
             SET status = '已完成',note = '后台超时结束', end_time = NOW() 
             WHERE order_id = ? AND status = '正在驾驶'"
        );
        $stmt->bind_param("s", $orderID);
        $stmt->execute();
        $stmt->close();

        $mysqli->commit();
        json_exit(200, '订单已完成');
    }

    /** ---------- 5) 未超时：按支付类型结算返还 ---------- */
    if ($pays_type === '能量') {
        // 锁定能量账户最近一条记录（若有）
        $stmt = $database->prepare(
            "SELECT id, energy 
             FROM energy_records 
             WHERE user_uid = ? AND venue_id = ?
             ORDER BY id DESC 
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->bind_param("ii", $uid, $venueId);
        $stmt->execute();
        $energyRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $lastEnergyId = $energyRow['id']     ?? null;
        $userEnergy   = isset($energyRow['energy']) ? (float)$energyRow['energy'] : 0.0;

        if ($refund > 0) {
            $newEnergy = $userEnergy + $refund;

            if ($lastEnergyId) {
                $stmt = $database->prepare("UPDATE energy_records SET energy = ? WHERE id = ?");
                $stmt->bind_param("di", $newEnergy, $lastEnergyId);
            } else {
                $stmt = $database->prepare("INSERT INTO energy_records (user_uid, venue_id, energy) VALUES (?, ?, ?)");
                $stmt->bind_param("iid", $uid, $venueId, $newEnergy);
            }
            $stmt->execute();
            $stmt->close();

            // 能量变更流水
            $stmt = $database->prepare(
                "INSERT INTO energy_changes 
                 (user_uid, venue_id, energy_change, balance_after_change, reason, balance_before_change)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $reason = '未驾驶时长返还';
            $stmt->bind_param("iiidsd", $uid, $venueId, $refund, $newEnergy, $reason, $userEnergy);
            $stmt->execute();
            $stmt->close();

            // 写回订单：完成 + 实际支付
            $stmt = $database->prepare(
                "UPDATE orders 
                 SET status = '已完成', end_time = NOW(), payment_amount = ? 
                 WHERE order_id = ? AND status = '正在驾驶'"
            );
            $stmt->bind_param("ds", $usedTimePay, $orderID);
            $stmt->execute();
            $stmt->close();

            $mysqli->commit();
            json_exit(200, '已提前结束驾驶，返还能量：' . $refund, ['refundAmount' => $refund]);
        } else {
            // 无返还：直接完成
            $stmt = $database->prepare(
                "UPDATE orders 
                 SET status = '已完成', end_time = NOW() 
                 WHERE order_id = ? AND status = '正在驾驶'"
            );
            $stmt->bind_param("s", $orderID);
            $stmt->execute();
            $stmt->close();

            $mysqli->commit();
            json_exit(200, '订单已完成');
        }
    } else {
        // 余额钱包路径
        $stmt = $database->prepare("SELECT wallet FROM users WHERE uid = ? FOR UPDATE");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $walletRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$walletRow) {
            $mysqli->rollback();
            json_exit(404, '用户不存在');
        }

        $userWallet = (float)$walletRow['wallet'];

        if ($refund > 0) {
            $newWallet = $userWallet + $refund;

            // 更新余额
            $stmt = $database->prepare("UPDATE users SET wallet = ? WHERE uid = ?");
            $stmt->bind_param("di", $newWallet, $uid);
            $stmt->execute();
            $stmt->close();

            // 余额流水
            $stmt = $database->prepare(
                "INSERT INTO balance_changes 
                 (user_id, change_amount, balance_before, balance_after, change_type, description, payment_channel)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $changeType     = '充值';
            $description    = '未驾驶时长返还';
            $paymentChannel = '余额';
            $stmt->bind_param("idddsss", $uid, $refund, $userWallet, $newWallet, $changeType, $description, $paymentChannel);
            $stmt->execute();
            $stmt->close();

            // 写回订单：完成 + 实际支付
            $stmt = $database->prepare(
                "UPDATE orders 
                 SET status = '已完成', end_time = NOW(), payment_amount = ? 
                 WHERE order_id = ? AND status = '正在驾驶'"
            );
            $stmt->bind_param("ds", $usedTimePay, $orderID);
            $stmt->execute();
            $stmt->close();

            $mysqli->commit();
            json_exit(200, '已提前结束驾驶，剩余时长返还余额：' . $refund, ['refundAmount' => $refund]);
        } else {
            // 无返还：仅完成订单
            $stmt = $database->prepare(
                "UPDATE orders 
                 SET status = '已完成', end_time = NOW() 
                 WHERE order_id = ? AND status = '正在驾驶'"
            );
            $stmt->bind_param("s", $orderID);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            $mysqli->commit();

            if ($affected > 0) {
                json_exit(200, '订单已完成');
            } else {
                json_exit(400, '订单已是已完成状态，无需重复操作');
            }
        }
    }

} catch (Throwable $e) {
    try { $mysqli->rollback(); } catch (Throwable $ignored) {}
    if (function_exists('log_message')) {
        log_message('endOrder tx error: ' . $e->getMessage(), 'ERROR', 'orders_end.log');
    }
    json_exit(500, '服务器繁忙，请稍后重试');
}
