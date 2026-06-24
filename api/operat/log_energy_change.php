<?php
require_once '../Database.php';

header('Content-Type: application/json');

$database = new Database();

$session_token = $_COOKIE['session_token'] ?? $_POST['token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '缺少 session_token']);
    exit;
}

// 获取管理员信息
$admin = $database->getUserBySessionToken($session_token);
if (!$admin) {
    echo json_encode(['code' => 1002, 'msg' => '无效的 session_token']);
    exit;
}

// 获取参数
$user_uid = $_POST['user_uid'] ?? null;
$venue_id = $_POST['venue_id'] ?? $admin['venue_id'] ?? null;
$energy_change = floatval($_POST['energy_change'] ?? 0);
$reason = $_POST['reason'] ?? '系统调整';

if (!$user_uid || !$venue_id || $energy_change == 0) {
    echo json_encode(['code' => 1003, 'msg' => '缺少参数']);
    exit;
}

// 获取用户当前能量
$userInfo = $database->query("SELECT energy FROM users WHERE uid = ?", [$user_uid]);
if (!$userInfo) {
    echo json_encode(['code' => 1004, 'msg' => '用户不存在']);
    exit;
}

$before = floatval($userInfo[0]['energy']);
$after = $before + $energy_change;
$now = date('Y-m-d H:i:s');

$database->beginTransaction();

try {
    // 更新用户能量
    $update = $database->query(
        "UPDATE users SET energy = ? WHERE uid = ?",
        [$after, $user_uid],
        true
    );

    if ($update <= 0) {
        throw new Exception('更新用户能量失败');
    }

    // 插入能量日志
    $insert = $database->query(
        "INSERT INTO universal_energy_details (user_uid, venue_id, energy_change, balance_before_change, balance_after_change, reason, created_at) 
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        [$user_uid, $venue_id, $energy_change, $before, $after, $reason, $now],
        true
    );

    if ($insert <= 0) {
        throw new Exception('插入能量日志失败');
    }

    $database->commit();
    echo json_encode(['code' => 0, 'msg' => '操作成功', 'data' => [
        'before' => $before,
        'after' => $after,
        'change' => $energy_change,
        'reason' => $reason
    ]]);
} catch (Exception $e) {
    $database->rollBack();
    $database->logToFile("能量变更失败: " . $e->getMessage());
    echo json_encode(['code' => 500, 'msg' => '操作失败: ' . $e->getMessage()]);
}
