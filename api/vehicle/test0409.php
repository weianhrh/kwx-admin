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
// logMessage("Database connection created."); 
 
// 获取 session_token 
$session_token = $_COOKIE['session_token'] ?? null; 
// logMessage("Attempting to get session_token from cookie. session_token: ". ($session_token ? $session_token : 'null')); 
//  
if (!$session_token) { 
    // logMessage("User is not logged in or session has expired."); 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]); 
    exit; 
} 
 
// 验证用户信息 
$user = $database->getUserBySessionToken($session_token); 
// logMessage("Verifying user information with session_token: $session_token. User: ". json_encode($user)); 
if (!$user || !$user['role_id']) { 
    // logMessage("User is not logged in or has no access."); 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]); 
    exit; 
} 
 
$role_id = $user['role_id']; 
$uid = $user['uid']; 
$inputJSON = file_get_contents('php://input'); 
$input = json_decode($inputJSON, TRUE); 
$serial_number = $input['serial_number'] ?? null;
$venue_id = $input['venue_id'] ?? null;
$ban_status = intval($input['id']); // 0=解封，1=封禁
$ban_type = $input['banType'] ?? 'device'; // device / venue
$ban_reason = $input['ban_reason'] ?? '';
$ban_duration = floatval($input['ban_duration']);
$minutes = intval($ban_duration * 60);
$ban_end_time = (new DateTime())->add(new DateInterval("PT{$minutes}M"))->format('Y-m-d H:i:s');

$database->beginTransaction();

try {
    if ($ban_status === 1) {
        // ✅ 设备封禁
        if ($ban_type === 'device') {
            $update_vehicle = "UPDATE vehicles SET is_banned = 1, sharing_status = '未共享', start_status = 'false' WHERE serial_number = ?";
            $database->query($update_vehicle, [$serial_number], true);

            $image_device_serial = $database->query("SELECT image_device_serial FROM vehicles WHERE serial_number = ?", [$serial_number])[0]['image_device_serial'] ?? null;
            $insert_sql = "INSERT INTO device_bans (serial_number, uid, venue_id, ban_duration, ban_end_time, ban_reason, image_device_serial, image_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";
            $database->query($insert_sql, [$serial_number, $uid, $venue_id, sprintf('%02d:%02d:%02d', floor($ban_duration), floor(($ban_duration*60)%60), floor(($ban_duration*3600)%60)), $ban_end_time, $ban_reason, $image_device_serial, $input['image_url'] ?? null], true);
        }

        // ✅ 场地封禁
        if ($ban_type === 'venue') {
            $update_venue = "UPDATE venues SET venue_status = '休息中', is_banned = 1, ban_end_time = ? WHERE id = ?";
            $database->query($update_venue, [$ban_end_time, $venue_id], true);
        }

    } else {
        // ✅ 解封操作

        // 解封设备
        if ($ban_type === 'device') {
            $database->query("UPDATE vehicles SET is_banned = 0, sharing_status = '正在共享', start_status = 'true' WHERE serial_number = ?", [$serial_number], true);
            $database->query("UPDATE device_bans SET status = 2 WHERE serial_number = ? AND status = 1", [$serial_number], true);
        }

        // 解封场地
        if ($ban_type === 'venue') {
            $database->query("UPDATE venues SET venue_status = '营业中', is_banned = 0, ban_end_time = NULL WHERE id = ?", [$venue_id], true);
        }
    }

    $database->commit();
    echo json_encode(['code' => 0, 'msg' => '操作成功']);
} catch (Exception $e) {
    $database->rollback();
    echo json_encode(['code' => 1, 'msg' => $e->getMessage()]);
}
 
// 添加处理更新场地和用户ID的逻辑 
if (isset($input['update_bind'])) { 
    $serial_number = $input['serial_number']; 
    logMessage("Update bind operation detected. Serial number: $serial_number"); 
 
    if (!$serial_number) { 
        // logMessage("Missing vehicle serial number."); 
        echo json_encode(['code' => 1, 'msg' => '缺少车辆序列号', 'data' => []]); 
        exit; 
    } 
 
    // 根据传入的参数决定更新哪个字段 
    if (isset($input['bind_site'])) { 
        $bind_site = $input['bind_site']; 
        $update_sql = "UPDATE vehicles SET bind_site = ? WHERE serial_number = ?"; 
        // logMessage("Executing SQL: $update_sql with params: ". json_encode([$bind_site, $serial_number])); 
        $database->query($update_sql, [$bind_site, $serial_number]); 
        // logMessage("Site ID updated successfully. Serial number: $serial_number, Bind site: $bind_site"); 
        echo json_encode(['code' => 0, 'msg' => '场地ID更新成功', 'data' => ['serial_number' => $serial_number, 'bind_site' => $bind_site]]); 
    } else if (isset($input['uid'])) { 
        $uid = $input['uid']; 
        $update_sql = "UPDATE vehicles SET uid = ? WHERE serial_number = ?"; 
        // logMessage("Executing SQL: $update_sql with params: ". json_encode([$uid, $serial_number])); 
        $database->query($update_sql, [$uid, $serial_number]); 
        // logMessage("User ID updated successfully. Serial number: $serial_number, UID: $uid"); 
        echo json_encode(['code' => 0, 'msg' => '用户ID更新成功', 'data' => ['serial_number' => $serial_number, 'uid' => $uid]]); 
    } 
    exit; 
} 
 
// 处理场地和车辆信息请求 
// 在 Test2getVehicleList.php 的 get_vehicles 部分替换以下代码：

if (isset($_GET['get_vehicles'])) {
    $database->query("...自动解封SQL...", [], true); // 自动解封 SQL 可保留原样
    $autoUnbanSQL = "
        UPDATE vehicles v
        JOIN (
            SELECT serial_number
            FROM device_bans
            WHERE ban_end_time <= NOW() AND status = 1
        ) AS db ON v.serial_number = db.serial_number
        SET v.is_banned = 0, v.sharing_status = '正在共享', v.start_status = 'true';
    ";
    $database->query($autoUnbanSQL, [], true);
    
    $updateBanStatusSQL = "
        UPDATE device_bans
        SET status = 2
        WHERE ban_end_time <= NOW() AND status = 1
    ";
    $database->query($updateBanStatusSQL, [], true);

    $venue_id = $_GET['venue_id'] ?? null;
    $global_search = $_GET['global_search'] ?? null;
    $params = [];

    $sql = "SELECT uid, bind_site,serial_number, status, is_banned, name, share_name, sharing_status, photo_url, image_device_serial, bk_image_device_serial FROM vehicles";

    $where = [];

    if ($venue_id && is_numeric($venue_id)) {
        $where[] = "bind_site = ?";
        $params[] = intval($venue_id);
    }


    if ($global_search) {
        $where[] = "(serial_number LIKE ? OR name LIKE ? OR image_device_serial LIKE ? OR bk_image_device_serial LIKE ?)";
        for ($i = 0; $i < 4; $i++) {
            $params[] = '%' . $global_search . '%';
        }
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $vehicles = $database->query($sql, $params);

    echo json_encode([
        'code' => 0,
        'msg' => '成功',
        'data' => [
            'vehicles' => $vehicles
        ]
    ]);
    exit;
}

 
// 在处理场地和车辆信息请求之前添加自动检查和解封逻辑
if (isset($_GET['get_venues'])) {
    // 首先检查并解封过期的场地
    $check_expired_bans = "
        UPDATE venues 
        SET is_banned = 0, 
            venue_status = '营业中',
            ban_end_time = NULL 
        WHERE is_banned = 1 
        AND ban_end_time IS NOT NULL 
        AND ban_end_time <= NOW()
        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    
    try {
        $database->query($check_expired_bans, [], true);
        
        // 获取最近一周内被封禁的场地信息
        $get_banned_venues = "
            SELECT id, venue_name, ban_end_time, is_banned 
            FROM venues 
            WHERE is_banned = 1 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        
        $banned_venues = $database->query($get_banned_venues);
        
        // 记录解封操作
        if (!empty($banned_venues)) {
            foreach ($banned_venues as $venue) {
                if ($venue['ban_end_time'] && strtotime($venue['ban_end_time']) <= time()) {
                    // 记录自动解封操作
                    $log_unban = "INSERT INTO venue_unban_logs (venue_id, unban_time, unban_type) VALUES (?, NOW(), 'auto')";
                    $database->query($log_unban, [$venue['id']], true);
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error checking banned venues: " . $e->getMessage());
    }

    // 获取场地列表
    $get_venuesidandname = "SELECT id, venue_name, is_banned, ban_end_time, venue_status FROM venues";
    $venues = $database->query($get_venuesidandname);
    
    echo json_encode([
        'code' => 0,
        'msg' => '成功',
        'data' => [
            'venues' => $venues
        ]
    ]);
    exit;
}


 
// 修改获取封禁信息的代码，增加自动解封逻辑
if (isset($_GET['get_ban_info'])) {
    $serial_number = $_GET['get_ban_info'] ?? null;
    $venue_id = $_GET['venue_id'] ?? null;
    $ban_type = $_GET['banType'] ?? 'device';

    if ($ban_type === 'device') {
        $info = $database->query("SELECT ban_end_time, ban_duration FROM device_bans WHERE serial_number = ? AND status = 1 ORDER BY created_at DESC LIMIT 1", [$serial_number]);
        if (!empty($info)) {
            $ban_end_time = $info[0]['ban_end_time'];
            $duration = $info[0]['ban_duration'];
            $is_banned = $database->query("SELECT is_banned FROM vehicles WHERE serial_number = ?", [$serial_number])[0]['is_banned'] ?? 0;

            // 自动解封
            if ($is_banned && strtotime($ban_end_time) <= time()) {
                $database->query("UPDATE vehicles SET is_banned = 0, sharing_status = '正在共享', start_status = 'true' WHERE serial_number = ?", [$serial_number], true);
                $database->query("UPDATE device_bans SET status = 2 WHERE serial_number = ? AND status = 1", [$serial_number], true);
                $is_banned = 0;
            }

            echo json_encode(['code' => 0, 'data' => ['ban_end_time' => $ban_end_time, 'ban_duration' => $duration, 'is_banned' => $is_banned]]);
        } else {
            echo json_encode(['code' => 0, 'data' => ['is_banned' => 0]]);
        }
    } elseif ($ban_type === 'venue') {
        $venue = $database->query("SELECT is_banned, ban_end_time FROM venues WHERE id = ?", [$venue_id]);
        if (!empty($venue)) {
            $ban_end_time = $venue[0]['ban_end_time'];
            $is_banned = $venue[0]['is_banned'];

            if ($is_banned && $ban_end_time && strtotime($ban_end_time) <= time()) {
                $database->query("UPDATE venues SET is_banned = 0, venue_status = '营业中', ban_end_time = NULL WHERE id = ?", [$venue_id], true);
                $is_banned = 0;
            }

            echo json_encode(['code' => 0, 'data' => ['ban_end_time' => $ban_end_time, 'is_banned' => $is_banned]]);
        } else {
            echo json_encode(['code' => 0, 'data' => ['is_banned' => 0]]);
        }
    }

    exit;
}
if (isset($_GET['get_venue_ban_info'])) {
    $venue_id = $_GET['get_venue_ban_info'];

    // 查询场地封禁信息
    $sql = "SELECT ban_end_time, is_banned FROM venues WHERE id = ?";
    $venue_info = $database->query($sql, [$venue_id]);

    if (!empty($venue_info)) {
        $end_time = strtotime($venue_info[0]['ban_end_time']);
        if ($venue_info[0]['is_banned'] && $end_time <= time()) {
            // 自动解封
            $database->query("UPDATE venues SET is_banned = 0, venue_status = '营业中' WHERE id = ?", [$venue_id], true);
            echo json_encode([
                'code' => 0,
                'msg' => '场地已自动解封',
                'data' => [
                    'is_banned' => 0,
                    'auto_unbanned' => true
                ]
            ]);
        } else {
            echo json_encode([
                'code' => 0,
                'msg' => '成功',
                'data' => $venue_info[0]
            ]);
        }
    } else {
        echo json_encode(['code' => 1, 'msg' => '找不到场地']);
    }
    exit;
}

echo json_encode([
    'code' => 0,
    'msg' => '成功',
    'data' => [ 'vehicles' => $vehicles ]
]);

 
// logMessage("Operation failed: null"); 
echo json_encode(['code' => 1002, 'msg' => '', 'data' => []]); //操作失败: null 
?> 