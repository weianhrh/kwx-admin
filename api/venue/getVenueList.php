<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../Database.php'; // 引入数据库连接类
require_once '../lib/venue_scope.php';

// 日志记录函数 
function logMessage($message) {
    $logFile = __DIR__ . '/operation_log.txt';
    if ((file_exists($logFile) && !is_writable($logFile)) || (!file_exists($logFile) && !is_writable(__DIR__))) {
        return;
    }

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
}

$database = new Database();

// 获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;
logMessage("Session token: " . ($session_token ?? 'null'));

if (!$session_token) {
    logMessage("未提供 session_token，返回登录错误");
    echo json_encode(['code' => 1001, 'msg' => '无有效认证信息，请登录', 'count' => 0, 'data' => []]);
    exit;
}

// 查询用户权限和绑定场地
$sql = "SELECT role_id, venue_id FROM admin_users WHERE session_token = ?";
$user = $database->query($sql, [$session_token]);

if (!$user) {
    logMessage("session_token 查询用户失败");
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或不存在', 'count' => 0, 'data' => []]);
    $database->close();
    exit;
}

// 提取角色和场地
$role_id = $user[0]['role_id'];
$bind_venue_id = $user[0]['venue_id'];
$get_venue_id = $_GET['id'] ?? null;
$current_user = $user[0];

// 记录请求信息
logMessage("用户角色: $role_id, 用户绑定场地: $bind_venue_id, 请求 id: " . var_export($get_venue_id, true));

// 构造 SQL 和参数
$params = [];
$venueFields = "id, venue_name, image_url, venue_description, venue_tags, venue_type, event_id, start_time, queue_length, live_stream_url, show_live_stream, venue_status, income_30d_lock";
$where = [];

if ($role_id == 1 || $role_id == 2) {
    if (!empty($get_venue_id) && is_numeric($get_venue_id)) {
        // 管理员传了有效 id，就查指定场地
        $where[] = "id = ?";
        $params[] = $get_venue_id;
    } elseif (!empty($bind_venue_id)) {
        // 管理员未传 id，但有绑定场地，只查绑定场地
        $where[] = "id = ?";
        $params[] = $bind_venue_id;
    }
} else {
    // 加盟商可查看自己名下多个场地；场地管理员仍然只看自己的场地
    $requestedVenueId = venue_scope_requested_id(['venue_id' => $get_venue_id]);
    $scopeParams = [];
    $scopeSql = venue_scope_apply_filter($database, $current_user, 'id', $scopeParams, $requestedVenueId);
    if ($scopeSql !== '') {
        $where[] = preg_replace('/^\s*AND\s+/i', '', trim($scopeSql));
        $params = array_merge($params, $scopeParams);
    }
}

$sql = "SELECT $venueFields FROM venues";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

logMessage("执行 SQL: $sql");
logMessage("参数: " . json_encode($params));

// 查询数据库
$result = $database->query($sql, $params);

if ($result) {
    logMessage("查询成功，返回 " . count($result) . " 条场地记录");
    echo json_encode([
        'code' => 0,
        'msg1' => $bind_venue_id,
        'count' => count($result),
        'data' => $result
    ]);
} else {
    logMessage("查询失败或无结果");
    echo json_encode([
        'code' => 1,
        'msg' => '获取场地列表失败',
        'count' => 0,
        'data' => []
    ]);
}

$database->close();
?>
