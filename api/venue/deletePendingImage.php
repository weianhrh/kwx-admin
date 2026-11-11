<?php
// KWX pending image cleanup fix, 2026-11-10. Compatible with PHP 7.3+.
// This endpoint only cleans temporary files. It never changes review status.

$GLOBALS['kwx_cleanup_ob_level'] = ob_get_level();
$GLOBALS['kwx_cleanup_response_sent'] = false;
$GLOBALS['kwx_cleanup_context'] = array();
ob_start();
@ini_set('display_errors', '0');
date_default_timezone_set('Asia/Shanghai');

function kwx_cleanup_log($event, array $context = array())
{
    $entry = array_merge(array(
        'time' => date('Y-m-d H:i:s'),
        'version' => '20261110-1',
        'event' => $event,
    ), $GLOBALS['kwx_cleanup_context'], $context);
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line !== false) {
        // Keep the log beside this PHP file; never record cookies or tokens.
        @file_put_contents(__DIR__ . '/delete_pending_image.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function kwx_cleanup_reply($code, $message, array $data = array())
{
    $GLOBALS['kwx_cleanup_response_sent'] = true;
    kwx_cleanup_log($code === 0 ? 'cleanup_ok' : 'cleanup_failed', array(
        'code' => $code,
        'message' => $message,
        'result' => $data,
    ));
    while (ob_get_level() > $GLOBALS['kwx_cleanup_ob_level']) {
        if (!@ob_end_clean()) {
            break;
        }
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(array('code' => $code, 'msg' => $message, 'data' => $data),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Legacy Database.php can terminate directly on connection failure.
register_shutdown_function(function () {
    if (!$GLOBALS['kwx_cleanup_response_sent']) {
        kwx_cleanup_reply(1500, '清理接口异常，请检查当前目录的 delete_pending_image.log');
    }
});

// A readable/searchable directory is required before treating a missing file
// as already cleaned. A missing or inaccessible directory is not success.
function kwx_cleanup_check_directory($directory)
{
    clearstatcache(true, $directory);
    if (!@is_dir($directory)) {
        kwx_cleanup_reply(1005, '待审核图片目录不存在或无法访问，请检查 pending_images 目录');
    }
    if (!@is_readable($directory) || (DIRECTORY_SEPARATOR === '/' && !@is_executable($directory))) {
        kwx_cleanup_reply(1005, '待审核图片目录无法读取或访问，请检查目录权限');
    }
}

function kwx_cleanup_is_absent($directory, $basename, $filepath)
{
    kwx_cleanup_check_directory($directory);
    clearstatcache(true, $filepath);
    if (@lstat($filepath) !== false) {
        return false;
    }
    // Distinguish an absent entry from a path that cannot be stat'ed. Only
    // scan when lstat fails; an inaccessible directory must never look empty.
    $entries = @scandir($directory);
    if ($entries === false) {
        kwx_cleanup_reply(1005, '无法检查待审核图片目录，请检查目录权限');
    }
    if (in_array($basename, $entries, true)) {
        kwx_cleanup_reply(1005, '待审核文件存在但无法读取其状态，请检查文件权限');
    }
    return true;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        kwx_cleanup_reply(1003, '仅支持 POST 请求');
    }

    $sessionToken = $_COOKIE['session_token'] ?? '';
    if (!is_string($sessionToken) || $sessionToken === '') {
        kwx_cleanup_reply(1001, '未登录');
    }

    require_once dirname(__DIR__) . '/Database.php';
    $database = new Database();
    $users = $database->query(
        'SELECT role_id FROM admin_users WHERE session_token = ? LIMIT 1',
        array($sessionToken)
    );
    if ($users === false) {
        throw new RuntimeException('reviewer_query_failed');
    }
    if (empty($users) || !in_array((int)$users[0]['role_id'], array(1, 2), true)) {
        kwx_cleanup_reply(1002, '权限不足');
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        kwx_cleanup_reply(1003, '请求 JSON 格式无效');
    }
    $venueIdRaw = $input['venue_id'] ?? null;
    $venueIdText = (is_int($venueIdRaw) || is_string($venueIdRaw)) ? (string)$venueIdRaw : '';
    if ($venueIdText === '' || !ctype_digit($venueIdText) || (int)$venueIdText <= 0) {
        kwx_cleanup_reply(1003, '缺少或无效的场地ID');
    }
    $venueId = (int)$venueIdText;
    $filename = $input['filename'] ?? '';
    if (!is_string($filename) || strpos($filename, 'pending_images/') !== 0) {
        kwx_cleanup_reply(1003, '无效的待审核图片路径');
    }
    $basename = substr($filename, strlen('pending_images/'));
    // Both legacy and current uploaders use venue_<id>_<suffix>.<extension>.
    // Keep old uppercase extensions and filenames without a random suffix.
    if ($basename === '' || strlen($basename) > 255 || $basename === '.' || $basename === '..'
        || strpos($basename, '/') !== false || strpos($basename, '\\') !== false
        || preg_match('/[\x00-\x1F\x7F]/', $basename)
        || strpos($basename, 'venue_' . $venueId . '_') !== 0) {
        kwx_cleanup_reply(1003, '文件名无效或与场地ID不匹配');
    }

    $directory = __DIR__ . '/pending_images';
    $filepath = $directory . '/' . $basename;
    $GLOBALS['kwx_cleanup_context'] = array(
        'venue_id' => $venueId,
        'filename' => $filename,
        'filepath' => $filepath,
    );

    // Match the existing frontend: approve/reject first, then clean up.
    // Never mask missing source images for a review that is still pending.
    $reviews = $database->query(
        'SELECT id, status FROM venue_image_reviews WHERE venue_id = ? ORDER BY uploaded_at DESC, id DESC LIMIT 1',
        array($venueId)
    );
    if ($reviews === false) {
        throw new RuntimeException('review_state_query_failed');
    }
    if (empty($reviews)) {
        kwx_cleanup_reply(1004, '未找到图片审核记录，未执行文件清理');
    }
    $reviewStatus = (string)$reviews[0]['status'];
    $result = array('review_id' => (int)$reviews[0]['id'], 'review_status' => $reviewStatus);
    if (!in_array($reviewStatus, array('approved', 'rejected'), true)) {
        kwx_cleanup_reply(1004, '图片尚未完成审核或已有新的待审核图片，未执行清理', $result);
    }

    kwx_cleanup_check_directory($directory);
    if (is_link($directory)) {
        kwx_cleanup_reply(1005, '待审核图片目录为符号链接，请核对真实目录');
    }
    if (kwx_cleanup_is_absent($directory, $basename, $filepath)) {
        $result['cleanup_status'] = 'already_missing';
        kwx_cleanup_reply(0, '待审核文件已不存在，无需重复清理', $result);
    }

    if (is_link($filepath) || !is_file($filepath)) {
        kwx_cleanup_reply(1003, '目标不是普通待审核图片文件，未执行删除');
    }
    if (@unlink($filepath)) {
        $result['cleanup_status'] = 'deleted';
        kwx_cleanup_reply(0, '待审核文件已清理', $result);
    }

    // A parallel cleanup may have removed the same file after our first check.
    $deleteError = error_get_last();
    if (kwx_cleanup_is_absent($directory, $basename, $filepath)) {
        $result['cleanup_status'] = 'already_missing';
        kwx_cleanup_reply(0, '待审核文件已不存在，无需重复清理', $result);
    }
    kwx_cleanup_log('unlink_failed', array(
        'filesystem_error' => is_array($deleteError) ? ($deleteError['message'] ?? '') : '',
    ));
    kwx_cleanup_reply(1006, '待审核文件删除失败，请检查目录权限及当前目录日志', $result);
} catch (Throwable $exception) {
    kwx_cleanup_log('exception', array('exception_type' => get_class($exception)));
    kwx_cleanup_reply(1500, '清理接口异常，请检查当前目录的 delete_pending_image.log');
}
