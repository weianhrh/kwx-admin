<?php
header('Content-Type: application/json; charset=utf-8');
// open.kwxapp.cn/single/api/redis_sync_receive.php
function ret($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function isAllowedKey($key) {
    if (!is_string($key) || $key === '') {
        return false;
    }

    // 允许 tk:*
    if (strpos($key, 'tk:') === 0) {
        return true;
    }

    // 允许 ws:uid:*:game
    if (preg_match('/^ws:uid:[^:]+:game$/', $key)) {
        return true;
    }

    // 允许 ws:game:*:sn
    if (preg_match('/^ws:game:[^:]+:sn$/', $key)) {
        return true;
    }

    return false;
}

$syncToken = '12345678';

$redisHost = '127.0.0.1';
$redisPort = 6379;
$redisPassword = null;
$redisDb = 3;

$headerToken = $_SERVER['HTTP_X_SYNC_TOKEN'] ?? '';
if ($headerToken !== $syncToken) {
    ret(401, 'unauthorized');
}

$raw = file_get_contents('php://input');
if (!$raw) {
    ret(400, 'empty body');
}

$input = json_decode($raw, true);
if (!is_array($input)) {
    ret(400, 'invalid json');
}

$items = $input['items'] ?? [];
if (!is_array($items)) {
    ret(400, 'items must be array');
}

try {
    $redis = new Redis();
    $redis->connect($redisHost, $redisPort, 5);

    if ($redisPassword !== null && $redisPassword !== '') {
        $redis->auth($redisPassword);
    }

    $redis->select($redisDb);
} catch (Throwable $e) {
    ret(500, 'redis connect failed: ' . $e->getMessage());
}

$writeCount = 0;
$receivedKeys = [];

try {
    $redis->multi(Redis::PIPELINE);

    foreach ($items as $item) {
        $key = (string)($item['key'] ?? '');
        $value = (string)($item['value'] ?? '');
        $pttl = intval($item['pttl'] ?? -1);

        if (!isAllowedKey($key)) {
            continue;
        }

        $receivedKeys[$key] = true;

        if ($pttl === -2) {
            continue;
        } elseif ($pttl > 0) {
            if (method_exists($redis, 'psetex')) {
                $redis->psetex($key, $pttl, $value);
            } else {
                $redis->set($key, $value);
                if (method_exists($redis, 'pExpire')) {
                    $redis->pExpire($key, $pttl);
                } else {
                    $seconds = max(1, (int)ceil($pttl / 1000));
                    $redis->expire($key, $seconds);
                }
            }
        } else {
            $redis->set($key, $value);
        }

        $writeCount++;
    }

    $redis->exec();
} catch (Throwable $e) {
    ret(500, 'redis write failed: ' . $e->getMessage());
}

ret(0, 'success', [
    'write_count' => $writeCount,
    'received'    => count($receivedKeys),
]);
?>