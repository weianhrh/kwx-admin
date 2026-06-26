<?php
require_once '../Database.php';
require_once __DIR__ . '/../lib/venue_scope.php';

header('Content-Type: application/json; charset=utf-8');

$database = new Database();

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || empty($user['role_id'])) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$role_id = (int)$user['role_id'];

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(1, min(200, (int)($_GET['limit'] ?? 140)));
$offset = ($page - 1) * $limit;
$orderNumber = trim((string)($_GET['order_number'] ?? ''));
$reservationDate = trim((string)($_GET['reservation_date'] ?? ''));
$reservationLocation = trim((string)($_GET['reservation_location'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$requestedVenueId = venue_scope_requested_id($_GET);

function apply_reservation_venue_scope(Database $database, array $user, int $role_id, int $requestedVenueId, string &$sql, array &$params): void
{
    if (in_array($role_id, [1, 2], true)) {
        if ($requestedVenueId > 0) {
            $sql .= " AND r.reservation_id = ?";
            $params[] = (string)$requestedVenueId;
        }
        return;
    }

    // 加盟商/场地账号：
    // - 从场地总览进入时，按 URL 里的 venue_id 校验并过滤；
    // - 未传 venue_id 时，展示该账号绑定的所有场地；
    // - 传了无权限场地时，自动变成空结果。
    $sql .= venue_scope_apply_filter($database, $user, 'r.reservation_id', $params, $requestedVenueId);
}

$sql = "SELECT r.id, r.order_number, r.reservation_type, r.reservation_location, r.reservation_id, r.reservation_time,
        r.user_id, u.nickname, r.order_status, r.driving_start_time, r.driving_end_time, r.driving_duration,
        r.pay_type, r.pay_money, r.start_time, r.notification_status, v.name AS vehicle_name
        FROM Reservations r
        LEFT JOIN users u ON r.user_id = u.uid
        LEFT JOIN vehicles v ON r.user_id = v.driver_id
        WHERE 1=1";

$params = [];

if ($reservationDate !== '') {
    $sql .= " AND DATE(r.reservation_time) = ?";
    $params[] = $reservationDate;
}

apply_reservation_venue_scope($database, $user, $role_id, $requestedVenueId, $sql, $params);

if ($orderNumber !== '') {
    $sql .= " AND r.order_number LIKE ?";
    $params[] = "%{$orderNumber}%";
}

if ($reservationLocation !== '') {
    $sql .= " AND r.reservation_location LIKE ?";
    $params[] = "%{$reservationLocation}%";
}

if ($status !== '') {
    $sql .= " AND r.order_status = ?";
    $params[] = $status;
}

$sql .= " ORDER BY r.reservation_time DESC";
$sql .= " LIMIT ?, ?";
$params[] = (string)$offset;
$params[] = (string)$limit;

$data = $database->query($sql, $params) ?: [];

$countSql = "SELECT COUNT(*) AS count FROM Reservations r WHERE 1=1";
$countParams = [];

if ($reservationDate !== '') {
    $countSql .= " AND DATE(r.reservation_time) = ?";
    $countParams[] = $reservationDate;
}

apply_reservation_venue_scope($database, $user, $role_id, $requestedVenueId, $countSql, $countParams);

if ($orderNumber !== '') {
    $countSql .= " AND r.order_number LIKE ?";
    $countParams[] = "%{$orderNumber}%";
}

if ($reservationLocation !== '') {
    $countSql .= " AND r.reservation_location LIKE ?";
    $countParams[] = "%{$reservationLocation}%";
}

if ($status !== '') {
    $countSql .= " AND r.order_status = ?";
    $countParams[] = $status;
}

$countResult = $database->query($countSql, $countParams);
$totalCount = $countResult ? (int)($countResult[0]['count'] ?? 0) : 0;

echo json_encode([
    'code' => 0,
    'msg' => '',
    'count' => $totalCount,
    'data' => $data,
], JSON_UNESCAPED_UNICODE);

$database->close();
?>
