<?php
// /api/operat/search_user.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../Database.php';
$database = new Database();

// 会话校验（和你其它接口一致）
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) { echo json_encode(['code'=>1001,'msg'=>'未登录','data'=>[]]); exit; }
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) { echo json_encode(['code'=>1001,'msg'=>'无权访问','data'=>[]]); exit; }

// 入参
$kw    = trim($_GET['kw'] ?? '');
$limit = (int)($_GET['limit'] ?? 8);
$limit = max(1, min(20, $limit));
if ($kw === '') { echo json_encode(['code'=>0,'msg'=>'ok','data'=>[]]); exit; }

// 根据输入做模糊：数字前缀匹配 UID + 模糊匹配昵称
$isNumeric = ctype_digit($kw);
$params = [];
$where  = [];

if ($isNumeric) {
    // UID 前缀匹配 + 精确命中优先
    $where[] = "(CAST(uid AS CHAR) LIKE ? OR nickname LIKE ?)";
    $params[] = $kw . '%';
    $params[] = '%' . $kw . '%';
    $orderSql = "ORDER BY (uid = ?) DESC, uid ASC";
    $params[] = $kw;
} else {
    $where[] = "nickname LIKE ?";
    $params[] = '%' . $kw . '%';
    $orderSql = "ORDER BY uid ASC";
}

// 注意：LIMIT 不能做参数绑定，这里用白名单后的整数直接拼
$sql = "SELECT uid, nickname, phone_number
        FROM users
        WHERE " . implode(' AND ', $where) . "
        $orderSql
        LIMIT $limit";

$rows = $database->query($sql, $params);
if ($rows === false) {
    echo json_encode(['code'=>2005, 'msg'=>'查询失败', 'data'=>[]]); exit;
}
echo json_encode(['code'=>0, 'msg'=>'ok', 'data'=>$rows]);
