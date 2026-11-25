<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/_common.php';
require_once __DIR__ . '/../lib/WechatAppPayConfig.php';

auth_json_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    auth_out(405, '仅支持查询');
}

try {
    $token = $_COOKIE[AUTH_COOKIE] ?? '';
    if (!is_string($token) || $token === '') {
        auth_out(1001, '未登录或会话已过期');
    }

    $db = new Database();
    $sessionSql = 'SELECT role_id FROM admin_users WHERE session_token = ?';
    if (auth_has_column($db, 'admin_users', 'session_expires')) {
        $sessionSql .= ' AND session_expires > NOW()';
    }
    $users = $db->query($sessionSql . ' LIMIT 1', [$token]);
    if ($users === false) {
        throw new RuntimeException('Cannot read admin session');
    }
    if (!$users) {
        auth_out(1001, '未登录或会话已过期');
    }
    if (!in_array((int) $users[0]['role_id'], [1, 2], true)) {
        auth_out(1002, '无权查看商户月统计');
    }

    $timezone = new DateTimeZone('Asia/Shanghai');
    $month = $_GET['month'] ?? (new DateTimeImmutable('now', $timezone))->format('Y-m');
    if (!is_string($month) || !preg_match('/\A[1-9][0-9]{3}-(?:0[1-9]|1[0-2])\z/', $month) || $month === '9999-12') {
        auth_out(400, '请选择有效月份');
    }
    $first = new DateTimeImmutable($month . '-01', $timezone);
    // 日期界限同时适用于 DATETIME 和带 T 的微信支付时间字符串。
    $start = $first->format('Y-m-d');
    $end = $first->modify('+1 month')->format('Y-m-d');
    if ($db->query("SET SESSION time_zone = '+08:00'", [], true) === false) {
        throw new RuntimeException('Cannot set report connection timezone');
    }

    $ids = array_map('strval', array_keys(WechatAppPayConfig::MERCHANTS));
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    // 商户来自下单时的固定记录。用 DECIMAL 在同一条查询内求各商户和全月合计，金额单位为元。
    $rows = $db->query(
        "SELECT r.merchant_id, COUNT(*) AS order_count,
                COUNT(NULLIF(TRIM(o.payer_total), '')) AS amount_count,
                SUM(CAST(o.payer_total AS DECIMAL(20, 2))) AS amount
         FROM RechargeOrders o
         JOIN kwx_wechat_app_order_routes r ON r.order_number = o.order_number
         WHERE o.status = '支付成功' AND o.paid_at >= ? AND o.paid_at < ?
           AND r.channel IN ('recharge', 'package') AND r.merchant_id IN ($placeholders)
         GROUP BY r.merchant_id WITH ROLLUP",
        array_merge([$start, $end], $ids)
    );
    if ($rows === false) {
        throw new RuntimeException('Cannot read payment totals');
    }

    $total = ['order_count' => 0, 'amount' => '0.00'];
    $merchants = [];
    foreach ($ids as $id) {
        $merchants[$id] = ['merchant_id' => $id] + $total;
    }
    foreach ($rows as $row) {
        $count = (int) $row['order_count'];
        if ($count !== (int) $row['amount_count']) {
            auth_out(500, '当月有已付款订单缺少实付金额，请核对订单记录');
        }
        if ($count === 0) {
            continue;
        }
        $summary = ['order_count' => $count, 'amount' => (string) $row['amount']];
        if ($row['merchant_id'] === null) {
            $total = $summary;
        } else {
            $id = (string) $row['merchant_id'];
            $merchants[$id] = ['merchant_id' => $id] + $summary;
        }
    }
    auth_out(200, '查询成功', [
        'month' => $month,
        'merchants' => array_values($merchants),
        'total' => $total,
    ]);
} catch (Throwable $e) {
    error_log('[kwx-wechat-monthly] ' . $e->getMessage());
    auth_out(500, '商户月统计读取失败，请检查后台日志');
}
