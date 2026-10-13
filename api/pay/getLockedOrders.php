<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/venue_scope.php';

function locked_orders_reply(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function locked_orders_positive_int($value, int $maximum): ?int
{
    if (!is_scalar($value)) {
        return null;
    }
    $number = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => $maximum],
    ]);
    return $number === false ? null : (int)$number;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        locked_orders_reply(['code' => 1002, 'msg' => '请使用 GET 查询锁定订单', 'data' => []]);
    }

    $token = $_COOKIE['session_token'] ?? '';
    if (!is_string($token) || $token === '') {
        locked_orders_reply(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    }

    $database = new Database();
    $user = $database->getUserBySessionToken($token);
    if (!$user || empty($user['role_id'])) {
        locked_orders_reply(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    }

    $scope = $_GET['scope'] ?? 'venue';
    if (!is_string($scope) || !in_array($scope, ['venue', 'all'], true)) {
        locked_orders_reply(['code' => 1002, 'msg' => '查询范围无效', 'data' => []]);
    }
    $allVenues = $scope === 'all';
    if ($allVenues && !venue_scope_is_platform_admin($user)) {
        locked_orders_reply(['code' => 1003, 'msg' => '仅管理员可查看全部场地锁定订单', 'data' => []]);
    }

    $requestedVenueId = 0;
    if (isset($_GET['venue_id']) || isset($_GET['venueId'])) {
        $requestedVenueId = locked_orders_positive_int($_GET['venue_id'] ?? $_GET['venueId'], PHP_INT_MAX);
        if ($requestedVenueId === null) {
            locked_orders_reply(['code' => 1002, 'msg' => '场地参数无效', 'data' => []]);
        }
    }
    if ($allVenues && $requestedVenueId > 0) {
        locked_orders_reply(['code' => 1002, 'msg' => '全部场地查询无需指定场地', 'data' => []]);
    }
    // 提现申请仍按原场地范围查询；只有管理员显式请求 all 时才查询全部。
    $venueId = $allVenues ? 0 : venue_scope_resolve_single_id($database, $user, $requestedVenueId);
    if (!$allVenues && $venueId <= 0) {
        locked_orders_reply(['code' => 1003, 'msg' => '未绑定或无权访问该场地', 'data' => []]);
    }

    $page = locked_orders_positive_int($_GET['page'] ?? 1, PHP_INT_MAX);
    $pageSize = locked_orders_positive_int($_GET['page_size'] ?? 20, 100);
    $orderId = $_GET['order_id'] ?? '';
    if ($page === null || $pageSize === null || !is_string($orderId) || strlen($orderId) > 300) {
        locked_orders_reply(['code' => 1002, 'msg' => '查询参数无效', 'data' => []]);
    }
    $orderId = trim($orderId);

    $venueName = '全部场地';
    if (!$allVenues) {
        $venues = $database->query('SELECT id, venue_name FROM venues WHERE id = ? LIMIT 1', [$venueId]);
        if ($venues === false) {
            throw new RuntimeException('Unable to load locked-order venue');
        }
        if (!$venues) {
            locked_orders_reply(['code' => 1003, 'msg' => '场地不存在', 'data' => []]);
        }
        $venueName = $venues[0]['venue_name'];
    }

    // 与提现申请和提现审批汇总口径一致：仅统计现存场地的 status=1 锁定记录。
    $where = 'olr.status = 1';
    $params = [];
    if (!$allVenues) {
        $where .= ' AND olr.venue_id = ?';
        $params[] = $venueId;
    }
    if ($orderId !== '') {
        $where .= ' AND LOCATE(?, olr.order_id) > 0';
        $params[] = $orderId;
    }
    $summary = $database->query(
        "SELECT COUNT(*) AS total, COALESCE(SUM(olr.lock_amount), 0) AS lock_amount
         FROM order_lock_records olr
         INNER JOIN venues v ON v.id = olr.venue_id
         WHERE {$where}",
        $params
    );
    if ($summary === false || !isset($summary[0])) {
        throw new RuntimeException('Unable to load locked-order summary');
    }

    $total = (int)$summary[0]['total'];
    $totalPages = max(1, (int)ceil($total / $pageSize));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $pageSize;
    $rows = $database->query(
        "SELECT olr.id, olr.order_id, olr.venue_id, v.venue_name, olr.lock_amount,
                olr.status AS lock_status, olr.operator_uid, olr.create_at, olr.update_at,
                COALESCE(olr.update_at, olr.create_at) AS lock_time,
                o.uid, o.serial_number, o.status AS order_status, o.payment_amount,
                o.pays_type, o.start_time, o.end_time, u.nickname,
                COALESCE(veh.name, '') AS device_name
         FROM order_lock_records olr
         INNER JOIN venues v ON v.id = olr.venue_id
         LEFT JOIN orders o ON o.order_id = olr.order_id AND o.reservation_id = olr.venue_id
         LEFT JOIN users u ON u.uid = o.uid
         LEFT JOIN vehicles veh ON veh.serial_number = o.serial_number
         WHERE {$where}
         ORDER BY COALESCE(olr.update_at, olr.create_at) DESC, olr.id DESC
         LIMIT {$pageSize} OFFSET {$offset}",
        $params
    );
    if ($rows === false) {
        throw new RuntimeException('Unable to load locked-order rows');
    }
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['venue_id'] = (int)$row['venue_id'];
        $row['lock_status'] = (int)$row['lock_status'];
        $row['lock_amount'] = number_format((float)$row['lock_amount'], 2, '.', '');
        $row['payment_amount'] = $row['payment_amount'] === null
            ? null : number_format((float)$row['payment_amount'], 2, '.', '');
    }
    unset($row);

    locked_orders_reply([
        'code' => 0,
        'msg' => '',
        'data' => [
            'scope' => $scope,
            'venue_id' => $venueId,
            'venue_name' => $venueName,
            'total' => $total,
            'lock_amount' => number_format((float)$summary[0]['lock_amount'], 2, '.', ''),
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => $totalPages,
            'rows' => $rows,
        ],
    ]);
} catch (Throwable $e) {
    error_log('getLockedOrders: ' . $e->getMessage());
    locked_orders_reply(['code' => 500, 'msg' => '锁定订单读取失败，请稍后重试', 'data' => []]);
}
