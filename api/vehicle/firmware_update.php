<?php
// /api/vehicle/firmware_update.php
header('Content-Type: application/json; charset=utf-8');

require_once '../Database.php';

function json_out($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function logMessage($message) {
    $logFile = __DIR__ . '/fw_update_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

/**
 * 发送 TCP 指令到 rcwulian.cn:6363
 * 返回 [ok(bool), err(string), sent(int)]
 */
function tcp_send_command($host, $port, $command, $timeoutSec = 3) {
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeoutSec);
    if (!$fp) {
        return [false, "connect fail errno=$errno err=$errstr", 0];
    }

    stream_set_timeout($fp, $timeoutSec);

    $len = strlen($command);
    $sent = 0;
    while ($sent < $len) {
        $w = @fwrite($fp, substr($command, $sent));
        if ($w === false || $w === 0) {
            fclose($fp);
            return [false, "write fail (sent=$sent/$len)", $sent];
        }
        $sent += $w;
    }

    // 可选：尝试读一小段回应（如果你的 TCP 服务端会回 ACK）
    // $reply = @fgets($fp, 256);
    fclose($fp);

    return [true, "sent=$sent", $sent];
}

// 1) 校验登录
$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    json_out(1001, '用户未登录或会话已过期');
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !isset($user['role_id'])) {
    json_out(1001, '用户未登录或无权访问');
}

$role_id = (int)$user['role_id'];

// 2) 权限：你现在按钮前端是“管理员可见”，后端也必须再卡一次
// 你原来 isPlatformAdmin = role_id 1 或 2
if (!in_array($role_id, [1, 2], true)) {
    json_out(1003, '无权限：仅管理员可执行固件更新');
}

// 3) 取参数
$input = file_get_contents('php://input');
$data  = json_decode($input, true);
logMessage("收到前端数据：" . $input);

$serial_number = $data['serial_number'] ?? '';
$serial_number = trim((string)$serial_number);

if ($serial_number === '') {
    json_out(1005, '缺少参数 serial_number');
}

// 可选：做一下格式过滤（按你设备 SN 规则调整）
if (!preg_match('/^[A-Za-z0-9_-]{4,64}$/', $serial_number)) {
    json_out(1005, 'serial_number 格式不合法');
}

// 4) 拼指令并下发
$host = 'rcwulian.cn';
$port = 6363;

// 你要求的命令格式：+F+{serial_number}+UP#rcwulian.cn-
$cmd = "+F+{$serial_number}+UP#rcwulian.cn-";

logMessage("准备下发：SN={$serial_number} CMD={$cmd}");

list($ok, $err, $sent) = tcp_send_command($host, $port, $cmd, 3);
if (!$ok) {
    logMessage("下发失败：{$err}");
    json_out(2001, '下发失败：' . $err, ['serial_number' => $serial_number, 'command' => $cmd]);
}

logMessage("下发成功：{$err}");
json_out(200, '已下发更新指令', ['serial_number' => $serial_number, 'command' => $cmd, 'sent' => $sent]);
