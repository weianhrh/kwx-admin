<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function logMessage_log($message) {
    $logFile = __DIR__ . '/get_user_orders_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

$database = new Database();

// 登录校验
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

    $reservation_id = isset($_GET['reservation_id']) ? intval($_GET['reservation_id']) : 0;
    $start_date = trim($_GET['start_date'] ?? '');
    $end_date   = trim($_GET['end_date'] ?? '');

    // 默认当天
    if ($start_date === '' && $end_date === '') {
        $query_start_date = date('Y-m-d');
        $query_end_date   = date('Y-m-d');
    } else {
        if ($start_date === '') $start_date = $end_date;
        if ($end_date === '')   $end_date   = $start_date;

        $startTs = strtotime($start_date);
        $endTs   = strtotime($end_date);

        if ($startTs === false || $endTs === false) {
            jsonOut(400, '日期格式错误，请传 YYYY-MM-DD');
        }

        if ($startTs > $endTs) {
            $tmp = $start_date;
            $start_date = $end_date;
            $end_date = $tmp;

            $tmpTs = $startTs;
            $startTs = $endTs;
            $endTs = $tmpTs;
        }

        $query_start_date = date('Y-m-d', $startTs);
        $query_end_date   = date('Y-m-d', $endTs);
    }

    $startDateTime = $query_start_date . ' 00:00:00';
    $endDateTime   = date('Y-m-d 00:00:00', strtotime($query_end_date . ' +1 day'));

    $sql = "
        SELECT
            o.order_id,
            o.serial_number,
            o.reservation_id,
            o.uid,
            o.status,
            o.start_time,
            o.end_time,
            o.payment_amount,

            u.nickname,
            u.headimgurl,
            u.wallet,

            v.id AS venue_id,
            v.venue_name

        FROM orders o
        LEFT JOIN users u ON u.uid = o.uid
        LEFT JOIN venues v ON v.id = o.reservation_id
        WHERE
            o.end_time >= ?
            AND o.end_time < ?
            AND (o.pays_type <> '能量' OR o.pays_type IS NULL)
    ";

    $params = [$startDateTime, $endDateTime];
    $types  = "ss";

    if ($reservation_id > 0) {
        $sql .= " AND o.reservation_id = ? ";
        $params[] = $reservation_id;
        $types .= "i";
    }

    $sql .= " ORDER BY o.end_time DESC, o.start_time DESC ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("SQL预处理失败: " . $conn->error);
    }

    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        throw new Exception("SQL执行失败: " . $stmt->error);
    }

    $result = $stmt->get_result();

    $list = [];
    $total_payment_amount = 0;

    while ($row = $result->fetch_assoc()) {
        $amount = (float)$row['payment_amount'];
        $total_payment_amount += $amount;

        $list[] = [
            'order_id'       => $row['order_id'],
            'serial_number'  => $row['serial_number'],
            'reservation_id' => (int)$row['reservation_id'],
            'venue_id'       => isset($row['venue_id']) ? (int)$row['venue_id'] : 0,
            'venue_name'     => $row['venue_name'] ?? '',
            'uid'            => (int)$row['uid'],
            'status'         => $row['status'],
            'start_time'     => $row['start_time'],
            'end_time'       => $row['end_time'],
            'payment_amount' => $amount,
            'nickname'       => $row['nickname'] ?? '',
            'headimgurl'     => $row['headimgurl'] ?? '',
            'wallet'         => isset($row['wallet']) ? (float)$row['wallet'] : 0,
        ];
    }

    $stmt->close();

    jsonOut(200, '获取成功', [
        'query_start_date'     => $query_start_date,
        'query_end_date'       => $query_end_date,
        'reservation_id'       => $reservation_id,
        'total'                => count($list),
        'total_payment_amount' => round($total_payment_amount, 2),
        'list'                 => $list
    ]);

} catch (Exception $e) {
    logMessage_log('错误: ' . $e->getMessage());
    jsonOut(500, '服务器异常: ' . $e->getMessage());
}