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
        // ✅ 前置修复：订单已完成但预约表仍在“正在驾驶”，补齐预约结束时间
if ($orderResult['status'] === '已完成') {

        // 1) 取 orders.end_time（可能为空）
        $stmt = $database->prepare("SELECT end_time FROM orders WHERE order_id = ?");
        $stmt->bind_param("s", $orderID);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        $orderEndTime = $row['end_time'] ?? null;
    
        // 2) 找到仍在“正在驾驶”的预约记录
        $stmt = $database->prepare("
            SELECT id
            FROM Reservations
            WHERE user_id = ? AND order_number = ? AND order_status = '正在驾驶'
            LIMIT 1
        ");
        $stmt->bind_param("is", $uid, $orderID);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        // 3) 如果存在，则用 orders.end_time 补写 driving_end_time，并置为已完成
        if ($res) {
            if (!empty($orderEndTime)) {
                $stmt = $database->prepare("
                    UPDATE Reservations
                    SET order_status = '已完成',
                        driving_end_time = ?
                    WHERE user_id = ? AND order_number = ? AND order_status = '正在驾驶'
                ");
                $stmt->bind_param("sis", $orderEndTime, $uid, $orderID);
            } else {
                // orders.end_time 为空：兜底用 NOW()
                $stmt = $database->prepare("
                    UPDATE Reservations
                    SET order_status = '已完成',
                        driving_end_time = NOW()
                    WHERE user_id = ? AND order_number = ? AND order_status = '正在驾驶'
                ");
                $stmt->bind_param("is", $uid, $orderID);
            }
            $stmt->execute();
            $stmt->close();
        }
    
        // 4) 这里按你原逻辑：已完成直接返回
        echo json_encode(['code' => 200, 'msg' => '订单修复已完成']);
        exit;
    }

    } else {
        echo json_encode(['code' => 400, 'msg' => '只有进行中的订单可以结束']);
        exit;
    }
}

// 从查询结果中提取当前的支付金额
$existingPaymentAmount = $orderResult['payment_amount'] ?? 0; // 如果没有金额，默认为0
// 从查询结果中提取当前的支付金额
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
    $order_number = $currentReservation['order_number'];

    // 计算剩余时间
    $currentTime = time();
    $endTime = $drivingStartTime + ($drivingDuration * 60); // 转换为秒
    $remainingTime = $endTime - $currentTime;

    // 计算已使用的时间（秒）
    $usedTime = $currentTime - $drivingStartTime;

    // 如果驾驶尚未开始，则已使用时间为 0
    if ($usedTime < 0) {
        $usedTime = 0;
    }
    $usedTimeMinutes = ceil($usedTime / 60); // 不足1分钟也会按照1分钟扣费

    if ($remainingTime < 0) {

        // ✅ 修复点：如果 NOW() 已超过 $endTime，则结束时间写入本应结束的 $endTime
        // 结束订单
        $stmt = $database->prepare("UPDATE orders SET status = '已完成', end_time = FROM_UNIXTIME(?) WHERE order_id = ?");
        $stmt->bind_param("is", $endTime, $orderID);
        $stmt->execute();
        $stmt->close();

        // 结束预约记录
        $stmt = $database->prepare("UPDATE Reservations SET order_status = '已完成', driving_end_time = FROM_UNIXTIME(?) WHERE order_number = ?");
        $stmt->bind_param("is", $endTime, $orderID);
        $stmt->execute();
        $stmt->close();

        // 检查订单是否存在并属于当前用户
        $checkSql = "SELECT * FROM Reservations WHERE order_number = ? AND user_id = ?";
        $checkParams = [$orderID, $uid];
        $checkResult = $database->query($checkSql, $checkParams);

        if (!$checkResult || count($checkResult) == 0) {
            echo json_encode(['code' => 404, 'msg' => '未找到对应的预约记录']);
            exit;
        }

        $reservation = $checkResult[0];
        $reservationLocation = $reservation['reservation_id'];

        $redisHelper = new RedisHelper();
        $redisHelper->connect(); // 可选参数：主机，端口，超时
        $redisHelper->selectDb(9); // 选择 Redis 的第 6 个数据库
        $redisHelper->delete($orderID);
        $redisHelper->close();

        echo json_encode(['code' => 200, 'msg' => '订单已完成']);
        exit;
    } else {

        // 结束预约记录
        $stmt = $database->prepare("UPDATE Reservations SET order_status = '已完成', driving_end_time = NOW() WHERE order_number = ?");
        $stmt->bind_param("s", $orderID);
        $stmt->execute();
        $stmt->close();

        // 取订单所在的场地id
        $checkSql = "SELECT * FROM Reservations WHERE order_number = ? AND user_id = ?";
        $checkParams = [$orderID, $uid];
        $checkResult = $database->query($checkSql, $checkParams);
        if (!$checkResult || count($checkResult) == 0) {
            echo json_encode(['code' => 404, 'msg' => '未找到对应的预约记录']);
            exit;
        }
        $reservation = $checkResult[0];
        $reservationLocation = $reservation['reservation_id'];

        // 减少排队人数
        $redisHelper = new RedisHelper();
        $redisHelper->connect(); // 可选参数：主机，端口，超时
        $redisHelper->selectDb(9); // 选择 Redis 的第 6 个数据库
        $redisHelper->delete($orderID);
        $redisHelper->close();

        if ($pays_type == "能量") {

            $stmt = $database->prepare("SELECT energy FROM energy_records WHERE user_uid = ? AND venue_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->bind_param("ii", $uid, $reservationLocation);
            $stmt->execute();
            $userEnergy = $stmt->get_result()->fetch_assoc()['energy']; // 取用户所在场地的能量
            $stmt->close();

            // 更新订单中的实际支付金额
            // 使用 ceil 函数向上取整计算已经使用的金额
            $usedTimepay = ceil($usedTimeMinutes) * $ratePerMinute;

            // 计算返还金额
            $refundAmount = floor($existingPaymentAmount - $usedTimepay);

            if ($refundAmount > 0) {

                $newEnergy = $userEnergy + $refundAmount;

                // 更新用户余额
                $stmt = $database->prepare("UPDATE energy_records SET energy = ? WHERE user_uid = ? AND venue_id = ?");
                $stmt->bind_param("dii", $newEnergy, $uid, $reservationLocation);
                $stmt->execute();
                $stmt->close();

                // 插入余额变动记录
                $stmt = $database->prepare("INSERT INTO energy_changes (user_uid, venue_id , energy_change , balance_after_change , reason ,balance_before_change  ) VALUES (?, ?, ?, ?, ?, ? )");

                $reason = '未驾驶时长返还'; // 变动原因
                $stmt->bind_param("iiidsd", $uid, $reservationLocation, $refundAmount, $newEnergy, $reason, $userEnergy);
                $stmt->execute();
                $stmt->close();

                $stmt = $database->prepare("UPDATE orders SET status = '已完成', end_time = NOW(),payment_amount = ? WHERE order_id = ?");
                $stmt->bind_param("ds", $usedTimepay, $orderID);
                $stmt->execute();
                $stmt->close();
                $msg = '已提前结束驾驶，返还能量：' . $refundAmount;
                // 输出成功信息
                echo json_encode(['code' => 200, 'msg' => $msg, 'refundAmount' => $refundAmount]);
                exit;
            } else {
                // 结束订单并更新实际使用时间
                $sql = "UPDATE orders SET status = '已完成', end_time = NOW() WHERE order_id = ?";
                $stmt = $database->prepare($sql);
                $stmt->bind_param("s", $orderID);
                $stmt->execute();
                $stmt->close();
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

            // 计算返还金额
            $refundAmount = floor($existingPaymentAmount - $usedTimepay);

            if ($refundAmount > 0) {

                $newWallet = $userWallet + $refundAmount;

                // 更新用户余额
                $stmt = $database->prepare("UPDATE users SET wallet = ? WHERE uid = ?");
                $stmt->bind_param("di", $newWallet, $uid);
                $stmt->execute();
                $stmt->close();

                // 获取变动前的余额
                $balanceBefore = $userWallet;

                // 计算变动后的余额
                $balanceAfter = $newWallet;

                // 插入余额变动记录
                $stmt = $database->prepare("INSERT INTO balance_changes (user_id, change_amount, balance_before, balance_after, change_type, description, payment_channel) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $changeType = '充值';
                $description = '未驾驶时长返还[后台自动结算]';
                $paymentChannel = '余额';
                $stmt->bind_param("idddsss", $uid, $refundAmount, $balanceBefore, $balanceAfter, $changeType, $description, $paymentChannel);
                $stmt->execute();
                $stmt->close();

                $stmt = $database->prepare("UPDATE orders SET status = '已完成', end_time = NOW(),payment_amount = ? WHERE order_id = ?");
                $stmt->bind_param("ds", $usedTimepay, $orderID);
                $stmt->execute();
                $stmt->close();
                $msg = '已提前结束驾驶，剩余时长返还余额：' . $refundAmount . $pays_type;
                // 输出成功信息
                echo json_encode(['code' => 200, 'msg' => $msg, 'refundAmount' => $refundAmount]);
                exit;
            } else {
                // 结束订单并更新实际使用时间
                $sql = "UPDATE orders SET status = '已完成', end_time = NOW() WHERE order_id = ?";
                $stmt = $database->prepare($sql);
                $stmt->bind_param("s", $orderID);
                $stmt->execute();
                $stmt->close();
                echo json_encode(['code' => 200, 'msg' => '订单已完成']);
                exit;
            }
        }
    }
}

echo json_encode(['code' => 404, 'msg' => '没有找到相应的预约记录']);
exit;
?>