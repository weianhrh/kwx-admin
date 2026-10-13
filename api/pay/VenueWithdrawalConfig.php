<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../Database.php';

function out($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

// Database::query() 在部分 SQL 错误时返回 false，必须检查，不能漏写日志后提交。
function withdrawalConfigQuery($db, $sql, $params = [], $isUpdate = false) {
    $result = $db->query($sql, $params, $isUpdate);
    if ($result === false) throw new RuntimeException('提现配置数据库操作失败');
    return $result;
}

function withdrawalPercent($value, $label) {
    if (!is_scalar($value) || !is_numeric($value)) out(1002, $label . '必须是有效数字');
    $number = (float)$value;
    if (!is_finite($number) || $number < 0 || $number > 100) {
        out(1002, $label . '必须在0%到100%之间');
    }
    // 与 DECIMAL(5,2) 的实际保存精度保持一致，日志记录最终入库值。
    return number_format($number, 2, '.', '');
}

function withdrawalLogDate($value) {
    if ($value === '') return null;
    if (!is_string($value)) out(1002, '日期格式必须为YYYY-MM-DD');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Shanghai'));
    if (!$date || $date->format('Y-m-d') !== $value) out(1002, '日期格式必须为YYYY-MM-DD');
    return $date;
}

$db = new Database();
$token = $_COOKIE['session_token'] ?? '';
$user = $token !== '' ? $db->getUserBySessionToken($token) : null;
if (!$user) out(1001, '用户未登录或会话已过期');
if (!in_array((int)$user['role_id'], [1, 2], true)) out(1003, '无权访问，仅管理员可操作');

try {
    withdrawalConfigQuery($db, "CREATE TABLE IF NOT EXISTS venue_withdrawal_configs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        venue_id INT NOT NULL,
        withdraw_ratio DECIMAL(5,2) NOT NULL DEFAULT 20.00 COMMENT '平台扣除比例(%)',
        withdrawal_fee_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '提现手续费率(%)',
        updated_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY uk_venue_id (venue_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='场地提现比例及手续费配置'", [], true);

    // 只追加记录；保留修改时的账号名称、角色和场地名称，避免更名影响追溯。
    withdrawalConfigQuery($db, "CREATE TABLE IF NOT EXISTS venue_withdrawal_config_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        venue_id INT NOT NULL,
        venue_name VARCHAR(255) NOT NULL DEFAULT '',
        change_type VARCHAR(16) NOT NULL COMMENT 'create/update/no_change',
        old_configured TINYINT UNSIGNED NOT NULL DEFAULT 0,
        old_withdraw_ratio DECIMAL(5,2) NOT NULL,
        new_withdraw_ratio DECIMAL(5,2) NOT NULL,
        old_withdrawal_fee_rate DECIMAL(5,2) NOT NULL,
        new_withdrawal_fee_rate DECIMAL(5,2) NOT NULL,
        operator_id INT NOT NULL COMMENT '后台账号admin_users.uid',
        operator_role_id INT NOT NULL,
        operator_name VARCHAR(255) NOT NULL DEFAULT '',
        ip VARCHAR(45) NOT NULL DEFAULT '' COMMENT '服务器看到的连接IP',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '北京时间，精确到秒',
        PRIMARY KEY (id),
        KEY idx_venue_time (venue_id, created_at, id),
        KEY idx_time (created_at, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='场地提现配置修改记录'", [], true);
} catch (Throwable $e) {
    error_log('提现配置表初始化失败：' . $e->getMessage());
    out(1005, '提现配置或修改记录表初始化失败，请检查数据库权限');
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
if ($action === 'venues') {
    try {
        out(0, '成功', withdrawalConfigQuery($db, "SELECT id, venue_name FROM venues ORDER BY id ASC"));
    } catch (Throwable $e) {
        error_log('读取提现配置场地失败：' . $e->getMessage());
        out(1005, '场地列表读取失败');
    }
}

if ($action === 'save') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') out(1002, '保存配置请使用POST请求');
    $venueId = filter_var($_POST['venue_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($venueId === false) out(1002, '请选择场地');
    $ratio = withdrawalPercent($_POST['withdraw_ratio'] ?? null, '平台扣除比例');
    $fee = withdrawalPercent($_POST['withdrawal_fee_rate'] ?? null, '手续费');
    if ((int)round((float)$ratio * 100) + (int)round((float)$fee * 100) > 10000) {
        out(1002, '平台扣除比例与手续费合计不能超过100%');
    }

    // 当前后台账号主键为 uid，兼容使用 id 的账号结构。
    $operatorId = (int)($user['uid'] ?? $user['id'] ?? 0);
    if ($operatorId <= 0) out(1003, '无法确认操作账号，请重新登录');
    $operatorName = (string)$operatorId;
    foreach (['username', 'nickname', 'name', 'account'] as $field) {
        if (isset($user[$field]) && trim((string)$user[$field]) !== '') {
            $operatorName = (string)$user[$field];
            break;
        }
    }
    // 不将客户端可自行填写的 X-Forwarded-For 当成可信审计IP。
    $remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ip = filter_var($remoteIp, FILTER_VALIDATE_IP) !== false ? $remoteIp : '';

    $conn = $db->getConnection();
    $transactionStarted = false;
    try {
        if (!$conn->begin_transaction()) throw new RuntimeException('无法开启提现配置事务');
        $transactionStarted = true;
        // 先锁定场地行，首次配置的并发保存也按顺序读取真实旧值。
        $venue = withdrawalConfigQuery($db,
            "SELECT id, venue_name FROM venues WHERE id = ? LIMIT 1 FOR UPDATE", [$venueId]);
        if (!$venue) {
            if (!$conn->rollback()) throw new RuntimeException('无法回滚提现配置事务');
            $transactionStarted = false;
            out(1004, '场地不存在');
        }
        $before = withdrawalConfigQuery($db,
            "SELECT withdraw_ratio, withdrawal_fee_rate FROM venue_withdrawal_configs
             WHERE venue_id = ? LIMIT 1 FOR UPDATE", [$venueId]);
        $oldConfigured = $before ? 1 : 0;
        $oldRatio = $before ? number_format((float)$before[0]['withdraw_ratio'], 2, '.', '') : '20.00';
        $oldFee = $before ? number_format((float)$before[0]['withdrawal_fee_rate'], 2, '.', '') : '0.00';
        $changeType = !$oldConfigured ? 'create' : (($oldRatio !== $ratio || $oldFee !== $fee) ? 'update' : 'no_change');
        $savedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');

        withdrawalConfigQuery($db, "INSERT INTO venue_withdrawal_configs
            (venue_id, withdraw_ratio, withdrawal_fee_rate, updated_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE withdraw_ratio=VALUES(withdraw_ratio),
            withdrawal_fee_rate=VALUES(withdrawal_fee_rate), updated_by=VALUES(updated_by),
            updated_at=VALUES(updated_at)", [$venueId, $ratio, $fee, $operatorId, $savedAt, $savedAt], true);

        $logRows = withdrawalConfigQuery($db, "INSERT INTO venue_withdrawal_config_logs
            (venue_id, venue_name, change_type, old_configured,
             old_withdraw_ratio, new_withdraw_ratio, old_withdrawal_fee_rate, new_withdrawal_fee_rate,
             operator_id, operator_role_id, operator_name, ip, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$venueId, $venue[0]['venue_name'], $changeType, $oldConfigured,
             $oldRatio, $ratio, $oldFee, $fee, $operatorId, (int)$user['role_id'], $operatorName, $ip, $savedAt], true);
        if ((int)$logRows !== 1) throw new RuntimeException('提现配置修改记录未写入');
        $logId = (string)$conn->insert_id;
        if (!$conn->commit()) throw new RuntimeException('提现配置事务提交失败');
        $transactionStarted = false;
        out(0, '保存成功，已记录操作日志', ['log_id' => $logId, 'created_at' => $savedAt, 'change_type' => $changeType]);
    } catch (Throwable $e) {
        if ($transactionStarted) {
            try {
                if (!$conn->rollback()) error_log('提现配置事务回滚失败');
            } catch (Throwable $rollbackError) {
                error_log('提现配置事务回滚失败：' . $rollbackError->getMessage());
            }
        }
        error_log('提现配置保存失败：' . $e->getMessage());
        out(1005, '保存失败，请刷新后重试');
    }
}

if ($action === 'logs') {
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
    $pageSize = filter_var($_GET['page_size'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    if ($page === false || $pageSize === false) out(1002, '分页参数错误');
    $start = withdrawalLogDate($_GET['start_date'] ?? '');
    $end = withdrawalLogDate($_GET['end_date'] ?? '');
    if ($start && $end && $start > $end) out(1002, '开始日期不能晚于结束日期');
    $where = [];
    $params = [];
    if (isset($_GET['venue_id']) && $_GET['venue_id'] !== '') {
        $venueId = filter_var($_GET['venue_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($venueId === false) out(1002, '场地ID错误');
        $where[] = 'l.venue_id = ?';
        $params[] = $venueId;
    }
    $keyword = $_GET['keyword'] ?? '';
    if (!is_string($keyword)) out(1002, '搜索内容格式错误');
    $keyword = trim($keyword);
    if ($keyword !== '') {
        $where[] = '(l.venue_name LIKE ? OR CAST(l.venue_id AS CHAR) LIKE ?)';
        $like = '%' . $keyword . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($start) {
        $where[] = 'l.created_at >= ?';
        $params[] = $start->format('Y-m-d H:i:s');
    }
    if ($end) {
        $where[] = 'l.created_at < ?';
        $params[] = $end->modify('+1 day')->format('Y-m-d H:i:s');
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $offset = ($page - 1) * $pageSize;
    try {
        $count = withdrawalConfigQuery($db, "SELECT COUNT(*) AS total FROM venue_withdrawal_config_logs l" . $whereSql, $params);
        $rows = withdrawalConfigQuery($db,
            "SELECT l.* FROM venue_withdrawal_config_logs l" . $whereSql .
            " ORDER BY l.created_at DESC, l.id DESC LIMIT {$pageSize} OFFSET {$offset}", $params);
        out(0, '成功', ['rows' => $rows, 'total' => (int)$count[0]['total'],
            'page' => $page, 'page_size' => $pageSize, 'timezone' => 'Asia/Shanghai']);
    } catch (Throwable $e) {
        error_log('读取提现配置修改记录失败：' . $e->getMessage());
        out(1005, '修改记录读取失败');
    }
}

if ($action !== 'list') out(1002, '不支持的操作');
$keyword = $_GET['keyword'] ?? '';
if (!is_string($keyword)) out(1002, '搜索内容格式错误');
$keyword = trim($keyword);
$sql = "SELECT v.id AS venue_id, v.venue_name,
        COALESCE(c.withdraw_ratio, 20.00) AS withdraw_ratio,
        COALESCE(c.withdrawal_fee_rate, 0.00) AS withdrawal_fee_rate,
        CASE WHEN c.id IS NULL THEN 0 ELSE 1 END AS configured,
        c.updated_by, c.updated_at
        FROM venues v LEFT JOIN venue_withdrawal_configs c ON c.venue_id=v.id";
$params = [];
if ($keyword !== '') {
    $sql .= " WHERE v.venue_name LIKE ? OR CAST(v.id AS CHAR) LIKE ?";
    $like = '%' . $keyword . '%';
    $params = [$like, $like];
}
$sql .= " ORDER BY v.id ASC";
try {
    out(0, '成功', withdrawalConfigQuery($db, $sql, $params));
} catch (Throwable $e) {
    error_log('读取提现配置失败：' . $e->getMessage());
    out(1005, '提现配置读取失败');
}
