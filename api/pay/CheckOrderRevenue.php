<?php
require_once '../Database.php';
require_once '../lib/venue_scope.php';

header('Content-Type: application/json; charset=utf-8');

function jsonResponse($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function checkOrderRevenueLog($message) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $logFile = $logDir . '/order_revenue_check_' . date('Y-m-d') . '.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function bindAndExecute(mysqli_stmt $stmt, string $types = '', array $params = []) {
    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new Exception($stmt->error ?: 'SQL执行失败');
    }

    return $stmt;
}

$database = new Database();
$conn = $database->getConnection();

$order_id = trim((string)($_POST['order_id'] ?? $_GET['order_id'] ?? ''));
$operator_id = 0;
$venue_id = 0;

try {
    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        jsonResponse(1001, '用户未登录或会话已过期');
    }

    $user = $database->getUserBySessionToken($session_token);
    if (!$user || empty($user['role_id'])) {
        jsonResponse(1001, '用户未登录或无权访问');
    }

    $operator_id = (int)($user['uid'] ?? 0);
    if ($operator_id <= 0) {
        jsonResponse(1004, '操作者信息缺失');
    }

    if ($order_id === '') {
        jsonResponse(400, '缺少订单编号');
    }

    $database->beginTransaction();

    // 1. 锁定订单，防止并发重复核对同一订单。
    $orderStmt = $conn->prepare("\n        SELECT\n            order_id,\n            reservation_id,\n            uid,\n            payment_amount,\n            pays_type,\n            status,\n            start_time,\n            end_time,\n            is_checked\n        FROM orders\n        WHERE order_id = ?\n        LIMIT 1\n        FOR UPDATE\n    ");
    if (!$orderStmt) {
        throw new Exception('订单查询预处理失败：' . $conn->error);
    }
    bindAndExecute($orderStmt, 's', [$order_id]);
    $orderResult = $orderStmt->get_result();
    $orderRow = $orderResult ? $orderResult->fetch_assoc() : null;
    $orderStmt->close();

    if (!$orderRow) {
        $database->rollBack();
        jsonResponse(404, '订单不存在');
    }

    $venue_id = (int)($orderRow['reservation_id'] ?? 0);
    $amount = round((float)($orderRow['payment_amount'] ?? 0), 2);
    $pays_type = (string)($orderRow['pays_type'] ?? '');
    $is_checked = (int)($orderRow['is_checked'] ?? 0);
    $revenueDate = '';

    if (!empty($orderRow['end_time'])) {
        $revenueDate = date('Y-m-d', strtotime((string)$orderRow['end_time']));
    } elseif (!empty($orderRow['start_time'])) {
        $revenueDate = date('Y-m-d', strtotime((string)$orderRow['start_time']));
    } else {
        $revenueDate = date('Y-m-d');
    }

    if ($venue_id <= 0) {
        throw new Exception('订单缺少场地ID，不能核对');
    }

    // 2. 多场地权限：role_id=1/2 可核对全场地；role_id=3 仅核对自己绑定场地。
    if (!venue_scope_can_access($database, $user, $venue_id)) {
        $database->rollBack();
        jsonResponse(1003, '无权核对该场地订单');
    }

    if ($is_checked === 1) {
        $database->rollBack();
        jsonResponse(1003, '该订单已核对，不能重复核对');
    }

    if ($pays_type === '能量') {
        throw new Exception('能量订单不参与收入核对');
    }

    if ($amount <= 0) {
        throw new Exception('订单收入金额为0，不能核对入账');
    }

    // 3. 有退款记录的订单，不允许核对入账；列表页也会把该订单收入展示为 0。
    $refundStmt = $conn->prepare("\n        SELECT id, refund_amount, status\n        FROM refund_records\n        WHERE order_id = ?\n        LIMIT 1\n        FOR UPDATE\n    ");
    if (!$refundStmt) {
        throw new Exception('退款记录查询预处理失败：' . $conn->error);
    }
    bindAndExecute($refundStmt, 's', [$order_id]);
    $refundResult = $refundStmt->get_result();
    $refundRow = $refundResult ? $refundResult->fetch_assoc() : null;
    $refundStmt->close();

    if ($refundRow) {
        $database->rollBack();
        jsonResponse(1006, '该订单存在退款记录，不允许核对入账');
    }

    // 4. 锁定场地资金账号。
    $fundStmt = $conn->prepare("\n        SELECT venue_id, account_balance\n        FROM venue_funds\n        WHERE venue_id = ?\n        LIMIT 1\n        FOR UPDATE\n    ");
    if (!$fundStmt) {
        throw new Exception('资金账号查询预处理失败：' . $conn->error);
    }
    bindAndExecute($fundStmt, 'i', [$venue_id]);
    $fundResult = $fundStmt->get_result();
    $fundRow = $fundResult ? $fundResult->fetch_assoc() : null;
    $fundStmt->close();

    if (!$fundRow) {
        $database->rollBack();
        jsonResponse(1002, '需要先绑定提现账号才可以核对');
    }

    $oldBalance = round((float)($fundRow['account_balance'] ?? 0), 2);

    // 5. 增加场地可提现余额。
    $updateFundStmt = $conn->prepare("\n        UPDATE venue_funds\n        SET account_balance = account_balance + ?\n        WHERE venue_id = ?\n        LIMIT 1\n    ");
    if (!$updateFundStmt) {
        throw new Exception('更新场地资金预处理失败：' . $conn->error);
    }
    bindAndExecute($updateFundStmt, 'di', [$amount, $venue_id]);
    if ($updateFundStmt->affected_rows !== 1) {
        $updateFundStmt->close();
        throw new Exception('更新场地资金失败');
    }
    $updateFundStmt->close();

    // 6. 再次读取新余额。
    $balanceStmt = $conn->prepare("\n        SELECT account_balance\n        FROM venue_funds\n        WHERE venue_id = ?\n        LIMIT 1\n        FOR UPDATE\n    ");
    if (!$balanceStmt) {
        throw new Exception('查询新余额预处理失败：' . $conn->error);
    }
    bindAndExecute($balanceStmt, 'i', [$venue_id]);
    $balanceResult = $balanceStmt->get_result();
    $balanceRow = $balanceResult ? $balanceResult->fetch_assoc() : null;
    $balanceStmt->close();

    if (!$balanceRow) {
        throw new Exception('获取更新后余额失败');
    }

    $newBalance = round((float)$balanceRow['account_balance'], 2);
    $sourceType = 'orders';
    // source_id 是数字字段时，不能直接写 varchar order_id；用 crc32 做可追踪数字源ID，原订单号写入 remarks。
    $sourceId = (int)sprintf('%u', crc32($order_id));
    $remarks = sprintf(
        '订单收入核对入账：order_id=%s，venue_id=%d，amount=%.2f，old_balance=%.2f，new_balance=%.2f，operator=%d',
        $order_id,
        $venue_id,
        $amount,
        $oldBalance,
        $newBalance,
        $operator_id
    );

    // 7. 写资金流水。若 fund_changes 有唯一键，也能进一步防止重复入账。
    $changeStmt = $conn->prepare("\n        INSERT INTO fund_changes (\n            venue_id,\n            change_type,\n            change_amount,\n            balance_after_change,\n            change_reason,\n            operator_id,\n            remarks,\n            revenue_date,\n            source_type,\n            source_id\n        ) VALUES (\n            ?,\n            'revenue',\n            ?,\n            ?,\n            '订单收益核对入账',\n            ?,\n            ?,\n            ?,\n            ?,\n            ?\n        )\n    ");
    if (!$changeStmt) {
        throw new Exception('插入资金流水预处理失败：' . $conn->error);
    }
    if (!@$changeStmt->bind_param('iddisssi', $venue_id, $amount, $newBalance, $operator_id, $remarks, $revenueDate, $sourceType, $sourceId)) {
        throw new Exception('资金流水参数绑定失败：' . $changeStmt->error);
    }
    if (!$changeStmt->execute()) {
        if ((int)$conn->errno === 1062) {
            $changeStmt->close();
            $database->rollBack();
            jsonResponse(1003, '该订单已核对，不能重复核对');
        }
        throw new Exception('插入资金流水失败：' . $changeStmt->error);
    }
    $changeStmt->close();

    // 8. 标记订单已核对，附带 is_checked = 0 防止异常并发。
    $checkStmt = $conn->prepare("\n        UPDATE orders\n        SET is_checked = 1\n        WHERE order_id = ?\n          AND is_checked = 0\n        LIMIT 1\n    ");
    if (!$checkStmt) {
        throw new Exception('更新订单核对状态预处理失败：' . $conn->error);
    }
    bindAndExecute($checkStmt, 's', [$order_id]);
    if ($checkStmt->affected_rows !== 1) {
        $checkStmt->close();
        $database->rollBack();
        jsonResponse(1003, '该订单已核对，不能重复核对');
    }
    $checkStmt->close();

    $database->commit();

    checkOrderRevenueLog("核对成功 | order_id={$order_id}, venue_id={$venue_id}, amount={$amount}, old_balance={$oldBalance}, new_balance={$newBalance}, operator={$operator_id}");

    jsonResponse(0, '核对成功', [
        'order_id' => $order_id,
        'venue_id' => $venue_id,
        'amount' => $amount,
        'balance_after_change' => $newBalance,
    ]);

} catch (Throwable $e) {
    try {
        $database->rollBack();
    } catch (Throwable $rollbackError) {
        // ignore rollback error
    }

    checkOrderRevenueLog("核对失败 | order_id={$order_id}, venue_id={$venue_id}, operator={$operator_id}, error=" . $e->getMessage());
    jsonResponse(500, '核对失败：' . $e->getMessage());
} finally {
    $database->close();
}
