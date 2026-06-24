<?php
require_once '../Database.php'; // 确保路径正确
require_once '../logger.php';
require_once '../logger.php';
require_once '../RedisHelper.php';
// 获取授权头




// 获取POST数据
$orderNumber = $_GET['order_number'] ?? '';

if (empty($orderNumber)) {
    echo json_encode(['code' => 400, 'msg' => '缺少必要的参数']);
    exit;
}

// 连接数据库
try {
    $db = new Database();
    $connection = $db->getConnection();
  
    // 检查订单是否存在并属于当前用户，且订单状态为"等待驾驶"
    $checkSql = "SELECT * FROM Reservations WHERE order_number = ?  AND order_status = '等待驾驶'";
    $checkParams = [$orderNumber];
    $checkResult = $db->query($checkSql, $checkParams);
    
    if (!$checkResult || count($checkResult) == 0) {
        echo json_encode(['code' => 404, 'msg' => '订单状态不允许取消']);
        exit;
    }

  // 假设查询结果为数组形式并包含至少一个结果
    $uid = $checkResult[0]['user_id']; // 从结果集中取出第一条记录的uid字段
    $reservation = $checkResult[0];
    $reservationLocation = $reservation['reservation_id'];
    $pay_type = $reservation['pay_type'];
  

    // 开始事务
    $connection->begin_transaction();

    // 更新订单状态为"取消预约"

    $updateSql = "UPDATE Reservations SET order_status = '已取消' WHERE order_number = ? AND user_id = ?";
    $updateParams = [$orderNumber, $uid];
    $updateResult = $db->query($updateSql, $updateParams, true);

    if (!$updateResult) {
        $connection->rollback();
        echo json_encode(['code' => 500, 'msg' => '更新预约状态失败']);
        exit;
    }
    $redisHelper = new RedisHelper();
    $redisHelper->connect(); // 可选参数：主机，端口，超时
    $redisHelper->selectDb(9); // 选择 Redis 的第 6 个数据库
    $redisHelper->delete($orderNumber);
    $redisHelper->close();
   
    
    //将时长返回到余额
    // 获取支付金额
    $payMoney = $reservation['pay_money'];
    
     if ($pay_type == '能量' ) {
        // code...
         $stmt = $db->prepare("SELECT energy FROM energy_records WHERE user_uid = ? AND venue_id = ? ORDER BY id DESC LIMIT 1");
         $stmt->bind_param("ii", $uid,$reservationLocation);
         $stmt->execute();
         $userEnergy = $stmt->get_result()->fetch_assoc()['energy']; //取用户所在场地的能量
         $stmt->close();
          
        $newEnergy = $userEnergy + $payMoney;
        // 格式化为 0.00 的形式
   
        $stmt = $db->prepare("UPDATE energy_records SET energy = ? WHERE user_uid = ? AND venue_id = ?");
        $stmt->bind_param("dii", $newEnergy, $uid, $reservationLocation); // "dii" 表示：第一个是双精度浮点数，后两个是整数
        $stmt->execute();
        $stmt->close();
        // 插入余额变动记录
        $stmt = $db->prepare("INSERT INTO energy_changes (user_uid, venue_id , energy_change , balance_after_change , reason ,balance_before_change  ) VALUES (?, ?, ?, ?, ?, ? )");
        $reason = '预约取消返还';//变动原因
        $stmt->bind_param("iiidsd", $uid, $reservationLocation, $payMoney, $newEnergy, $reason, $userEnergy);
        $stmt->execute();
        $stmt->close();
            $connection->commit();
         echo json_encode(['code' => 200, 'msg' => '预约已取消，剩余能量'.$newEnergy]);
         
    }else{
        // 获取用户当前余额
        $balanceSql = "SELECT wallet FROM users WHERE uid = ?";
        $balanceParams = [$uid];
        $balanceResult = $db->query($balanceSql, $balanceParams);
        
        if (!$balanceResult || count($balanceResult) == 0) {
            $connection->rollback();
            echo json_encode(['code' => 404, 'msg' => '未找到用户记录']);
            exit;
        }
        
        $currentBalance = $balanceResult[0]['wallet'];
        
        // 更新用户余额
        $newBalance = $currentBalance + $payMoney;
        $updateBalanceSql = "UPDATE users SET wallet = ? WHERE uid = ?";
        $updateBalanceParams = [$newBalance, $uid];
        $updateBalanceResult = $db->query($updateBalanceSql, $updateBalanceParams, true);
        
        if (!$updateBalanceResult) {
            $connection->rollback();
            echo json_encode(['code' => 500, 'msg' => '更新用户余额失败']);
            exit;
        }
        $reason = '预约取消返还,h订单：'.$orderNumber;//变动原因
        // 创建余额变动记录
        $balanceChangeSql = "INSERT INTO balance_changes (user_id, change_amount, balance_before, balance_after, change_type, description, payment_channel) VALUES (?, ?, ?, ?, '充值', ?, '余额')";
        $balanceChangeParams = [$uid, $payMoney, $currentBalance, $newBalance,$reason];
        $balanceChangeResult = $db->query($balanceChangeSql, $balanceChangeParams, true);
        
        if (!$balanceChangeResult) {
            $connection->rollback();
            echo json_encode(['code' => 500, 'msg' => '记录余额变动失败']);
            exit;
        }
        
        // 提交事务
        $connection->commit();
        
        echo json_encode(['code' => 200, 'msg' => '预约已取消，支付金额已返还']);
    }
    

} catch (Exception $e) {
    $connection->rollback();
    error_log("Exception: " . $e->getMessage());
    echo json_encode(['code' => 500, 'msg' => '服务器错误: ' . $e->getMessage()]);
}
?>
