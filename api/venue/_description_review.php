<?php
function descReviewReply($code, $msg) {
    echo json_encode(['code' => $code, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// 提交、管理员直改和审核共用同一把场地锁，连接关闭时自动释放。
function lockVenueDescription($database, $venueId) {
    $row = $database->query('SELECT GET_LOCK(?, 5) AS acquired', ['kwx:venue:description:' . $venueId]);
    if ((int)($row[0]['acquired'] ?? 0) !== 1) descReviewReply(409, '场地描述正在处理，请稍后重试');
}

function clearVenueDescriptionAudit($redis, $venueId) {
    $key = "venue_description_audit:$venueId";
    $tx = $redis->getNative()->multi();
    $tx->del($key, "venue:desc:$venueId");
    $tx->sRem('venue_description_audit_pool', $key);
    $result = $tx->exec();
    if (!$result || in_array(false, $result, true)) descReviewReply(500, '审核状态清理失败，请重试');
}
