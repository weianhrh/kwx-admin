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

$uid = intval($_GET['uid'] ?? 0);
$type = intval($_GET['type'] ?? -1); // 0普通用户，1主播，2管理员
if ($uid <= 0) {
    jsonOut(1003, '缺少有效的 uid');
}

$info = $database->query(
    "SELECT uid, IFNULL(is_streamer, 0) AS is_streamer, streamer_venue FROM users WHERE uid = ?",
    [$uid]
);

if (!$info) {
    jsonOut(1004, '用户不存在');
}
if (!in_array($type, [0, 1, 2], true)) {
    jsonOut(1005, '身份类型错误');
}

if ($type === 0) {
    $affected = $database->query(
        "UPDATE users SET is_streamer = 0, streamer_venue = NULL WHERE uid = ?",
        [$uid],
        true
    );

    if ($affected === false) {
        jsonOut(1006, '取消身份失败');
    }

    jsonOut(0, '已取消身份', [
        'uid' => $uid,
        'is_streamer' => 0,
        'streamer_venue' => null
    ]);
}

$affected = $database->query(
    "UPDATE users SET is_streamer = ? WHERE uid = ?",
    [$type, $uid],
    true
);

if ($affected === false) {
    jsonOut(1007, '设置身份失败');
}

jsonOut(0, $type === 1 ? '已设为主播' : '已设为管理员', [
    'uid' => $uid,
    'is_streamer' => $type
]);