<?php // api/operat/blacklist_list.php
require_once __DIR__.'/_bootstrap.php';

$db   = new Database();
$user = auth_or_die($db); // 返回包含 role_id, venue_id 等
if(!can_block($user)) json_err('无权查看此列表', 1003);

$role_id  = intval($user['role_id'] ?? 0);
$user_vid = intval($user['venue_id'] ?? 0);

$page      = max(1, intval($_GET['page'] ?? 1));
$page_size = min(100, max(1, intval($_GET['page_size'] ?? 12)));
$kw        = trim($_GET['kw'] ?? '');
$offset    = ($page-1)*$page_size;

$where  = " WHERE 1=1 ";
$params = [];

// 管理员可通过 query 指定场地；非管理员强制当前场地
if ($role_id === 1 || $role_id === 2) {
    $filter_vid = intval($_GET['venue_id'] ?? 0);
    if ($filter_vid > 0) {
        $where   .= " AND venue_id = ? ";
        $params[] = $filter_vid;
    }
} else {
    if($user_vid <= 0) json_err('当前用户未绑定场地，无法操作', 1004);
    $where   .= " AND venue_id = ? ";
    $params[] = $user_vid;
}

// 关键字：纯数字按 UID 精确，否则按原因模糊
if ($kw !== '') {
    if (ctype_digit($kw)) {
        $where   .= " AND uid = ? ";
        $params[] = $kw;
    } else {
        $where   .= " AND reason LIKE ? ";
        $params[] = "%{$kw}%";
    }
}

// 统计
$sqlCnt = "SELECT COUNT(*) AS c FROM venue_user_blacklist {$where}";
$cntRow = $db->query($sqlCnt, $params);
$total  = intval($cntRow[0]['c'] ?? 0);

// 列表
$sql = "SELECT id, uid, handler_uid, venue_id, reason, created_at
        FROM venue_user_blacklist
        {$where}
        ORDER BY created_at DESC
        LIMIT {$offset}, {$page_size}";
$list = $db->query($sql, $params);

json_ok([
    'total'     => $total,
    'page'      => $page,
    'page_size' => $page_size,
    'list'      => $list ?: []
]);
