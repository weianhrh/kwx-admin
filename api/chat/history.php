<?php
require_once __DIR__ . '/_bootstrap.php';

$token = read_token();
$db = new Database();
$uid = resolve_uid($db, $token);
if (!$uid) json_err(401, '无效token');

$peer_uid = isset($_GET['peer_uid']) ? intval($_GET['peer_uid']) : 0;
if ($uid !== AGENT_UID) $peer_uid = AGENT_UID;
if ($peer_uid <= 0) json_err(400, '缺少peer_uid');

$before_id = isset($_GET['before_id']) ? intval($_GET['before_id']) : 0;
$limit = min(50, max(1, intval($_GET['limit'] ?? 20)));

// 计算本会话的会话键
list($a,$b) = ($uid < $peer_uid) ? [$uid,$peer_uid] : [$peer_uid,$uid];

$params = [$a,$b];
$sql = "SELECT * FROM messages WHERE conv_a=? AND conv_b=?";
if ($before_id > 0) { $sql .= " AND id < ?"; $params[] = $before_id; }
$sql .= " ORDER BY id DESC LIMIT $limit";

$rows = $db->query($sql, $params);
$rows = array_reverse($rows);
json_ok($rows);