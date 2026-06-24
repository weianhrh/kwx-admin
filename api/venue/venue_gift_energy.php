<?php
// 引入数据库类 
require_once '../Database.php';  

header('Content-Type: application/json; charset=utf-8');

// 创建数据库实例 
$database = new Database(); 

// 获取会话令牌 
$session_token = $_COOKIE['session_token'] ?? null; 

// 检查会话令牌是否存在 
if (!$session_token) { 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]); 
    exit; 
} 

// 根据会话令牌获取用户信息 
$user = $database->getUserBySessionToken($session_token); 

// 检查用户信息及角色 ID 是否存在 
if (!$user || !$user['role_id']) { 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]); 
    exit; 
} 

// 当前用户所属场地 ID
$venue_id = (int)($user['venue_id'] ?? 0);
if ($venue_id <= 0) {
    echo json_encode(['code' => 1002, 'msg' => '当前账号未绑定场地', 'data' => []]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // ======== 获取当前场地赠送能量数 ========
        $sql = "SELECT gift_energy FROM venues WHERE id = ?";
        $rows = $database->query($sql, [$venue_id]);
        if ($rows === false || count($rows) === 0) {
            echo json_encode(['code' => 1003, 'msg' => '场地不存在', 'data' => []]);
            exit;
        }

        $gift_energy = $rows[0]['gift_energy'];

        echo json_encode([
            'code' => 0,
            'msg'  => 'success',
            'data' => [
                'venue_id'     => $venue_id,
                'gift_energy'  => is_null($gift_energy) ? null : (int)$gift_energy
            ]
        ]);
        exit;

    } elseif ($method === 'POST') {
        // ======== 修改当前场地赠送能量数 ========

        // 兼容 JSON 和表单两种方式
        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (!array_key_exists('gift_energy', $data)) {
            echo json_encode(['code' => 1004, 'msg' => '缺少参数：gift_energy', 'data' => []]);
            exit;
        }

        $gift_energy_raw = $data['gift_energy'];

        // 允许传 null 或空字符串表示清空（设为 NULL）
        if ($gift_energy_raw === '' || is_null($gift_energy_raw)) {
            $gift_energy = null;
        } else {
            if (!is_numeric($gift_energy_raw)) {
                echo json_encode(['code' => 1005, 'msg' => 'gift_energy 必须是数字', 'data' => []]);
                exit;
            }
            $gift_energy = (int)$gift_energy_raw;

            // 这里可以根据业务做一些限制，比如不能为负
            if ($gift_energy < 0) {
                echo json_encode(['code' => 1006, 'msg' => 'gift_energy 不能为负数', 'data' => []]);
                exit;
            }
        }

        // 根据是否为 NULL 构造 SQL
        if (is_null($gift_energy)) {
            $sql = "UPDATE venues SET gift_energy = NULL WHERE id = ?";
            $params = [$venue_id];
        } else {
            $sql = "UPDATE venues SET gift_energy = ? WHERE id = ?";
            $params = [$gift_energy, $venue_id];
        }

        $affected = $database->query($sql, $params, true);
        if ($affected === false) {
            echo json_encode(['code' => 1007, 'msg' => '数据库更新失败', 'data' => []]);
            exit;
        }

        echo json_encode([
            'code' => 0,
            'msg'  => '更新成功',
            'data' => [
                'venue_id'    => $venue_id,
                'gift_energy' => $gift_energy
            ]
        ]);
        exit;

    } else {
        echo json_encode(['code' => 405, 'msg' => '不支持的请求方法', 'data' => []]);
        exit;
    }

} catch (Exception $e) {
    // 简单异常处理
    $database->logToFile('venue_gift_energy error: ' . $e->getMessage());
    echo json_encode(['code' => 500, 'msg' => '服务器内部错误', 'data' => []]);
    exit;
}
