<?php
require_once '../Database.php';
//require_once '../RedisHelper.php';

// 日志记录函数
function logMessage_log($message) {
    $logFile = __DIR__ . '/doll_catch_record_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

header('Content-Type: application/json; charset=utf-8');

$database = new Database();

// =========================
// 会话校验
// =========================
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================
// 参数
// =========================
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$pageSize = isset($_GET['page_size']) ? intval($_GET['page_size']) : 12;
$pageSize = max(6, min(50, $pageSize));
$offset = ($page - 1) * $pageSize;

$keyword = trim($_GET['keyword'] ?? '');
$status = trim($_GET['status'] ?? '');
$isExchange = trim($_GET['is_exchange'] ?? '');
$isReceive = trim($_GET['is_receive'] ?? '');
$isGift = trim($_GET['is_gift'] ?? '');

// 抓中时间筛选（按 created_at）
$startDate = trim($_GET['start_date'] ?? '');
$endDate   = trim($_GET['end_date'] ?? '');

// =========================
// 条件
// =========================
$where = ["1=1"];
$params = [];

if ($keyword !== '') {
    $where[] = "(CAST(user_id AS CHAR) LIKE ?
                OR CAST(doll_machine_id AS CHAR) LIKE ?
                OR doll_id LIKE ?
                OR doll_name LIKE ?
                OR order_id LIKE ?)";
    $kw = '%' . $keyword . '%';
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}

if ($status !== '' && in_array($status, ['0', '1', '2', '3', '4'], true)) {
    $where[] = "doll_status = ?";
    $params[] = $status;
}

if ($isExchange !== '' && in_array($isExchange, ['0', '1'], true)) {
    $where[] = "is_exchange = ?";
    $params[] = $isExchange;
}

if ($isReceive !== '' && in_array($isReceive, ['0', '1'], true)) {
    $where[] = "is_receive = ?";
    $params[] = $isReceive;
}

if ($isGift !== '' && in_array($isGift, ['0', '1'], true)) {
    $where[] = "is_gift = ?";
    $params[] = $isGift;
}


// 开始日期
if ($startDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $where[] = "created_at >= ?";
    $params[] = $startDate . ' 00:00:00';
}

if ($endDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $where[] = "created_at <= ?";
    $params[] = $endDate . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

// =========================
// 总数
// =========================
$countSql = "SELECT COUNT(*) AS total FROM user_doll_detail WHERE {$whereSql}";
$countResult = $database->query($countSql, $params);
$total = ($countResult && isset($countResult[0]['total'])) ? intval($countResult[0]['total']) : 0;

// =========================
// 统计
// =========================
$statsSql = "
    SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN doll_status = 0 THEN 1 ELSE 0 END) AS status_0,
        SUM(CASE WHEN doll_status = 1 THEN 1 ELSE 0 END) AS status_1,
        SUM(CASE WHEN doll_status = 2 THEN 1 ELSE 0 END) AS status_2,
        SUM(CASE WHEN doll_status = 3 THEN 1 ELSE 0 END) AS status_3,
        SUM(CASE WHEN doll_status = 4 THEN 1 ELSE 0 END) AS status_4
    FROM user_doll_detail
    WHERE {$whereSql}
";
$statsResult = $database->query($statsSql, $params);
$stats = $statsResult[0] ?? [
    'total_count' => 0,
    'status_0' => 0,
    'status_1' => 0,
    'status_2' => 0,
    'status_3' => 0,
    'status_4' => 0
];

// =========================
// 列表
// =========================
$listSql = "
    SELECT
        id,
        user_id,
        doll_machine_id,
        doll_id,
        doll_status,
        doll_name,
        doll_machine_integral,
        doll_image,
        order_id,
        is_exchange,
        is_receive,
        is_gift,
        created_at,
        er_time
    FROM user_doll_detail
    WHERE {$whereSql}
    ORDER BY id DESC
    LIMIT {$offset}, {$pageSize}
";

$list = $database->query($listSql, $params);
if ($list === false) {
    logMessage_log('查询娃娃抓中记录失败');
    echo json_encode(['code' => 500, 'msg' => '查询失败', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================
// 数据格式整理
// =========================
function statusText($status) {
    $map = [
        0 => '寄存中',
        1 => '待发货',
        2 => '已发货',
        3 => '已完成',
        4 => '已兑换'
    ];
    return $map[intval($status)] ?? '未知';
}

foreach ($list as &$row) {
    $row['doll_status_text'] = statusText($row['doll_status']);
    $row['is_exchange_text'] = intval($row['is_exchange']) === 1 ? '已兑换' : '未兑换';
    $row['is_receive_text']  = intval($row['is_receive']) === 1 ? '已领取' : '未领取';
    $row['is_gift_text']     = intval($row['is_gift']) === 1 ? '已赠送' : '未赠送';
    $row['created_at'] = (!empty($row['created_at']) && $row['created_at'] !== '0000-00-00 00:00:00') ? $row['created_at'] : '-';
    $row['er_time'] = (!empty($row['er_time']) && $row['er_time'] !== '0000-00-00 00:00:00') ? $row['er_time'] : '-';
}
unset($row);

// =========================
// 输出
// =========================
echo json_encode([
    'code' => 200,
    'msg' => '获取成功',
    'data' => [
        'list' => $list,
        'stats' => [
            'total_count' => intval($stats['total_count'] ?? 0),
            'status_0' => intval($stats['status_0'] ?? 0),
            'status_1' => intval($stats['status_1'] ?? 0),
            'status_2' => intval($stats['status_2'] ?? 0),
            'status_3' => intval($stats['status_3'] ?? 0),
            'status_4' => intval($stats['status_4'] ?? 0),
        ],
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => max(1, ceil($total / $pageSize))
        ]
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);