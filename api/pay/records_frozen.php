<?php
require_once '../Database.php';
require_once '../RedisHelper.php';

/**
 * 计算并记录某条违规的冻结金额（回溯7天），并写入 Redis，TTL=违规起30天
 * @param int $venue_id
 * @param int $ban_id
 * @param string $ban_created_at  数据库里取出的 DATETIME，如 "2025-10-08 13:20:11"
 * @return array ['frozen_amount'=>float, 'ttl'=>int]
 */
function recordFreezeForBan($venue_id, $ban_id, $ban_created_at) {
    $db = new Database();

    // 以自然日为粒度：回溯7天 [created_at-7d, created_at)
    $sql = "
        SELECT COALESCE(SUM(dvr.total_revenue),0) AS frozen_amount
        FROM DailyVenueRevenue dvr
        WHERE dvr.venue_id = ?
          AND dvr.stat_date >= DATE(?) - INTERVAL 7 DAY
          AND dvr.stat_date  < DATE(?)
    ";
    $row = $db->query($sql, [$venue_id, $ban_created_at, $ban_created_at]);
    $frozen_amount = (float)($row[0]['frozen_amount'] ?? 0.0);

    // 计算 TTL：从 ban_created_at 起 30 天
    $created_ts = strtotime($ban_created_at);
    $expire_ts  = $created_ts + 30*24*3600;
    $ttl = max(0, $expire_ts - time());
    // ttl=0 代表已经超过30天，无需写入冻结

    // 写入 Redis
    $r = new RedisHelper();
    $r->connect('127.0.0.1', 6379, 1);
    $r->selectDb(6);

    if ($ttl > 0 && $frozen_amount > 0) {
        $key = "venue:{$venue_id}:frozen:{$ban_id}";
        // 值用纯小数，便于直接相加
        $r->setWithExpiration($key, (string)$frozen_amount, $ttl);
    }

    $r->close();
    $db->close();

    return ['frozen_amount'=>$frozen_amount, 'ttl'=>$ttl];
}
