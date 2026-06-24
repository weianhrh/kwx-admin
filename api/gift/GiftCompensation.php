<?php

require_once '../Database.php';

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

function jsonResponse($code, $msg, $data = [])
{
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function getIntPost($key, $default = null)
{
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        return $default;
    }
    return intval($_POST[$key]);
}

function getUserInfoByUid($database, $uid)
{
    $sql = "SELECT uid, nickname FROM users WHERE uid = ? LIMIT 1";
    $result = $database->query($sql, [$uid]);
    return $result ? $result[0] : null;
}

function getGiftInfoById($database, $giftId)
{
    $sql = "SELECT id, gift_name, is_display FROM gift_detail WHERE id = ? LIMIT 1";
    $result = $database->query($sql, [$giftId]);
    return $result ? $result[0] : null;
}

function getBackpackRow($database, $uid, $giftId)
{
    $sql = "SELECT id, uid, gift_id, quantity FROM user_gift_backpack WHERE uid = ? AND gift_id = ? LIMIT 1";
    $result = $database->query($sql, [$uid, $giftId]);
    return $result ? $result[0] : null;
}
function generateGiftBizNo($prefix = 'GCMP') {
    return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
}

$database = new Database();

// 从 cookie 里拿 session_token，沿用你现在能量补偿的权限校验方式
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    jsonResponse(1001, '用户未登录或会话已过期');
}

$user = $database->getUserBySessionToken($session_token);

if (!$user || !isset($user['role_id'])) {
    jsonResponse(1001, '用户未登录或无权访问');
}

$username   = $user['username'] ?? '';
$operatorId = null;

// 尽量兼容不同后台用户表字段
if (isset($user['uid']) && is_numeric($user['uid'])) {
    $operatorId = intval($user['uid']);
} elseif (isset($user['id']) && is_numeric($user['id'])) {
    $operatorId = intval($user['id']);
}

$action = $_POST['action'] ?? '';

if ($action === 'get_gifts') {

    // 这里只加载展示中的礼物；如果你想全部礼物都显示，把 WHERE is_display = 1 去掉
    $sql = "SELECT id, gift_name FROM gift_detail WHERE is_display = 1 ORDER BY id ASC";
    $result = $database->query($sql);

    jsonResponse(0, '成功', $result ?: []);
}

/**
 * 查询某个用户某个礼物当前数量
 */
elseif ($action === 'query_gift') {

    $userId = getIntPost('user_id');
    $giftId = getIntPost('gift_id');

    if (!$userId || !$giftId) {
        jsonResponse(1002, '缺少必要参数');
    }

    $userInfo = getUserInfoByUid($database, $userId);
    if (!$userInfo) {
        jsonResponse(1003, '用户不存在');
    }

    $giftInfo = getGiftInfoById($database, $giftId);
    if (!$giftInfo) {
        jsonResponse(1004, '礼物不存在');
    }

    $backpackRow = getBackpackRow($database, $userId, $giftId);

    jsonResponse(0, '查询成功', [
        'uid'       => $userId,
        'gift_id'   => $giftId,
        'gift_name' => $giftInfo['gift_name'],
        'quantity'  => $backpackRow ? intval($backpackRow['quantity']) : 0
    ]);
}

/**
 * 赠送礼物（后台补偿）
 * 有则 quantity += gift_quantity
 * 无则插入新记录
 * 同时写 user_gift_backpack_changes
 */
elseif ($action === 'gift_item') {

    $userId       = getIntPost('user_id');
    $giftId       = getIntPost('gift_id');
    $giftQuantity = getIntPost('gift_quantity');

    if (!$userId || !$giftId || !$giftQuantity || $giftQuantity <= 0) {
        jsonResponse(1002, '缺少必要参数或赠送数量不正确');
    }

    $userInfo = getUserInfoByUid($database, $userId);
    if (!$userInfo) {
        jsonResponse(1003, '用户不存在');
    }

    $giftInfo = getGiftInfoById($database, $giftId);
    if (!$giftInfo) {
        jsonResponse(1004, '礼物不存在');
    }

    $giftName    = $giftInfo['gift_name'];
    $backpackRow = getBackpackRow($database, $userId, $giftId);

    $beforeQuantity = $backpackRow ? intval($backpackRow['quantity']) : 0;
    $afterQuantity  = $beforeQuantity + $giftQuantity;
    $backpackId     = null;

    if ($backpackRow) {
        $backpackId = intval($backpackRow['id']);

        $updateSql = "UPDATE user_gift_backpack 
                      SET quantity = ?, updated_at = NOW() 
                      WHERE id = ?";
        $database->query($updateSql, [$afterQuantity, $backpackId], true);
    } else {
        $insertSql = "INSERT INTO user_gift_backpack (uid, gift_id, quantity, created_at, updated_at) 
                      VALUES (?, ?, ?, NOW(), NOW())";
        $database->query($insertSql, [$userId, $giftId, $afterQuantity], true);

        $newBackpackRow = getBackpackRow($database, $userId, $giftId);
        $backpackId = $newBackpackRow ? intval($newBackpackRow['id']) : null;
    }

    // 记录礼物变动
    $changeType = 'admin_add';
    $bizNo = generateGiftBizNo('GCMP');
    $reason = '礼物补偿，操作人员：' . $username;

    $changeSql = "INSERT INTO user_gift_backpack_changes
        (uid, backpack_id, related_order_id, gift_id, gift_name, change_quantity, balance_before_change, balance_after_change, change_type, reason, operator_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $database->query($changeSql, [
        $userId,
        $backpackId,
        $bizNo,
        $giftId,
        $giftName,
        $giftQuantity,
        $beforeQuantity,
        $afterQuantity,
        $changeType,
        $reason,
        $operatorId
    ], true);

    jsonResponse(0, '赠送礼物成功', [
        'uid'       => $userId,
        'gift_id'   => $giftId,
        'gift_name' => $giftName,
        'quantity'  => $afterQuantity
    ]);
}

/**
 * 清除礼物
 * 1. 如果 gift_quantity > 0，则扣减指定数量
 * 2. 如果 gift_quantity 为空或 0，则清空当前礼物数量
 * 同时写 user_gift_backpack_changes（负数）
 */
elseif ($action === 'clear_gift') {

    $userId       = getIntPost('user_id');
    $giftId       = getIntPost('gift_id');
    $giftQuantity = getIntPost('gift_quantity', 0);

    if (!$userId || !$giftId) {
        jsonResponse(1002, '缺少必要参数');
    }

    $userInfo = getUserInfoByUid($database, $userId);
    if (!$userInfo) {
        jsonResponse(1003, '用户不存在');
    }

    $giftInfo = getGiftInfoById($database, $giftId);
    if (!$giftInfo) {
        jsonResponse(1004, '礼物不存在');
    }

    $giftName    = $giftInfo['gift_name'];
    $backpackRow = getBackpackRow($database, $userId, $giftId);

    if (!$backpackRow) {
        jsonResponse(0, '当前礼物数量为0，无需清除', [
            'uid'       => $userId,
            'gift_id'   => $giftId,
            'gift_name' => $giftName,
            'quantity'  => 0
        ]);
    }

    $backpackId      = intval($backpackRow['id']);
    $beforeQuantity  = intval($backpackRow['quantity']);

    if ($beforeQuantity <= 0) {
        jsonResponse(0, '当前礼物数量为0，无需清除', [
            'uid'       => $userId,
            'gift_id'   => $giftId,
            'gift_name' => $giftName,
            'quantity'  => 0
        ]);
    }

    // 输入了数量：扣指定数量；没输入或为0：全部清空
    $deductQuantity = ($giftQuantity > 0) ? min($giftQuantity, $beforeQuantity) : $beforeQuantity;
    $afterQuantity  = max(0, $beforeQuantity - $deductQuantity);

    $updateSql = "UPDATE user_gift_backpack 
                  SET quantity = ?, updated_at = NOW() 
                  WHERE id = ?";
    $database->query($updateSql, [$afterQuantity, $backpackId], true);

    // 记录礼物变动（负数）
    $changeType = 'admin_deduct';
    $bizNo = generateGiftBizNo('GDED');
    $reason = '礼物清除，操作人员：' . $username;

    $changeSql = "INSERT INTO user_gift_backpack_changes
        (uid, backpack_id, related_order_id, gift_id, gift_name, change_quantity, balance_before_change, balance_after_change, change_type, reason, operator_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $database->query($changeSql, [
        $userId,
        $backpackId,
        $bizNo,
        $giftId,
        $giftName,
        0 - $deductQuantity,
        $beforeQuantity,
        $afterQuantity,
        $changeType,
        $reason,
        $operatorId
    ], true);

    jsonResponse(0, '清除礼物成功', [
        'uid'           => $userId,
        'gift_id'       => $giftId,
        'gift_name'     => $giftName,
        'clear_quantity'=> $deductQuantity,
        'quantity'      => $afterQuantity
    ]);
}

else {
    jsonResponse(1005, '无效的请求操作');
}
?>