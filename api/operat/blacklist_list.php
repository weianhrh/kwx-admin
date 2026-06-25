<?php // api/operat/blacklist_list.php
require_once __DIR__.'/_bootstrap.php';
require_once __DIR__.'/../lib/venue_scope.php';

$db   = new Database();
$user = auth_or_die($db); // 返回包含 role_id, venue_id 等
if(!can_block($user)) json_err('无权查看此列表', 1003);

$role_id  = intval($user['role_id'] ?? 0);
$requestedVenueId = venue_scope_requested_id($_GET);

$page      = max(1, intval($_GET['page'] ?? 1));
$page_size = min(100, max(1, intval($_GET['page_size'] ?? 12)));
$kw        = trim($_GET['kw'] ?? '');
$offset    = ($page-1)*$page_size;

$where  = " WHERE 1=1 ";
$params = [];

// 管理员可通过 query 指定场地；非管理员限制在自己可管理场地内
if (venue_scope_is_platform_admin($user)) {
    if ($requestedVenueId > 0) {
        $where   .= " AND venue_id = ? ";
        $params[] = $requestedVenueId;
    }
} else {
    $where .= venue_scope_apply_filter($db, $user, 'venue_id', $params, $requestedVenueId);
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
