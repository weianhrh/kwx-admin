<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function jsonResponse($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();
$conn = $database->getConnection();

try {
    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        jsonResponse(1001, '用户未登录或会话已过期');
    }

    $user = $database->getUserBySessionToken($session_token);
    if (!$user || empty($user['role_id'])) {
        jsonResponse(1001, '用户未登录或无权访问');
    }

    $venue_id = (int)($user['venue_id'] ?? 0);
    $operator_id = (int)($user['uid'] ?? 0);

    if ($venue_id <= 0) {
        jsonResponse(1002, '用户未绑定场地');
    }

    if ($operator_id <= 0) {
        jsonResponse(1004, '操作者信息缺失');
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(400, '参数错误');
    }

    $database->beginTransaction();

    // 锁定提现账户余额行，确认该场地已绑定提现账号
    $fundStmt = $conn->prepare("
        SELECT venue_id, account_balance
        FROM venue_funds
        WHERE venue_id = ?
        FOR UPDATE
    ");
    if (!$fundStmt) {
        throw new Exception('提现账号查询预处理失败：' . $conn->error);
    }

    $fundStmt->bind_param('i', $venue_id);
    $fundStmt->execute();
    $fundResult = $fundStmt->get_result();
    $fundRow = $fundResult ? $fundResult->fetch_assoc() : null;
    $fundStmt->close();

    if (!$fundRow) {
        $database->rollBack();
        jsonResponse(1002, '需要先绑定提现账号才可以核对');
    }

    // 锁定收益记录，必须属于当前场地
    $revenueStmt = $conn->prepare("
        SELECT id, venue_id, `date`, total_revenue, is_checked
        FROM DailyVenueRevenue
        WHERE id = ?
          AND venue_id = ?
        FOR UPDATE
    ");
    if (!$revenueStmt) {
        throw new Exception('收益记录查询预处理失败：' . $conn->error);
    }

    $revenueStmt->bind_param('ii', $id, $venue_id);
    $revenueStmt->execute();
    $revenueResult = $revenueStmt->get_result();
    $revenueRow = $revenueResult ? $revenueResult->fetch_assoc() : null;
    $revenueStmt->close();

    if (!$revenueRow) {
        $database->rollBack();
        jsonResponse(404, '收益记录不存在或无权核对');
    }

    if ((int)$revenueRow['is_checked'] === 1) {
        $database->rollBack();
        jsonResponse(1003, '该记录已核对，不能重复核对');
    }

    $amount = (float)$revenueRow['total_revenue'];
    $revenueDate = $revenueRow['date'];
    $sourceType = 'DailyVenueRevenue';
    $sourceId = (int)$revenueRow['id'];

    // 更新余额
    $updateBalanceStmt = $conn->prepare("
        UPDATE venue_funds
        SET account_balance = account_balance + ?
        WHERE venue_id = ?
    ");
    if (!$updateBalanceStmt) {
        throw new Exception('更新余额预处理失败：' . $conn->error);
    }

    $updateBalanceStmt->bind_param('di', $amount, $venue_id);
    if (!$updateBalanceStmt->execute()) {
        throw new Exception('更新余额失败：' . $updateBalanceStmt->error);
    }
    $updateBalanceStmt->close();

    // 获取更新后的余额
    $balanceStmt = $conn->prepare("
        SELECT account_balance
        FROM venue_funds
        WHERE venue_id = ?
        FOR UPDATE
    ");
    if (!$balanceStmt) {
        throw new Exception('查询新余额预处理失败：' . $conn->error);
    }

    $balanceStmt->bind_param('i', $venue_id);
    $balanceStmt->execute();
    $balanceResult = $balanceStmt->get_result();
    $balanceRow = $balanceResult ? $balanceResult->fetch_assoc() : null;
    $balanceStmt->close();

    if (!$balanceRow) {
        throw new Exception('获取更新后余额失败');
    }

    $newBalance = (float)$balanceRow['account_balance'];

    // 插入余额变动记录。唯一键 uniq_venue_revenue_day 会防止同场地同账期重复入账
    $insertStmt = $conn->prepare("
        INSERT INTO fund_changes (
            venue_id,
            change_type,
            change_amount,
            balance_after_change,
            change_reason,
            operator_id,
            remarks,
            revenue_date,
            source_type,
            source_id
        ) VALUES (
            ?,
            'revenue',
            ?,
            ?,
            '收益核对入账',
            ?,
            NULL,
            ?,
            ?,
            ?
        )
    ");
    if (!$insertStmt) {
        throw new Exception('插入流水预处理失败：' . $conn->error);
    }

    $insertStmt->bind_param(
        'iddissi',
        $venue_id,
        $amount,
        $newBalance,
        $operator_id,
        $revenueDate,
        $sourceType,
        $sourceId
    );

    if (!$insertStmt->execute()) {
        if ((int)$conn->errno === 1062) {
            $insertStmt->close();
            $database->rollBack();
            jsonResponse(1003, '该账期已核对，不能重复核对');
        }

        throw new Exception('插入余额变动记录失败：' . $insertStmt->error);
    }
    $insertStmt->close();

    // 标记收益记录为已核对，附带 is_checked = 0 防止异常并发
    $checkStmt = $conn->prepare("
        UPDATE DailyVenueRevenue
        SET is_checked = 1
        WHERE id = ?
          AND venue_id = ?
          AND is_checked = 0
    ");
    if (!$checkStmt) {
        throw new Exception('更新核对状态预处理失败：' . $conn->error);
    }

    $checkStmt->bind_param('ii', $id, $venue_id);
    if (!$checkStmt->execute()) {
        throw new Exception('更新核对状态失败：' . $checkStmt->error);
    }

    if ($checkStmt->affected_rows !== 1) {
        $checkStmt->close();
        $database->rollBack();
        jsonResponse(1003, '该记录已核对，不能重复核对');
    }

    $checkStmt->close();

    $database->commit();

    jsonResponse(0, '核对成功', [
        'venue_id' => $venue_id,
        'revenue_date' => $revenueDate,
        'amount' => $amount,
        'balance_after_change' => $newBalance
    ]);

} catch (Throwable $e) {
    try {
        $database->rollBack();
    } catch (Throwable $rollbackError) {
        // ignore rollback error
    }

    jsonResponse(500, '核对失败：' . $e->getMessage());
} finally {
    $database->close();
}