<?php
require_once __DIR__ . '/../auth/_common.php';

auth_json_headers();

function jsonOut($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function isValidDateYmd($value) {
    return is_string($value) && (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

function bodyParams() {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST;
}

function rewardRatePercent($amount, $reward) {
    $amount = (float)$amount;
    if ($amount <= 0) {
        return 0.0;
    }
    return round(((float)$reward / $amount) * 100, 2);
}

function normalizeIds($value) {
    if (!is_array($value)) {
        $value = [$value];
    }
    $ids = [];
    foreach ($value as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

function buildLogList($rows) {
    $list = [];
    foreach ($rows as $row) {
        $orderAmount = (float)($row['order_amount'] ?? 0);
        $rewardAmount = (float)($row['reward_amount'] ?? 0);
        $list[] = [
            'id' => (int)$row['id'],
            'order_id' => (string)$row['order_id'],
            'reward_date' => (string)$row['reward_date'],
            'promotion_venue_id' => (int)$row['promotion_venue_id'],
            'promotion_venue_name' => (string)($row['promotion_venue_name'] ?? ''),
            'consumer_venue_id' => (int)$row['consumer_venue_id'],
            'consumer_venue_name' => (string)($row['consumer_venue_name'] ?? ''),
            'invitee_uid' => (int)$row['invitee_uid'],
            'invitee_nickname' => (string)($row['invitee_nickname'] ?? ''),
            'invite_code' => (string)$row['invite_code'],
            'order_amount' => number_format($orderAmount, 2, '.', ''),
            'reward_amount' => number_format($rewardAmount, 2, '.', ''),
            'reward_rate_percent' => rewardRatePercent($orderAmount, $rewardAmount),
            'reward_status' => (string)$row['reward_status'],
            'settlement_batch_id' => (string)($row['settlement_batch_id'] ?? ''),
            'generated_at' => (string)$row['generated_at'],
            'settled_at' => (string)($row['settled_at'] ?? ''),
            'cancelled_at' => (string)($row['cancelled_at'] ?? ''),
            'remark' => (string)($row['remark'] ?? ''),
        ];
    }
    return $list;
}

function makeSettlementNo($venueId) {
    return 'PRS' . date('YmdHis') . sprintf('%04d', (int)$venueId) . mt_rand(1000, 9999);
}

function bindDynamicParams($stmt, $types, $values) {
    $refs = [$types];
    foreach ($values as $key => $value) {
        $refs[] = &$values[$key];
    }
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

$database = new Database();
$conn = $database->getConnection();
$transactionStarted = false;

try {
    $sessionToken = (string)($_COOKIE[AUTH_COOKIE] ?? '');
    if (!$sessionToken) {
        jsonOut(1001, '用户未登录或会话已过期');
    }

    // 与 KWX 的菜单登录校验保持一致，支持带 session_expires 的账号表。
    $users = auth_has_column($database, 'admin_users', 'session_expires')
        ? $database->query('SELECT * FROM admin_users WHERE session_token = ? AND session_expires > NOW() LIMIT 1', [$sessionToken])
        : $database->query('SELECT * FROM admin_users WHERE session_token = ? LIMIT 1', [$sessionToken]);
    $user = $users[0] ?? null;
    if (!$user || empty($user['role_id'])) {
        jsonOut(1001, '用户未登录或无权访问');
    }

    $roleId = (int)$user['role_id'];
    if (!in_array($roleId, [1, 2], true)) {
        jsonOut(1002, '当前账号无推广收益结算管理权限');
    }

    $operatorUid = (int)($user['uid'] ?? 0);
    $action = $_GET['action'] ?? $_POST['action'] ?? 'summary';
    $params = bodyParams();
    if (!empty($params['action'])) {
        $action = $params['action'];
    }

    if ($action === 'summary') {
        $startDate = trim($_GET['start_date'] ?? '');
        $endDate = trim($_GET['end_date'] ?? '');
        $venueId = (int)($_GET['venue_id'] ?? 0);

        // 2026-09-23 KWX 8899 固化脚本使用 daily_17_snapshot 作为来源标记。
        $where = "vpr.reward_status = 'pending' AND vpr.source_type = 'daily_17_snapshot'";
        $bind = [];
        if ($startDate !== '') {
            if (!isValidDateYmd($startDate)) {
                jsonOut(400, '开始日期格式不正确');
            }
            $where .= " AND vpr.reward_date >= ?";
            $bind[] = $startDate;
        }
        if ($endDate !== '') {
            if (!isValidDateYmd($endDate)) {
                jsonOut(400, '结束日期格式不正确');
            }
            $where .= " AND vpr.reward_date <= ?";
            $bind[] = $endDate;
        }
        if ($venueId > 0) {
            $where .= " AND vpr.promotion_venue_id = ?";
            $bind[] = $venueId;
        }

        $summaryRows = $database->query(
            "SELECT
                vpr.promotion_venue_id,
                pv.venue_name AS promotion_venue_name,
                COUNT(*) AS order_count,
                COUNT(DISTINCT vpr.invitee_uid) AS invitee_count,
                MIN(vpr.reward_date) AS start_date,
                MAX(vpr.reward_date) AS end_date,
                ROUND(COALESCE(SUM(vpr.order_amount), 0), 2) AS order_amount,
                ROUND(COALESCE(SUM(vpr.reward_amount), 0), 2) AS reward_amount
             FROM venue_promotion_reward_logs vpr
             LEFT JOIN venues pv ON pv.id = vpr.promotion_venue_id
             WHERE {$where}
             GROUP BY vpr.promotion_venue_id, pv.venue_name
             ORDER BY reward_amount DESC, order_count DESC
             LIMIT 500",
            $bind
        );
        if ($summaryRows === false) {
            jsonOut(500, '待结算汇总查询失败');
        }

        $totalRows = $database->query(
            "SELECT
                COUNT(*) AS order_count,
                COUNT(DISTINCT promotion_venue_id) AS venue_count,
                ROUND(COALESCE(SUM(order_amount), 0), 2) AS order_amount,
                ROUND(COALESCE(SUM(reward_amount), 0), 2) AS reward_amount
             FROM venue_promotion_reward_logs vpr
             WHERE {$where}",
            $bind
        );
        if ($totalRows === false || count($totalRows) === 0) {
            jsonOut(500, '待结算总览查询失败');
        }

        jsonOut(200, 'ok', [
            'summary' => [
                'venue_count' => (int)$totalRows[0]['venue_count'],
                'order_count' => (int)$totalRows[0]['order_count'],
                'order_amount' => (string)$totalRows[0]['order_amount'],
                'reward_amount' => (string)$totalRows[0]['reward_amount'],
            ],
            'list' => $summaryRows,
        ]);
    }

    if ($action === 'pending_detail') {
        $venueId = (int)($_GET['venue_id'] ?? 0);
        $startDate = trim($_GET['start_date'] ?? '');
        $endDate = trim($_GET['end_date'] ?? '');
        if ($venueId <= 0) {
            jsonOut(400, '缺少场地ID');
        }

        $where = "vpr.reward_status = 'pending' AND vpr.source_type = 'daily_17_snapshot' AND vpr.promotion_venue_id = ?";
        $bind = [$venueId];
        if ($startDate !== '') {
            if (!isValidDateYmd($startDate)) {
                jsonOut(400, '开始日期格式不正确');
            }
            $where .= " AND vpr.reward_date >= ?";
            $bind[] = $startDate;
        }
        if ($endDate !== '') {
            if (!isValidDateYmd($endDate)) {
                jsonOut(400, '结束日期格式不正确');
            }
            $where .= " AND vpr.reward_date <= ?";
            $bind[] = $endDate;
        }

        $rows = $database->query(
            "SELECT
                vpr.*,
                pv.venue_name AS promotion_venue_name,
                cv.venue_name AS consumer_venue_name,
                u.nickname AS invitee_nickname
             FROM venue_promotion_reward_logs vpr
             LEFT JOIN venues pv ON pv.id = vpr.promotion_venue_id
             LEFT JOIN venues cv ON cv.id = vpr.consumer_venue_id
             LEFT JOIN users u ON u.uid = vpr.invitee_uid
             WHERE {$where}
             ORDER BY vpr.reward_date DESC, vpr.generated_at DESC, vpr.id DESC
             LIMIT 2000",
            $bind
        );
        if ($rows === false) {
            jsonOut(500, '待结算明细查询失败');
        }
        jsonOut(200, 'ok', ['list' => buildLogList($rows)]);
    }

    if ($action === 'exception_detail') {
        $venueId = (int)($_GET['venue_id'] ?? 0);
        $startDate = trim($_GET['start_date'] ?? '');
        $endDate = trim($_GET['end_date'] ?? '');
        $where = "vpr.source_type = 'daily_17_snapshot' AND vpr.reward_status IN ('frozen', 'cancelled')";
        $bind = [];
        if ($venueId > 0) {
            $where .= ' AND vpr.promotion_venue_id = ?';
            $bind[] = $venueId;
        }
        if ($startDate !== '') {
            if (!isValidDateYmd($startDate)) jsonOut(400, '开始日期格式不正确');
            $where .= ' AND vpr.reward_date >= ?';
            $bind[] = $startDate;
        }
        if ($endDate !== '') {
            if (!isValidDateYmd($endDate)) jsonOut(400, '结束日期格式不正确');
            $where .= ' AND vpr.reward_date <= ?';
            $bind[] = $endDate;
        }
        $rows = $database->query(
            "SELECT vpr.*, pv.venue_name AS promotion_venue_name,
                    cv.venue_name AS consumer_venue_name, u.nickname AS invitee_nickname
             FROM venue_promotion_reward_logs vpr
             LEFT JOIN venues pv ON pv.id = vpr.promotion_venue_id
             LEFT JOIN venues cv ON cv.id = vpr.consumer_venue_id
             LEFT JOIN users u ON u.uid = vpr.invitee_uid
             WHERE {$where}
             ORDER BY vpr.reward_date DESC, vpr.id DESC LIMIT 2000",
            $bind
        );
        if ($rows === false) jsonOut(500, '异常明细查询失败');
        jsonOut(200, 'ok', ['list' => buildLogList($rows)]);
    }

    if ($action === 'batches') {
        $startDate = trim($_GET['start_date'] ?? '');
        $endDate = trim($_GET['end_date'] ?? '');
        $venueId = (int)($_GET['venue_id'] ?? 0);
        $status = trim($_GET['status'] ?? '');

        $where = "EXISTS (
            SELECT 1 FROM venue_promotion_reward_logs vpr
            WHERE vpr.settlement_batch_id = s.id
              AND vpr.source_type = 'daily_17_snapshot'
        )";
        $bind = [];
        if ($startDate !== '') {
            if (!isValidDateYmd($startDate)) {
                jsonOut(400, '开始日期格式不正确');
            }
            $where .= " AND s.settlement_end_date >= ?";
            $bind[] = $startDate;
        }
        if ($endDate !== '') {
            if (!isValidDateYmd($endDate)) {
                jsonOut(400, '结束日期格式不正确');
            }
            $where .= " AND s.settlement_start_date <= ?";
            $bind[] = $endDate;
        }
        if ($venueId > 0) {
            $where .= " AND s.promotion_venue_id = ?";
            $bind[] = $venueId;
        }
        if ($status !== '') {
            $where .= " AND s.settlement_status = ?";
            $bind[] = $status;
        }

        $rows = $database->query(
            "SELECT
                s.*,
                v.venue_name AS promotion_venue_name,
                au.username AS operator_name
             FROM venue_promotion_reward_settlements s
             LEFT JOIN venues v ON v.id = s.promotion_venue_id
             LEFT JOIN admin_users au ON au.uid = s.operator_uid
             WHERE {$where}
             ORDER BY s.settled_at DESC, s.id DESC
             LIMIT 500",
            $bind
        );
        if ($rows === false) {
            jsonOut(500, '结算批次查询失败');
        }
        jsonOut(200, 'ok', ['list' => $rows]);
    }

    if ($action === 'batch_detail') {
        $batchId = (int)($_GET['batch_id'] ?? 0);
        if ($batchId <= 0) {
            jsonOut(400, '缺少结算批次ID');
        }
        $batchRows = $database->query(
            "SELECT s.*, v.venue_name AS promotion_venue_name, au.username AS operator_name
             FROM venue_promotion_reward_settlements s
             LEFT JOIN venues v ON v.id = s.promotion_venue_id
             LEFT JOIN admin_users au ON au.uid = s.operator_uid
             WHERE s.id = ?
               AND EXISTS (
                   SELECT 1 FROM venue_promotion_reward_logs matched
                   WHERE matched.settlement_batch_id = s.id
                     AND matched.source_type = 'daily_17_snapshot'
               )
             LIMIT 1",
            [$batchId]
        );
        if ($batchRows === false || count($batchRows) === 0) {
            jsonOut(404, '结算批次不存在');
        }
        $rows = $database->query(
            "SELECT
                vpr.*,
                pv.venue_name AS promotion_venue_name,
                cv.venue_name AS consumer_venue_name,
                u.nickname AS invitee_nickname
             FROM venue_promotion_reward_logs vpr
             LEFT JOIN venues pv ON pv.id = vpr.promotion_venue_id
             LEFT JOIN venues cv ON cv.id = vpr.consumer_venue_id
             LEFT JOIN users u ON u.uid = vpr.invitee_uid
             WHERE vpr.settlement_batch_id = ?
               AND vpr.source_type = 'daily_17_snapshot'
             ORDER BY vpr.reward_date DESC, vpr.id DESC
             LIMIT 3000",
            [$batchId]
        );
        if ($rows === false) {
            jsonOut(500, '结算批次明细查询失败');
        }
        jsonOut(200, 'ok', ['batch' => $batchRows[0], 'list' => buildLogList($rows)]);
    }

    if ($action === 'create_settlement') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            jsonOut(405, '仅支持 POST 请求');
        }
        $venueIds = normalizeIds($params['venue_ids'] ?? []);
        $startDate = trim($params['start_date'] ?? '');
        $endDate = trim($params['end_date'] ?? '');
        $remark = trim($params['remark'] ?? '');
        if (empty($venueIds)) {
            jsonOut(400, '请选择需要结算的场地');
        }
        if ($startDate === '' || $endDate === '' || !isValidDateYmd($startDate) || !isValidDateYmd($endDate)) {
            jsonOut(400, '请选择正确的结算日期范围');
        }
        if ($startDate > $endDate) {
            jsonOut(400, '开始日期不能大于结束日期');
        }

        $created = [];
        $skipped = [];
        $database->beginTransaction();
        $transactionStarted = true;

        foreach ($venueIds as $venueId) {
            $summaryRows = $database->query(
                "SELECT
                    COUNT(*) AS order_count,
                    MIN(reward_date) AS start_date,
                    MAX(reward_date) AS end_date,
                    ROUND(COALESCE(SUM(order_amount), 0), 2) AS order_amount,
                    ROUND(COALESCE(SUM(reward_amount), 0), 2) AS reward_amount
                 FROM venue_promotion_reward_logs
                 WHERE promotion_venue_id = ?
                   AND reward_status = 'pending'
                   AND source_type = 'daily_17_snapshot'
                   AND reward_date >= ?
                   AND reward_date <= ?",
                [$venueId, $startDate, $endDate]
            );
            if ($summaryRows === false || count($summaryRows) === 0) {
                throw new Exception('查询待结算汇总失败');
            }
            $orderCount = (int)$summaryRows[0]['order_count'];
            if ($orderCount <= 0) {
                $skipped[] = ['venue_id' => $venueId, 'reason' => '无待结算记录'];
                continue;
            }

            $settlementNo = makeSettlementNo($venueId);
            $stmt = $conn->prepare(
                "INSERT INTO venue_promotion_reward_settlements
                    (settlement_no, promotion_venue_id, settlement_start_date, settlement_end_date, order_count, order_amount, reward_amount, settlement_status, operator_uid, settled_at, remark, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'settled', ?, NOW(), ?, NOW(), NOW())"
            );
            if (!$stmt) {
                throw new Exception('创建结算批次预处理失败：' . $conn->error);
            }
            $settlementStartDate = (string)$summaryRows[0]['start_date'];
            $settlementEndDate = (string)$summaryRows[0]['end_date'];
            $orderAmount = (float)$summaryRows[0]['order_amount'];
            $rewardAmount = (float)$summaryRows[0]['reward_amount'];
            $stmt->bind_param('sissiddis', $settlementNo, $venueId, $settlementStartDate, $settlementEndDate, $orderCount, $orderAmount, $rewardAmount, $operatorUid, $remark);
            if (!$stmt->execute()) {
                throw new Exception('创建结算批次失败：' . $stmt->error);
            }
            $batchId = $stmt->insert_id;
            $stmt->close();

            $stmt = $conn->prepare(
                "UPDATE venue_promotion_reward_logs
                 SET reward_status = 'settled',
                     settlement_batch_id = ?,
                     settled_at = NOW(),
                     operator_uid = ?,
                     remark = CASE WHEN ? = '' THEN remark ELSE ? END
                 WHERE promotion_venue_id = ?
                   AND reward_status = 'pending'
                   AND source_type = 'daily_17_snapshot'
                   AND reward_date >= ?
                   AND reward_date <= ?"
            );
            if (!$stmt) {
                throw new Exception('更新推广收益明细预处理失败：' . $conn->error);
            }
            $stmt->bind_param('iississ', $batchId, $operatorUid, $remark, $remark, $venueId, $startDate, $endDate);
            if (!$stmt->execute()) {
                throw new Exception('更新推广收益明细失败：' . $stmt->error);
            }
            $updatedRows = $stmt->affected_rows;
            $stmt->close();

            if ($updatedRows !== $orderCount) {
                throw new Exception("结算批次 {$batchId} 明细数量异常，预期 {$orderCount}，实际 {$updatedRows}");
            }

            $created[] = [
                'batch_id' => $batchId,
                'settlement_no' => $settlementNo,
                'venue_id' => $venueId,
                'order_count' => $orderCount,
                'order_amount' => number_format($orderAmount, 2, '.', ''),
                'reward_amount' => number_format($rewardAmount, 2, '.', ''),
            ];
        }

        $database->commit();
        $transactionStarted = false;
        jsonOut(200, '结算批次生成完成', ['created' => $created, 'skipped' => $skipped]);
    }

    if ($action === 'mark_exception') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            jsonOut(405, '仅支持 POST 请求');
        }
        $ids = normalizeIds($params['ids'] ?? []);
        $targetStatus = trim($params['target_status'] ?? '');
        $remark = trim($params['remark'] ?? '');
        if (empty($ids)) {
            jsonOut(400, '请选择需要处理的明细');
        }
        if (!in_array($targetStatus, ['pending', 'frozen', 'cancelled'], true)) {
            jsonOut(400, '异常处理状态不正确');
        }
        if ($targetStatus !== 'pending' && $remark === '') {
            jsonOut(400, '冻结或撤销异常收益时必须填写原因');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $database->beginTransaction();
        $transactionStarted = true;
        $sql = "UPDATE venue_promotion_reward_logs
                SET reward_status = ?,
                    cancelled_at = CASE WHEN ? = 'cancelled' THEN NOW() ELSE cancelled_at END,
                    operator_uid = ?,
                    remark = CASE WHEN ? = '' THEN remark ELSE ? END
                WHERE id IN ({$placeholders})
                  AND source_type = 'daily_17_snapshot'
                  AND reward_status IN ('pending', 'frozen')";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('异常处理预处理失败：' . $conn->error);
        }
        $bindTypes = 'ssiss' . $types;
        $bindValues = array_merge([$targetStatus, $targetStatus, $operatorUid, $remark, $remark], $ids);
        bindDynamicParams($stmt, $bindTypes, $bindValues);
        if (!$stmt->execute()) {
            throw new Exception('异常处理失败：' . $stmt->error);
        }
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        $database->commit();
        $transactionStarted = false;
        jsonOut(200, '异常处理完成', ['affected_rows' => $affectedRows]);
    }

    jsonOut(400, '未知操作类型');
} catch (Throwable $e) {
    if ($transactionStarted) {
        try {
            $database->rollBack();
        } catch (Throwable $ignored) {
        }
    }
    error_log('PromotionRewardSettlementAdmin error: ' . $e->getMessage());
    jsonOut(500, '服务器内部错误，请查看服务端日志');
} finally {
    $database->close();
}
