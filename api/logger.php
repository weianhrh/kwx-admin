<?php
/**
 * 日志记录脚本
 * 保存日志到当前目录下的 app.log 文件中
 *
 * 使用方法：
 *   require_once 'logger.php';
 *   log_message('这里是日志内容', 'INFO');
 */

/**
 * 记录日志信息
 *
 * @param string $message 需要记录的日志内容
 * @param string $level   日志级别，如 INFO、WARNING、ERROR，默认为 INFO
 * @param string $filename 日志文件名，默认为 app.log
 * @return bool 返回 true 表示写入成功，false 表示失败
 */
function log_message($message, $level = 'INFO', $filename = 'app.log') {
    // 获取当前时间戳
    $timestamp = date('Y-m-d H:i:s');
    // 格式化日志内容
    $log_entry = sprintf("%s [%s] %s%s", $timestamp, $level, $message, PHP_EOL);
    // 设定日志文件路径，保存到当前目录
    $filepath = __DIR__ . DIRECTORY_SEPARATOR . $filename;
    // 以追加模式写入日志，并加锁防止并发写入问题
    return file_put_contents($filepath, $log_entry, FILE_APPEND | LOCK_EX) !== false;
}
?>
