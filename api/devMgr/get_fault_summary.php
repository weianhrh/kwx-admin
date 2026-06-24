<?php
require_once '../Database.php';

$database = new Database();
$conn = $database->getConnection();

// 获取 session 用户信息
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未登录']);
    exit;
}
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1002, 'msg' => '无权限']);
    exit;
}

// 时间参数
$start = isset($_GET['start']) ? str_replace('T', ' ', $_GET['start']) : date('Y-m-d 00:00:00');
$end   = isset($_GET['end'])   ? str_replace('T', ' ', $_GET['end'])   : date('Y-m-d 23:59:59');
if ($end < $start) {
    echo json_encode(['code' => 1004, 'msg' => '时间范围不合法']);
    exit;
}

// 场地权限
$role_id = $user['role_id'];
// $venue_id = ($role_id == 1) ? ($_GET['venue_id'] ?? null) : $user['venue_id'];改为
$venue_id = (in_array($role_id, [1, 2], true)
    && isset($_GET['venue_id'])
    && (int)$_GET['venue_id'] > 0
)
    ? (int)$_GET['venue_id']
    : (int)$user['venue_id'];

if (!$venue_id) {
    echo json_encode(['code' => 1003, 'msg' => '缺少场地参数']);
    exit;
}

// 查询语句
$sql = "SELECT f.serial_number, f.fault_reason, f.user_id, v.name AS vehicle_name
        FROM FaultRecords f
        INNER JOIN vehicles v ON f.serial_number = v.serial_number
        WHERE f.created_at BETWEEN ? AND ?
        AND v.bind_site = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $start, $end, $venue_id);
$stmt->execute();
$result = $stmt->get_result();

// 聚合
$vehicle_faults = [];

while ($row = $result->fetch_assoc()) {
    $serial = $row['serial_number'];
    $vehicle_name = $row['vehicle_name'];
    $reason = $row['fault_reason'];
    $user_id = $row['user_id'];

    if (!isset($vehicle_faults[$serial])) {
        $vehicle_faults[$serial] = [
            'serial_number' => $serial,
            'vehicle_name' => $vehicle_name,
            'fault_reasons' => [],
            'reason_count' => [],
            'report_users' => []
        ];
    }

    // 故障原因聚合
    if (!in_array($reason, $vehicle_faults[$serial]['fault_reasons'])) {
        $vehicle_faults[$serial]['fault_reasons'][] = $reason;
    }
    $vehicle_faults[$serial]['reason_count'][$reason] = 
        ($vehicle_faults[$serial]['reason_count'][$reason] ?? 0) + 1;

    // 用户举报统计
    $vehicle_faults[$serial]['report_users'][$user_id] = 
        ($vehicle_faults[$serial]['report_users'][$user_id] ?? 0) + 1;
}

// 构造输出
$output = [];
foreach ($vehicle_faults as $fault) {
    // 格式化举报人：[举报ID:xxx, 举报次数:x]
    $report_list = [];
    foreach ($fault['report_users'] as $uid => $count) {
        $report_list[] = [
            'user_id' => $uid,
            'count' => $count
        ];
    }


    $output[] = [
        'vehicle_name' => $fault['vehicle_name'],
        'serial_number' => $fault['serial_number'],
        'fault_reasons' => implode('，', $fault['fault_reasons']),
        'reason_count' => $fault['reason_count'],
        'report_users' => $report_list
    ];
}

header('Content-Type: application/json');
echo json_encode(['code' => 0, 'data' => $output]);
