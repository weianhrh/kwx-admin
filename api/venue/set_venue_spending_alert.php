<?php
// /api/venue/set_venue_spending_alert.php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function out($code, $msg, $data = null) {
    $resp = [
        'code' => $code,
        'msg'  => $msg
    ];

    if ($data !== null) {
        $resp['data'] = $data;
    }

    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(405, '仅支持POST请求');
}

$database = new Database();

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    out(1001, '用户未登录或会话已过期');
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || empty($user['role_id'])) {
    out(1001, '用户未登录或无权访问');
}

// 可按你后台权限规则调整
$role_id = intval($user['role_id']);
if (!in_array($role_id, [1, 2], true)) {
    out(1002, '无权限操作');
}

$venue_id = intval($_POST['venue_id'] ?? 0);
$is_spending_alert = intval($_POST['is_spending_alert'] ?? -1);

if ($venue_id <= 0) {
    out(1, '场地ID无效');
}

if (!in_array($is_spending_alert, [0, 1], true)) {
    out(2, '高消费提示参数错误');
}

$conn = $database->getConnection();

try {
    // 先确认场地存在
    $checkSql = "SELECT id FROM venues WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($checkSql);
    if (!$stmt) {
        out(500, 'SQL预处理失败：' . $conn->error);
    }

    $stmt->bind_param('i', $venue_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $venue = $result->fetch_assoc();
    $stmt->close();

    if (!$venue) {
        out(404, '场地不存在');
    }

    // 修改高消费提示状态
    $updateSql = "UPDATE venues 
                  SET is_spending_alert = ?
                  WHERE id = ?
                  LIMIT 1";

    $stmt = $conn->prepare($updateSql);
    if (!$stmt) {
        out(500, 'SQL预处理失败：' . $conn->error);
    }

    $stmt->bind_param('ii', $is_spending_alert, $venue_id);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        out(3, '更新失败：' . $conn->error);
    }

    out(0, $is_spending_alert === 1 ? '已开启高消费提示' : '已关闭高消费提示', [
        'venue_id' => $venue_id,
        'is_spending_alert' => $is_spending_alert
    ]);

} catch (Throwable $e) {
    out(500, '服务器异常：' . $e->getMessage());
}