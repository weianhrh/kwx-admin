<?php
require_once __DIR__ . '/../Database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$database = new Database();
try {
    $token = $_COOKIE['session_token'] ?? null;
    $user = $token ? $database->getUserBySessionToken($token) : null;
    if (!$user || empty($user['role_id'])) {
        echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期']);
        exit;
    }
    if (!in_array((int)$user['role_id'], [1, 2], true)) {
        echo json_encode(['code' => 1003, 'msg' => '无权查看玩家余额']);
        exit;
    }
    // 所有用户的钱包余额；不限制封禁状态，不含金币、电池及加盟商资金。
    $rows = $database->query('SELECT COALESCE(SUM(wallet), 0) AS player_balance FROM users');
    if (!is_array($rows) || !isset($rows[0]['player_balance']) || !is_numeric($rows[0]['player_balance'])) {
        throw new RuntimeException('玩家余额查询失败');
    }
    echo json_encode(['code' => 0, 'data' => ['player_balance' => (string)$rows[0]['player_balance']]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('提现审批玩家余额汇总失败: ' . $e->getMessage());
    echo json_encode(['code' => 500, 'msg' => '玩家余额暂时读取失败'], JSON_UNESCAPED_UNICODE);
} finally {
    $database->close();
}
