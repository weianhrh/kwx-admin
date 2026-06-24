<?php
// 文件：/api/finance/cancelRefundRecord.php
header('Content-Type: application/json; charset=utf-8');
require_once '../Database.php';

try {
    $db = new Database();

    // 会话检查
    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        echo json_encode(['code' => 1001, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 权限验证：保持和 addRefundRecord.php 一样，仅超管 role_id = 1 可操作
    $admin = $db->query(
        "SELECT uid, role_id FROM admin_users WHERE session_token = ? LIMIT 1",
        [$session_token]
    );

    if (!$admin || !isset($admin[0])) {
        echo json_encode(['code' => 1001, 'msg' => '登录态失效'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $roleId = (int)$admin[0]['role_id'];
    if ($roleId !== 1) {
        echo json_encode(['code' => 1002, 'msg' => '权限不足'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 只接受 JSON
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        echo json_encode(['code' => 2000, 'msg' => '请求体不是有效 JSON'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $order_id = trim($payload['order_id'] ?? '');
    $uid = isset($payload['uid']) ? (int)$payload['uid'] : 0;

    if ($order_id === '') {
        echo json_encode(['code' => 2001, 'msg' => '缺少必要参数: order_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 先查这条退款记录
    if ($uid > 0) {
        $rows = $db->query(
            "SELECT id, order_id, uid, status
             FROM refund_records
             WHERE order_id = ? AND uid = ?
             ORDER BY id DESC
             LIMIT 1",
            [$order_id, $uid]
        );
    } else {
        $rows = $db->query(
            "SELECT id, order_id, uid, status
             FROM refund_records
             WHERE order_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$order_id]
        );
    }

    if (!$rows || !isset($rows[0])) {
        echo json_encode(['code' => 2002, 'msg' => '退款记录不存在或已取消'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $refundId = (int)$rows[0]['id'];
    $status = (string)($rows[0]['status'] ?? '');

    // 只允许删除当前前端认为“已退款/已申请”的状态
    // 如果你后面有真正打款成功状态，比如 refunded / success，建议不要删除
    if (!in_array($status, ['applied', 'approved'], true)) {
        echo json_encode([
            'code' => 2003,
            'msg'  => '当前退款状态不允许取消'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $db->prepare("DELETE FROM refund_records WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $refundId);

    if (!$stmt->execute()) {
        echo json_encode(['code' => 3001, 'msg' => '取消退款失败：' . $stmt->error], JSON_UNESCAPED_UNICODE);
        $stmt->close();
        exit;
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected <= 0) {
        echo json_encode(['code' => 3002, 'msg' => '未删除任何退款记录'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'code' => 0,
        'msg'  => '已取消退款记录',
        'data' => [
            'order_id' => $order_id
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    error_log($e);
    echo json_encode(['code' => 500, 'msg' => '服务器异常'], JSON_UNESCAPED_UNICODE);
}