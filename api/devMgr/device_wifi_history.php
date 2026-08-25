<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/venue_scope.php';
require_once __DIR__ . '/../lib/device_wifi_whitelist.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

function wifi_history_out(int $code, string $msg, array $data = [], int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 从文件尾部逐块倒序读取，日志很大时也只占用少量内存。
 */
function wifi_history_reverse_lines(string $file): Generator
{
    if (!is_file($file) || !is_readable($file)) {
        return;
    }

    $handle = fopen($file, 'rb');
    if ($handle === false) {
        return;
    }

    try {
        fseek($handle, 0, SEEK_END);
        $position = (int)ftell($handle);
        $buffer = '';

        while ($position > 0) {
            $readSize = min(8192, $position);
            $position -= $readSize;
            fseek($handle, $position);
            $chunk = fread($handle, $readSize);
            if ($chunk === false) {
                break;
            }

            $buffer = $chunk . $buffer;
            $parts = explode("\n", $buffer);
            $buffer = array_shift($parts);

            for ($index = count($parts) - 1; $index >= 0; $index--) {
                $line = trim($parts[$index]);
                if ($line !== '') {
                    yield $line;
                }
            }
        }

        $buffer = trim($buffer);
        if ($buffer !== '') {
            yield $buffer;
        }
    } finally {
        fclose($handle);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    wifi_history_out(405, '仅支持 GET 请求', [], 405);
}

$sessionToken = trim((string)($_COOKIE['session_token'] ?? ''));
if ($sessionToken === '') {
    wifi_history_out(1001, '用户未登录或会话已过期', [], 401);
}

$serialNumber = trim((string)($_GET['serial_number'] ?? ''));
$roomId = trim((string)($_GET['room_id'] ?? ''));
if ($serialNumber === '' && $roomId === '') {
    wifi_history_out(400, 'serial_number 和 room_id 至少填写一个', [], 400);
}

$limit = (int)($_GET['limit'] ?? 100);
$limit = max(1, min($limit, 100));

$database = new Database();
$user = $database->getUserBySessionToken($sessionToken);
if (!$user || empty($user['role_id'])) {
    $database->close();
    wifi_history_out(1001, '用户未登录或无权访问', [], 401);
}

$roleId = (int)$user['role_id'];
$device = null;
$venueId = 0;

if ($roomId !== '') {
    // 摄像管理页面按 device_information.room_id 查询。
    $devices = $database->query(
        "SELECT id, device_id, room_id
         FROM device_information
         WHERE room_id = ?
         LIMIT 1",
        [$roomId]
    );
    $device = is_array($devices) ? ($devices[0] ?? null) : null;
    if (!$device) {
        $database->close();
        wifi_history_out(404, '未找到目标摄像设备', [], 404);
    }
} else {
    // 加盟商设备管理页面按车辆序列号查询并校验场地权限。
    if (!in_array($roleId, [1, 2, 3, 4], true)) {
        $database->close();
        wifi_history_out(403, '当前角色无权查看设备改网记录', [], 403);
    }

    $devices = $database->query(
        "SELECT serial_number, name, bind_site
         FROM vehicles
         WHERE serial_number = ?
         LIMIT 1",
        [$serialNumber]
    );
    $device = is_array($devices) ? ($devices[0] ?? null) : null;

    if (!$device) {
        $database->close();
        wifi_history_out(404, '未找到目标设备', [], 404);
    }

    $venueId = (int)($device['bind_site'] ?? 0);
    if (!venue_scope_can_access($database, $user, $venueId)) {
        $database->close();
        wifi_history_out(403, '当前账号无权查看该场地设备记录', [], 403);
    }

    if (in_array($roleId, [3, 4], true) && !device_wifi_whitelist_enabled($venueId)) {
        $database->close();
        wifi_history_out(403, '该场地尚未开放设备改网功能', [], 403);
    }
}

$database->close();

$records = [];
foreach (wifi_history_reverse_lines(__DIR__ . '/device_wifi_command.log') as $line) {
    $record = json_decode($line, true);
    if (!is_array($record)) {
        continue;
    }
    if ($roomId !== '') {
        $recordRoomId = (string)($record['verified_room_id'] ?? $record['room_id'] ?? '');
        if ($recordRoomId !== $roomId) {
            continue;
        }
    } elseif ((string)($record['serial_number'] ?? '') !== $serialNumber) {
        continue;
    }

    $records[] = [
        'logged_at' => (string)($record['logged_at'] ?? ''),
        'status' => (string)($record['status'] ?? ''),
        'wifi_essid' => (string)($record['wifi_essid'] ?? ''),
        'wifi_password' => (string)($record['wifi_password'] ?? ''),
        'room_id' => (string)($record['verified_room_id'] ?? $record['room_id'] ?? ''),
        'operator_uid' => (int)($record['operator_uid'] ?? 0),
        'operator_username' => (string)($record['operator_username'] ?? ''),
        'operator_role_id' => (int)($record['operator_role_id'] ?? 0),
        'app_name' => (string)($record['app_name'] ?? ''),
        'zego_code' => array_key_exists('zego_code', $record) ? (int)$record['zego_code'] : null,
        'zego_message' => (string)($record['zego_message'] ?? ''),
        'reason' => (string)($record['reason'] ?? ''),
    ];

    if (count($records) >= $limit) {
        break;
    }
}

wifi_history_out(0, '查询成功', [
    'device' => [
        'serial_number' => $serialNumber,
        'device_id' => (string)($device['device_id'] ?? ''),
        'room_id' => $roomId,
        'name' => (string)($device['name'] ?? ''),
        'venue_id' => $venueId,
    ],
    'count' => count($records),
    'records' => $records,
]);
