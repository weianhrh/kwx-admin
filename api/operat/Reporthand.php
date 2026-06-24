<?php 
// 引入数据库类 
require_once '../Database.php';  
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

// 获取用户角色 ID、场地 ID 和用户 ID 
$role_id = $user['role_id']; 
$venue_id = $user['venue_id']; 
$user_id = $user['uid']; 
$handler_uid = $user['username']; 

// 删除投诉请求处理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = intval($_POST['delete_id']);
    try {
        $stmt = $database->getConnection()->prepare("DELETE FROM Reports WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            echo json_encode(['code' => 0, 'msg' => '删除成功']);
        } else {
            echo json_encode(['code' => 1005, 'msg' => '删除失败或记录不存在']);
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log('Delete error: '. $e->getMessage());
        echo json_encode(['code' => 1006, 'msg' => '数据库删除错误']);
    }
    exit;
}

// 处理状态更新请求 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_id']) && isset($_POST['status'])) { 
    $new_status = $_POST['status']; 
    $report_id = $_POST['report_id']; 
    try { 
        $stmt = $database->getConnection()->prepare(" 
            UPDATE Reports 
            SET status = ?, handler_uid = ? 
            WHERE id = ?  
        "); 
        if (!$stmt) { 
            throw new Exception("Prepare failed: ". $database->getConnection()->error); 
        } 
        $stmt->bind_param("ssi", $new_status, $handler_uid, $report_id); 
        $stmt->execute(); 
        if ($stmt->affected_rows > 0) { 
            echo json_encode(['code' => 0, 'msg' => '状态更新成功', 'data' => []]); 
        } else { 
            echo json_encode(['code' => 1002, 'msg' => '状态更新失败', 'data' => []]); 
        } 
        $stmt->close(); 
    } catch (Exception $e) { 
        error_log('Database error: '. $e->getMessage()); 
        echo json_encode(['code' => 1003, 'msg' => '数据库错误，请稍后重试', 'data' => []]); 
    } 
    exit; 
} 

try { 
    if ($role_id == 3 || $role_id == 4) { 
        $sql = " 
            SELECT r.id, r.device_id, r.insert_time, CAST(v.name AS CHAR) AS vehicle_name, 
                   v.bind_site, r.report_type, r.status, r.notes, r.image_url, r.handler_uid 
            FROM Reports r 
            JOIN vehicles v ON r.device_id = v.serial_number  
            WHERE v.bind_site = ? AND (r.status = '未处理' OR r.status = '处理中') 
        "; 
        $bind_param = $venue_id; 
    } elseif ($role_id == 1) { 
        $sql = " 
            SELECT r.id, r.device_id, r.insert_time, CAST(v.name AS CHAR) AS vehicle_name, 
                   v.bind_site, r.report_type, r.status, r.notes, r.image_url, r.handler_uid 
            FROM Reports r 
            JOIN vehicles v ON r.device_id = v.serial_number  
            WHERE (r.status = '未处理' OR r.status = '处理中') 
        "; 
        $bind_param = null; 
    } else { 
        throw new Exception("不支持的角色 ID: ". $role_id); 
    } 

    $stmt = $database->getConnection()->prepare($sql); 
    if (!$stmt) { 
        throw new Exception("Prepare failed: ". $database->getConnection()->error); 
    } 

    if ($bind_param) { 
        $stmt->bind_param("s", $bind_param); 
    } 

    $stmt->execute(); 
    $result = $stmt->get_result(); 
    $reports = []; 
    while ($row = $result->fetch_assoc()) { 
        $row['handler_uid'] = $handler_uid; 
        $reports[] = $row; 
    } 
    $stmt->close(); 

    if (empty($reports)) { 
        echo json_encode(['code' => 1004, 'msg' => '该场地没有未处理的投诉信息', 'data' => $user_id]); 
    } else { 
        echo json_encode(['code' => 0, 'msg' => '获取未处理投诉信息成功', 'data' => $reports]); 
    } 
} catch (Exception $e) { 
    error_log('Database error: '. $e->getMessage()); 
    echo json_encode(['code' => 1003, 'msg' => '数据库错误，请稍后重试', 'data' => []]); 
} 
?>