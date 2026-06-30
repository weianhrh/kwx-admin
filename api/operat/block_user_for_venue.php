<?php
// /api/operat/block_user_for_venue.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once '../Database.php';
session_start();

define('OPEN_INTERNAL_KEY', 'open_send_zego_internal_20260529_xxxxxxxx');

$database = new Database();

function out($arr){
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 调用 open 自己的 ZEGO 发送接口
 */
function call_open_send_zego_custom_msg($target_uid, $venue_id) {
    $postData = http_build_query([
        'internal_key' => OPEN_INTERNAL_KEY,
        'target_uid'  => $target_uid,
        'venue_id'    => $venue_id,
        'ban_reason'  => '您已被主播移出房间'
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://open.kwxapp.cn/api/operat/send_zego_custom_msg.php',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return [
            'ok' => false,
            'msg' => '调用open ZEGO接口失败：' . $err,
            'raw' => null
        ];
    }

    $json = json_decode($resp, true);

    return [
        'ok' => ($code === 200 && is_array($json) && intval($json['code'] ?? -1) === 0),
        'msg' => is_array($json) ? ($json['msg'] ?? 'open ZEGO接口已返回') : 'open ZEGO接口返回非JSON',
        'raw' => $json ?: $resp
    ];
}

/* ===== 受保护 UID ===== */
$DENY_UIDS = [10001, 10107, 10130];

/* ===== 登录校验 ===== */
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    out(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
}

$user = $database->getUserBySessionToken($session_token);

if (!$user || empty($user['role_id'])) {
    out(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
}

/* ===== 权限校验：role_id 1/2 可指定场地拉黑 ===== */
$role_id = intval($user['role_id'] ?? 0);

if (!in_array($role_id, [1, 2], true)) {
    http_response_code(403);
    out(['code' => 403, 'msg' => '无权执行指定场地拉黑', 'data' => ['role_id' => $role_id]]);
}

/* 当前操作者 UID */
$handler_uid = intval($user['uid'] ?? 0);

if (isset($_SESSION['operat_user']['uid'])) {
    $handler_uid = intval($_SESSION['operat_user']['uid']);
} elseif (isset($_SESSION['uid'])) {
    $handler_uid = intval($_SESSION['uid']);
}

/* ===== 读取参数 ===== */
$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$uid      = intval($payload['uid'] ?? 0);
$venue_id = intval($payload['venue_id'] ?? 0);
$reason   = trim($payload['reason'] ?? '');
$remove_voice_room = intval($payload['remove_voice_room'] ?? 0);

if ($uid < 1 || $venue_id < 1) {
    out(['code' => 1, 'msg' => '参数不完整']);
}

if (in_array($uid, $DENY_UIDS, true)) {
    out(['code' => 1, 'msg' => "UID {$uid} 为受保护账号，禁止拉黑"]);
}

if ($reason === '') {
    $reason = '未填写原因';
}

$table = 'venue_user_blacklist';

try {
    /* 1. 先检查是否已存在 */
    $exists = false;

    $checkSql = "SELECT id FROM {$table} WHERE uid = ? AND venue_id = ? LIMIT 1";
    $stmt = $database->prepare($checkSql);
    $stmt->bind_param("ii", $uid, $venue_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $res->num_rows > 0) {
        $exists = true;
    }

    $stmt->close();

    /* 2. 已存在就更新原因，不存在就插入 */
    if ($exists) {
        $updateSql = "UPDATE {$table}
                      SET reason = ?, handler_uid = ?, created_at = NOW()
                      WHERE uid = ? AND venue_id = ?";
        $stmt = $database->prepare($updateSql);
        $stmt->bind_param("siii", $reason, $handler_uid, $uid, $venue_id);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            out(['code' => 500, 'msg' => '数据库更新失败']);
        }

        $msg = '已在拉黑列表，已更新原因';
    } else {
        $insSql = "INSERT INTO {$table}
                   (uid, venue_id, reason, handler_uid, created_at)
                   VALUES (?, ?, ?, ?, NOW())";
        $stmt = $database->prepare($insSql);
        $stmt->bind_param("iisi", $uid, $venue_id, $reason, $handler_uid);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            out(['code' => 500, 'msg' => '数据库插入失败']);
        }

        $msg = '已拉黑';
    }

    $data = [
        'uid' => $uid,
        'venue_id' => $venue_id,
        'remove_voice_room' => $remove_voice_room === 1
    ];

    /* 3. 勾选了才发送移除语音房指令 */
    if ($remove_voice_room === 1) {
        $zegoResult = call_open_send_zego_custom_msg($uid, $venue_id);

        $data['zego_remove_success'] = $zegoResult['ok'];
        $data['zego_remove_msg'] = $zegoResult['msg'];
        $data['zego_remove_raw'] = $zegoResult['raw'];

        $msg .= $zegoResult['ok']
            ? '，已发送移除语音房指令'
            : '，但移除语音房指令发送失败';
    }

    out([
        'code' => 0,
        'msg' => $msg,
        'data' => $data
    ]);

} catch (Throwable $e) {
    out([
        'code' => 500,
        'msg' => '操作失败：' . $e->getMessage(),
        'data' => []
    ]);
}