<?php
/**
 * URL schedules for the two financial cron jobs do not use a query-string key.
 * Only a direct request from this server may run them; CLI remains available
 * for an operator to backfill a chosen date.
 */
function kwx8899RequireCronWebAccess() {
    if (PHP_SAPI === 'cli') {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    $remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $allowedIps = ['127.0.0.1', '::1', '39.96.207.0'];
    if (!in_array($remoteIp, $allowedIps, true)
        || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
        || !empty($_SERVER['HTTP_FORWARDED'])
        || !empty($_SERVER['HTTP_X_REAL_IP'])) {
        http_response_code(403);
        echo json_encode(['code' => 403, 'msg' => '仅允许本机计划任务通过 URL 调用'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit;
    }

    // No date defaults to yesterday; ?date=YYYY-MM-DD backfills a complete day.
    foreach (array_keys($_GET) as $parameter) {
        if ($parameter !== 'date' && $parameter !== 'check') {
            http_response_code(400);
            echo json_encode(['code' => 400, 'msg' => '未知 URL 参数'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            exit;
        }
    }
    if (isset($_GET['date'])) {
        $requestedDate = $_GET['date'];
        $parsed = is_string($requestedDate) ? DateTime::createFromFormat('!Y-m-d', $requestedDate) : false;
        if (!$parsed || $parsed->format('Y-m-d') !== $requestedDate || $requestedDate >= date('Y-m-d')) {
            http_response_code(400);
            echo json_encode(['code' => 400, 'msg' => '补账日期必须是已结束的 YYYY-MM-DD 自然日'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            exit;
        }
    }
    if (isset($_GET['check']) && $_GET['check'] !== '1') {
        http_response_code(400);
        echo json_encode(['code' => 400, 'msg' => 'check 参数只能是 1'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit;
    }

    // Allows BaoTa to check that the URL is reachable without settling money.
    if (isset($_GET['check']) && $_GET['check'] === '1') {
        echo json_encode(['code' => 0, 'msg' => 'URL 任务可访问；未执行结算'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit;
    }
}
