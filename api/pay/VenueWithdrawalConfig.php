<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../Database.php';

function out($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = new Database();
$token = $_COOKIE['session_token'] ?? '';
$user = $token !== '' ? $db->getUserBySessionToken($token) : null;
if (!$user) out(1001, '用户未登录或会话已过期');
if (!in_array((int)$user['role_id'], [1, 2], true)) out(1003, '无权访问，仅管理员可操作');

$db->query("CREATE TABLE IF NOT EXISTS venue_withdrawal_configs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    venue_id INT NOT NULL,
    withdraw_ratio DECIMAL(5,2) NOT NULL DEFAULT 20.00 COMMENT '平台扣除比例(%)',
    withdrawal_fee_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '提现手续费率(%)',
    updated_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uk_venue_id (venue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='场地提现比例及手续费配置'", [], true);

$action = $_REQUEST['action'] ?? 'list';
if ($action === 'venues') {
    $rows = $db->query("SELECT id, venue_name FROM venues ORDER BY id ASC");
    out(0, '成功', $rows ?: []);
}

if ($action === 'save') {
    $venueId = (int)($_POST['venue_id'] ?? 0);
    $ratio = isset($_POST['withdraw_ratio']) ? (float)$_POST['withdraw_ratio'] : -1;
    $fee = isset($_POST['withdrawal_fee_rate']) ? (float)$_POST['withdrawal_fee_rate'] : -1;
    if ($venueId <= 0) out(1002, '请选择场地');
    if ($ratio < 0 || $ratio > 100) out(1002, '平台扣除比例必须在0%到100%之间');
    if ($fee < 0 || $fee > 100) out(1002, '手续费必须在0%到100%之间');
    if ($ratio + $fee > 100) out(1002, '平台扣除比例与手续费合计不能超过100%');
    $venue = $db->query("SELECT id FROM venues WHERE id = ? LIMIT 1", [$venueId]);
    if (!$venue) out(1004, '场地不存在');
    $ok = $db->query("INSERT INTO venue_withdrawal_configs
        (venue_id, withdraw_ratio, withdrawal_fee_rate, updated_by)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE withdraw_ratio=VALUES(withdraw_ratio),
        withdrawal_fee_rate=VALUES(withdrawal_fee_rate), updated_by=VALUES(updated_by)",
        [$venueId, $ratio, $fee, (int)$user['id']], true);
    if ($ok === false) out(1005, '保存失败');
    out(0, '保存成功');
}

$keyword = trim($_GET['keyword'] ?? '');
$sql = "SELECT v.id AS venue_id, v.venue_name,
        COALESCE(c.withdraw_ratio, 20.00) AS withdraw_ratio,
        COALESCE(c.withdrawal_fee_rate, 0.00) AS withdrawal_fee_rate,
        CASE WHEN c.id IS NULL THEN 0 ELSE 1 END AS configured,
        c.updated_at
        FROM venues v LEFT JOIN venue_withdrawal_configs c ON c.venue_id=v.id";
$params = [];
if ($keyword !== '') {
    $sql .= " WHERE v.venue_name LIKE ? OR CAST(v.id AS CHAR) LIKE ?";
    $like = '%' . $keyword . '%';
    $params = [$like, $like];
}
$sql .= " ORDER BY v.id ASC";
out(0, '成功', $db->query($sql, $params) ?: []);
