<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/venue_scope.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function make_placeholders(int $count): string
{
    return implode(',', array_fill(0, $count, '?'));
}

function apply_venue_scope_filter(Database $database, array $user, int $roleId, string $field, array &$params, int $requestedVenueId): string
{
    if (in_array($roleId, [1, 2], true)) {
        if ($requestedVenueId > 0) {
            $params[] = $requestedVenueId;
            return " AND {$field} = ?";
        }
        return '';
    }

    $allowedVenueIds = array_values(array_unique(array_map('intval', venue_scope_user_ids($database, $user))));

    if (!$allowedVenueIds && !empty($user['venue_id'])) {
        $allowedVenueIds[] = (int)$user['venue_id'];
    }

    if ($requestedVenueId > 0) {
        if (!in_array($requestedVenueId, $allowedVenueIds, true)) {
            json_out(['code' => 1006, 'msg' => '无权访问该场地订单', 'count' => 0, 'data' => []]);
        }

        $params[] = $requestedVenueId;
        return " AND {$field} = ?";
    }

    if (!$allowedVenueIds) {
        return " AND 1 = 0";
    }

    foreach ($allowedVenueIds as $venueId) {
        $params[] = $venueId;
    }

    return " AND {$field} IN (" . make_placeholders(count($allowedVenueIds)) . ")";
}

function append_like_filter(string &$sql, array &$params, string $field, string $value): void
{
    if ($value !== '') {
        $sql .= " AND {$field} LIKE ?";
        $params[] = '%' . $value . '%';
    }
}

function append_reservation_location_filter(string &$sql, array &$params, string $value): void
{
    if ($value !== '') {
        $sql .= " AND (r.reservation_location LIKE ? OR vv.venue_name LIKE ?)";
        $params[] = '%' . $value . '%';
        $params[] = '%' . $value . '%';
    }
}

$database = new Database();

$sessionToken = $_COOKIE['session_token'] ?? null;
if (!$sessionToken) {
    json_out(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'count' => 0, 'data' => []]);
}

$user = $database->getUserBySessionToken($sessionToken);
if (!$user || empty($user['role_id'])) {
    json_out(['code' => 1001, 'msg' => '用户未登录或无权访问', 'count' => 0, 'data' => []]);
}

$roleId = (int)$user['role_id'];

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(1, min(200, (int)($_GET['limit'] ?? 140)));
$offset = ($page - 1) * $limit;

$orderNumber = trim((string)($_GET['order_number'] ?? ''));
$reservationDate = trim((string)($_GET['reservation_date'] ?? ''));
$reservationLocation = trim((string)($_GET['reservation_location'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$requestedVenueId = isset($_GET['venue_id']) ? (int)$_GET['venue_id'] : 0;

/**
 * 预约管理仍然查 Reservations；
 * 正在驾驶的订单管理必须以 orders 为准，否则 orders.status=正在驾驶 但 Reservations.order_status 未同步时会查不到。
 */
$isDrivingOrderQuery = ($status === '正在驾驶');

if ($isDrivingOrderQuery) {
    $sql = "
        SELECT
            COALESCE(r.id, 0) AS id,
            o.order_id AS order_number,
            COALESCE(r.reservation_type, '') AS reservation_type,
            COALESCE(r.reservation_location, vv.venue_name, CONCAT('场地 ', o.reservation_id)) AS reservation_location,
            o.reservation_id AS reservation_id,
            COALESCE(r.reservation_time, o.start_time) AS reservation_time,
            o.uid AS user_id,
            u.nickname,
            o.status AS order_status,
            COALESCE(NULLIF(r.driving_start_time, ''), o.start_time) AS driving_start_time,
            COALESCE(r.driving_end_time, o.end_time) AS driving_end_time,
            COALESCE(NULLIF(r.driving_duration, 0), NULLIF(o.billing_rules, 0), NULLIF(o.duration, 0), 0) AS driving_duration,
            o.pays_type AS pay_type,
            o.payment_amount AS pay_money,
            o.start_time,
            r.notification_status,
            COALESCE(v.name, o.serial_number, '-') AS vehicle_name
        FROM orders o
        LEFT JOIN Reservations r ON r.order_number = o.order_id
        LEFT JOIN users u ON o.uid = u.uid
        LEFT JOIN vehicles v ON v.serial_number = o.serial_number
        LEFT JOIN venues vv ON vv.id = o.reservation_id
        WHERE 1 = 1
    ";

    $params = [];

    if ($reservationDate !== '') {
        $sql .= " AND DATE(o.start_time) = ?";
        $params[] = $reservationDate;
    }

    $sql .= apply_venue_scope_filter($database, $user, $roleId, 'o.reservation_id', $params, $requestedVenueId);

    append_like_filter($sql, $params, 'o.order_id', $orderNumber);
    append_reservation_location_filter($sql, $params, $reservationLocation);

    if ($status !== '') {
        $sql .= " AND o.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY o.start_time DESC LIMIT ?, ?";
    $params[] = $offset;
    $params[] = $limit;

    $data = $database->query($sql, $params) ?: [];

    $countSql = "
        SELECT COUNT(DISTINCT o.order_id) AS count
        FROM orders o
        LEFT JOIN Reservations r ON r.order_number = o.order_id
        LEFT JOIN venues vv ON vv.id = o.reservation_id
        WHERE 1 = 1
    ";

    $countParams = [];

    if ($reservationDate !== '') {
        $countSql .= " AND DATE(o.start_time) = ?";
        $countParams[] = $reservationDate;
    }

    $countSql .= apply_venue_scope_filter($database, $user, $roleId, 'o.reservation_id', $countParams, $requestedVenueId);

    append_like_filter($countSql, $countParams, 'o.order_id', $orderNumber);
    append_reservation_location_filter($countSql, $countParams, $reservationLocation);

    if ($status !== '') {
        $countSql .= " AND o.status = ?";
        $countParams[] = $status;
    }

    $countResult = $database->query($countSql, $countParams);
    $totalCount = $countResult ? (int)$countResult[0]['count'] : 0;

    json_out([
        'code' => 0,
        'msg' => '',
        'count' => $totalCount,
        'data' => $data
    ]);
}

$sql = "
    SELECT
        r.id,
        r.order_number,
        r.reservation_type,
        r.reservation_location,
        r.reservation_id,
        r.reservation_time,
        r.user_id,
        u.nickname,
        r.order_status,
        r.driving_start_time,
        r.driving_end_time,
        r.driving_duration,
        r.pay_type,
        r.pay_money,
        r.start_time,
        r.notification_status,
        v.name AS vehicle_name
    FROM Reservations r
    LEFT JOIN users u ON r.user_id = u.uid
    LEFT JOIN vehicles v ON r.user_id = v.driver_id
    WHERE 1 = 1
";

$params = [];

if ($reservationDate !== '') {
    $sql .= " AND DATE(r.reservation_time) = ?";
    $params[] = $reservationDate;
}

$sql .= apply_venue_scope_filter($database, $user, $roleId, 'r.reservation_id', $params, $requestedVenueId);

append_like_filter($sql, $params, 'r.order_number', $orderNumber);

if ($reservationLocation !== '') {
    $sql .= " AND r.reservation_location LIKE ?";
    $params[] = '%' . $reservationLocation . '%';
}

if ($status !== '') {
    $sql .= " AND r.order_status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY r.reservation_time DESC LIMIT ?, ?";
$params[] = $offset;
$params[] = $limit;

$data = $database->query($sql, $params) ?: [];

$countSql = "SELECT COUNT(*) AS count FROM Reservations r WHERE 1 = 1";
$countParams = [];

if ($reservationDate !== '') {
    $countSql .= " AND DATE(r.reservation_time) = ?";
    $countParams[] = $reservationDate;
}

$countSql .= apply_venue_scope_filter($database, $user, $roleId, 'r.reservation_id', $countParams, $requestedVenueId);

append_like_filter($countSql, $countParams, 'r.order_number', $orderNumber);

if ($reservationLocation !== '') {
    $countSql .= " AND r.reservation_location LIKE ?";
    $countParams[] = '%' . $reservationLocation . '%';
}

if ($status !== '') {
    $countSql .= " AND r.order_status = ?";
    $countParams[] = $status;
}

$countResult = $database->query($countSql, $countParams);
$totalCount = $countResult ? (int)$countResult[0]['count'] : 0;

$database->close();

json_out([
    'code' => 0,
    'msg' => '',
    'count' => $totalCount,
    'data' => $data
]);
