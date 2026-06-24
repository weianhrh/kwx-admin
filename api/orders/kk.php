<?php
require_once '../Database.php';


$database = new Database();
$uid=$_GET['uid'] ?? '';
// 获取传入的参数

$db = new Database();



// 初始化响应数组
$response = [
    'code' => 400,
    'msg' => '操作失败',
    'data' => []
];



// 新增：根据uid获取对应的device_id
$deviceQuery = "SELECT serial_number FROM vehicles WHERE driver_id = ?";
$deviceResult = $db->query($deviceQuery, [$uid]);

if (empty($deviceResult)) {
    $response['msg'] = '找不到相关的设备ID';
    echo json_encode($response);
    exit;
}

$device_id = $deviceResult[0]['serial_number'];


$bindSiteQuery = "SELECT bind_site FROM vehicles WHERE serial_number = ?";
$bindSiteResult = $db->query($bindSiteQuery, [$device_id]);

if (empty($bindSiteResult)) {
    $response['msg'] = '找不到绑定的场地ID';
    echo json_encode($response);
    exit;
}

$reservation_id = $bindSiteResult[0]['bind_site'];


// 查询预约记录
$sql = "SELECT driving_start_time,order_number,driving_duration FROM Reservations WHERE user_id = ? AND reservation_id = ? AND order_status = '正在驾驶'";

$params = [$uid, $reservation_id];
$result = $db->query($sql, $params);
if ($result && count($result) > 0) {
    $currentReservation = $result[0];
    $drivingStartTime = strtotime($currentReservation['driving_start_time']);
    $drivingDuration = $currentReservation['driving_duration']; // 假设这个是以分钟为单位
    $order_number =  $currentReservation['order_number']; 
    // 计算剩余时间
    $currentTime = time();
    $endTime = $drivingStartTime + ($drivingDuration * 60); // 转换为秒
    $remainingTime = $endTime - $currentTime;
    if ($remainingTime < 0) {
    
      $response['msg'] = '剩余时间不足';
      echo json_encode($response);
 
     
    }else{
        // 更新成功，返回成功信息
        $response['code'] = 200;
        $response['msg'] = '操作成功';
        $response['data']['order_number'] = $order_number;
        $response['data']['expireTime'] = $remainingTime;
        echo json_encode($response);
    }
    exit;

}

// 第一步：更新预约订单状态
$orderNumber = $db->query("SELECT order_number ,reservation_type, pay_money,driving_duration,pay_type FROM Reservations WHERE reservation_id = ? AND user_id = ? AND order_status = '等待驾驶'", [$reservation_id, $uid]);
if ($orderNumber) {
    $order_number = $orderNumber[0]['order_number'];
    $reservation_type =  $orderNumber[0]['reservation_type'];
    $pay_money =  $orderNumber[0]['pay_money'];
    $driving_duration =  $orderNumber[0]['driving_duration'];
    $pays_type=  $orderNumber[0]['pay_type'];
    if($reservation_type == "按次计费"){
        $pay_type = "a";
    }else{
        $pay_type = "b";
    }

    $updateResult = $db->query("UPDATE Reservations SET order_status = '正在驾驶', driving_start_time = NOW() WHERE order_number = ?", [$order_number], true);
    if ($updateResult === false) {
        $response['msg'] = '更新预约订单状态失败';
        echo json_encode($response);
        exit;
    }
    // 第二步：从 RechargeOrders 表中获取 product_name 和 payer_total

    // 第三步：在 orders 表创建记录，并更新 billing_rules 和 payment_amount
    $insertOrderResult = $db->query(
        "INSERT INTO orders (order_id, serial_number, uid, status, start_time, billing_rules, payment_amount, pay_type, pays_type, reservation_id) 
         VALUES (?, ?, ?, '正在驾驶', NOW(), ?, ?, ?, ?, ?)",
        [$order_number, $device_id, $uid, $driving_duration, $pay_money,$pay_type,$pays_type,$reservation_id],
        true
    );
    if ($insertOrderResult === false) {
        $response['msg'] = '创建订单记录失败';
        echo json_encode($response);
        exit;
    }
} else {
    $response['msg'] = '找不到有效的预约订单'.$reservation_id.'订单'.$uid.$device_id;
    echo json_encode($response);
    exit;
}




// 更新成功，返回成功信息
$response['code'] = 200;
$response['msg'] = '操作成功';
$response['data']['order_number'] = $order_number;
$response['data']['expireTime'] = $expireTime;

echo json_encode($response);
?>
