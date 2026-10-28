<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '0');
function rechargeRankOut($code, $msg, $data = []) {
    echo json_encode(['code'=>$code, 'msg'=>$msg, 'data'=>$data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
try {
    require_once __DIR__ . '/../Database.php';
    $db = new Database();
    $token = $_COOKIE['session_token'] ?? '';
    $user = $token ? $db->getUserBySessionToken($token) : null;
    if (!$user) rechargeRankOut(1001, '未登录或会话已过期');
    if (!in_array((int)($user['role_id'] ?? 0), [1,2], true)) rechargeRankOut(1002, '充值榜仅管理员和运营可查看');
    $page = max(1, min(1000000, (int)($_GET['page'] ?? 1)));
    $size = max(1, min(100, (int)($_GET['page_size'] ?? 20)));
    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $start = trim((string)($_GET['start_date'] ?? ''));
    $end = trim((string)($_GET['end_date'] ?? ''));
    foreach ([$start,$end] as $date) {
        if ($date !== '') {
            $parsed = DateTime::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) rechargeRankOut(1003, '日期格式错误');
        }
    }
    if ($start !== '' && $end !== '' && $start > $end) rechargeRankOut(1003, '开始日期不能晚于结束日期');
    $where = "r.status = '支付成功'";
    $params = [];
    if ($start !== '') { $where .= ' AND r.paid_at >= ?'; $params[] = $start . ' 00:00:00'; }
    if ($end !== '') { $where .= ' AND r.paid_at < ?'; $params[] = (new DateTime($end))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'; }
    // 先聚合订单，避免关联用户资料使订单重复累计。保留注销/资料缺失用户的真实充值。
    $group = "SELECT r.uid, SUM(COALESCE(r.payer_total,0)) AS recharge_total, COUNT(*) AS recharge_count FROM RechargeOrders r WHERE $where GROUP BY r.uid";
    $from = " FROM ($group) a LEFT JOIN users u ON u.uid = a.uid WHERE 1=1";
    if ($keyword !== '') {
        $from .= ' AND (CAST(a.uid AS CHAR) LIKE ? OR u.nickname LIKE ?)';
        $params[] = '%' . $keyword . '%'; $params[] = '%' . $keyword . '%';
    }
    $count = $db->query('SELECT COUNT(*) AS total' . $from, $params);
    if ($count === false) throw new Exception('count failed');
    $total = (int)($count[0]['total'] ?? 0);
    $page = min($page, max(1, (int)ceil($total / $size)));
    $offset = ($page-1)*$size;
    $rows = $db->query('SELECT a.uid, u.nickname, u.headimgurl, u.vip, u.vip_name, a.recharge_total, a.recharge_count' . $from . " ORDER BY a.recharge_total DESC, a.uid ASC LIMIT $offset, $size", $params);
    if ($rows === false) throw new Exception('list failed');
    foreach ($rows as $i => &$row) {
        $row['rank'] = $offset+$i+1;
        $row['recharge_total'] = (string)$row['recharge_total'];
        $row['headimgurl'] = $row['headimgurl'] ?: 'https://app.kwxapp.cn/img/logo.png';
    }
    unset($row);
    rechargeRankOut(200, 'success', ['page'=>$page,'page_size'=>$size,'total'=>$total,'list'=>$rows]);
} catch (Throwable $e) {
    error_log('recharge rankings: ' . $e->getMessage());
    rechargeRankOut(1005, '充值排行查询失败，请检查服务端日志');
}
