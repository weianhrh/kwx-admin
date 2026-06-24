<?php
require_once '../RedisHelper.php';

/**
 * 读取某场馆当前仍在冻结期内的总额（所有未过期冻结 key 的和）
 * @param int $venue_id
 * @return float
 * 
 */
 
function getCurrentFrozenAmount($venue_id) {
    $r = new RedisHelper();
    $r->connect('127.0.0.1', 6379, 1);
    $r->selectDb(6);

    // 优先用 scan，若没加 scan 方法也可以用 getAllKeys
    if (method_exists($r, 'scan')) {
        $keys = $r->scan("venue:{$venue_id}:frozen:*", 200);
    } else {
        $keys = $r->getAllKeys("venue:{$venue_id}:frozen:*"); // 注意：KEYS 可能阻塞
    }

    $total = 0.0;
    foreach ($keys as $k) {
        $val = $r->get($k);
        if ($val !== false && $val !== null) {
            $total += (float)$val;
        }
    }
    $r->close();
    return (float)$total;
}

