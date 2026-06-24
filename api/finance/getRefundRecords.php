<?php
// 文件：/api/finance/getRefundRecords.php
require_once '../Database.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $db = new Database();

    // 1) 登录 & 权限
    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
        exit;
    }

    $user = $db->getUserBySessionToken($session_token);
    if (!$user || !$user['role_id']) {
        echo json_encode(['code' => 1002, 'msg' => '用户无权限访问', 'data' => []]);
        exit;
    }

    $role_id   = (int)$user['role_id'];
    $venue_id  = isset($user['venue_id']) ? (string)$user['venue_id'] : null;

    // 2) 读取筛选参数（可选）
    $order_number = isset($_GET['order_number']) ? trim($_GET['order_number']) : '';
    $uid          = isset($_GET['uid']) ? trim($_GET['uid']) : '';
    $status       = isset($_GET['status']) ? trim($_GET['status']) : ''; // applied / approved / rejected
    $page         = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $pageSize     = isset($_GET['page_size']) ? min(100, max(1, (int)$_GET['page_size'])) : 20;

    $where = " WHERE 1=1 ";
    $params = [];

    // 非超管：仅能查看绑定场地
    if (!in_array($role_id, [1, 2], true)) {
        if (empty($venue_id)) {
            echo json_encode(['code' => 1003, 'msg' => '未绑定场地', 'data' => []]);
            exit;
        }
        $where .= " AND rr.reservation_id = ? ";
        $params[] = $venue_id;
    }

    if ($order_number !== '') {
        $where .= " AND rr.order_id LIKE ? ";
        $params[] = "%{$order_number}%";
    }

    if ($uid !== '') {
        $where .= " AND rr.uid = ? ";
        $params[] = $uid;
    }

    if ($status !== '') {
        $where .= " AND rr.status = ? ";
        $params[] = $status;
    }

    // 分页
    $offset = ($page - 1) * $pageSize;
    // 注意：你的 Database::query 里只支持占位符给参数，LIMIT/OFFSET 这里直接拼接整数（已做上限保护）
    $limitSql  = " LIMIT {$offset}, {$pageSize} ";

    // 3) 主查询：退款记录 + 订单（可选）+ 场地名
    $sql = "
        SELECT
            rr.id,
            rr.order_id,
            rr.uid,
            rr.reservation_id,
            rr.refund_amount,
            rr.reason,
            rr.status,
            rr.applicant_admin_uid,
            rr.created_at,
            rr.updated_at,
            rr.is_reduced,
            v.venue_name
        FROM refund_records rr
        LEFT JOIN venues v ON v.id = rr.reservation_id
        {$where}
        ORDER BY rr.id DESC
        {$limitSql}
    ";

    $rows = $db->query($sql, $params) ?: [];

    // 4) 计数
    $countSql = "
        SELECT COUNT(*) AS cnt
        FROM refund_records rr
        {$where}
    ";
    $countRes = $db->query($countSql, $params);
    $total = (is_array($countRes) && isset($countRes[0]['cnt'])) ? (int)$countRes[0]['cnt'] : 0;

    echo json_encode([
        'code'  => 0,
        'msg'   => '',
        'count' => $total,
        'data'  => $rows,
        'page'  => $page,
        'page_size' => $pageSize
    ]);
} catch (Throwable $e) {
    error_log($e);
    echo json_encode(['code' => 500, 'msg' => '服务器异常', 'data' => []]);
}
