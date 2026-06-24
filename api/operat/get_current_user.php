<?php
// /api/operat/get_current_user.php
require_once '../Database.php';
$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;
$user = $database->getUserBySessionToken($session_token);

if ($user) {
    echo json_encode([
        'code' => 0,
        'data' => [
            'role_id' => $user['role_id'],
            'venue_id' => $user['venue_id'],
            'user_uid' => $user['uid']
            
        ]
    ]);
} else {
    echo json_encode(['code' => 1, 'msg' => '未登录']);
}
