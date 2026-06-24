<?php
require_once '../Database.php';

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['error' => '未登录']);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user) {
    echo json_encode(['error' => '非法访问']);
    exit;
}

$role_id = $user['role_id'];
$venue_id = (in_array($role_id, [1, 2], true) && isset($_GET['venue_id'])) ? (int)$_GET['venue_id'] : $user['venue_id'];

// 查询该场地的能量分配记录
$rows = $database->query(
    "SELECT user_uid, energy_change, reason, created_at, franchisee_income, platform_income
     FROM universal_energy_details
     WHERE venue_id = ?
     ORDER BY id DESC
     LIMIT 100",
    [$venue_id]
);

echo json_encode(['data' => $rows]);
