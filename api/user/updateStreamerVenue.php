<?php
require_once '../Database.php';
header('Content-Type: application/json; charset=utf-8');

function jsonOut($code, $msg, $data = null) {
    $resp = ['code' => $code, 'msg' => $msg];
    if ($data !== null) $resp['data'] = $data;
    echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$database = new Database();

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    jsonOut(1001, '用户未登录或会话已过期');
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    jsonOut(1001, '用户未登录或无权访问');
}

$uid = intval($_POST['uid'] ?? 0);
$streamer_venue = intval($_POST['streamer_venue'] ?? ($_POST['venue_id'] ?? 0));

if ($uid <= 0) {
    jsonOut(1003, '缺少有效的 uid');
}
if ($streamer_venue <= 0) {
    jsonOut(1004, '请选择有效场地');
}

$userInfo = $database->query(
    "SELECT uid, IFNULL(is_streamer, 0) AS is_streamer FROM users WHERE uid = ?",
    [$uid]
);

if (!$userInfo) {
    jsonOut(1005, '用户不存在');
}

if (!in_array(intval($userInfo[0]['is_streamer']), [1, 2], true)) {
    jsonOut(1006, '该用户还不是主播或管理员，请先设置身份');
}

$affected = $database->query(
    "UPDATE users SET streamer_venue = ? WHERE uid = ?",
    [$streamer_venue, $uid],
    true
);

if ($affected === false) {
    jsonOut(1007, '分配主播场地失败');
}

jsonOut(0, '场地分配成功', [
    'uid' => $uid,
    'streamer_venue' => $streamer_venue
]);