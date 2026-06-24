<?php
// api/finance/Comsumquery_v2.php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

function json_response($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();
$mysqli = $database->getConnection();

if (!$mysqli) {
    json_response(500, '数据库连接失败', []);
}

// 1) 登录态
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    json_response(1001, '用户未登录或会话已过期', []);
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !isset($user['role_id'])) {
    json_response(1001, '用户未登录或无权访问', []);
}

$role_id   = (int)$user['role_id'];
$myVenueId = isset($user['venue_id']) ? (int)$user['venue_id'] : 0;

// 2) 入参
$user_id    = trim($_GET['user_id'] ?? '');     // balance_changes / gold_balance_changes / user_gift_backpack_changes 用
$user_uid   = trim($_GET['user_uid'] ?? '');    // energy_changes 用（兼容）
$start_date = trim($_GET['start_date'] ?? '');
$end_date   = trim($_GET['end_date'] ?? '');

$change_type           = trim($_GET['change_type'] ?? '');            // money: 充值/消费/提现/金额返还...
$gold_change_type      = trim($_GET['gold_change_type'] ?? '');       // gold: recharge/admin_add/vip_exchange/refund/activity_gift/admin_deduct
$backpack_change_type  = trim($_GET['backpack_change_type'] ?? '');   // backpack: obtain/use/expire/delete/admin_add/admin_deduct
$mode                  = trim($_GET['mode'] ?? 'all');                // money | energy | gold | backpack | all
$limit                 = (int)($_GET['limit'] ?? 200);

$allowedModes = ['all', 'money', 'energy', 'gold', 'backpack'];
if (!in_array($mode, $allowedModes, true)) {
    $mode = 'all';
}

if ($limit <= 0 || $limit > 10000) {
    $limit = 200;
}

// 兼容各种日期格式：统一成 YYYY-MM-DD HH:MM:SS
function fixDate($date, $isEnd = false){
    // 替换斜杠为短横线
    $date = str_replace('/', '-', $date);

    // 如果只有日期，自动补全时间
    if (strlen($date) === 10) {
        $date .= $isEnd ? ' 23:59:59' : ' 00:00:00';
    }

    // 转为标准 datetime
    return date('Y-m-d H:i:s', strtotime($date));
}

if ($start_date) $start_date = fixDate($start_date, false);
if ($end_date)   $end_date   = fixDate($end_date, true);
// user_id / user_uid 互相兼容
if (!$user_uid && $user_id) {
    $user_uid = $user_id;
}
if (!$user_id && $user_uid) {
    $user_id = $user_uid;
}

// 必填：用户ID
if (!$user_id && !$user_uid) {
    json_response(1002, '用户 ID 不能为空', []);
}

// 3) 查询 money（balance_changes）
$money = [];
if ($mode === 'all' || $mode === 'money') {
    $sql = "SELECT id, user_id, change_amount, balance_before, balance_after, change_type, description, payment_channel, created_at
            FROM balance_changes
            WHERE user_id = ? ";
    $types = "s";
    $params = [$user_id];

    if ($change_type !== '') {
        $sql .= " AND change_type = ? ";
        $types .= "s";
        $params[] = $change_type;
    }

    if ($start_date) {
        $sql .= " AND created_at >= ? ";
        $types .= "s";
        $params[] = $start_date;
    }

    if ($end_date) {
        $sql .= " AND created_at <= ? ";
        $types .= "s";
        $params[] = $end_date;
    }

    $sql .= " ORDER BY created_at DESC LIMIT ? ";
    $types .= "i";
    $params[] = $limit;

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        json_response(500, 'SQL prepare failed: balance_changes', []);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $money[] = [
            'kind'       => 'money',
            'id'         => (int)$row['id'],
            'user_id'    => (string)$row['user_id'],
            'amount'     => (float)$row['change_amount'],
            'before'     => (float)$row['balance_before'],
            'after'      => (float)$row['balance_after'],
            'type'       => (string)$row['change_type'],
            'desc'       => (string)($row['description'] ?? ''),
            'channel'    => (string)($row['payment_channel'] ?? ''),
            'created_at' => (string)$row['created_at'],
        ];
    }

    $stmt->close();
}

// 4) 查询 energy（energy_changes）
$energy = [];
if ($mode === 'all' || $mode === 'energy') {
    $sql = "SELECT id, user_uid, venue_id, energy_change, balance_before_change, balance_after_change, reason, created_at
            FROM energy_changes
            WHERE user_uid = ? ";
    $types = "s";
    $params = [$user_uid];

    // 非平台管理员：限制场地
if (!in_array($role_id, [1, 2]) && $myVenueId > 0) {
        $sql .= " AND venue_id = ? ";
        $types .= "i";
        $params[] = $myVenueId;
    }

    if ($start_date) {
        $sql .= " AND created_at >= ? ";
        $types .= "s";
        $params[] = $start_date;
    }

    if ($end_date) {
        $sql .= " AND created_at <= ? ";
        $types .= "s";
        $params[] = $end_date;
    }

    $sql .= " ORDER BY created_at DESC LIMIT ? ";
    $types .= "i";
    $params[] = $limit;

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        json_response(500, 'SQL prepare failed: energy_changes', []);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $energy[] = [
            'kind'       => 'energy',
            'id'         => (int)$row['id'],
            'user_uid'   => (string)$row['user_uid'],
            'venue_id'   => (int)$row['venue_id'],
            'amount'     => (float)$row['energy_change'],
            'before'     => (float)$row['balance_before_change'],
            'after'      => (float)$row['balance_after_change'],
            'reason'     => (string)($row['reason'] ?? ''),
            'created_at' => (string)$row['created_at'],
        ];
    }

    $stmt->close();
}

// 5) 查询 gold（gold_balance_changes）
$gold = [];
if ($mode === 'all' || $mode === 'gold') {
    $sql = "SELECT id, uid, change_type, change_amount, balance_before, balance_after, biz_type, biz_id, biz_no, remark, operator_id, created_at
            FROM gold_balance_changes
            WHERE uid = ? ";
    $types = "s";
    $params = [$user_id];

    if ($gold_change_type !== '') {
        $sql .= " AND change_type = ? ";
        $types .= "s";
        $params[] = $gold_change_type;
    }

    if ($start_date) {
        $sql .= " AND created_at >= ? ";
        $types .= "s";
        $params[] = $start_date;
    }

    if ($end_date) {
        $sql .= " AND created_at <= ? ";
        $types .= "s";
        $params[] = $end_date;
    }

    $sql .= " ORDER BY created_at DESC LIMIT ? ";
    $types .= "i";
    $params[] = $limit;

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        json_response(500, 'SQL prepare failed: gold_balance_changes', []);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $gold[] = [
            'kind'        => 'gold',
            'id'          => (int)$row['id'],
            'uid'         => (string)$row['uid'],
            'amount'      => (int)$row['change_amount'],
            'before'      => (int)$row['balance_before'],
            'after'       => (int)$row['balance_after'],
            'type'        => (string)$row['change_type'],
            'biz_type'    => (string)($row['biz_type'] ?? ''),
            'biz_id'      => isset($row['biz_id']) ? ($row['biz_id'] === null ? null : (int)$row['biz_id']) : null,
            'biz_no'      => (string)($row['biz_no'] ?? ''),
            'remark'      => (string)($row['remark'] ?? ''),
            'operator_id' => isset($row['operator_id']) ? ($row['operator_id'] === null ? null : (int)$row['operator_id']) : null,
            'created_at'  => (string)$row['created_at'],
        ];
    }

    $stmt->close();
}

// 6) 查询 礼物背包变动（user_gift_backpack_changes）
$backpack = [];
if ($mode === 'all' || $mode === 'backpack') {
    $sql = "SELECT id, uid, backpack_id, related_order_id, gift_id, gift_name, change_quantity,
                   balance_before_change, balance_after_change, change_type, reason, operator_id, created_at
            FROM user_gift_backpack_changes
            WHERE uid = ? ";
    $types = "s";
    $params = [$user_id];

    $backpackTypeAliasMap = [
        'obtain'       => '获取',
        'use'          => '使用',
        'expire'       => '过期',
        'delete'       => '删除',
        'admin_add'    => '后台增加',
        'admin_deduct' => '后台扣减',
    ];

    if ($backpack_change_type !== '') {
        $sql .= " AND (change_type = ? ";
        $types .= "s";
        $params[] = $backpack_change_type;

        if (isset($backpackTypeAliasMap[$backpack_change_type])) {
            $sql .= " OR change_type = ? ";
            $types .= "s";
            $params[] = $backpackTypeAliasMap[$backpack_change_type];
        }

        $sql .= ") ";
    }

    if ($start_date) {
        $sql .= " AND created_at >= ? ";
        $types .= "s";
        $params[] = $start_date;
    }

    if ($end_date) {
        $sql .= " AND created_at <= ? ";
        $types .= "s";
        $params[] = $end_date;
    }

    $sql .= " ORDER BY created_at DESC LIMIT ? ";
    $types .= "i";
    $params[] = $limit;

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        json_response(500, 'SQL prepare failed: user_gift_backpack_changes', []);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $backpack[] = [
            'kind'             => 'backpack',
            'id'               => (int)$row['id'],
            'uid'              => (string)$row['uid'],
            'backpack_id'      => isset($row['backpack_id']) ? ($row['backpack_id'] === null ? null : (int)$row['backpack_id']) : null,
            'related_order_id' => (string)($row['related_order_id'] ?? ''),
            'gift_id'          => (int)$row['gift_id'],
            'gift_name'        => (string)($row['gift_name'] ?? ''),
            'change_quantity'  => (int)$row['change_quantity'],
            'before'           => (int)$row['balance_before_change'],
            'after'            => (int)$row['balance_after_change'],
            'type'             => (string)($row['change_type'] ?? ''),
            'reason'           => (string)($row['reason'] ?? ''),
            'operator_id'      => isset($row['operator_id']) ? ($row['operator_id'] === null ? null : (int)$row['operator_id']) : null,
            'created_at'       => (string)$row['created_at'],
        ];
    }

    $stmt->close();
}

// 7) 合并排序
$merged = array_merge($money, $energy, $gold, $backpack);
usort($merged, function ($a, $b) {
    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});

json_response(200, '查询成功', [
    'mode'     => $mode,
    'money'    => $money,
    'energy'   => $energy,
    'gold'     => $gold,
    'backpack' => $backpack,
    'merged'   => $merged,
]);