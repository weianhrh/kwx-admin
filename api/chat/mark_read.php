<?php
require_once __DIR__ . '/_bootstrap.php';

$token = read_token();
$db = new Database();
$uid = resolve_uid($db, $token);
if (!$uid) json_err(401, '无效token');

$peer_uid = isset($_POST['peer_uid']) ? intval($_POST['peer_uid']) : 0;
if ($uid !== AGENT_UID) $peer_uid = AGENT_UID;
if ($peer_uid <= 0) json_err(400, '缺少peer_uid');

list($a,$b) = ($uid < $peer_uid) ? [$uid,$peer_uid] : [$peer_uid,$uid];

$mx = $db->query("SELECT MAX(id) AS mid FROM messages WHERE conv_a=? AND conv_b=?",
                 [$a,$b]);
$max_id = intval($mx[0]['mid'] ?? 0);

$db->query(
  "INSERT INTO conv_state (owner_uid, peer_uid, last_read_id)
   VALUES (?,?,?)
   ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id)), updated_at=NOW()",
  [$uid, $peer_uid, $max_id],
  true
);

json_ok(['last_read_id'=>$max_id]);
