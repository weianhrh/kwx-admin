<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../RedisHelper.php';

$database = new Database();
$redis    = new RedisHelper();
$redis->connect();
$redis->selectDb(3);

/** 读取豁免集合，并清理已过期的 id，返回当前有效 id 数组（字符串） */
function getActiveUnlockedIds(RedisHelper $redis): array {
    $setKey = 'income30d:unlock:set';
    $ids = $redis->getNative()->sMembers($setKey);
    $active = [];

    foreach ($ids as $id) {
        $k = "income30d:unlock:venue:$id";
        $ttl = $redis->ttl($k);
        if ($ttl > 0) {
            $active[] = (string)$id;   // 仍在豁免期
        } else {
            // 键已过期：从集合里清理这个 id（容错，不影响结果）
            $redis->getNative()->sRem($setKey, (string)$id);
        }
    }
    return $active;
}

$excludeIds = getActiveUnlockedIds($redis);

// ====== 构造 SQL（两步法），排除在豁免期的场地 ======
$params = [];
$whereNotIn = '';
if (!empty($excludeIds)) {
    $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
    $whereNotIn = " WHERE v.id NOT IN ($placeholders) ";
    $params = array_map('intval', $excludeIds);
}

/* 第一步：按近30天收入计算 income_30d_lock（排除豁免） */
$sqlLock = "
UPDATE venues v
LEFT JOIN (
    SELECT o.reservation_id AS venue_id,
           SUM(o.payment_amount) AS total_30d
    FROM orders o
    WHERE IFNULL(TRIM(o.pays_type),'') <> '能量'
      AND o.payment_amount > 0
      AND o.end_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      AND o.end_time <  DATE_ADD(CURDATE(), INTERVAL 1 DAY)
    GROUP BY o.reservation_id
) s ON s.venue_id = v.id
LEFT JOIN (
    SELECT DISTINCT o.reservation_id AS venue_id
    FROM orders o
    WHERE IFNULL(TRIM(o.pays_type),'') <> '能量'
      AND o.payment_amount > 0
) e ON e.venue_id = v.id
SET v.income_30d_lock = CASE
    WHEN e.venue_id IS NOT NULL AND COALESCE(s.total_30d, 0) = 0 THEN 1  
    WHEN COALESCE(s.total_30d, 0) > 0 THEN 0                              
    ELSE 0                                                                
END
" . $whereNotIn;

$database->query($sqlLock, $params, true);

/* 第二步：把“已上锁且仍标记营业中”的场地，改为休息中（同样排除豁免） */
$sqlRest = "
UPDATE venues v
SET v.venue_status = '休息中'
" . (empty($whereNotIn) ? "WHERE" : "WHERE") . "
 v.income_30d_lock = 1
 AND TRIM(v.venue_status) = '营业中'
" . (empty($whereNotIn) ? "" : " AND v.id NOT IN (" . implode(',', array_fill(0, count($excludeIds), '?')) . ")");

$database->query($sqlRest, $params, true);

echo json_encode(['code'=>0,'msg'=>'ok','skip_ids'=>$excludeIds], JSON_UNESCAPED_UNICODE);
