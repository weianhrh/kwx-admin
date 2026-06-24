<?php
require_once '../Database.php';

// 创建数据库连接
$database = new Database();


// 从会话中获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;

// 验证 session_token 并获取用户信息
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $database->getUserBySessionToken($session_token);

// 检查用户是否存在和权限获取
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

$role_id = $user['role_id'];
$username = $user['username'];
$venue_id = $user['venue_id'];
// 获取请求的用户ID和充值金额
$uid = $_POST['uid'] ?? null;
$amount = $_POST['amount'] ?? null;

header('Content-Type: application/json'); // 确保返回 JSON 格式

if ($uid && is_numeric($amount)) { // 确保金额是有效数字

      //判断每次能量冲得限额
    if ($amount > 6) {
        echo json_encode([
            'code' => 1,             // 非 0 表示失败
            'success' => false,
            'message' => '单笔超过限额'
        ]);
        exit;
    }



     // 获取与指定场地ID关联的最新用户余额
    $queryBalance = "SELECT energy FROM energy_records WHERE user_uid = ? AND venue_id = ? ORDER BY id DESC LIMIT 1";
    $resultBalance = $database->query($queryBalance, [$uid, $venue_id]);

    if ($resultBalance && count($resultBalance) > 0) {
        $energy = $resultBalance[0]['energy'];
       //判断每次能量冲得限额
        if ($energy > 10) {
            echo json_encode([
                'code' => 1,             // 非 0 表示失败
                'success' => false,
                'msg' => '总能量超过限额'
            ]);
            exit;
        }
    } else {
        // 不存在记录时初始化余额为0
        $energy = 0;
        // 创建新的余额记录
        $insertSql = "INSERT INTO energy_records (user_uid, venue_id, energy) VALUES (?, ?, 0)";
        $database->query($insertSql, [$uid, $venue_id], true);
    }

  
    $newWallet = $amount+$energy;
     // 更新余额记录
    $sql = "UPDATE energy_records SET energy = energy + ? WHERE user_uid = ? AND venue_id = ? ";
    $params = [$amount, $uid,  $venue_id];
    $result = $database->query($sql, $params, true);
  
    
     // 记录余额变动
    $balanceChangeSql = "INSERT INTO energy_changes (user_uid, venue_id , energy_change , balance_after_change , reason ,balance_before_change  ) VALUES (?, ?, ?, ?, ?, ? )";
    $reason = '代理充值，充值人员：'.$username;//变动原因
    $balanceChangeParams = [$uid, $venue_id, $amount, $newWallet, $reason, $energy];
    $database->query($balanceChangeSql, $balanceChangeParams, true);
    
  
    if ($result !== false) {
        echo json_encode([
            'code' => 0,             // 表示成功
            'success' => true,
            'message' => '充值成功，总能量余额：'.$newWallet
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
