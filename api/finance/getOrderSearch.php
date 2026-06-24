<?php
require_once '../Database.php';
header('Content-Type: application/json');

// 创建数据库连接
$database = new Database();

// 获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

// 验证用户信息
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

try {
    // 获取所有订单
    if (isset($_GET['get_all'])) {
        $sql = "SELECT order_number, payer_total 
                FROM RechargeOrders 
                WHERE payment_channel = '微信' 
                AND status = '支付成功'
                ORDER BY created_at DESC";
               // LIMIT 1000  限制返回最近1000条记录
                
        $orders = $database->query($sql);
        
        echo json_encode([
            'code' => 0,
            'msg' => 'success',
            'data' => $orders
        ]);
        exit;
    }
    
    if (isset($_GET['search'])) {
        // 模糊搜索订单号
        $search = $_GET['search'] . '%';
        $sql = "SELECT order_number, payer_total 
                FROM RechargeOrders 
                WHERE order_number LIKE ? 
                AND payment_channel = '微信' 
                AND status = '支付成功'
                ORDER BY created_at DESC";
                /*LIMIT 10*/
                
        $orders = $database->query($sql, [$search]);
        
        if ($orders) {
            echo json_encode([
                'code' => 0,
                'msg' => 'success',
                'data' => $orders
            ]);
        } else {
            echo json_encode([
                'code' => 0,
                'msg' => 'success',
                'data' => []
            ]);
        }
    } 
    elseif (isset($_GET['order_number'])) {
        // 精确查询订单
        $sql = "SELECT order_number, payer_total 
                FROM RechargeOrders 
                WHERE order_number = ? 
                AND payment_channel = '微信' 
                AND status = '支付成功'
                LIMIT 1";
                
        $order = $database->query($sql, [$_GET['order_number']]);
        
        if ($order && !empty($order)) {
            echo json_encode([
                'code' => 0,
                'msg' => 'success',
                'data' => $order[0]
            ]);
        } else {
            echo json_encode([
                'code' => 1002,
                'msg' => '未找到订单信息',
                'data' => null
            ]);
        }
    }
} catch (Exception $e) {
    echo json_encode([
        'code' => 1003,
        'msg' => '查询失败：' . $e->getMessage(),
        'data' => null
    ]);
}
?>
