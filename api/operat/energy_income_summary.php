<?php
require_once '../Database.php';

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['error' => '未登录']);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['venue_id']) {
    echo json_encode(['error' => '非法访问']);
    exit;
}

$role_id = (int)$user['role_id'];
$venue_id = $user['venue_id'];

// 若是权限1，允许从GET传venue_id
if ($role_id === 1 && isset($_GET['venue_id'])) {
    $venue_id = (int)$_GET['venue_id'];
}

$start = $_GET['start'] ?? date('Y-m-d 00:00:00');
$end   = $_GET['end']   ?? date('Y-m-d 23:59:59');

if ($role_id === 1) {
    // 平台视角
    $rows = $database->query(
        "SELECT 
            DATE(created_at) as date,
            SUM(franchisee_income) as daily_income,
            SUM(platform_income) as platform_income
         FROM universal_energy_details
         WHERE venue_id = ? AND created_at BETWEEN ? AND ?
         GROUP BY DATE(created_at)
         ORDER BY date ASC",
        [$venue_id, $start, $end]
    );
    $total = array_sum(array_column($rows, 'platform_income'));
} else {
    // 普通加盟商视角
    $rows = $database->query(
        "SELECT 
            DATE(created_at) as date,
            SUM(franchisee_income) as daily_income
         FROM universal_energy_details
         WHERE venue_id = ? AND created_at BETWEEN ? AND ?
         GROUP BY DATE(created_at)
         ORDER BY date ASC",
        [$venue_id, $start, $end]
    );
    $total = array_sum(array_column($rows, 'daily_income'));
}

echo json_encode([
    'venue_id' => $venue_id,
    'start' => $start,
    'end' => $end,
    'total_income' => round($total, 2),
    'daily' => $rows
]);
