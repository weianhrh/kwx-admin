<?php
// 文件：/api/finance/addRefundRecord.php
header('Content-Type: application/json; charset=utf-8');
require_once '../Database.php';

try {
    $db = new Database();

    // 会话检查
    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        echo json_encode(['code' => 1001, 'msg' => '未登录']);
        exit;
    }

    // 权限验证：仅超管(1)可申请；如需运营(3)也可，改成 IN (1,3)
    $admin = $db->query("SELECT uid, role_id FROM admin_users WHERE session_token = ?", [$session_token]);
    if (!$admin || !isset($admin[0])) {
        echo json_encode(['code' => 1001, 'msg' => '登录态失效']);
        exit;
    }
    $adminUid = (int)$admin[0]['uid'];
    $roleId   = (int)$admin[0]['role_id'];
    if ($roleId !== 1) {
        echo json_encode(['code' => 1002, 'msg' => '权限不足']);
        exit;
    }

// 只接受 JSON
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    echo json_encode(['code' => 2000, 'msg' => '请求体不是有效 JSON']);
    exit;
}
$order_id = trim($payload['order_id'] ?? '');
$uid      = isset($payload['uid']) ? (int)$payload['uid'] : 0;
// 金额/原因可选
$refund_amount = isset($payload['refund_amount']) && $payload['refund_amount'] !== ''
    ? round((float)$payload['refund_amount'], 2)   // ← 用 round 得到 float
    : null;

$reason = isset($payload['reason']) ? trim($payload['reason']) : null;
// ✅ 原因必填校验
if ($reason === null || $reason === '') {
    echo json_encode(['code' => 2004, 'msg' => '原因不能为空']);
    exit;
}

if ($order_id === '' || $uid <= 0) {
    echo json_encode(['code' => 2001, 'msg' => '缺少必要参数: order_id / uid']);
    exit;
}

// 1) 查订单，拿 reservation_id（并可校验 uid 是否匹配）
$ord = $db->query("SELECT order_id, uid, reservation_id FROM orders WHERE order_id = ? LIMIT 1", [$order_id]);
if (!$ord) {
    echo json_encode(['code' => 2002, 'msg' => '订单不存在']);
    exit;
}
$reservation_id = (int)$ord[0]['reservation_id'];
if ((int)$ord[0]['uid'] !== $uid) {
    // 如果你的业务允许只靠 order_id 就行，可以不做这个强校验；这里给出提示
    // echo json_encode(['code' => 2003, 'msg' => '订单归属与UID不匹配']);
    // exit;
}
// 1.5) 已锁定订单不允许退款
$lock = $db->query("
    SELECT id
    FROM order_lock_records
    WHERE order_id = ? AND status = 1
    LIMIT 1
", [$order_id]);

if ($lock) {
    echo json_encode([
        'code' => 2005,
        'msg'  => '该订单已锁定，请先解锁后再退款'
    ]);
    exit;
}
// 2) 入库（包含 reservation_id；并根据 NULL 情况拼SQL）
if ($refund_amount === null && $reason === null) {
    $sql = "INSERT INTO refund_records (order_id, uid, reservation_id, refund_amount, reason, applicant_admin_uid, status)
            VALUES (?, ?, ?, NULL, NULL, ?, 'applied')";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("siii", $order_id, $uid, $reservation_id, $adminUid);
} elseif ($refund_amount === null && $reason !== null) {
    $sql = "INSERT INTO refund_records (order_id, uid, reservation_id, refund_amount, reason, applicant_admin_uid, status)
            VALUES (?, ?, ?, NULL, ?, ?, 'applied')";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("siisi", $order_id, $uid, $reservation_id, $reason, $adminUid);
} elseif ($refund_amount !== null && $reason === null) {
    $sql = "INSERT INTO refund_records (order_id, uid, reservation_id, refund_amount, reason, applicant_admin_uid, status)
            VALUES (?, ?, ?, ?, NULL, ?, 'applied')";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("siidi", $order_id, $uid, $reservation_id, $refund_amount, $adminUid); // d=金额
} else {
    $sql = "INSERT INTO refund_records (order_id, uid, reservation_id, refund_amount, reason, applicant_admin_uid, status)
            VALUES (?, ?, ?, ?, ?, ?, 'applied')";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("siidsi", $order_id, $uid, $reservation_id, $refund_amount, $reason, $adminUid);
}

if (!$stmt->execute()) {
    echo json_encode(['code' => 3001, 'msg' => '写入失败或重复申请：' . $stmt->error]);
    $stmt->close();
    exit;
}
$stmt->close();

echo json_encode([
    'code' => 0,
    'msg'  => '退款申请已记录',
    'data' => [
        'order_id' => $order_id,
        'reservation_id' => $reservation_id
    ]
]);
} catch (Throwable $e) {
    error_log($e);
    echo json_encode(['code' => 500, 'msg' => '服务器异常']);
}
