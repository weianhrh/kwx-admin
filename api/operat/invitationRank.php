<?php
// /api/user/invitationRank.php

require_once '../Database.php';
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// 创建数据库连接实例
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

$username = $user['username']; // 你要的变量

// ========== 参数 ==========
$page  = max(1, intval($_GET['page'] ?? 1));
$limit = max(1, intval($_GET['limit'] ?? 20));
$offset = ($page - 1) * $limit;

$kw = trim($_GET['invitation_code'] ?? ''); // 模糊搜索邀请码（可选）

// 是否包含更多统计（可选：1=开启）
$withExtra = intval($_GET['with_extra'] ?? 0) === 1;

// ========== WHERE 条件（过滤空邀请码） ==========
$where = " WHERE invitation_code IS NOT NULL AND invitation_code <> '' ";
$params = [];

if ($kw !== '') {
    $where .= " AND invitation_code LIKE ? ";
    $params[] = "%{$kw}%";
}

// ========== count 总数（用于分页） ==========
$countSql = "SELECT COUNT(*) AS c
             FROM (
                SELECT invitation_code
                FROM users
                {$where}
                GROUP BY invitation_code
             ) t";
$countRes = $database->query($countSql, $params);
$total = $countRes ? intval($countRes[0]['c']) : 0;

// ========== 列表查询 ==========
if ($withExtra) {
    // 可选扩展字段：人数、累计消费、总余额、累计充值
    // 注意：字段名按你 users 表截图：cumulative_spending / wallet / earnings
    $sql = "SELECT
              invitation_code,
              COUNT(*) AS user_count,
              IFNULL(SUM(cumulative_spending), 0) AS total_spending,
              IFNULL(SUM(wallet), 0) AS total_wallet,
              IFNULL(SUM(earnings), 0) AS total_earnings
            FROM users
            {$where}
            GROUP BY invitation_code
            ORDER BY user_count DESC, total_spending DESC
            LIMIT {$offset}, {$limit}";
} else {
    // 默认最简单：邀请码 + 数量
    $sql = "SELECT
              invitation_code,
              COUNT(*) AS user_count
            FROM users
            {$where}
            GROUP BY invitation_code
            ORDER BY user_count DESC
            LIMIT {$offset}, {$limit}";
}

$list = $database->query($sql, $params);
if ($list === false) {
    echo json_encode(['code' => 1, 'msg' => '查询失败', 'count' => 0, 'data' => []], JSON_UNESCAPED_UNICODE);
    $database->close();
    exit;
}

echo json_encode([
    'code' => 0,
    'msg' => '',
    'count' => $total,
    'data' => $list
], JSON_UNESCAPED_UNICODE);

$database->close();
