<?php
/**
 * /single/api/devMgr/cleanup_uploads.php
 * 清理违规上传：按目录 uploads/YYYYMMDD 删除 7 天前（含）的目录
 *
 * 运行方式：
 *   php /www/wwwroot/open.rcwulian.cn/single/api/devMgr/cleanup_uploads.php
 */

date_default_timezone_set('Asia/Shanghai');

$uploadsBase = __DIR__ . '/uploads';
$keepDays = 7; // ✅ 保留 7 天（最近 7 天不删）

$logFile = __DIR__ . '/cleanup_uploads_log.txt';

function log_line($msg, $logFile) {
    $ts = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND);
}

function rrmdir($dir) {
    if (!is_dir($dir)) return true;
    $items = scandir($dir);
    if ($items === false) return false;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            if (!rrmdir($path)) return false;
        } else {
            @unlink($path);
        }
    }
    return @rmdir($dir);
}

if (!is_dir($uploadsBase)) {
    log_line("uploadsBase 不存在：$uploadsBase", $logFile);
    echo "uploadsBase not found\n";
    exit;
}

$now = time();
// 阈值：小于等于 threshold 的日期目录将被删除（例如保留7天：删除今天-7天及更早）
$thresholdYmd = date('Ymd', strtotime("-{$keepDays} days", $now));

$dirs = scandir($uploadsBase);
if ($dirs === false) {
    log_line("无法读取目录：$uploadsBase", $logFile);
    echo "scan failed\n";
    exit;
}

$deleted = 0;
$skipped = 0;
$failed  = 0;

foreach ($dirs as $d) {
    if ($d === '.' || $d === '..') continue;

    $full = $uploadsBase . DIRECTORY_SEPARATOR . $d;
    if (!is_dir($full)) continue;

    // 只处理 YYYYMMDD 这种目录名
    if (!preg_match('/^\d{8}$/', $d)) {
        $skipped++;
        continue;
    }

    // 只删除 <= thresholdYmd 的目录
    if ($d <= $thresholdYmd) {
        $ok = rrmdir($full);
        if ($ok) {
            $deleted++;
            log_line("已删除：$full", $logFile);
        } else {
            $failed++;
            log_line("删除失败：$full", $logFile);
        }
    } else {
        $skipped++;
    }
}

$msg = "完成：deleted=$deleted skipped=$skipped failed=$failed thresholdYmd=$thresholdYmd";
log_line($msg, $logFile);
echo $msg . "\n";
