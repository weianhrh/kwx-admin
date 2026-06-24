<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../Database.php';

try {
    $db = new Database();

    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        echo json_encode(['code' => 1001, 'msg' => '未登录']);
        exit;
    }

    $admin = $db->query("SELECT uid, role_id FROM admin_users WHERE session_token = ?", [$session_token]);
    if (!$admin || !isset($admin[0])) {
        echo json_encode(['code' => 1001, 'msg' => '登录态失效']);
        exit;
    }

    $adminUid = (int)$admin[0]['uid'];
    $roleId   = (int)$admin[0]['role_id'];

    // 按你现在退款接口风格，只给超管
    if ($roleId !== 1) {
        echo json_encode(['code' => 1002, 'msg' => '权限不足']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        echo json_encode(['code' => 2000, 'msg' => '请求体不是有效 JSON']);
        exit;
    }

    $order_id = trim($payload['order_id'] ?? '');
    if ($order_id === '') {
        echo json_encode(['code' => 2001, 'msg' => '缺少 order_id']);
        exit;
    }

    // 查订单
    $ord = $db->query("
        SELECT order_id, reservation_id, payment_amount, pays_type
        FROM orders
        WHERE order_id = ?
        LIMIT 1
    ", [$order_id]);

    if (!$ord) {
        echo json_encode(['code' => 2002, 'msg' => '订单不存在']);
        exit;
    }

    $order = $ord[0];
    $venue_id = (int)$order['reservation_id'];
    $lock_amount = round((float)$order['payment_amount'], 2);
    $pays_type = trim((string)$order['pays_type']);

    if ($pays_type === '能量') {
        echo json_encode(['code' => 2003, 'msg' => '能量订单不支持锁定']);
        exit;
    }

    // 已退款的不允许锁定
    $refund = $db->query("
        SELECT id FROM refund_records
        WHERE order_id = ? AND status IN ('applied','approved')
        LIMIT 1
    ", [$order_id]);
    if ($refund) {
        echo json_encode(['code' => 2004, 'msg' => '该订单已退款，不能再锁定']);
        exit;
    }

    $exists = $db->query("
        SELECT id, status
        FROM order_lock_records
        WHERE order_id = ?
        LIMIT 1
    ", [$order_id]);

    if (!$exists) {
        $db->query("
            INSERT INTO order_lock_records
            (order_id, venue_id, lock_amount, status, operator_uid, create_at, update_at)
            VALUES (?, ?, ?, 1, ?, NOW(), NOW())
        ", [$order_id, $venue_id, $lock_amount, $adminUid], true);

        echo json_encode([
            'code' => 0,
            'msg'  => '锁定成功',
            'data' => [
                'order_id'    => $order_id,
                'lock_status' => 1
            ]
        ]);
        exit;
    }

    $currentStatus = (int)$exists[0]['status'];

    if ($currentStatus === 1) {
        $db->query("
            UPDATE order_lock_records
            SET status = 2, operator_uid = ?, update_at = NOW()
            WHERE order_id = ?
        ", [$adminUid, $order_id], true);

        echo json_encode([
            'code' => 0,
            'msg'  => '解锁成功',
            'data' => [
                'order_id'    => $order_id,
                'lock_status' => 2
            ]
        ]);
        exit;
    }

    $db->query("
        UPDATE order_lock_records
        SET venue_id = ?, lock_amount = ?, status = 1, operator_uid = ?, update_at = NOW()
        WHERE order_id = ?
    ", [$venue_id, $lock_amount, $adminUid, $order_id], true);

    echo json_encode([
        'code' => 0,
        'msg'  => '锁定成功',
        'data' => [
            'order_id'    => $order_id,
            'lock_status' => 1
        ]
    ]);
} catch (Throwable $e) {
    error_log($e);
    echo json_encode(['code' => 500, 'msg' => '服务器异常']);
}