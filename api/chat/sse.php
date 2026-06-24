<?php
require_once __DIR__ . '/_bootstrap.php';

header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");
header("Connection: keep-alive");
header("X-Accel-Buffering: no"); // Nginx 不缓冲

$token = read_token();
$db = new Database();
$uid = resolve_uid($db, $token);
if (!$uid) { http_response_code(401); exit; }

@set_time_limit(0);
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
while (ob_get_level() > 0) ob_end_flush();

function sse_send($event, $data){
  // 注意 data 中可能有换行；建议总是 json_encode 后传入
  echo "event: $event\n";
  echo "data: $data\n\n";
  @ob_flush(); @flush();
}

// hello
sse_send('hello', json_encode(['ok'=>1,'uid'=>$uid], JSON_UNESCAPED_UNICODE));

// Redis
$rh = new RedisHelper();
$rh->connect('127.0.0.1', 6379, 0);
$redis = $rh->getNative();

// 关键：设置读超时，使用 pubSubLoop 循环
$redis->setOption(Redis::OPT_READ_TIMEOUT, 5);  // 5 秒无消息就返回一次循环
$ps = $redis->pubSubLoop();
$channel = chan_for_user($uid);
$ps->subscribe($channel);

$last = time();

foreach ($ps as $m) {
  if ($m->kind === 'message' && $m->channel === $channel) {
    sse_send('message', $m->payload);
    $last = time();
  }

  // 每 20 秒发一个心跳（SSE 允许以注释或自定义事件维持连接）
  if (time() - $last >= 20) {
    // 方式1：注释行
    echo ": ping\n\n";
    // 方式2（可选）：自定义事件
    // sse_send('ping', '{}');
    @ob_flush(); @flush();
    $last = time();
  }
}
