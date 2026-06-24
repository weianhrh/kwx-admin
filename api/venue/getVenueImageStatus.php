<?php

// /api/venue/getVenueImageStatus.php
require_once '../Database.php';
$database = new Database();

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未登录']);
    exit;
}

$user = $database->query("SELECT venue_id FROM admin_users WHERE session_token = ?", [$session_token]);
if (!$user) {
    echo json_encode(['code' => 1002, 'msg' => '非法用户']);
    exit;
}
$role_id = $user['role_id']; 
$venue_id = $user[0]['venue_id'];

// 查找该场地最近一次上传的图片审核记录
$sql = "SELECT status, reason, uploaded_at, reviewed_at 
        FROM venue_image_reviews 
        WHERE venue_id = ? 
        ORDER BY uploaded_at DESC 
        LIMIT 1";

$result = $database->query($sql, [$venue_id]);

if ($result) {
    echo json_encode(['code' => 0, 'data' => $result[0]]);
} else {
    echo json_encode(['code' => 0, 'data' => null]);  // 没有记录也返回成功
}
