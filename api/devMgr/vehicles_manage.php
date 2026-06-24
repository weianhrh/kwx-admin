<?php
require_once '../Database.php';  

// 创建数据库连接 
$database = new Database(); 

$session_token = $_COOKIE['session_token'] ?? null; 
if (!$session_token) { 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]); 
    exit; 
} 

$user = $database->getUserBySessionToken($session_token); 
if (!$user || !$user['role_id']) { 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]); 
    exit; 
} 
// POST 请求处理批量修改
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);

    if (!isset($input['updates']) || !is_array($input['updates'])) {
        echo json_encode(['code' => 400, 'msg' => '参数格式错误', 'data' => []]);
        exit;
    }

    $updated = 0;
    foreach ($input['updates'] as $item) {
        if (!isset($item['serial_number'])) continue;

        $fields = [];
        $params = [];

        if (isset($item['uid'])) {
            $fields[] = "uid = ?";
            $params[] = $item['uid'];
        }
        if (isset($item['bind_site'])) {
            $fields[] = "bind_site = ?";
            $params[] = $item['bind_site'];
        }

        if (count($fields) === 0) continue;

        $params[] = $item['serial_number'];
        $sql = "UPDATE vehicles SET " . implode(", ", $fields) . " WHERE serial_number = ?";

        try {
            $affected = $database->query($sql, $params, true);
            if ($affected > 0) $updated++;
        } catch (Exception $e) {
            echo json_encode(['code' => 500, 'msg' => '更新失败: ' . $e->getMessage(), 'data' => []]);
            exit;
        }
    }

    echo json_encode([
        'code' => 0,
        'msg' => "成功更新 $updated 条记录",
        'data' => []
    ]);
    exit;
}

// 默认 GET 请求，返回车辆信息
try {
    $sql = "SELECT serial_number, photo_url, name, status, uid, bind_site FROM vehicles";
    $vehicles = $database->query($sql);

    echo json_encode([
        'code' => 0,
        'msg' => 'success',
        'data' => $vehicles
    ]);
} catch (Exception $e) {
    echo json_encode([
        'code' => 500,
        'msg' => '数据库查询失败: ' . $e->getMessage(),
        'data' => []
    ]);
}
