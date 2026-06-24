<?php
// /api/operat/latest_device_run.php
require_once '../Database.php';
header('Content-Type: application/json; charset=utf-8');

// ───────────────────────── helper ─────────────────────────
function jerr($msg, $code = 1001) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'count' => 0, 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
function jok($data) {
    if (!is_array($data)) $data = [];
    echo json_encode(['code' => 0, 'msg' => 'ok', 'count' => count($data), 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function map_pay_type($raw) {
    if ($raw === null || $raw === '') return null;
    $t = trim(strtolower($raw));
    if (in_array($t, ['wechat','weixin','wx','wxpay','微信'])) return '微信';
    if (in_array($t, ['alipay','zfb','支付宝'])) return '支付宝';
    if (in_array($t, ['balance','wallet','余额'])) return '余额';
    if (in_array($t, ['free','gift','赠送'])) return '赠送';
    return $raw; // 保留原值
}

// ───────────────────────── auth ─────────────────────────
$database = new Database();

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) jerr('无有效认证信息，请登录');

$admin = $database->getUserBySessionToken($session_token);
if (!$admin) jerr('用户未登录或不存在');

$role_id       = (int)($admin['role_id'] ?? 0);
$bind_venue_id = (int)($admin['venue_id'] ?? 0);

// 超管允许用 ?id 指定场地，否则用绑定场地
$req_venue_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$venue_id     = (in_array($role_id, [1, 2], true)) ? ($req_venue_id ?: $bind_venue_id) : $bind_venue_id;

if ($venue_id <= 0) jerr('未绑定场地，无法查询');

// 可选过滤：?only_active=1 仅返回“使用中”的；?only_has_order=1 仅返回有最新订单的
$only_active    = isset($_GET['only_active'])    && $_GET['only_active'] == '1';
$only_has_order = isset($_GET['only_has_order']) && $_GET['only_has_order'] == '1';

// ───────────────────────── query ─────────────────────────
// 说明：
// - 以 vehicles.serial_number 作为设备唯一键
// - 在 orders 中取“该场地下每台设备的最新一笔（最大 start_time）” → o2
// - 订单金额：取 o2.payment_amount（你要的就是这笔订单的金额，不做累计）
// - 驾驶开始/结束：优先 Reservations.driving_*，否则回落到 o2.start_time
// - 操作状态：未结束 → 使用中；否则 空闲
$sql = "
SELECT
  vn.venue_name                                 AS venue_name,
  v.`name`                                      AS device_name,
  CASE
    WHEN LOWER(v.`status`) IN ('offline','shutdown','离线') THEN '离线'
    WHEN LOWER(v.`status`) IN ('occupied','占有','占用','busy') THEN '占用'
    ELSE '在线'
  END                                           AS online_status,
  CASE
    WHEN r.driving_start_time IS NOT NULL AND r.driving_end_time IS NULL THEN '使用中'
    WHEN r.driving_start_time IS NULL AND o2.order_id IS NOT NULL AND o2.end_time IS NULL THEN '使用中'
    WHEN LOWER(v.`status`) IN ('occupied','占有','占用','busy') THEN '使用中'
    ELSE '空闲'
  END                                           AS operation_status,
  u.nickname                                    AS driver_nickname,
  COALESCE(r.driving_start_time, o2.start_time) AS start_time,
  r.driving_end_time                            AS end_time,
  CASE
    WHEN COALESCE(r.driving_start_time, o2.start_time) IS NULL THEN NULL
    WHEN r.driving_end_time IS NULL THEN TIMESTAMPDIFF(SECOND, COALESCE(r.driving_start_time, o2.start_time), NOW())
    ELSE TIMESTAMPDIFF(SECOND, COALESCE(r.driving_start_time, o2.start_time), r.driving_end_time)
  END                                           AS duration_seconds,
  o2.payment_amount                             AS order_amount,
  COALESCE(r.pay_type, o2.pay_type)             AS pay_type,
  v.serial_number                               AS serial_number,
  v.id                                          AS vehicle_id
FROM vehicles v
JOIN venues vn ON vn.id = v.bind_site
LEFT JOIN (
  SELECT o1.*
  FROM orders o1
  JOIN (
    SELECT o.serial_number, MAX(o.start_time) AS max_start_time
    FROM orders o
    JOIN vehicles v2 ON v2.serial_number = o.serial_number
    WHERE v2.bind_site = ?
    GROUP BY o.serial_number
  ) mx
    ON mx.serial_number  = o1.serial_number
   AND mx.max_start_time = o1.start_time
) o2 ON o2.serial_number = v.serial_number
LEFT JOIN Reservations r
       ON r.order_number = o2.order_id
      AND (r.order_status IS NULL OR r.order_status <> '已取消')
LEFT JOIN users u ON u.uid = o2.uid
WHERE vn.id = ?
";


$tail = [];
if ($only_has_order) { $tail[] = "o2.order_id IS NOT NULL"; }
if ($only_active)    { $tail[] = "( (r.driving_start_time IS NOT NULL AND r.driving_end_time IS NULL) OR (r.driving_start_time IS NULL AND o2.order_id IS NOT NULL AND o2.end_time IS NULL) )"; }
if ($tail) {
    $sql .= " AND " . implode(" AND ", $tail);
}
$sql .= " ORDER BY v.id DESC";

// 执行
$params = [ (string)$venue_id, (string)$venue_id ]; // 子查询 WHERE v2.bind_site = ? 以及 外层 WHERE vn.id = ?
$rows = $database->query($sql, $params);
if ($rows === false) {
    $database->logToFile("latest_device_run SQL failed for venue_id={$venue_id}");
    jerr('查询失败，请检查表结构/索引/数据是否一致');
}

// 清洗支付方式
// 清洗支付方式
foreach ($rows as &$r) {
    $r['pay_type'] = map_pay_type($r['pay_type'] ?? null);
}
unset($r);

// 直接把 role_id 和 venue_id 一起返回
echo json_encode([
    'code'     => 0,
    'msg'      => 'ok',
    'role_id'  => $role_id,   // ← 新增
    'venue_id' => $venue_id,  // ← 新增（本次查询实际使用的场地ID）
    'count'    => count($rows),
    'data'     => $rows,
], JSON_UNESCAPED_UNICODE);
exit;

