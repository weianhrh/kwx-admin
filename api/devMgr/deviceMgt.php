<?php 
require_once '../Database.php';  

// 日志记录函数 
function logMessage($message) { 
    $logFile = __DIR__ . '/operation_log.txt';  
    $timestamp = date('Y-m-d H:i:s'); 
    $logEntry = "[$timestamp] $message\n"; 
    file_put_contents($logFile, $logEntry, FILE_APPEND); 
} 

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

$role_id = $user['role_id']; 
$uid = $user['uid']; 
$inputJSON = file_get_contents('php://input'); 
$input = json_decode($inputJSON, TRUE); 

if (isset($input['ban'])) { 
    $serial_number = $input['serial_number']; 
    $ban_status = $input['id']; // 0 = 解封，1 = 封禁 

    if (!$serial_number) { 
        echo json_encode(['code' => 1, 'msg' => '缺少车辆序列号', 'data' => []]); 
        exit; 
    } 
    if (isset($input['additionalData'])) { 
        $input = array_merge($input, $input['additionalData']); 
    } 

    $database->beginTransaction(); 
    try { 
        if ($ban_status == 1) {
           if ($input['banType'] === 'device') {
                $update_sql = "UPDATE vehicles SET is_banned = 1, sharing_status = '未共享', start_status = 'false' WHERE serial_number = ?";
                logMessage("准备更新车辆 SQL: $update_sql, 参数: [" . $serial_number . "]");
                $affectedRows = $database->query($update_sql, [$serial_number], true);
                logMessage("车辆更新完成，受影响行数: $affectedRows");
            
                $device_info_sql = "SELECT image_device_serial FROM vehicles WHERE serial_number = ?";
                logMessage("查询 image_device_serial SQL: $device_info_sql, 参数: [" . $serial_number . "]");
                $device_info = $database->query($device_info_sql, [$serial_number]);
                $image_device_serial = $device_info[0]['image_device_serial'] ?? null;
            
                $hours = floatval($input['ban_duration']);
                $now = new DateTime();
                $now->add(new DateInterval("PT" . intval($hours * 60) . "M"));
                $ban_end_time = $now->format('Y-m-d H:i:s');
            
                $ban_duration_str = sprintf('%02d:%02d:%02d', floor($hours), ($hours * 60) % 60, ($hours * 3600) % 60);
                $insert_sql = "INSERT INTO device_bans (serial_number, uid, venue_id, ban_duration, ban_end_time, ban_reason, image_device_serial, image_url, status)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";
                $params = [
                    $serial_number,
                    $uid,
                    $input['venue_id'],
                    $ban_duration_str,
                    $ban_end_time,
                    $input['ban_reason'] ?? '违规封禁',
                    $image_device_serial,
                    $input['image_url'] ?? null
                ];
                logMessage("插入封禁记录 SQL: $insert_sql, 参数: " . json_encode($params, JSON_UNESCAPED_UNICODE));
                $insertResult = $database->query($insert_sql, $params, true);
                if ($insertResult === false) {
                    logMessage("封禁记录插入失败！");
                } elseif ($insertResult === 0) {
                    logMessage("封禁记录插入执行成功，但没有受影响的行（可能重复或被忽略）");
                } else {
                    logMessage("封禁记录插入成功，受影响行数: $insertResult");
                }
            }

            elseif ($input['banType'] === 'venue') {
                $hours = floatval($input['ban_duration']);
                $now = new DateTime();
                $now->add(new DateInterval("PT" . intval($hours * 60) . "M"));
                $ban_end_time = $now->format('Y-m-d H:i:s');
            
                // 插入场地封禁记录
                $insert_venue_ban_sql = "INSERT INTO venue_bans (venue_id, uid, ban_duration, ban_end_time, ban_reason, image_device_serial, image_url, status)
                                         VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
                $insert_params = [
                    $input['venue_id'],
                    $uid,
                    sprintf('%02d:%02d:%02d', floor($hours), ($hours * 60) % 60, ($hours * 3600) % 60),
                    $ban_end_time,
                    $input['ban_reason'] ?? '违规封禁',
                    $input['image_device_serial'] ?? null,
                    $input['image_url'] ?? null
                ];
            
                // 加日志，记录插入前的SQL和参数
                logMessage("即将插入venue_bans: SQL=[$insert_venue_ban_sql], PARAMS=" . json_encode($insert_params, JSON_UNESCAPED_UNICODE));
            
                try {
                    $database->query($insert_venue_ban_sql, $insert_params, true);
                    logMessage("成功插入venue_bans记录");
                } catch (Exception $e) {
                    logMessage("插入venue_bans失败: " . $e->getMessage());
                    throw $e; // 抛出让上层catch
                }
            
                // 更新场地状态
                $update_venue_sql = "UPDATE venues SET venue_status = '休息中', is_banned = 1 WHERE id = ?";
                try {
                    $database->query($update_venue_sql, [$input['venue_id']], true);
                    logMessage("成功更新venues表为休息中，venue_id=" . $input['venue_id']);
                } catch (Exception $e) {
                    logMessage("更新venues表失败: " . $e->getMessage());
                    throw $e;
                }
            }


        }
        elseif ($ban_status == 0) {
    // 新增解封逻辑
    $update_sql = "UPDATE vehicles SET is_banned = 0, sharing_status = '正在共享', start_status = 'true' WHERE serial_number = ?";
    logMessage("准备解封车辆 serial_number=" . $serial_number);
    $affectedRows = $database->query($update_sql, [$serial_number], true);
    logMessage("车辆解封 affectedRows=" . $affectedRows);

    $update_ban_sql = "UPDATE device_bans SET status = 2 WHERE serial_number = ? AND status = 1";
    $database->query($update_ban_sql, [$serial_number], true);
    logMessage("封禁记录更新完成 serial_number=" . $serial_number);
}

        $database->commit(); 
        echo json_encode(['code' => 0, 'msg' => '操作成功', 'data' => [ 
            'serial_number' => $serial_number, 
            'is_banned' => $ban_status 
        ]]); 
    } catch (Exception $e) { 
        $database->rollback(); 
        echo json_encode(['code' => 1, 'msg' => $e->getMessage(), 'data' => []]); 
    } 
    exit; 
} 

if (isset($input['update_bind'])) { 
    $serial_number = $input['serial_number']; 
    if (!$serial_number) { 
        echo json_encode(['code' => 1, 'msg' => '缺少车辆序列号', 'data' => []]); 
        exit; 
    } 

    if (isset($input['bind_site'])) { 
        $bind_site = $input['bind_site']; 
        $update_sql = "UPDATE vehicles SET bind_site = ? WHERE serial_number = ?"; 
        $database->query($update_sql, [$bind_site, $serial_number]); 
        echo json_encode(['code' => 0, 'msg' => '场地ID更新成功', 'data' => ['serial_number' => $serial_number, 'bind_site' => $bind_site]]); 
    } else if (isset($input['uid'])) { 
        $uid = $input['uid']; 
        $update_sql = "UPDATE vehicles SET uid = ? WHERE serial_number = ?"; 
        $database->query($update_sql, [$uid, $serial_number]); 
        echo json_encode(['code' => 0, 'msg' => '用户ID更新成功', 'data' => ['serial_number' => $serial_number, 'uid' => $uid]]); 
    } 
    exit; 
} 

if (isset($_GET['get_vehicles'])) {
    // 解封车辆
    $database->query("UPDATE vehicles v
        JOIN (SELECT serial_number FROM device_bans WHERE ban_end_time <= NOW() AND status = 1) AS db
        ON v.serial_number = db.serial_number
        SET v.is_banned = 0, v.sharing_status = '正在共享', v.start_status = 'true'", [], true);
    
    // 更新设备封禁记录状态
    $database->query("UPDATE device_bans SET status = 2 WHERE ban_end_time <= NOW() AND status = 1", [], true);
    
    // 解封场地：根据 venue_bans 表
    $database->query("UPDATE venues v
        JOIN (SELECT venue_id FROM venue_bans WHERE ban_end_time <= NOW() AND status = 1) AS vb
        ON v.id = vb.venue_id
        SET v.is_banned = 0, v.venue_status = '营业中'", [], true);
    
    // 更新场地封禁记录状态
    $database->query("UPDATE venue_bans SET status = 2 WHERE ban_end_time <= NOW() AND status = 1", [], true);

    $venue_id = $_GET['venue_id'] ?? null;
    $global_search = $_GET['global_search'] ?? null;
    $params = [];

    $sql = "SELECT uid, bind_site, serial_number, status, is_banned, name, share_name, sharing_status, photo_url, image_device_serial, bk_image_device_serial FROM vehicles";
    $where = [];

    if ($venue_id && is_numeric($venue_id)) {
        $where[] = "bind_site = ?";
        $params[] = intval($venue_id);
    }
    if ($global_search) {
        $where[] = "(serial_number LIKE ? OR name LIKE ? OR image_device_serial LIKE ? OR bk_image_device_serial LIKE ?)";
        for ($i = 0; $i < 4; $i++) $params[] = '%' . $global_search . '%';
    }
    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $vehicles = $database->query($sql, $params);

    echo json_encode(['code' => 0, 'msg' => '成功', 'data' => ['vehicles' => $vehicles]]);
    exit;
}

if (isset($_GET['get_venues'])) { 
    $venues = $database->query("SELECT id, venue_name FROM venues"); 
    echo json_encode(['code' => 0, 'msg' => '成功', 'data' => ['venues' => $venues]]); 
    exit; 
} 

if (isset($_GET['get_ban_info'])) {
    $serial_number = $_GET['get_ban_info'];
    $check_ban_sql = "SELECT db.ban_end_time, v.is_banned
                      FROM device_bans db
                      JOIN vehicles v ON v.serial_number = db.serial_number
                      WHERE db.serial_number = ? AND db.status = 1 AND v.is_banned = 1
                      ORDER BY db.created_at DESC LIMIT 1";
    $ban_info = $database->query($check_ban_sql, [$serial_number]);

    if (!empty($ban_info) && strtotime($ban_info[0]['ban_end_time']) <= time()) {
        $database->query("UPDATE vehicles SET is_banned = 0, sharing_status = '正在共享', start_status = 'true' WHERE serial_number = ?", [$serial_number], true);
        $database->query("UPDATE device_bans SET status = 2 WHERE serial_number = ? AND status = 1", [$serial_number], true);

        echo json_encode(['code' => 0, 'msg' => '设备已自动解封', 'data' => ['is_banned' => 0, 'auto_unbanned' => true]]);
    } else {
        echo json_encode(['code' => 0, 'msg' => '成功', 'data' => !empty($ban_info) ? $ban_info[0] : []]);
    }
    exit;
}

echo json_encode(['code' => 1002, 'msg' => '', 'data' => []]);
?>
