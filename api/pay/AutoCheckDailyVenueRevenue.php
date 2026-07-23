<?php
/**
 * /api/pay/AutoCheckDailyVenueRevenue.php
 *
 * 每日收益自动核对计划任务：默认核对全部尚未核对的记录，不限制日期。
 * 支持 PHP CLI 或携带密钥的 URL 计划任务。
 *
 * 手工补跑指定日期：
 * php /www/wwwroot/open.kwxapp.cn/api/pay/AutoCheckDailyVenueRevenue.php --date=2026-07-21
 */

// URL 计划任务必须携带此密钥，防止外部人员反复触发资金核对。
define('AUTO_CHECK_CRON_KEY', 'kwx_revenue_20260723_K8m4Q2x9');

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $requestKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
    if (!hash_equals(AUTO_CHECK_CRON_KEY, $requestKey)) {
        http_response_code(403);
        exit('Forbidden: cron key error');
    }
}

require_once __DIR__ . '/../Database.php';

date_default_timezone_set('Asia/Shanghai');

function taskLog($message)
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    echo $line . PHP_EOL;
    error_log('[AutoCheckDailyVenueRevenue] ' . $message);
}

function getTargetDate($isCli, $argv)
{
    $date = null;

    if ($isCli) {
        foreach ($argv as $arg) {
            if (strpos($arg, '--date=') === 0) {
                $date = substr($arg, 7);
                break;
            }
        }
    } elseif (isset($_GET['date']) && $_GET['date'] !== '') {
        $date = (string)$_GET['date'];
    }

    if ($date === null || $date === '') {
        return null;
    }

    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('日期格式错误，应为 YYYY-MM-DD');
    }

    return $date;
}

$lockHandle = fopen(sys_get_temp_dir() . '/kwx_auto_check_daily_revenue.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    taskLog('已有自动核对任务正在运行，本次退出');
    exit(2);
}

$database = null;

try {
    $targetDate = getTargetDate($isCli, isset($argv) ? $argv : []);
    $database = new Database();
    $conn = $database->getConnection();

    if (!$conn) {
        throw new RuntimeException('数据库连接失败');
    }

    // 沿用人工核对流水的 operator_id 规则，取一个有效的平台管理员 UID 记账。
    $operatorResult = $database->query(
        "SELECT uid FROM admin_users
         WHERE role_id IN (1, 2) AND uid IS NOT NULL AND uid > 0
         ORDER BY role_id ASC, id ASC
         LIMIT 1"
    );
    $operatorId = (int)($operatorResult[0]['uid'] ?? 0);
    if ($operatorId <= 0) {
        throw new RuntimeException('未找到可用于自动核对记账的平台管理员 UID');
    }

    taskLog(
        '开始自动核对，范围：' . ($targetDate ?: '全部未核对记录') .
        '，系统操作者UID：' . $operatorId
    );

    if ($targetDate) {
        $listStmt = $conn->prepare(
            "SELECT id FROM DailyVenueRevenue
             WHERE `date` = ? AND is_checked = 0
             ORDER BY `date` ASC, id ASC"
        );
    } else {
        $listStmt = $conn->prepare(
            "SELECT id FROM DailyVenueRevenue
             WHERE is_checked = 0
             ORDER BY `date` ASC, id ASC"
        );
    }
    if (!$listStmt) {
        throw new RuntimeException('查询待核对记录预处理失败：' . $conn->error);
    }

    if ($targetDate) {
        $listStmt->bind_param('s', $targetDate);
    }
    $listStmt->execute();
    $listResult = $listStmt->get_result();
    $ids = [];
    while ($row = $listResult->fetch_assoc()) {
        $ids[] = (int)$row['id'];
    }
    $listStmt->close();

    $success = 0;
    $skipped = 0;
    $failed = 0;
    $totalAmount = 0.0;

    foreach ($ids as $id) {
        try {
            $database->beginTransaction();

            // 先锁收益记录，避免和人工核对或另一个任务重复入账。
            $revenueStmt = $conn->prepare(
                "SELECT id, venue_id, `date`, total_revenue, is_checked
                 FROM DailyVenueRevenue
                 WHERE id = ?
                 FOR UPDATE"
            );
            if (!$revenueStmt) {
                throw new RuntimeException('收益记录预处理失败：' . $conn->error);
            }
            $revenueStmt->bind_param('i', $id);
            $revenueStmt->execute();
            $revenueResult = $revenueStmt->get_result();
            $revenue = $revenueResult ? $revenueResult->fetch_assoc() : null;
            $revenueStmt->close();

            if (!$revenue || (int)$revenue['is_checked'] === 1) {
                $database->rollBack();
                $skipped++;
                continue;
            }

            $venueId = (int)$revenue['venue_id'];
            $amount = (float)$revenue['total_revenue'];
            $revenueDate = $revenue['date'];
            $sourceType = 'DailyVenueRevenue';

            // 没有 venue_funds 的场地不能入账，保留未核对状态供后续处理。
            $fundStmt = $conn->prepare(
                "SELECT account_balance FROM venue_funds WHERE venue_id = ? FOR UPDATE"
            );
            if (!$fundStmt) {
                throw new RuntimeException('场地资金预处理失败：' . $conn->error);
            }
            $fundStmt->bind_param('i', $venueId);
            $fundStmt->execute();
            $fundResult = $fundStmt->get_result();
            $fund = $fundResult ? $fundResult->fetch_assoc() : null;
            $fundStmt->close();

            if (!$fund) {
                $database->rollBack();
                taskLog("跳过：场地 {$venueId} 未绑定提现账户，记录ID {$id}");
                $skipped++;
                continue;
            }

            $updateStmt = $conn->prepare(
                "UPDATE venue_funds
                 SET account_balance = account_balance + ?
                 WHERE venue_id = ?"
            );
            if (!$updateStmt) {
                throw new RuntimeException('更新余额预处理失败：' . $conn->error);
            }
            $updateStmt->bind_param('di', $amount, $venueId);
            // 金额为 0.00 时余额不会发生变化，MySQL affected_rows 会返回 0，
            // 但这仍是一次有效核对；前面已经确认并锁定了该 venue_funds 记录。
            if (!$updateStmt->execute()) {
                throw new RuntimeException('更新余额失败：' . $updateStmt->error);
            }
            $updateStmt->close();

            $balanceStmt = $conn->prepare(
                "SELECT account_balance FROM venue_funds WHERE venue_id = ? FOR UPDATE"
            );
            if (!$balanceStmt) {
                throw new RuntimeException('查询新余额预处理失败：' . $conn->error);
            }
            $balanceStmt->bind_param('i', $venueId);
            $balanceStmt->execute();
            $balanceResult = $balanceStmt->get_result();
            $balance = $balanceResult ? $balanceResult->fetch_assoc() : null;
            $balanceStmt->close();
            if (!$balance) {
                throw new RuntimeException('读取更新后余额失败');
            }
            $newBalance = (float)$balance['account_balance'];

            // 唯一键继续作为最终防重保障，remarks 标记为计划任务自动核对。
            $changeStmt = $conn->prepare(
                "INSERT INTO fund_changes (
                    venue_id, change_type, change_amount, balance_after_change,
                    change_reason, operator_id, remarks, revenue_date,
                    source_type, source_id
                 ) VALUES (?, 'revenue', ?, ?, '收益自动核对入账', ?,
                           '每日计划任务自动核对', ?, ?, ?)"
            );
            if (!$changeStmt) {
                throw new RuntimeException('资金流水预处理失败：' . $conn->error);
            }
            $changeStmt->bind_param(
                'iddissi',
                $venueId,
                $amount,
                $newBalance,
                $operatorId,
                $revenueDate,
                $sourceType,
                $id
            );
            if (!$changeStmt->execute()) {
                $error = $changeStmt->error;
                $errno = $changeStmt->errno;
                $changeStmt->close();
                if ((int)$errno === 1062) {
                    throw new RuntimeException('账期流水已存在，已阻止重复入账');
                }
                throw new RuntimeException('写入资金流水失败：' . $error);
            }
            $changeStmt->close();

            $checkStmt = $conn->prepare(
                "UPDATE DailyVenueRevenue
                 SET is_checked = 1
                 WHERE id = ? AND venue_id = ? AND is_checked = 0"
            );
            if (!$checkStmt) {
                throw new RuntimeException('核对状态预处理失败：' . $conn->error);
            }
            $checkStmt->bind_param('ii', $id, $venueId);
            if (!$checkStmt->execute() || $checkStmt->affected_rows !== 1) {
                throw new RuntimeException('核对状态更新失败或记录已被处理');
            }
            $checkStmt->close();

            $database->commit();
            $success++;
            $totalAmount += $amount;
            taskLog("成功：场地 {$venueId}，记录ID {$id}，金额 {$amount}");
        } catch (Throwable $itemError) {
            try {
                $database->rollBack();
            } catch (Throwable $ignore) {
            }
            $failed++;
            taskLog("失败：记录ID {$id}，原因：" . $itemError->getMessage());
        }
    }

    taskLog(
        "核对完成：范围 " . ($targetDate ?: '全部未核对记录') . "，待处理 " . count($ids) .
        " 条，成功 {$success} 条，跳过 {$skipped} 条，失败 {$failed} 条，入账合计 " .
        number_format($totalAmount, 2, '.', '')
    );

    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    taskLog('任务终止：' . $e->getMessage());
    exit(1);
} finally {
    if ($database) {
        $database->close();
    }
    if ($lockHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
