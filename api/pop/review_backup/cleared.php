<?php
require_once '../../Database.php';
// https://open.rcwulian.cn/api/pop/review_backup/cleared.php?date=2026-05-13
header('Content-Type: application/json; charset=utf-8');

// 日志记录函数
function logMessage_log($message) {
    $logFile = __DIR__ . '/xxx.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// 规范化路径：支持绝对路径；相对路径时按项目根目录拼接
function normalize_local_path($path) {
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }

    // URL 不处理
    if (preg_match('#^https?://#i', $path)) {
        return '';
    }

    // 绝对路径
    if ($path[0] === '/') {
        return $path;
    }

    // 相对路径：按当前文件上两级目录作为基准
    $baseDir = realpath(__DIR__ . '/../../');
    if (!$baseDir) {
        return '';
    }

    return $baseDir . '/' . ltrim($path, '/');
}

try {
    $database = new Database();
    $conn = $database->getConnection();

    if (!$conn) {
        throw new Exception('数据库连接失败');
    }

    // 传入日期，如 ?date=2026-04-13
    // 不传默认今天
    $date = isset($_GET['date']) && trim($_GET['date']) !== ''
        ? trim($_GET['date'])
        : date('Y-m-d');

    // 校验日期格式
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        throw new Exception('date参数格式错误，正确格式如：2026-04-13');
    }

    $startTime = $dt->format('Y-m-d 00:00:00');
    $endTime   = (clone $dt)->modify('+1 day')->format('Y-m-d 00:00:00');

    $riskLevel = 'high';
    $reviewStatus = 'cleared';

    $sql = "
        SELECT id, local_image_path
        FROM device_violation_archive
        WHERE risk_level = ?
          AND review_status = ?
          AND cleared_at >= ?
          AND cleared_at < ?
          AND local_image_path IS NOT NULL
          AND local_image_path <> ''
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('SQL预处理失败：' . $conn->error);
    }

    $stmt->bind_param("ssss", $riskLevel, $reviewStatus, $startTime, $endTime);

    if (!$stmt->execute()) {
        throw new Exception('SQL执行失败：' . $stmt->error);
    }

    $result = $stmt->get_result();

    $deletedCount = 0;
    $missingCount = 0;
    $failedCount  = 0;
    $skippedCount = 0;
    $details = [];

    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['id'];
        $rawPath = (string)$row['local_image_path'];
        $filePath = normalize_local_path($rawPath);

        if ($filePath === '') {
            $skippedCount++;
            $details[] = [
                'id' => $id,
                'local_image_path' => $rawPath,
                'status' => 'skip_invalid_path'
            ];
            logMessage_log("跳过 id={$id}，路径无效：{$rawPath}");
            continue;
        }

        if (!file_exists($filePath)) {
            $missingCount++;
            $details[] = [
                'id' => $id,
                'local_image_path' => $rawPath,
                'real_path' => $filePath,
                'status' => 'file_not_exists'
            ];
            logMessage_log("文件不存在 id={$id}：{$filePath}");
            continue;
        }

        if (!is_file($filePath)) {
            $skippedCount++;
            $details[] = [
                'id' => $id,
                'local_image_path' => $rawPath,
                'real_path' => $filePath,
                'status' => 'not_a_file'
            ];
            logMessage_log("跳过 id={$id}，不是文件：{$filePath}");
            continue;
        }

        if (@unlink($filePath)) {
            $deletedCount++;
            $details[] = [
                'id' => $id,
                'local_image_path' => $rawPath,
                'real_path' => $filePath,
                'status' => 'deleted'
            ];
            logMessage_log("删除成功 id={$id}：{$filePath}");
        } else {
            $failedCount++;
            $details[] = [
                'id' => $id,
                'local_image_path' => $rawPath,
                'real_path' => $filePath,
                'status' => 'unlink_failed'
            ];
            logMessage_log("删除失败 id={$id}：{$filePath}");
        }
    }

    $stmt->close();
    $database->close();

    echo json_encode([
        'code' => 200,
        'msg' => '执行完成',
        'date' => $date,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'risk_level' => $riskLevel,
        'review_status' => $reviewStatus,
        'deleted_count' => $deletedCount,
        'missing_count' => $missingCount,
        'failed_count' => $failedCount,
        'skipped_count' => $skippedCount,
        'details' => $details
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    logMessage_log("执行失败：" . $e->getMessage());

    echo json_encode([
        'code' => 500,
        'msg' => '执行失败：' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}