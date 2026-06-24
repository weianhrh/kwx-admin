<?php
require_once __DIR__ . '/_bootstrap.php';

$token = read_token();
$db    = new Database();
$sender_uid = resolve_uid($db, $token);
if (!$sender_uid) json_err(401, '无效token');

// 文本校验
$text = isset($_POST['text']) ? trim($_POST['text']) : '';
if ($text === '') json_err(400, 'text不能为空');

// 会话对端：普通用户 -> 固定客服；客服必须传 peer_uid
$peer_uid = isset($_POST['peer_uid']) ? intval($_POST['peer_uid']) : 0;
if ($sender_uid !== AGENT_UID) {
    $peer_uid = AGENT_UID;            // 普通用户默认发给客服
} elseif ($peer_uid <= 0) {
    json_err(400, '客服发言必须指定 peer_uid');
}

try {
    $db->beginTransaction();

    // 1) 写入消息
    $stmt = $db->prepare("INSERT INTO messages (uid, peer_uid, text) VALUES (?,?,?)");
    $stmt->bind_param("iis", $sender_uid, $peer_uid, $text);
    if (!$stmt->execute()) throw new Exception("insert failed: ".$stmt->error);
    $msg_id = conn($db)->insert_id;
    $stmt->close();

    // 2) 取完整一行
    $row = $db->query("SELECT * FROM messages WHERE id = ?", [$msg_id])[0];

    // 3) 更新“自己视角”的已读到最新（自己不计未读）
    $db->query(
      "INSERT INTO conv_state (owner_uid, peer_uid, last_read_id)
       VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE
         last_read_id = GREATEST(last_read_id, VALUES(last_read_id)),
         updated_at = NOW()",
      [$sender_uid, $peer_uid, $msg_id],
      true
    );

    $db->commit();

    // 4) Redis 发布 —— WebSocket 网关订阅 chat:uid:* 并下发到浏览器
    $rh = new RedisHelper();
    $rh->connect('127.0.0.1', 6379, 0);
    $redis = $rh->getNative();

    // 统一包装为 {event:"message", data:{...}}
    $data = [
        'id'          => (int)$row['id'],
        'uid'         => (int)$row['uid'],       // 发送者
        'peer_uid'    => (int)$row['peer_uid'],  // 接收者
        'text'        => (string)$row['text'],
        'create_time' => (string)$row['create_time'],
    ];
    $payload = json_encode(['event' => 'message', 'data' => $data], JSON_UNESCAPED_UNICODE);

    // 发送给“对方”和“自己”（自己用于多端同步）
    $redis->publish(chan_for_user($peer_uid),  $payload);
    $redis->publish(chan_for_user($sender_uid), $payload);

    json_ok($row);

} catch (Throwable $e) {
    $db->rollBack();
    json_err(500, '发送失败: '.$e->getMessage());
}
