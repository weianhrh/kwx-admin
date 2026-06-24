<?php
require_once '../Database.php';

// 日志记录函数
function logMessage_log($message) {
    $logFile = __DIR__ . '/payment_global_config_api_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

header('Content-Type: application/json; charset=utf-8');

$database = new Database();

// 会话校验
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// 仅管理员可操作
if (!in_array((int)$user['role_id'], [1, 2])) {
    echo json_encode(['code' => 1002, 'msg' => '无权访问', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

function toBoolInt($value) {
    return ((string)$value === '1') ? 1 : 0;
}

// 确保默认配置存在
function ensureDefaultConfig($database) {
    $checkSql = "SELECT id FROM payment_global_config WHERE config_key = ? LIMIT 1";
    $config = $database->query($checkSql, ['default']);

    if (!$config || count($config) === 0) {
        $insertSql = "INSERT INTO payment_global_config
            (config_key, show_xykpay, show_line, show_jkpay, show_payment_web, show_exchange, show_text, text)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $database->query($insertSql, [
            'default',
            1,
            0,
            1,
            0,
            0,
            0,
            '充值多多，福利多多'
        ], true);
    }
}

try {
    ensureDefaultConfig($database);

    // 获取配置
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sql = "SELECT id, config_key, show_xykpay, show_line, show_jkpay, show_payment_web, show_exchange, show_text, text, updated_at
                FROM payment_global_config
                WHERE config_key = ?
                LIMIT 1";
        $result = $database->query($sql, ['default']);

        if (!$result || count($result) === 0) {
            echo json_encode(['code' => 404, 'msg' => '未找到配置', 'data' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'code' => 200,
            'msg' => '获取成功',
            'data' => $result[0]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 保存配置
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);

        $showXykpay = toBoolInt($_POST['show_xykpay'] ?? ($json['show_xykpay'] ?? 0));
        $showLine = toBoolInt($_POST['show_line'] ?? ($json['show_line'] ?? 0));
        $showJkpay = toBoolInt($_POST['show_jkpay'] ?? ($json['show_jkpay'] ?? 0));
        $showPaymentWeb = toBoolInt($_POST['show_payment_web'] ?? ($json['show_payment_web'] ?? 0));
        $showExchange = toBoolInt($_POST['show_exchange'] ?? ($json['show_exchange'] ?? 0));
        $showText = toBoolInt($_POST['show_text'] ?? ($json['show_text'] ?? 0));
        $text = trim($_POST['text'] ?? ($json['text'] ?? ''));

        if ($text === '') {
            $text = '充值多多，福利多多';
        }

        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, 255, 'UTF-8');
        } else {
            $text = substr($text, 0, 255);
        }

        $updateSql = "UPDATE payment_global_config
                      SET show_xykpay = ?,
                          show_line = ?,
                          show_jkpay = ?,
                          show_payment_web = ?,
                          show_exchange = ?,
                          show_text = ?,
                          text = ?
                      WHERE config_key = ?";

        $res = $database->query($updateSql, [
            $showXykpay,
            $showLine,
            $showJkpay,
            $showPaymentWeb,
            $showExchange,
            $showText,
            $text,
            'default'
        ], true);

        if ($res === false) {
            echo json_encode(['code' => 500, 'msg' => '保存失败', 'data' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }

        logMessage_log("payment_global_config updated by admin_user_id=" . ($user['id'] ?? 0));

        $sql = "SELECT id, config_key, show_xykpay, show_line, show_jkpay, show_payment_web, show_exchange, show_text, text, updated_at
                FROM payment_global_config
                WHERE config_key = ?
                LIMIT 1";
        $result = $database->query($sql, ['default']);

        echo json_encode([
            'code' => 200,
            'msg' => '保存成功',
            'data' => $result[0] ?? []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['code' => 405, 'msg' => 'Method Not Allowed', 'data' => []], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    logMessage_log("Exception: " . $e->getMessage());
    echo json_encode([
        'code' => 500,
        'msg' => '服务器错误：' . $e->getMessage(),
        'data' => []
    ], JSON_UNESCAPED_UNICODE);
}