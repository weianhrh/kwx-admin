<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../Database.php';
require_once '../RedisHelper.php';
require_once __DIR__ . '/_description_review.php';

$database = new Database();
$token = $_COOKIE['session_token'] ?? '';
$user = is_string($token) && $token !== '' ? $database->getUserBySessionToken($token) : null;
if (!$user) descReviewReply(1001, '请先登录');
if (!in_array((int)$user['role_id'], [1, 2], true)) descReviewReply(1003, '仅管理员可审核场地描述');

$data = json_decode(file_get_contents('php://input'), true);
$venueId = filter_var($data['venue_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = $data['action'] ?? '';
$reason = $data['reason'] ?? '';
if (!$venueId || !in_array($action, ['approve', 'reject'], true) || !is_string($reason)) {
    descReviewReply(400, '审核参数无效');
}

lockVenueDescription($database, $venueId);
$redis = new RedisHelper();
$redis->connect();
$redis->selectDb(3);
$key = "venue_description_audit:$venueId";
$audit = json_decode((string)$redis->get($key), true);
if (($audit['status'] ?? '') !== 'pending' || !is_string($audit['venue_description'] ?? null)) {
    descReviewReply(409, '这条申请已处理或已过期，请刷新审核列表');
}
// KWX 审核页回传审核员实际看到的文字，保留原有审核调用的参数兼容性。
if (array_key_exists('expected_description', $data) && $data['expected_description'] !== $audit['venue_description']) {
    descReviewReply(409, '场地描述已重新提交，请核对最新内容');
}
if ($action === 'approve') {
    $ok = $database->query('UPDATE venues SET venue_description = ? WHERE id = ?', [$audit['venue_description'], $venueId], true);
    if ($ok === false) descReviewReply(500, '数据库保存失败，请重试');
    clearVenueDescriptionAudit($redis, $venueId);
    descReviewReply(0, '描述审核通过，已更新');
}

$audit['status'] = 'rejected';
$audit['reason'] = trim($reason);
$audit['timestamp'] = time();
$tx = $redis->getNative()->multi();
$tx->setEx($key, 86400, json_encode($audit, JSON_UNESCAPED_UNICODE));
$tx->sRem('venue_description_audit_pool', $key);
$result = $tx->exec();
if (!$result || in_array(false, $result, true)) descReviewReply(500, '驳回保存失败，请重试');
descReviewReply(0, '描述已驳回');
