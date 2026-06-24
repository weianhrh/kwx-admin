<?php
require_once '../Database.php';
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) { echo json_encode(['code'=>1001,'msg'=>'用户未登录或会话已过期','data'=>[]]); exit; }
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) { echo json_encode(['code'=>1001,'msg'=>'用户未登录或无权访问','data'=>[]]); exit; }

/* ===== 固定规则：不接收参数 ===== */
$THRESHOLD_RATE   = 3.0;    // 高费率阈值：>3 元/分钟
$MIN_MINUTES      = 1;      // 过滤极短分钟套餐（可按需改为 3/5）
$ONLY_PRICINGTYPE = '';     // 仅筛“按次计费”时填 '按次计费'，否则留空

$conn = $database->getConnection();

/* 每个场地取最高单价并筛选 >2 */
$sql = "SELECT v.id,
               v.venue_name,
               ROUND(MAX(p.Battery / NULLIF(p.Minutes, 0)), 4) AS max_rate
        FROM PricingOptions p
        INNER JOIN venues v ON v.id = p.BindLocation
        WHERE p.Minutes > 0";

$types  = "";
$params = [];

/* 可选：仅统计某计费类型 */
if ($ONLY_PRICINGTYPE !== '') {
    $sql    .= " AND p.PricingType = ?";
    $types  .= "s";
    $params[] = $ONLY_PRICINGTYPE;
}

/* 过滤极短时长 */
if ($MIN_MINUTES > 1) {
    $sql    .= " AND p.Minutes >= ?";
    $types  .= "i";
    $params[] = $MIN_MINUTES;
}

$sql .= " GROUP BY v.id, v.venue_name
          HAVING max_rate > ?
          ORDER BY max_rate DESC";

$types  .= "d";
$params[] = $THRESHOLD_RATE;

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['code'=>2,'msg'=>'SQL 预处理失败: '.$conn->error,'data'=>[]]);
    exit;
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
$names = [];
while ($row = $res->fetch_assoc()) {
    $items[] = [
        'id'         => (int)$row['id'],
        'venue_name' => $row['venue_name'],
        'max_rate'   => (float)$row['max_rate'],
    ];
    $names[] = $row['venue_name'];
}
$stmt->close();
$database->close();

$names = array_values(array_unique($names));

echo json_encode([
    'code' => 0,
    'msg'  => '',
    'data' => [
        'threshold' => $THRESHOLD_RATE,
        'names'     => $names,   // 前端直接用来高亮
        'items'     => $items    // 便于调试：含每场地 max_rate
    ]
]);
