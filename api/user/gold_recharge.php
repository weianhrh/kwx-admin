<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut($code, $message, $data = [])
{
    echo json_encode([
        'code'    => $code,
        'success' => $code === 0,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();

// =========================
// 会话校验
// =========================
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    jsonOut(1001, '用户未登录或会话已过期');
}

$adminUser = $database->getUserBySessionToken($session_token);
if (!$adminUser || empty($adminUser['role_id'])) {
    jsonOut(1001, '用户未登录或无权访问');
}

$operatorId = !empty($adminUser['uid']) ? intval($adminUser['uid']) : 10000;

// =========================
// 参数校验
// =========================
$uidRaw    = trim((string)($_POST['uid'] ?? ''));
$amountRaw = trim((string)($_POST['amount'] ?? ''));

if ($uidRaw === '' || !ctype_digit($uidRaw)) {
    jsonOut(1, '无效的用户ID');
}

if ($amountRaw === '' || !preg_match('/^-?\d+$/', $amountRaw)) {
    jsonOut(1, '金币变动数量必须是整数，例如 100 或 -100');
}

$uid    = intval($uidRaw);
$amount = intval($amountRaw);

if ($amount === 0) {
    jsonOut(1, '金币变动数量不能为 0');
}

try {
    $database->beginTransaction();

    // 锁定用户行，避免并发下余额不准
    $userRows = $database->query(
        "SELECT uid, gold_balance 
         FROM users 
         WHERE uid = ? 
         LIMIT 1 
         FOR UPDATE",
        [$uid]
    );

    if (!$userRows || !isset($userRows[0])) {
        $database->rollBack();
        jsonOut(1, '用户不存在');
    }

    $beforeBalance = intval($userRows[0]['gold_balance']);
    $afterBalance  = $beforeBalance + $amount;

    // 防止扣成负数
    if ($afterBalance < 0) {
        $database->rollBack();
        jsonOut(1, '扣减后金币余额不能小于 0');
    }

    // 更新 users.gold_balance
    $updateRes = $database->query(
        "UPDATE users SET gold_balance = ? WHERE uid = ?",
        [$afterBalance, $uid],
        true
    );

    if ($updateRes === false) {
        throw new Exception('更新用户金币余额失败');
    }

    // 变动类型
    $changeType = $amount > 0 ? 'admin_add' : 'admin_deduct';
    $remark     = $amount > 0 ? '后台金币充值' : '后台金币扣减';
    $bizType    = 'manual';
    $bizNo      = 'manual_gold_' . date('YmdHis') . '_' . $uid;

    // 写入 gold_balance_changes
    $insertRes = $database->query(
        "INSERT INTO gold_balance_changes 
            (uid, change_type, change_amount, balance_before, balance_after, biz_type, biz_id, biz_no, remark, operator_id)
         VALUES 
            (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)",
        [
            $uid,
            $changeType,
            $amount,
            $beforeBalance,
            $afterBalance,
            $bizType,
            $bizNo,
            $remark,
            $operatorId
        ],
        true
    );

    if ($insertRes === false) {
        throw new Exception('写入金币变动记录失败');
    }

    $database->commit();

    jsonOut(0, '金币变动成功', [
        'uid'            => $uid,
        'change_type'    => $changeType,
        'change_amount'  => $amount,
        'balance_before' => $beforeBalance,
        'balance_after'  => $afterBalance,
        'operator_id'    => $operatorId,
        'biz_type'       => $bizType,
        'biz_no'         => $bizNo
    ]);

} catch (Throwable $e) {
    $database->rollBack();
    jsonOut(1, '金币变动失败：' . $e->getMessage());
}