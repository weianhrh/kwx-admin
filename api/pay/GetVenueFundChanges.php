<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function respond($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();

try {
    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        respond(1001, '用户未登录或会话已过期');
    }

    $user = $database->getUserBySessionToken($session_token);
    if (!$user || empty($user['role_id'])) {
        respond(1001, '用户未登录或无权访问');
    }

    $venue_id = $user['venue_id'] ?? null;
    if (empty($venue_id)) {
        respond(1002, '当前账号未绑定场地，无法查看流水');
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $page_size = (int)($_GET['page_size'] ?? 20);
    $page_size = min(100, max(10, $page_size));
    $offset = ($page - 1) * $page_size;

    $change_type = $_GET['change_type'] ?? '';
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $abnormal = $_GET['abnormal'] ?? '';
    $problem_source_sql = "CONVERT(UNHEX('E5AD98E59CA8E997AEE9A298E8AEA2E58D95') USING utf8mb4)";

    $where = ['venue_id = ?'];
    $params = [$venue_id];

    if (in_array($change_type, ['revenue', 'withdrawal'], true)) {
        $where[] = 'change_type = ?';
        $params[] = $change_type;
    }

    if ($abnormal === '1') {
        $where[] = "source_type = {$problem_source_sql}";
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
        $where[] = 'change_date >= ?';
        $params[] = $start_date . ' 00:00:00';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        $where[] = 'change_date <= ?';
        $params[] = $end_date . ' 23:59:59';
    }

    $where_sql = implode(' AND ', $where);

    $count_sql = "SELECT COUNT(*) AS total FROM fund_changes WHERE {$where_sql}";
    $count_result = $database->query($count_sql, $params);
    if ($count_result === false) {
        respond(500, '查询流水总数失败');
    }
    $total = (int)($count_result[0]['total'] ?? 0);

    $summary_sql = "
        SELECT
            COALESCE(SUM(CASE WHEN change_type = 'revenue' THEN change_amount ELSE 0 END), 0) AS revenue_total,
            COALESCE(SUM(CASE WHEN change_type = 'withdrawal' THEN change_amount ELSE 0 END), 0) AS withdrawal_total,
            COALESCE(SUM(CASE WHEN source_type = {$problem_source_sql} THEN 1 ELSE 0 END), 0) AS abnormal_total
        FROM fund_changes
        WHERE {$where_sql}
    ";
    $summary_result = $database->query($summary_sql, $params);
    if ($summary_result === false) {
        respond(500, '查询流水汇总失败');
    }

    $rows_sql = "
        SELECT
            id,
            venue_id,
            change_type,
            change_amount,
            balance_after_change,
            change_date,
            change_reason,
            operator_id,
            remarks,
            revenue_date,
            source_type,
            source_id
        FROM fund_changes
        WHERE {$where_sql}
        ORDER BY change_date DESC, id DESC
        LIMIT {$page_size} OFFSET {$offset}
    ";
    $rows = $database->query($rows_sql, $params);
    if ($rows === false) {
        respond(500, '查询流水列表失败');
    }

    respond(0, 'success', [
        'venue_id' => (int)$venue_id,
        'page' => $page,
        'page_size' => $page_size,
        'total' => $total,
        'summary' => [
            'revenue_total' => $summary_result[0]['revenue_total'] ?? '0.00',
            'withdrawal_total' => $summary_result[0]['withdrawal_total'] ?? '0.00',
            'abnormal_total' => (int)($summary_result[0]['abnormal_total'] ?? 0),
        ],
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    error_log('GetVenueFundChanges error: ' . $e->getMessage());
    respond(500, '服务器异常');
} finally {
    $database->close();
}
