<?php
require_once '../Database.php';

date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

function jsonOut($code, $msg, $data = [])
{
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$database = new Database();

$session_token = $_COOKIE['session_token'] ?? '';

if (!$session_token) {
    jsonOut(1001, '用户未登录或会话已过期');
}

$user = $database->getUserBySessionToken($session_token);

if (!$user || !isset($user['role_id'])) {
    jsonOut(1001, '用户未登录或无权访问');
}

$role_id = (int)$user['role_id'];
$loginVenueId = (int)($user['venue_id'] ?? 0);

/**
 * 管理员角色：
 * 这里先兼容 role_id 0 / 1 / 2。
 * 如果你后台只有 role_id == 1 是管理员，把这里改成：
 * $isAdmin = ($role_id === 1);
 */
$isAdmin = in_array($role_id, [0, 1, 2], true);

$action = $_REQUEST['action'] ?? 'list';

/**
 * 删除认证记录
 * 注意：a.id 不展示在页面上，但删除时用它精准定位。
 */
if ($action === 'delete') {
    if (!$isAdmin) {
        jsonOut(403, '仅管理员可以删除认证记录');
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        jsonOut(400, '缺少记录ID');
    }

    $checkSql = "
        SELECT id, uid, venue_id, aliyun_passed
        FROM anchor_realname_auth
        WHERE id = ?
        LIMIT 1
    ";

    $checkRows = $database->query($checkSql, [$id]);

    if (!$checkRows) {
        jsonOut(404, '记录不存在');
    }

    $record = $checkRows[0];

    if (($record['aliyun_passed'] ?? '') !== 'T') {
        jsonOut(400, '只能删除认证通过记录');
    }

    $deleteSql = "
        DELETE FROM anchor_realname_auth
        WHERE id = ?
          AND aliyun_passed = 'T'
        LIMIT 1
    ";

    $ok = $database->query($deleteSql, [$id], true);
    
    if ($ok === false || (int)$ok < 1) {
        jsonOut(500, '删除失败或记录状态已变化');
    }

    jsonOut(0, '删除成功');
}

/**
 * 列表查询
 */
if ($action !== 'list') {
    jsonOut(400, '无效操作');
}

$page = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));

$pageSize = (int)($_GET['page_size'] ?? $_POST['page_size'] ?? 20);
$pageSize = max(10, min($pageSize, 100));

$offset = ($page - 1) * $pageSize;

$venueId = (int)($_GET['venue_id'] ?? $_POST['venue_id'] ?? 0);
$keyword = trim($_GET['keyword'] ?? $_POST['keyword'] ?? '');

$where = [];
$params = [];

/**
 * 核心条件：
 * 只展示有场地，并且阿里云认证通过的记录
 */
$where[] = "a.venue_id IS NOT NULL";
$where[] = "a.venue_id > 0";
$where[] = "a.aliyun_passed = 'T'";

/**
 * 非管理员只能看自己的场地
 */
if (!$isAdmin) {
    if ($loginVenueId <= 0) {
        jsonOut(403, '当前账号未绑定场地，无法查看认证记录');
    }

    $where[] = "a.venue_id = ?";
    $params[] = $loginVenueId;
} else {
    /**
     * 管理员可以按场地筛选
     */
    if ($venueId > 0) {
        $where[] = "a.venue_id = ?";
        $params[] = $venueId;
    }
}

/**
 * 搜索：
 * a.id 不直接展示，也不作为搜索项。
 * 支持 uid / venue_id / 场地名称
 */
if ($keyword !== '') {
    $like = '%' . $keyword . '%';

    $where[] = "(
        CAST(a.uid AS CHAR) LIKE ?
        OR CAST(a.venue_id AS CHAR) LIKE ?
        OR v.venue_name LIKE ?
    )";

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);

$countSql = "
    SELECT COUNT(*) AS total
    FROM anchor_realname_auth a
    LEFT JOIN venues v ON v.id = a.venue_id
    WHERE {$whereSql}
";

$countRows = $database->query($countSql, $params);
$total = (int)($countRows[0]['total'] ?? 0);

$listSql = "
    SELECT
        a.id,
        a.uid,
        a.venue_id,
        v.venue_name,
        a.aliyun_passed,
        a.certify_finished_at
    FROM anchor_realname_auth a
    LEFT JOIN venues v ON v.id = a.venue_id
    WHERE {$whereSql}
    ORDER BY a.certify_finished_at DESC, a.id DESC
    LIMIT {$pageSize} OFFSET {$offset}
";

$rows = $database->query($listSql, $params);

jsonOut(0, 'success', [
    'list' => $rows ?: [],
    'page' => $page,
    'page_size' => $pageSize,
    'total' => $total,
    'total_page' => (int)ceil($total / $pageSize),
    'is_admin' => $isAdmin ? 1 : 0,
    'login_venue_id' => $loginVenueId
]);

$database->close();
?>