<?php
require_once '../Database.php';
require_once '../RedisHelper.php';

header('Content-Type: application/json; charset=utf-8');

function out($code, $msg, $data = []) {
    echo json_encode(['code'=>$code,'msg'=>$msg,'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) out(1001, '用户未登录或会话已过期', []);

$user = $database->getUserBySessionToken($session_token);
if (!$user || empty($user['role_id'])) out(1001, '用户未登录或无权访问', []);

// ✅ 兼容两种参数名：type / action
$type = $_GET['type'] ?? ($_GET['action'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = intval($_GET['page_size'] ?? 20);
$pageSize = ($pageSize <= 0) ? 20 : min($pageSize, 200);
$offset = ($page - 1) * $pageSize;

$kw = trim((string)($_GET['kw'] ?? ''));

if (!in_array($type, ['register', 'active'], true)) {
    out(1002, 'type 参数错误: register | active', []);
}

// 时间条件
if ($type === 'register') {
    $where = "created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY";
    $orderBy = "created_at DESC";
} else {
    $where = "last_active_at >= CURDATE() AND last_active_at < CURDATE() + INTERVAL 1 DAY";
    $orderBy = "last_active_at DESC";
}

// ✅ 关键字过滤
// ✅ 关键字过滤（昵称/手机号模糊 + 纯数字时也支持 uid 精确）
if ($kw !== '') {
    if (ctype_digit($kw)) {
        // 纯数字：uid 精确 + 手机/昵称模糊（可按你喜好只留 uid）
        $where .= " AND (uid = ? OR nickname LIKE ? OR phone_number LIKE ?)";
        $params[] = $kw;
        $params[] = "%{$kw}%";
        $params[] = "%{$kw}%";
    } else {
        // 非纯数字：只做昵称/手机号模糊
        $where .= " AND (nickname LIKE ? OR phone_number LIKE ?)";
        $params[] = "%{$kw}%";
        $params[] = "%{$kw}%";
    }
}


// 1) 总数
$countSql = "SELECT COUNT(*) AS total FROM users WHERE $where";
$countRes = $database->query($countSql, $params) ?: [];
$total = intval($countRes[0]['total'] ?? 0);

// 2) 列表（⚠️ LIMIT/OFFSET 不走占位符）
$listSql = "
    SELECT
        uid,
        nickname,
        headimgurl,
        phone_number AS phone,
        last_login,
        created_at,
        wallet
    FROM users
    WHERE $where
    ORDER BY $orderBy
    LIMIT " . intval($pageSize) . " OFFSET " . intval($offset);

$list = $database->query($listSql, $params) ?: [];

out(0, 'ok', [
    'type' => $type,
    'page' => $page,
    'page_size' => $pageSize,
    'total' => $total,
    'list' => $list
]);
