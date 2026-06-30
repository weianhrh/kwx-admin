<?php

// 引入数据库连接类
require_once '../Database.php';
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$database = new Database();

// 从会话中获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;

// 验证 session_token 并获取用户信息
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// 根据 session_token 获取用户信息
$user = $database->getUserBySessionToken($session_token);

// 检查用户是否存在和权限获取
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- 参数 ----------
$type = $_GET['type'] ?? 'level'; // level=等级榜, spending=消费榜
$keyword = trim($_GET['keyword'] ?? ''); // nickname 或 uid（支持模糊）
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = intval($_GET['page_size'] ?? 20);
if ($pageSize <= 0) $pageSize = 20;
if ($pageSize > 100) $pageSize = 100;

$offset = ($page - 1) * $pageSize;

// ---------- 排序规则 ----------
if ($type === 'spending') {
    // 消费榜：消费金额降序，其次等级降序，再 uid 降序
    $orderBy = " ORDER BY cumulative_spending DESC, vip DESC, uid DESC ";
} else {
    // 等级榜：等级降序，其次消费金额降序，再 uid 降序
    $orderBy = " ORDER BY vip DESC, cumulative_spending DESC, uid DESC ";
}

// ---------- 搜索条件 ----------
$where = " WHERE deleted = 0 ";
$params = [];

if ($keyword !== '') {
    // 如果 keyword 是纯数字，优先按 uid 精确匹配；同时也允许 nickname 模糊
    if (ctype_digit($keyword)) {
        $where .= " AND (uid = ? OR nickname LIKE ?) ";
        $params[] = $keyword;
        $params[] = "%" . $keyword . "%";
    } else {
        $where .= " AND (nickname LIKE ? OR CAST(uid AS CHAR) LIKE ?) ";
        $params[] = "%" . $keyword . "%";
        $params[] = "%" . $keyword . "%";
    }
}

// ---------- 统计总数 ----------
$sqlCount = "SELECT COUNT(1) AS total FROM users " . $where;
$cntRes = $database->query($sqlCount, $params);
$total = ($cntRes && isset($cntRes[0]['total'])) ? intval($cntRes[0]['total']) : 0;

// ---------- 列表查询 ----------
$sqlList = "
SELECT
  uid,
  nickname,
  headimgurl,
  vip,
  vip_name,
  cumulative_spending
FROM users
" . $where . $orderBy . " LIMIT $offset, $pageSize ";

$list = $database->query($sqlList, $params);
if ($list === false) {
    echo json_encode(['code' => 400, 'msg' => '查询失败', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// 补 rank
$rows = [];
$rankStart = $offset + 1;
foreach ($list as $i => $row) {
    $row['rank'] = $rankStart + $i;
    // 兜底：头像空就给默认
    if (!isset($row['headimgurl']) || $row['headimgurl'] === '' || $row['headimgurl'] === null) {
        $row['headimgurl'] = "https://app.kwxapp.cn/img/logo.png";
    }
    // 金额转字符串避免前端精度坑
    $row['cumulative_spending'] = (string)$row['cumulative_spending'];
    $rows[] = $row;
}

echo json_encode([
    'code' => 200,
    'msg' => 'success',
    'data' => [
        'type' => $type,
        'keyword' => $keyword,
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'list' => $rows
    ]
], JSON_UNESCAPED_UNICODE);
