<?php
// admin/recharge_orders_api.php

require_once '../Database.php';
$database = new Database();

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Taipei');

function out_json($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== 登录鉴权 =====
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    out_json(1001, '用户未登录或会话已过期', []);
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || empty($user['role_id'])) {
    out_json(1001, '用户未登录或无权访问', []);
}

// ===== 分页 =====
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page <= 0) $page = 1;

$page_size = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 30;
if ($page_size <= 0) $page_size = 10;
if ($page_size > 200) $page_size = 200;

// ===== 时间处理 =====
function norm_time($s, $isEnd = false) {
    $s = trim((string)$s);
    if ($s === '') return null;

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $isEnd ? ($s . ' 23:59:59') : ($s . ' 00:00:00');
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $s)) {
        return $s;
    }

    return false;
}

$start_time = norm_time($_GET['start_time'] ?? '', false);
$end_time   = norm_time($_GET['end_time'] ?? '', true);

if ($start_time === false) out_json(400, 'start_time 格式错误');
if ($end_time === false)   out_json(400, 'end_time 格式错误');

// 默认今天
if ($start_time === null && $end_time === null) {
    $today = date('Y-m-d');
    $start_time = $today . ' 00:00:00';
    $end_time   = $today . ' 23:59:59';
} else {
    if ($start_time === null) {
        $d = substr($end_time, 0, 10);
        $start_time = $d . ' 00:00:00';
    }
    if ($end_time === null) {
        $d = substr($start_time, 0, 10);
        $end_time = $d . ' 23:59:59';
    }
}

if (strtotime($start_time) > strtotime($end_time)) {
    out_json(400, 'start_time 不能大于 end_time');
}
// ===== 类型筛选：默认全部 =====
// all / battery / gold
$recharge_type = trim((string)($_GET['recharge_type'] ?? 'all'));

$allowTypes = ['all', 'battery', 'gold'];

if (!in_array($recharge_type, $allowTypes, true)) {
    $recharge_type = 'all';
}
// ===== 支付渠道筛选：默认全部 =====
// all / 微信 / 支付宝 / 拉卡拉微信 / 苹果IAP / 苹果IAP(Sandbox)
$payment_channel = trim((string)($_GET['payment_channel'] ?? 'all'));

$allowChannels = [
    'all',
    '微信',
    '支付宝',
    '拉卡拉微信',
    '苹果IAP',
    '苹果IAP(Sandbox)',
];

if (!in_array($payment_channel, $allowChannels, true)) {
    $payment_channel = 'all';
}

// ===== 数据库名 =====
$dbName = '5grc';

/**
 * 构建“电池 + 金币”的统一子查询
 */
function build_union_sql($dbName, $startTime, $endTime, $rechargeType = 'all') {
    $parts = [];
    $params = [];

    // 1) 电池充值：RechargeOrders
    if ($rechargeType === 'all' || $rechargeType === 'battery') {
        $parts[] = "
            SELECT
                CONCAT('battery_', r.id) AS row_key,
                r.id AS id,
                r.uid AS uid,
                r.order_number AS order_number,
                r.product_name AS product_name,
                CONCAT(COALESCE(NULLIF(r.value, ''), '0'), '电池') AS value,
                CAST(COALESCE(NULLIF(r.payer_total, ''), '0') AS DECIMAL(10,2)) AS payer_total_num,
                COALESCE(r.payer_total, '0.00') AS payer_total,
                COALESCE(NULLIF(r.payment_channel, ''), NULLIF(r.third_party, ''), '') AS payment_channel,
                r.status AS status,
                r.created_at AS created_at,
                r.paid_at AS paid_at,
                '电池' AS recharge_type,
                r.created_at AS sort_time
            FROM {$dbName}.RechargeOrders r
            WHERE r.status = '支付成功'
              AND r.created_at >= ?
              AND r.created_at <= ?
        ";
        $params[] = $startTime;
        $params[] = $endTime;
    }

    // 2) 金币充值：apple_iap_orders + iap_gold_products
    if ($rechargeType === 'all' || $rechargeType === 'gold') {
        $parts[] = "
            SELECT
                CONCAT('gold_', a.id) AS row_key,
                a.id AS id,
                a.uid AS uid,
                COALESCE(
                    NULLIF(a.transaction_id, ''),
                    NULLIF(a.purchase_id, ''),
                    NULLIF(a.original_transaction_id, ''),
                    CONCAT('IAP_', a.id)
                ) AS order_number,
                COALESCE(
                    p.product_name,
                    CONCAT('金币充值', COALESCE(a.total_gold, 0), '金币')
                ) AS product_name,
                CONCAT(COALESCE(a.total_gold, 0), '金币') AS value,
                CAST(COALESCE(p.price, 0) AS DECIMAL(10,2)) AS payer_total_num,
                CAST(COALESCE(p.price, 0) AS CHAR) AS payer_total,
                CASE
                    WHEN a.environment = 'Sandbox' THEN '苹果IAP(Sandbox)'
                    ELSE '苹果IAP'
                END AS payment_channel,
                '支付成功' AS status,
                a.created_at AS created_at,
                COALESCE(a.purchase_date, a.created_at) AS paid_at,
                '金币' AS recharge_type,
                COALESCE(a.purchase_date, a.created_at) AS sort_time
            FROM {$dbName}.apple_iap_orders a
            LEFT JOIN {$dbName}.iap_gold_products p
                ON p.product_id = a.product_id
            WHERE a.order_status = 'success'
              AND a.verify_status = 1
              AND COALESCE(a.purchase_date, a.created_at) >= ?
              AND COALESCE(a.purchase_date, a.created_at) <= ?
        ";
        $params[] = $startTime;
        $params[] = $endTime;
    }

    if (empty($parts)) {
        return [false, []];
    }

    $sql = implode("\nUNION ALL\n", $parts);
    return [$sql, $params];
}

list($unionSql, $unionParams) = build_union_sql($dbName, $start_time, $end_time, $recharge_type);
// ===== 外层筛选条件：支付渠道 =====
$outerWhere = '';
$outerParams = [];

if ($payment_channel !== 'all') {
    $outerWhere = " WHERE t.payment_channel = ? ";
    $outerParams[] = $payment_channel;
}

$queryParams = array_merge($unionParams, $outerParams);
if ($unionSql === false) {
    out_json(500, '未生成查询SQL', []);
}

// ===== 统计总条数 =====
$countSql = "SELECT COUNT(*) AS c FROM ({$unionSql}) t {$outerWhere}";
$countRes = $database->query($countSql, $queryParams);
if ($countRes === false || !isset($countRes[0]['c'])) {
    out_json(500, '统计失败', []);
}

$total = (int)$countRes[0]['c'];
$total_pages = (int)ceil($total / $page_size);
if ($total_pages <= 0) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;

$offset = ($page - 1) * $page_size;

// ===== 当前筛选区间累计金额 =====
$sumSql = "SELECT IFNULL(SUM(t.payer_total_num), 0) AS s FROM ({$unionSql}) t {$outerWhere}";
$sumRes = $database->query($sumSql, $queryParams);
$range_sum = ($sumRes !== false && isset($sumRes[0]['s'])) ? (float)$sumRes[0]['s'] : 0.0;

// ===== 今日累计金额：电池充值，按 created_at =====
$today = date('Y-m-d');
$today_start = $today . ' 00:00:00';
$today_end   = $today . ' 23:59:59';

$todayBatterySumSql = "
    SELECT IFNULL(SUM(CAST(NULLIF(payer_total, '') AS DECIMAL(10,2))), 0) AS s
    FROM {$dbName}.RechargeOrders
    WHERE status = '支付成功'
      AND created_at >= ?
      AND created_at <= ?
";
$todaySumRes = $database->query($todayBatterySumSql, [$today_start, $today_end]);
$today_sum = ($todaySumRes !== false && isset($todaySumRes[0]['s']))
    ? (float)$todaySumRes[0]['s']
    : 0.0;

// ===== 列表 =====
$listSql = "
    SELECT
        t.row_key,
        t.id,
        t.uid,
        t.order_number,
        t.product_name,
        t.value,
        t.payer_total,
        t.payment_channel,
        t.status,
        t.created_at,
        t.paid_at,
        t.recharge_type
    FROM ({$unionSql}) t
    {$outerWhere}
    ORDER BY t.sort_time DESC, t.id DESC
    LIMIT {$page_size} OFFSET {$offset}
";

$rows = $database->query($listSql, $queryParams);
if ($rows === false) {
    out_json(500, '查询失败', []);
}

out_json(0, 'ok', [
    'start_time'    => $start_time,
    'end_time'      => $end_time,
    'page'          => $page,
    'page_size'     => $page_size,
    'total'         => $total,
    'total_pages'   => $total_pages,
    'recharge_type' => $recharge_type,
    'payment_channel' => $payment_channel,
    'rows'          => $rows,
    'range_sum'     => $range_sum,
    'today_sum'     => $today_sum,
]);