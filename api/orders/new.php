<?php
require_once '../Database.php';
require_once '../logger.php';
require_once '../RedisHelper.php';
$database = new Database();

$orderID = $_GET['order_id'] ?? '';

// 获取订单信息，包括状态、开始时间、计费规则以及当前的支付金额
$orderInfoSql = "SELECT status, start_time, uid ,billing_rules, payment_amount, pays_type FROM orders WHERE order_id = ?";
$stmt = $database->prepare($orderInfoSql);
$stmt->bind_param("s", $orderID);
$stmt->execute();
$orderResult = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$orderResult) {
    echo json_encode(['code' => 400, 'msg' => '订单不存在']);
    exit;
}
$pays_type = $orderResult['pays_type']; 
$uid = $orderResult['uid']; 

if ($orderResult['status'] !== '正在驾驶') {
    if ($orderResult['status'] == '已完成') {
        echo json_encode(['code' => 400, 'msg' => '订单已完成']);
        exit;
    } else {
        echo json_encode(['code' => 400, 'msg' => '只有进行中的订单可以结束']);
        exit;
    }
}

// 从查询结果中提取当前的支付金额
$existingPaymentAmount = $orderResult['payment_amount'] ?? 0; // 如果没有金额，默认为0
//从查询结果中提取当前的支付金额
$existingtime = $orderResult['billing_rules'] ?? 0; // 可驾驶的时长
if ($existingtime > 0) {
    $ratePerMinute = ceil($existingPaymentAmount / $existingtime); // 每分钟资费，向上取整
} else {
    $ratePerMinute = 0; // 防止除以0错误
}

$sql = "SELECT driving_start_time, order_number,driving_duration FROM Reservations WHERE user_id = ? AND order_number = ? AND order_status = '正在驾驶'";
$params = [$uid, $orderID];
$result = $database->query($sql, $params);
if ($result && count($result) > 0) {
    $currentReservation = $result[0];
    $drivingStartTime = strtotime($currentReservation['driving_start_time']);
    $drivingDuration = $currentReservation['driving_duration']; // 假设这个是以分钟为单位
    $order_number =  $currentReservation['order_number']; 

    // 计算剩余时间
    $currentTime = time();
    $endTime = $drivingStartTime + ($drivingDuration * 60); // 转换为秒
    $remainingTime = $endTime - $currentTime;
    // 计算已使用的时间（秒）
    $usedTime = $currentTime - $drivingStartTime;
    if ($usedTime < 0) {
        $usedTime = 0;
    }
    $usedTimeMinutes = ceil($usedTime / 60);//不足1分钟也会按照1分钟扣费

    // ---- 事务开始（关键段）----
    $stmt = $database->prepare("START TRANSACTION");
    $stmt->execute();
    $stmt->close();

    if ($remainingTime < 0) {
        // 幂等更新 Reservations：仅当当前仍为“正在驾驶”才置“已完成”
        $stmt = $database->prepare("UPDATE Reservations SET order_status = '已完成', driving_end_time = NOW() WHERE order_number = ? AND user_id = ? AND order_status = '正在驾驶'");
        $stmt->bind_param("si",  $orderID, $uid);
        $stmt->execute();
        $affected1 = $stmt->affected_rows;
        $stmt->close();

        if ($affected1 === 0) {
            // 状态被其他并发修改，回滚并返回冲突
            $stmt = $database->prepare("ROLLBACK");
            $stmt->execute();
            $stmt->close();
            echo json_encode(['code' => 409, 'msg' => '预约已结算或状态已变化，请勿重复提交']);
            exit;
        }

        // 幂等更新 Orders：仅当仍为“正在驾驶”才置“已完成”
        $stmt = $database->prepare("UPDATE orders SET status = '已完成', end_time = NOW() WHERE order_id = ? AND status = '正在驾驶'");
        $stmt->bind_param("s",  $orderID);
        $stmt->execute();
        $affected2 = $stmt->affected_rows;
        $stmt->close();

        if ($affected2 === 0) {
            // 订单状态已被并发修改，回滚
            $stmt = $database->prepare("ROLLBACK");
            $stmt->execute();
            $stmt->close();
            echo json_encode(['code' => 409, 'msg' => '订单已结算或状态已变化，请勿重复提交']);
            exit;
        }

        // 所有关键写入成功，提交
        $stmt = $database->prepare("COMMIT");
        $stmt->execute();
        $stmt->close();

        // 非事务部分（Redis 清理）
        $checkSql = "SELECT * FROM Reservations WHERE order_number = ? AND user_id = ?";
        $checkParams = [$orderID, $uid];
        $checkResult = $database->query($checkSql, $checkParams);

        if (!$checkResult || count($checkResult) == 0) {
            echo json_encode(['code' => 404, 'msg' => '未找到对应的预约记录']);
            exit;
        }

        $redisHelper = new RedisHelper();
        $redisHelper->connect(); 
        $redisHelper->selectDb(9); // 实为 DB9
        $redisHelper->delete($orderID);
        $redisHelper->close();

        echo json_encode(['code' => 200, 'msg' => '订单已完成']);     
        exit;

    } else {
        // 未超时，先幂等结束 Reservations
        $stmt = $database->prepare("UPDATE Reservations SET order_status = '已完成', driving_end_time = NOW() WHERE order_number = ? AND user_id = ? AND order_status = '正在驾驶'");
        $stmt->bind_param("si",  $orderID, $uid);
        $stmt->execute();
        $affected1 = $stmt->affected_rows;
        $stmt->close();

        if ($affected1 === 0) {
            $stmt = $database->prepare("ROLLBACK");
            $stmt->execute();
            $stmt->close();
            echo json_encode(['code' => 409, 'msg' => '预约已结算或状态已变化，请勿重复提交']);
            exit;
        }

        // 取订单所在的场地id（保持你原逻辑不变，只做事务幂等）
        $checkSql = "SELECT * FROM Reservations WHERE order_number = ? AND user_id = ?";
        $checkParams = [$orderID, $uid];
        $checkResult = $database->query($checkSql, $checkParams);
        if (!$checkResult || count($checkResult) == 0) {
            // 这里理论上不应该发生（上一步才更新过），但仍做回滚保护
            $stmt = $database->prepare("ROLLBACK");
            $stmt->execute();
            $stmt->close();
            echo json_encode(['code' => 404, 'msg' => '未找到对应的预约记录']);
            exit;
        }
        $reservation = $checkResult[0];
        $reservationLocation = $reservation['reservation_id']; // 保持原样，不改业务

        // 你的原有计费/返还逻辑（只在最后更新 orders 时做幂等保护）
        if($pays_type == "能量"){
            $stmt = $database->prepare("SELECT energy FROM energy_records WHERE user_uid = ? AND venue_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->bind_param("ii", $uid,$reservationLocation);
            $stmt->execute();
            $userEnergy = $stmt->get_result()->fetch_assoc()['energy'];
            $stmt->close();

            $usedTimepay = ceil($usedTimeMinutes) * $ratePerMinute;
            $refundAmount = floor($existingPaymentAmount - $usedTimepay);

            if ($refundAmount > 0) {
                $newEnergy = $userEnergy + $refundAmount;

                $stmt = $database->prepare("UPDATE energy_records SET energy = ? WHERE user_uid = ? AND venue_id = ?");
                $stmt->bind_param("dii", $newEnergy, $uid, $reservationLocation);
                $stmt->execute();
                $stmt->close();

                $stmt = $database->prepare("INSERT INTO energy_changes (user_uid, venue_id , energy_change , balance_after_change , reason ,balance_before_change  ) VALUES (?, ?, ?, ?, ?, ? )");
                $reason = '未驾驶时长返还';
                $stmt->bind_param("iiidsd", $uid, $reservationLocation, $refundAmount, $newEnergy, $reason, $userEnergy);
                $stmt->execute();
                $stmt->close();

                // 幂等更新 orders（附加 status 条件）
                $stmt = $database->prepare("UPDATE orders SET status = '已完成', end_time = NOW(),payment_amount = ? WHERE order_id = ? AND status = '正在驾驶'");
                $stmt->bind_param("ds", $usedTimepay, $orderID);
                $stmt->execute();
                $affected2 = $stmt->affected_rows;
                $stmt->close();

                if ($affected2 === 0) {
                    // 并发导致订单状态变化，回滚之前写入（能量修改/流水回滚不了，只能整体回滚事务前的变更。鉴于上面已经写了能量，这里按你原需求只做状态幂等控制）
                    $stmt = $database->prepare("ROLLBACK");
                    $stmt->execute();
                    $stmt->close();
                    echo json_encode(['code' => 409, 'msg' => '订单已结算或状态已变化，请勿重复提交']);
                    exit;
                }

                // 成功，提交事务
                $stmt = $database->prepare("COMMIT");
                $stmt->execute();
                $stmt->close();

                // 非事务部分：Redis 清理
                $redisHelper = new RedisHelper();
                $redisHelper->connect(); 
                $redisHelper->selectDb(9);
                $redisHelper->delete($orderID);
                $redisHelper->close();

                $msg='已提前结束驾驶，返还能量：'.$refundAmount;
                echo json_encode(['code' => 200, 'msg' => $msg, 'refundAmount' => $refundAmount]);
                exit;

            } else {
                // 无返还，仅幂等完成 orders
                $sql = "UPDATE orders SET status = '已完成', end_time = NOW() WHERE order_id = ? AND status = '正在驾驶'";
                $stmt = $database->prepare($sql);
                $stmt->bind_param("s", $orderID);
                $stmt->execute();
                $affected2 = $stmt->affected_rows;
                $stmt->close();

                if ($affected2 === 0) {
                    $stmt = $database->prepare("ROLLBACK");
                    $stmt->execute();
                    $stmt->close();
                    echo json_encode(['code' => 409, 'msg' => '订单已结算或状态已变化，请勿重复提交']);
                    exit;
                }

                $stmt = $database->prepare("COMMIT");
                $stmt->execute();
                $stmt->close();

                $redisHelper = new RedisHelper();
                $redisHelper->connect(); 
                $redisHelper->selectDb(9);
                $redisHelper->delete($orderID);
                $redisHelper->close();

                echo json_encode(['code' => 200, 'msg' => '订单已完成']);
                exit;
            }

        } else {
            // 获取用户余额
            $stmt = $database->prepare("SELECT wallet FROM users WHERE uid = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $userWallet = $stmt->get_result()->fetch_assoc()['wallet'];
            $stmt->close();

            $usedTimepay = ceil($usedTimeMinutes) * $ratePerMinute;
            $refundAmount = floor($existingPaymentAmount - $usedTimepay);

            if ($refundAmount > 0) {
                $newWallet = $userWallet + $refundAmount;

                $stmt = $database->prepare("UPDATE users SET wallet = ? WHERE uid = ?");
                $stmt->bind_param("di", $newWallet, $uid);
                $stmt->execute();
                $stmt->close();

                $balanceBefore = $userWallet;
                $balanceAfter = $newWallet;

                $stmt = $database->prepare("INSERT INTO balance_changes (user_id, change_amount, balance_before, balance_after, change_type, description, payment_channel) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $changeType = '充值';
                $description = '未驾驶时长返还[后台自动结算]';
                $paymentChannel = '余额';
                $stmt->bind_param("idddsss", $uid, $refundAmount, $balanceBefore, $balanceAfter, $changeType, $description, $paymentChannel);
                $stmt->execute();
                $stmt->close();

                // 幂等完成 orders
                $stmt = $database->prepare("UPDATE orders SET status = '已完成', end_time = NOW(),payment_amount = ? WHERE order_id = ? AND status = '正在驾驶'");
                $stmt->bind_param("ds", $usedTimepay, $orderID);
                $stmt->execute();
                $affected2 = $stmt->affected_rows;
                $stmt->close();

                if ($affected2 === 0) {
                    $stmt = $database->prepare("ROLLBACK");
                    $stmt->execute();
                    $stmt->close();
                    echo json_encode(['code' => 409, 'msg' => '订单已结算或状态已变化，请勿重复提交']);
                    exit;
                }

                $stmt = $database->prepare("COMMIT");
                $stmt->execute();
                $stmt->close();

                $redisHelper = new RedisHelper();
                $redisHelper->connect(); 
                $redisHelper->selectDb(9);
                $redisHelper->delete($orderID);
                $redisHelper->close();

                $msg='已提前结束驾驶，剩余时长返还余额：'.$refundAmount.$pays_type;
                echo json_encode(['code' => 200, 'msg' => $msg, 'refundAmount' => $refundAmount]);
                exit;

            } else {
                // 无返还，仅幂等完成 orders
                $sql = "UPDATE orders SET status = '已完成', end_time = NOW() WHERE order_id = ? AND status = '正在驾驶'";
                $stmt = $database->prepare($sql);
                $stmt->bind_param("s", $orderID);
                $stmt->execute();
                $affected2 = $stmt->affected_rows;
                $stmt->close();

                if ($affected2 === 0) {
                    $stmt = $database->prepare("ROLLBACK");
                    $stmt->execute();
                    $stmt->close();
                    echo json_encode(['code' => 409, 'msg' => '订单已结算或状态已变化，请勿重复提交']);
                    exit;
                }

                $stmt = $database->prepare("COMMIT");
                $stmt->execute();
                $stmt->close();

                $redisHelper = new RedisHelper();
                $redisHelper->connect(); 
                $redisHelper->selectDb(9);
                $redisHelper->delete($orderID);
                $redisHelper->close();

                echo json_encode(['code' => 200, 'msg' => '订单已完成']);
                exit;
            }
        }
    }

}  

echo json_encode(['code' => 404, 'msg' => '没有找到相应的预约记录']);
exit;
?>
