<?php
require_once '../Database.php'; 
$database = new Database(); 
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
?>