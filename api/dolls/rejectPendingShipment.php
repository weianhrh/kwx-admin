<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Singapore');

$database = new Database();

// 会话校验
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode([
        'code' => 1001,
        'msg'  => '用户未登录或会话已过期'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode([
        'code' => 1001,
        'msg'  => '用户未登录或无权访问'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 兼容单个 id 和批量 ids
$idsRaw = $_POST['ids'] ?? ($_POST['id'] ?? '');

$ids = [];

if (is_array($idsRaw)) {
    $ids = $idsRaw;
} elseif (is_string($idsRaw) && $idsRaw !== '') {
    $decoded = json_decode($idsRaw, true);
    if (is_array($decoded)) {
        $ids = $decoded;
    } else {
        $ids = explode(',', $idsRaw);
    }
} elseif (is_numeric($idsRaw)) {
    $ids = [$idsRaw];
}

$ids = array_map('intval', (array)$ids);
$ids = array_values(array_unique(array_filter($ids, function ($v) {
    return $v > 0;
})));

if (empty($ids)) {
    echo json_encode([
        'code' => 400,
        'msg'  => '缺少有效ID'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

$sql = "
    UPDATE user_doll_detail
    SET doll_status = 0,
        is_gift = 0
    WHERE id IN ($placeholders)
      AND doll_status = 1
      AND is_gift = 0
";

$affected = $database->query($sql, $ids, true);

if ($affected > 0) {
    echo json_encode([
        'code'     => 0,
        'msg'      => '驳回成功',
        'affected' => (int)$affected
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'code' => 1,
        'msg'  => '记录不存在或已被处理'
    ], JSON_UNESCAPED_UNICODE);
}