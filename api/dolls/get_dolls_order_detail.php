<?php
require_once '../Database.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Singapore');

// 日志
function logMessage_log($message) {
    $logFile = __DIR__ . '/claw_orders_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

function jsonOut($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizeDateTime($value) {
    $value = trim((string)$value);
    if ($value === '') return '';

    $value = str_replace('T', ' ', $value);

    // 2026-04-02
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value . ' 00:00:00';
    }

    // 2026-04-02 12:30
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        return $value . ':00';
    }

    // 2026-04-02 12:30:45
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
        return $value;
    }

    return '';
}

$database = new Database();

// 会话校验
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    jsonOut(1001, '用户未登录或会话已过期');
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    jsonOut(1001, '用户未登录或无权访问');
}

try {
    $conn = $database->getConnection();

    // 可选：按单个设备筛选
    $serial_number = trim($_GET['serial_number'] ?? '');

    // 时间筛选：
    // 默认当天
    // 也支持传 start_time / end_time
    // 例如：
    // ?start_time=2026-04-02 00:00:00&end_time=2026-04-02 23:59:59
    $start_time = normalizeDateTime($_GET['start_time'] ?? '');
    $end_time   = normalizeDateTime($_GET['end_time'] ?? '');

    if ($start_time === '' && $end_time === '') {
        $start_time = date('Y-m-d 00:00:00');
        $end_time   = date('Y-m-d 23:59:59');
    } elseif ($start_time !== '' && $end_time === '') {
        // 只传开始时间，默认到当天 23:59:59
        $end_time = date('Y-m-d 23:59:59', strtotime($start_time));
    } elseif ($start_time === '' && $end_time !== '') {
        // 只传结束时间，默认从当天 00:00:00 开始
        $start_time = date('Y-m-d 00:00:00', strtotime($end_time));
    }

    if ($start_time === '' || $end_time === '') {
        jsonOut(400, '时间格式错误');
    }

    $noteLike = '%娃娃机抓取扣费%';

    // 公共 where
    $where = "
        WHERE o.note LIKE ?
          AND o.start_time >= ?
          AND o.start_time <= ?
    ";
    $params = [$noteLike, $start_time, $end_time];
    $types  = 'sss';

    if ($serial_number !== '') {
        $where .= " AND o.serial_number = ? ";
        $params[] = $serial_number;
        $types .= 's';
    }

    // 1) 订单列表
$listTypes  = 'ss' . $types;
$listParams = array_merge([$start_time, $end_time], $params);

$sqlList = "
    SELECT
        o.order_id,
        o.uid,
        o.serial_number,
        o.payment_amount,
        o.start_time,
        o.end_time,
        o.status,
        o.note,
        COALESCE(NULLIF(cmr.product_name, ''), v.name) AS vehicle_name,
        COALESCE(NULLIF(cmr.product_image, ''), v.photo_url) AS photo_url,
        u.nickname,
        u.headimgurl,
        u.wallet,
        COALESCE(ud_sel.selected_catch_count, 0) AS selected_catch_count,
        COALESCE(ud_total.total_catch_count, 0) AS total_catch_count
    FROM orders o
    LEFT JOIN vehicles v ON v.serial_number = o.serial_number
    LEFT JOIN claw_machine_records cmr ON cmr.serial_number = o.serial_number
    LEFT JOIN users u ON u.uid = o.uid
    LEFT JOIN (
        SELECT
            user_id,
            COUNT(*) AS selected_catch_count
        FROM user_doll_detail
        WHERE created_at >= ?
          AND created_at <= ?
        GROUP BY user_id
    ) ud_sel ON ud_sel.user_id = o.uid
    LEFT JOIN (
        SELECT
            user_id,
            COUNT(*) AS total_catch_count
        FROM user_doll_detail
        GROUP BY user_id
    ) ud_total ON ud_total.user_id = o.uid
    $where
    ORDER BY o.start_time DESC, o.order_id DESC
";

$stmtList = $conn->prepare($sqlList);
if (!$stmtList) {
    throw new Exception('订单列表 SQL 预处理失败：' . $conn->error);
}
$stmtList->bind_param($listTypes, ...$listParams);
$stmtList->execute();
    $resultList = $stmtList->get_result();

    $list = [];
    $totalAmount = 0.00;

while ($row = $resultList->fetch_assoc()) {
    $row['payment_amount'] = (float)$row['payment_amount'];
    $row['wallet'] = isset($row['wallet']) ? (float)$row['wallet'] : 0.00;
    $row['selected_catch_count'] = (int)($row['selected_catch_count'] ?? 0);
    $row['total_catch_count'] = (int)($row['total_catch_count'] ?? 0);
    $list[] = $row;
    $totalAmount += $row['payment_amount'];
}
    $stmtList->close();

    // 2) 当期消费最大用户
$topTypes  = 'ss' . $types;
$topParams = array_merge([$start_time, $end_time], $params);

$sqlTopUser = "
    SELECT
        o.uid,
        MAX(u.nickname) AS nickname,
        MAX(u.headimgurl) AS headimgurl,
        MAX(u.wallet) AS wallet,
        COUNT(*) AS order_count,
        COALESCE(SUM(o.payment_amount), 0) AS total_spent,
        MAX(COALESCE(ud_sel.selected_catch_count, 0)) AS selected_catch_count,
        MAX(COALESCE(ud_total.total_catch_count, 0)) AS total_catch_count
    FROM orders o
    LEFT JOIN users u ON u.uid = o.uid
    LEFT JOIN (
        SELECT
            user_id,
            COUNT(*) AS selected_catch_count
        FROM user_doll_detail
        WHERE created_at >= ?
          AND created_at <= ?
        GROUP BY user_id
    ) ud_sel ON ud_sel.user_id = o.uid
    LEFT JOIN (
        SELECT
            user_id,
            COUNT(*) AS total_catch_count
        FROM user_doll_detail
        GROUP BY user_id
    ) ud_total ON ud_total.user_id = o.uid
    $where
    GROUP BY o.uid
    ORDER BY total_spent DESC, order_count DESC, o.uid DESC
    LIMIT 1
";

$stmtTop = $conn->prepare($sqlTopUser);
if (!$stmtTop) {
    throw new Exception('消费最大用户 SQL 预处理失败：' . $conn->error);
}
$stmtTop->bind_param($topTypes, ...$topParams);
$stmtTop->execute();
    $resultTop = $stmtTop->get_result();

    $topUser = null;
if ($resultTop && $resultTop->num_rows > 0) {
    $topUser = $resultTop->fetch_assoc();
    $topUser['wallet'] = isset($topUser['wallet']) ? (float)$topUser['wallet'] : 0.00;
    $topUser['order_count'] = (int)$topUser['order_count'];
    $topUser['total_spent'] = (float)$topUser['total_spent'];
    $topUser['selected_catch_count'] = (int)($topUser['selected_catch_count'] ?? 0);
    $topUser['total_catch_count'] = (int)($topUser['total_catch_count'] ?? 0);
}
    $stmtTop->close();

    // 3) 设备总收益
    // 如果传了 serial_number，就返回该设备汇总
    // 不传，就返回设备收益排行
    $deviceSummary = null;
    $deviceRevenueList = [];

    if ($serial_number !== '') {
        $sqlDevice = "
            SELECT
                o.serial_number,
MAX(COALESCE(NULLIF(cmr.product_name, ''), v.name)) AS vehicle_name,
MAX(COALESCE(NULLIF(cmr.product_image, ''), v.photo_url)) AS photo_url,
                COUNT(*) AS order_count,
                COALESCE(SUM(o.payment_amount), 0) AS total_revenue
            FROM orders o
            LEFT JOIN vehicles v ON v.serial_number = o.serial_number
            LEFT JOIN claw_machine_records cmr ON cmr.serial_number = o.serial_number
            $where
            GROUP BY o.serial_number
            LIMIT 1
        ";

        $stmtDevice = $conn->prepare($sqlDevice);
        if (!$stmtDevice) {
            throw new Exception('设备收益统计 SQL 预处理失败：' . $conn->error);
        }
        $stmtDevice->bind_param($types, ...$params);
        $stmtDevice->execute();
        $resultDevice = $stmtDevice->get_result();

        if ($resultDevice && $resultDevice->num_rows > 0) {
            $deviceSummary = $resultDevice->fetch_assoc();
            $deviceSummary['order_count'] = (int)$deviceSummary['order_count'];
            $deviceSummary['total_revenue'] = (float)$deviceSummary['total_revenue'];
        }
        $stmtDevice->close();
    } else {
        $sqlDeviceRank = "
            SELECT
                o.serial_number,
                MAX(v.name) AS vehicle_name,
                MAX(v.photo_url) AS photo_url,
                COUNT(*) AS order_count,
                COALESCE(SUM(o.payment_amount), 0) AS total_revenue
            FROM orders o
            LEFT JOIN vehicles v ON v.serial_number = o.serial_number
            $where
            GROUP BY o.serial_number
            ORDER BY total_revenue DESC, order_count DESC, o.serial_number DESC
        ";

        $stmtDeviceRank = $conn->prepare($sqlDeviceRank);
        if (!$stmtDeviceRank) {
            throw new Exception('设备收益排行 SQL 预处理失败：' . $conn->error);
        }
        $stmtDeviceRank->bind_param($types, ...$params);
        $stmtDeviceRank->execute();
        $resultDeviceRank = $stmtDeviceRank->get_result();

        while ($row = $resultDeviceRank->fetch_assoc()) {
            $row['order_count'] = (int)$row['order_count'];
            $row['total_revenue'] = (float)$row['total_revenue'];
            $deviceRevenueList[] = $row;
        }
        $stmtDeviceRank->close();
    }

    $machineOptions = [];
    
    $sqlMachineOptions = "
        SELECT
            serial_number,
            MAX(product_name) AS product_name,
            MAX(product_image) AS product_image
        FROM claw_machine_records
        WHERE serial_number <> ''
        GROUP BY serial_number
        ORDER BY serial_number ASC
    ";
    
    $resultMachineOptions = $conn->query($sqlMachineOptions);
    if ($resultMachineOptions) {
        while ($row = $resultMachineOptions->fetch_assoc()) {
            $machineOptions[] = [
                'serial_number' => (string)$row['serial_number'],
                'product_name' => (string)($row['product_name'] ?? ''),
                'product_image' => (string)($row['product_image'] ?? '')
            ];
        }
    }
    jsonOut(200, '获取成功', [
        'filters' => [
            'serial_number' => $serial_number,
            'start_time' => $start_time,
            'end_time' => $end_time
        ],
        'total_count' => count($list),
        'total_amount' => round($totalAmount, 2), // 当前筛选条件下总收益
        'top_user' => $topUser,                   // 当前筛选条件下消费最大用户
        'device_summary' => $deviceSummary,      // 传 serial_number 时有值
        'device_revenue_list' => $deviceRevenueList, // 不传 serial_number 时返回
        'machine_options' => $machineOptions,
        'list' => $list
    ]);

} catch (Throwable $e) {
    logMessage_log('error: ' . $e->getMessage());
    jsonOut(500, '获取失败：' . $e->getMessage());
}