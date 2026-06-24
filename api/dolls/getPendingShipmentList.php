<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

$database = new Database();

// 会话校验
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode([
        'code' => 1001,
        'msg'  => '用户未登录或会话已过期',
        'data' => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode([
        'code' => 1001,
        'msg'  => '用户未登录或无权访问',
        'data' => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/*
 * 先把所有待发货记录查出来，
 * 再在 PHP 里按 user_id 分组，这样最稳，兼容性也最好。
 */
$sql = "
    SELECT
        udd.id,
        udd.user_id,
        udd.doll_machine_id,
        udd.doll_id,
        udd.doll_name,
        udd.doll_image,
        udd.order_id,
        udd.doll_status,
        udd.is_gift,
        udd.created_at,
        udd.er_time,
        ua.addressee AS addressee,
        ua.phone,
        ua.address
    FROM user_doll_detail udd
    LEFT JOIN user_address ua
        ON ua.id = (
            SELECT ua2.id
            FROM user_address ua2
            WHERE ua2.user_uid = udd.user_id
            ORDER BY ua2.is_default DESC, ua2.id DESC
            LIMIT 1
        )
    WHERE udd.doll_status = 1
      AND udd.is_gift = 0
    ORDER BY udd.user_id ASC, udd.created_at ASC, udd.id ASC
";

$rows = $database->query($sql);
$rows = is_array($rows) ? $rows : [];

$grouped = [];

foreach ($rows as $row) {
    $userId = (string)($row['user_id'] ?? '');
    if ($userId === '') {
        continue;
    }

    if (!isset($grouped[$userId])) {
        $grouped[$userId] = [
            'user_id'          => (int)$row['user_id'],
            'record_ids'       => [],
            'records'          => [],
            'order_ids'        => [],
            'doll_names'       => [],
            'record_count'     => 0,
            'order_count'      => 0,
            'first_created_at' => $row['created_at'] ?? '',
            'last_created_at'  => $row['created_at'] ?? '',
            'cover_image'      => $row['doll_image'] ?? '',
            'addressee'        => $row['addressee'] ?? '',
            'phone'            => $row['phone'] ?? '',
            'address'          => $row['address'] ?? '',
        ];
    }

    $grouped[$userId]['record_ids'][] = (int)$row['id'];
    $grouped[$userId]['record_count']++;

    $createdAt = $row['created_at'] ?? '';

    if ($createdAt !== '') {
        if (
            $grouped[$userId]['first_created_at'] === '' ||
            $createdAt < $grouped[$userId]['first_created_at']
        ) {
            $grouped[$userId]['first_created_at'] = $createdAt;
        }

        if (
            $grouped[$userId]['last_created_at'] === '' ||
            $createdAt > $grouped[$userId]['last_created_at']
        ) {
            $grouped[$userId]['last_created_at'] = $createdAt;
        }
    }

    if (
        empty($grouped[$userId]['cover_image']) &&
        !empty($row['doll_image'])
    ) {
        $grouped[$userId]['cover_image'] = $row['doll_image'];
    }

    $orderId = trim((string)($row['order_id'] ?? ''));
    if ($orderId !== '' && !in_array($orderId, $grouped[$userId]['order_ids'], true)) {
        $grouped[$userId]['order_ids'][] = $orderId;
    }

    $dollName = trim((string)($row['doll_name'] ?? ''));
    if ($dollName !== '' && !in_array($dollName, $grouped[$userId]['doll_names'], true)) {
        $grouped[$userId]['doll_names'][] = $dollName;
    }

    $grouped[$userId]['records'][] = [
        'id'         => (int)$row['id'],
        'order_id'   => $row['order_id'] ?? '',
        'doll_name'  => $row['doll_name'] ?? '',
        'doll_image' => $row['doll_image'] ?? '',
        'created_at' => $row['created_at'] ?? '',
    ];
}

$groupedList = array_values($grouped);

foreach ($groupedList as &$item) {
    $item['order_count'] = count($item['order_ids']);
}
unset($item);

// 按“最末抓中时间”倒序，最新的用户排前面
usort($groupedList, function ($a, $b) {
    return strcmp($b['last_created_at'], $a['last_created_at']);
});

echo json_encode([
    'code'         => 0,
    'msg'          => 'success',
    'total_users'  => count($groupedList),
    'total_orders' => count($rows),
    'data'         => $groupedList
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);