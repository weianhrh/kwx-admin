<?php
// /api/operat/get_current_user.php
require_once '../Database.php';
require_once '../lib/venue_scope.php';
$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;
$user = $database->getUserBySessionToken($session_token);

if ($user) {
    $venueIds = venue_scope_is_platform_admin($user)
        ? []
        : venue_scope_user_ids($database, $user);
    echo json_encode([
        'code' => 0,
        'data' => [
            'role_id' => $user['role_id'],
            'venue_id' => $user['venue_id'],
            'venue_ids' => $venueIds,
            'user_uid' => $user['uid']
            
        ]
    ]);
} else {
    echo json_encode(['code' => 1, 'msg' => '未登录']);
}
