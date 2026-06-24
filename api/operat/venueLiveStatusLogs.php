<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

$database = new Database();

function json_out($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? ($_GET['action'] ?? 'get_logs');

if ($action !== 'get_logs') {
    json_out(400, '无效操作');
}

$venueId = trim($_POST['venue_id'] ?? ($_GET['venue_id'] ?? ''));
$venueKeyword = trim($_POST['venue_keyword'] ?? ($_GET['venue_keyword'] ?? ''));
$page = intval($_POST['page'] ?? ($_GET['page'] ?? 1));
$pageSize = intval($_POST['page_size'] ?? ($_GET['page_size'] ?? 20));

if (strlen($venueKeyword) > 100) {
    $venueKeyword = substr($venueKeyword, 0, 100);
}

if ($page <= 0) {
    $page = 1;
}

if ($pageSize <= 0) {
    $pageSize = 20;
}

if ($pageSize > 100) {
    $pageSize = 100;
}

$where = " WHERE 1 = 1 ";
$params = [];

if ($venueId !== '' && $venueId !== '0' && strtolower($venueId) !== 'all') {
    if (!ctype_digit($venueId) || intval($venueId) <= 0) {
        json_out(422, 'venue_id参数错误');
    }

    $where .= " AND l.venue_id = ? ";
    $params[] = intval($venueId);
} elseif ($venueKeyword !== '') {
    $likeKeyword = '%' . $venueKeyword . '%';

    $where .= " AND (
        CAST(l.venue_id AS CHAR) LIKE ?
        OR v.venue_name LIKE ?
    ) ";

    $params[] = $likeKeyword;
    $params[] = $likeKeyword;
}

try {
    // 总数
    $countRows = $database->query(
        "SELECT COUNT(*) AS total
         FROM venue_live_status_logs l
         LEFT JOIN venues v ON v.id = l.venue_id
         $where",
        $params
    );

    if ($countRows === false) {
        json_out(500, '查询总数失败');
    }

    $total = intval($countRows[0]['total'] ?? 0);
    $totalPages = $total > 0 ? ceil($total / $pageSize) : 1;

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * $pageSize;

    // 注意：LIMIT/OFFSET 已经 intval，不走用户原始输入
    $sql = "
        SELECT
            l.id,
            l.venue_id,
            IFNULL(v.venue_name, '') AS venue_name,

            l.action,
            l.old_is_live,
            l.new_is_live,
            l.old_venue_status_num,
            l.new_venue_status_num,

            l.operator_uid,
            l.remark,
            l.ip,
            l.user_agent,
            l.created_at
        FROM venue_live_status_logs l
        LEFT JOIN venues v ON v.id = l.venue_id
        $where
        ORDER BY l.id DESC
        LIMIT $offset, $pageSize
    ";

    $rows = $database->query($sql, $params);

    if ($rows === false) {
        json_out(500, '查询日志失败');
    }

    foreach ($rows as &$row) {
        $action = intval($row['action']);

        $row['action_text'] = $action === 1 ? '开播' : '关播';
        $row['operator_text'] = empty($row['operator_uid']) ? '系统' : ('UID ' . $row['operator_uid']);

        if ($row['remark'] === '系统关播') {
            $row['type_text'] = '系统关播';
        } else {
            $row['type_text'] = '主播开关播';
        }
    }
    unset($row);

    json_out(0, 'success', [
        'list' => $rows,
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'total_pages' => $totalPages
    ]);

} catch (Exception $e) {
    json_out(500, '服务器异常：' . $e->getMessage());
}