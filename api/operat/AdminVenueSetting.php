<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Taipei');

$db = new Database();

// 读取 session_token
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未登录', 'data' => []]);
    exit;
}

// 获取用户
$user = $db->getUserBySessionToken($session_token);
if (!$user) {
    echo json_encode(['code' => 1001, 'msg' => '会话无效', 'data' => []]);
    exit;
}

// 必须是超级管理员 role_id = 1
if (!in_array((int)$user['role_id'], [1, 2], true)) {
    echo json_encode(['code' => 1001, 'msg' => '无权访问', 'data' => []]);
    exit;
}

$action = $_POST['action'] ?? '';

/* ---------------------- 获取所有场地列表 ---------------------- */
if ($action === 'get_venues') {

    $rows = $db->query("SELECT id, venue_name FROM venues ORDER BY id ASC");

    echo json_encode([
        'code' => 0,
        'venue_id' => (int)$user['venue_id'], // ⭐ 返回当前管理员的场地
        'data' => $rows
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------- 修改当前管理员的 venue_id ---------------------- */
if ($action === 'update_venue') {

    $new_venue = intval($_POST['venue_id'] ?? 0);
    if ($new_venue <= 0) {
        echo json_encode(['code' => 1002, 'msg' => 'venue_id 无效']);
        exit;
    }

    $db->query(
        "UPDATE admin_users SET venue_id=? WHERE id=?",
        [$new_venue, $user['id']],
        true
    );

    echo json_encode(['code' => 0, 'msg' => '修改成功']);
    exit;
}

echo json_encode(['code' => 1003, 'msg' => '无效 action']);
exit;
