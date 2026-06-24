<?php
require_once '../Database.php';

// 创建数据库连接
$database = new Database();

// 获取请求的用户ID和充值金额
$uid = $_POST['uid'] ?? null;
$amount = $_POST['amount'] ?? null;

header('Content-Type: application/json'); // 确保返回 JSON 格式

if ($uid && is_numeric($amount)) { // 确保金额是有效数字
    // 更新用户钱包
   
   
    
    $userSql = "SELECT wallet FROM users WHERE uid = ?";
    $user = $database->query($userSql, [$uid]);
    $wallet = $user[0]['wallet'];
    
    $sql = "UPDATE users SET wallet = wallet + ? WHERE uid = ?";
    $params = [$amount, $uid];
    $result = $database->query($sql, $params, true);
    $newWallet = $amount+$wallet;
    
     // 记录余额变动
    $balanceChangeSql = "INSERT INTO balance_changes (user_id, change_amount, balance_before, balance_after, change_type, description, payment_channel) VALUES (?, ?, ?, ?, '充值', '官方代充', '官方01')";
    $balanceChangeParams = [$uid, $amount, $wallet, $newWallet];
    $database->query($balanceChangeSql, $balanceChangeParams, true);
    
  
    if ($result !== false) {
        echo json_encode([
            'code' => 0,             // 表示成功
            'success' => true,
            'message' => '充值成功'
        ]);
    } else {
        echo json_encode([
            'code' => 1,             // 非 0 表示失败
            'success' => false,
            'message' => '充值失败'
        ]);
    }
} else {
    echo json_encode([
        'code' => 1,
        'success' => false,
        'message' => '无效的用户ID或金额'
    ]);
}

$database->close();
