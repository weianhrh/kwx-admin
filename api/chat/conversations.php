<?php
require_once __DIR__ . '/_bootstrap.php';

$token = read_token();
$db = new Database();
$uid = resolve_uid($db, $token);
if (!$uid) json_err(401, '无效token');

/*
  思路：找出“和我有消息往来的所有对端”，取每个会话的最新一条 + 未读数
  会话键：min(uid,peer_uid)-max(uid,peer_uid)
*/
// 找出与我有关的所有会话的最新一条
$sql = "
SELECT t.*
FROM messages t
JOIN (
  SELECT conv_a, conv_b, MAX(id) AS max_id
  FROM messages
  WHERE conv_a = ? OR conv_b = ?
  GROUP BY conv_a, conv_b
) x ON t.id = x.max_id
ORDER BY t.create_time DESC";
$list = $db->query($sql, [$uid, $uid]);

// 计算每条的 peer_uid 与未读
foreach ($list as &$row) {
  $a = intval($row['conv_a']);
  $b = intval($row['conv_b']);
  $peer = ($a==$uid) ? $b : $a;

  $st = $db->query("SELECT last_read_id FROM conv_state WHERE owner_uid=? AND peer_uid=? LIMIT 1",
                   [$uid, $peer]);
  $last_read_id = $st ? intval($st[0]['last_read_id']) : 0;

  $maxRow = $db->query(
    "SELECT MAX(id) AS mid FROM messages WHERE conv_a=? AND conv_b=?",
    [$a,$b]
  );
  $max_id = intval($maxRow[0]['mid'] ?? 0);

  $row['peer_uid_resolved'] = $peer;
  $row['unread'] = max(0, $max_id - $last_read_id);
}
unset($row);

json_ok($list);

