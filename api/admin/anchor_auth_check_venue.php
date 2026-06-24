<?php
require_once '../Database.php';
// api/admin/anchor_auth_check_venue.php
date_default_timezone_set('Asia/Shanghai');

header('Content-Type: application/json; charset=utf-8');

$db = new Database();

function jsonOut($code, $msg, $data = null)
{
    $res = [
        'code' => $code,
        'msg' => $msg
    ];

    if ($data !== null) {
        $res['data'] = $data;
    }

    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 当前后台登录用户
$sessionToken = $_COOKIE['session_token'] ?? null;
$currentUser = $sessionToken ? $db->getUserBySessionToken($sessionToken) : null;

if (!$currentUser) {
    jsonOut(1001, '未登录或登录已过期', [
        'can_auth' => false
    ]);
}

$venueId = intval($currentUser['venue_id'] ?? 0);

if ($venueId <= 0) {
    jsonOut(1002, '当前账号未绑定场地，无法发起主播认证', [
        'can_auth' => false,
        'venue_id' => $venueId
    ]);
}

// 判断当前场地是否已有认证成功记录
// auth_status = 2：活体通过，待后台审核
// auth_status = 3：后台审核通过
$rows = $db->query("
    SELECT
        id,
        uid,
        venue_id,
        real_name,
        phone,
        id_card_mask,
        auth_status,
        aliyun_passed,
        created_at,
        updated_at
    FROM anchor_realname_auth
    WHERE venue_id = ?
        AND (
              auth_status IN (2, 3)
              OR aliyun_passed = 'T'
            )
    ORDER BY auth_status DESC, updated_at DESC, id DESC
    LIMIT 1
", [$venueId]);

if ($rows && count($rows) > 0) {
    $row = $rows[0];

    jsonOut(1010, '当前场地已存在认证成功记录，不允许重复认证', [
        'can_auth' => false,
        'venue_id' => $venueId,
        'record' => [
            'id' => $row['id'],
            'uid' => $row['uid'],
            'real_name' => $row['real_name'],
            // 'phone' => $row['phone'],
            'id_card_mask' => $row['id_card_mask'],
            'auth_status' => $row['auth_status'],
            'aliyun_passed' => $row['aliyun_passed'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ]
    ]);
}

jsonOut(0, '当前场地可以发起认证', [
    'can_auth' => true,
    'venue_id' => $venueId
]);