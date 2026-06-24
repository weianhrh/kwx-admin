<?php
require_once '../Database.php';
header('Content-Type: application/json; charset=utf-8');

function json_out($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

$database = new Database();

/* ========== 认证 ========== */
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) json_out(['code'=>1001,'msg'=>'无有效认证信息，请登录','count'=>0,'data'=>[]]);

$userRows = $database->query(
  "SELECT uid, role_id, venue_id FROM admin_users WHERE session_token = ?",
  [$session_token]
);
if (!$userRows || empty($userRows)) {
  json_out(['code'=>1001,'msg'=>'用户未登录或不存在','count'=>0,'data'=>[]]);
}
$user = $userRows[0];
$role_id       = (int)$user['role_id'];
$bind_venue_id = (int)$user['venue_id'];

/* ========== 分页 ========== */
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

/* ========== 可选筛选参数 ========== */
// 兼容 venue_id / venueId
$venueParam  = $_GET['venue_id'] ?? $_GET['venueId'] ?? '';
// 只接受 '0' 或 '1'
$statusParam = $_GET['status'] ?? '';
if ($statusParam !== '0' && $statusParam !== '1') $statusParam = '';

/* ========== 构造 WHERE（管理员/加盟商共用） ========== */
$where  = ' WHERE 1=1 ';
$params = [];

if ($role_id === 1 || $role_id === 2) {
  // 管理员：可选按场地过滤
  if ($venueParam !== '' && ctype_digit((string)$venueParam)) {
    $where   .= ' AND f.venue_id = ? ';
    $params[] = (string)$venueParam;
  }
} elseif ($role_id === 3 || $role_id === 4) {
  // 加盟商：强制限定自己的场地
  $where   .= ' AND f.venue_id = ? ';
  $params[] = (string)$bind_venue_id;
} else {
  json_out(['code'=>1003,'msg'=>'无权限','count'=>0,'data'=>[]]);
}

// 状态过滤（管理员/加盟商都支持）
if ($statusParam !== '') {
  $where   .= ' AND f.status = ? ';
  $params[] = (string)$statusParam;
}

/* ========== 查询与计数（同一 WHERE，保证分页准确） ========== */
$cols = "f.id, f.venue_id, f.message, f.status, f.created_at,
         r.reply_content, r.replied_by_uid, r.replied_at";

$sql = "SELECT $cols
        FROM feedback f
        LEFT JOIN feedback_reply r ON r.feedback_id = f.id
        $where
        ORDER BY f.created_at DESC
        LIMIT ? OFFSET ?";

$rows = $database->query($sql, array_merge($params, [(string)$limit, (string)$offset]));

$count_sql  = "SELECT COUNT(*) AS c FROM feedback f $where";
$count_rows = $database->query($count_sql, $params);
$count      = (int)($count_rows[0]['c'] ?? 0);

/* ========== 输出 ========== */
json_out(['code'=>0,'msg'=>'ok','count'=>$count,'data'=>$rows ?: []]);
