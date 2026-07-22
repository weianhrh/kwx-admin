<?php
// /api/venue/reviewImage.php
// PHP 7.3 compatible. Keep every response as clean JSON, even when an included
// legacy file emits a warning or terminates unexpectedly.

$GLOBALS['review_api_ob_base'] = ob_get_level();
$GLOBALS['review_api_response_sent'] = false;
ob_start();

error_reporting(E_ALL);
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

function review_api_log($level, $message, array $context = array())
{
    $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message;

    if (!empty($context)) {
        $encoded = @json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            $line .= ' ' . $encoded;
        }
    }

    $line .= PHP_EOL;
    $logDir = dirname(__DIR__) . '/log';
    $logFile = $logDir . '/review_image.log';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    if (@file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX) === false) {
        // error_log writes to the PHP/web-server log and does not enter the
        // HTTP response body. Suppress it as a final safety measure.
        @error_log(rtrim($line));
    }
}

function review_api_clean_output()
{
    $baseLevel = isset($GLOBALS['review_api_ob_base'])
        ? (int)$GLOBALS['review_api_ob_base']
        : 0;

    while (ob_get_level() > $baseLevel) {
        if (!@ob_end_clean()) {
            break;
        }
    }
}

function review_api_send($code, $message, array $data = array(), $httpStatus = 200)
{
    $GLOBALS['review_api_response_sent'] = true;
    review_api_clean_output();

    if (!headers_sent()) {
        http_response_code((int)$httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    $payload = array(
        'code' => (int)$code,
        'msg' => (string)$message,
        'data' => $data,
    );
    $json = @json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        $json = '{"code":1500,"msg":"服务器响应编码失败","data":{}}';
    }

    echo $json;
    exit;
}

// A small last-resort guard for legacy Database.php, whose constructor uses
// die() on connection failure. Normal execution always exits through
// review_api_send(), so an unsent shutdown means the endpoint ended abnormally.
register_shutdown_function(function () {
    if (!empty($GLOBALS['review_api_response_sent'])) {
        return;
    }

    $lastError = error_get_last();
    $context = array();
    if (is_array($lastError)) {
        $context = array(
            'type' => isset($lastError['type']) ? $lastError['type'] : null,
            'message' => isset($lastError['message']) ? $lastError['message'] : '',
            'file' => isset($lastError['file']) ? $lastError['file'] : '',
            'line' => isset($lastError['line']) ? $lastError['line'] : 0,
        );
    }

    review_api_log('error', 'Image review endpoint terminated before sending JSON', $context);
    $GLOBALS['review_api_response_sent'] = true;
    review_api_clean_output();

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    echo '{"code":1500,"msg":"服务器内部错误，请稍后重试","data":{}}';
});

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    review_api_send(1000, '仅支持 POST 请求', array(), 405);
}

$payload = $_POST;
$contentType = isset($_SERVER['CONTENT_TYPE']) ? (string)$_SERVER['CONTENT_TYPE'] : '';
if (stripos($contentType, 'application/json') !== false) {
    $rawBody = file_get_contents('php://input');
    $decoded = json_decode((string)$rawBody, true);

    if (!is_array($decoded)) {
        review_api_send(1000, '请求 JSON 格式无效', array(), 400);
    }
    $payload = $decoded;
}

$sessionToken = isset($_COOKIE['session_token'])
    ? trim((string)$_COOKIE['session_token'])
    : '';

if ($sessionToken === '') {
    review_api_log('warning', 'Unauthenticated image review request');
    review_api_send(1001, '请先登录', array(), 401);
}

$database = null;

try {
    require_once dirname(__DIR__) . '/Database.php';
    $database = new Database();

    $userRows = $database->query(
        'SELECT uid, role_id FROM admin_users WHERE session_token = ? LIMIT 1',
        array($sessionToken)
    );

    if ($userRows === false) {
        throw new RuntimeException('Failed to query reviewer account');
    }

    if (empty($userRows) || !in_array((int)$userRows[0]['role_id'], array(1, 2), true)) {
        review_api_log('warning', 'Unauthorized image review request');
        review_api_send(1002, '权限不足，仅管理员可操作', array(), 403);
    }

    $reviewerUid = (int)$userRows[0]['uid'];
    $venueIdRaw = isset($payload['venue_id']) ? $payload['venue_id'] : '';
    $venueIdText = is_scalar($venueIdRaw) ? trim((string)$venueIdRaw) : '';

    if ($venueIdText === '' || !ctype_digit($venueIdText) || (int)$venueIdText <= 0) {
        review_api_log('warning', 'Invalid venue id for image review', array(
            'reviewer_uid' => $reviewerUid,
        ));
        review_api_send(1003, '缺少或无效的场地ID', array(), 422);
    }

    $venueId = (int)$venueIdText;
    $requestImageUrl = isset($payload['oss_uploaded_url'])
        ? trim((string)$payload['oss_uploaded_url'])
        : '';

    // venues.image_url is the authoritative URL after upload_profile.php has
    // updated the venue avatar. This also supports old upload endpoints that
    // returned success without a URL.
    $venueRows = $database->query(
        'SELECT image_url FROM venues WHERE id = ? LIMIT 1',
        array($venueId)
    );

    if ($venueRows === false) {
        throw new RuntimeException('Failed to query venue image');
    }
    if (empty($venueRows)) {
        review_api_send(1003, '场地不存在', array(), 404);
    }

    $venueImageUrl = trim((string)($venueRows[0]['image_url'] ?? ''));
    $approvedImageUrl = $venueImageUrl !== '' ? $venueImageUrl : $requestImageUrl;

    if ($approvedImageUrl === '') {
        review_api_log('warning', 'Approved image URL is unavailable', array(
            'venue_id' => $venueId,
            'reviewer_uid' => $reviewerUid,
        ));
        review_api_send(
            1005,
            '图片已上传，但未取得新图片地址，待审核记录已保留',
            array(),
            422
        );
    }

    if ($requestImageUrl !== '' && $venueImageUrl !== '' && $requestImageUrl !== $venueImageUrl) {
        review_api_log('warning', 'Client image URL differs from venues.image_url; using database value', array(
            'venue_id' => $venueId,
            'reviewer_uid' => $reviewerUid,
        ));
    }

    // Always operate on the latest review row. The legacy upload endpoint may
    // already have changed its status to approved before this endpoint runs.
    $reviewRows = $database->query(
        "SELECT id, status, reviewer_uid, reviewed_at, image_url
         FROM venue_image_reviews
         WHERE venue_id = ?
         ORDER BY uploaded_at DESC, id DESC
         LIMIT 1",
        array($venueId)
    );

    if ($reviewRows === false) {
        throw new RuntimeException('Failed to query image review record');
    }
    if (empty($reviewRows)) {
        review_api_send(1004, '未找到该场地的图片审核记录', array(), 404);
    }

    $reviewId = (int)$reviewRows[0]['id'];
    $currentStatus = (string)$reviewRows[0]['status'];
    $reviewedAt = date('Y-m-d H:i:s');

    if ($currentStatus === 'pending') {
        $affected = $database->query(
            "UPDATE venue_image_reviews
             SET status = 'approved', reviewer_uid = ?, reviewed_at = ?, image_url = ?
             WHERE id = ? AND status = 'pending'",
            array($reviewerUid, $reviewedAt, $approvedImageUrl, $reviewId),
            true
        );

        if ($affected === false) {
            throw new RuntimeException('Failed to approve image review record');
        }
        // affected_rows=0 may mean another request approved it concurrently.
        // The final status check below decides whether this is a valid retry.
    } elseif ($currentStatus !== 'approved') {
        review_api_send(1004, '该图片已处理，当前状态：' . $currentStatus, array(
            'review_id' => $reviewId,
            'status' => $currentStatus,
        ), 409);
    }

    // Idempotent repair for the legacy sequence where upload_profile.php first
    // sets status=approved but leaves reviewer/time/final URL incomplete.
    $repaired = $database->query(
        "UPDATE venue_image_reviews
         SET reviewer_uid = CASE
                 WHEN reviewer_uid IS NULL OR reviewer_uid = 0 THEN ?
                 ELSE reviewer_uid
             END,
             reviewed_at = COALESCE(reviewed_at, ?),
             image_url = ?
         WHERE id = ? AND status = 'approved'",
        array($reviewerUid, $reviewedAt, $approvedImageUrl, $reviewId),
        true
    );

    if ($repaired === false) {
        throw new RuntimeException('Failed to repair approved image review metadata');
    }

    $finalRows = $database->query(
        'SELECT status, reviewer_uid, reviewed_at, image_url FROM venue_image_reviews WHERE id = ? LIMIT 1',
        array($reviewId)
    );

    if ($finalRows === false) {
        throw new RuntimeException('Failed to verify image review result');
    }
    if (empty($finalRows) || (string)$finalRows[0]['status'] !== 'approved') {
        $finalStatus = empty($finalRows) ? 'missing' : (string)$finalRows[0]['status'];
        review_api_log('warning', 'Image review status changed concurrently', array(
            'venue_id' => $venueId,
            'review_id' => $reviewId,
            'reviewer_uid' => $reviewerUid,
            'status' => $finalStatus,
        ));
        review_api_send(1004, '审核状态已发生变化，请刷新后重试', array(
            'review_id' => $reviewId,
            'status' => $finalStatus,
        ), 409);
    }

    // Redis is deliberately initialized only after authorization and a
    // successful database update. Reuse an existing lock without refreshing
    // its TTL; create one only when it is absent.
    $lockOk = false;
    $lockCreated = false;
    $lockInfo = null;
    $lockError = '';

    try {
        require_once __DIR__ . '/_venue_locks.php';
        $locks = new VenueLocks();
        $lockInfo = $locks->get('image', $venueId);

        if ($lockInfo === null) {
            $lockInfo = $locks->set(
                'image',
                $venueId,
                '场地图片审核通过',
                $reviewerUid
            );
            $lockCreated = true;
        }

        $lockOk = true;
    } catch (Throwable $lockException) {
        $lockError = '图片锁定服务异常，请检查 Redis';
        review_api_log('error', 'Failed to apply venue image lock', array(
            'venue_id' => $venueId,
            'review_id' => $reviewId,
            'reviewer_uid' => $reviewerUid,
            'exception' => get_class($lockException),
            'message' => $lockException->getMessage(),
        ));
    }

    review_api_log('info', 'Image review approved', array(
        'venue_id' => $venueId,
        'review_id' => $reviewId,
        'reviewer_uid' => $reviewerUid,
        'lock_ok' => $lockOk,
        'lock_created' => $lockCreated,
    ));

    if ($database !== null) {
        $database->close();
        $database = null;
    }

    review_api_send(0, $lockOk
        ? '图片已审核通过并锁定'
        : '图片已审核通过，但图片锁定服务异常', array(
        'review_id' => $reviewId,
        'venue_id' => $venueId,
        'status' => 'approved',
        'image_url' => $approvedImageUrl,
        'lock_ok' => $lockOk,
        'lock_created' => $lockCreated,
        'lock_until' => is_array($lockInfo) && isset($lockInfo['until_iso'])
            ? $lockInfo['until_iso']
            : null,
        'warning' => $lockError,
    ));
} catch (Throwable $exception) {
    review_api_log('error', 'Unhandled image review error', array(
        'exception' => get_class($exception),
        'message' => $exception->getMessage(),
    ));

    if ($database !== null) {
        try {
            $database->close();
        } catch (Throwable $closeException) {
            review_api_log('error', 'Failed to close database connection', array(
                'exception' => get_class($closeException),
                'message' => $closeException->getMessage(),
            ));
        }
    }

    review_api_send(1500, '服务器内部错误，请稍后重试', array(), 500);
}
