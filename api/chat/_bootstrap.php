<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../RedisHelper.php';

header('Content-Type: application/json; charset=utf-8');

// 固定客服 UID
define('AGENT_UID', 10001);

// 从 cookie 或 GET/POST 里拿 token
function read_token(): string {
  if (!empty($_COOKIE['auth_token'])) return $_COOKIE['auth_token'];
  if (!empty($_GET['token'])) return $_GET['token'];
  if (!empty($_POST['token'])) return $_POST['token'];
  return '';
}

// 解析 token -> uid（先 users，再 admin_users）
function resolve_uid(Database $db, string $token): ?int {
  if ($token === '') return null;

  // 普通用户（你的老逻辑）
  $u = $db->query("SELECT uid FROM users WHERE token = ? LIMIT 1", [$token]);
  if ($u && isset($u[0]['uid'])) return intval($u[0]['uid']);
  $a = $db->query("SELECT uid FROM users WHERE token = ? LIMIT 1", [$token]);
  if ($a && isset($a[0]['uid'])) return intval($a[0]['uid']);

//   // 客服后台（你新的 Database 里有 admin_users.session_token）
//   $a = $db->query("SELECT uid FROM admin_users WHERE session_token = ? AND session_expires > NOW() LIMIT 1", [$token]);
//   if ($a && isset($a[0]['uid'])) return intval($a[0]['uid']);

  return null;
}

// 统一返回
function json_ok($data) { echo json_encode(['code'=>200,'data'=>$data], JSON_UNESCAPED_UNICODE); exit; }
function json_err($code, $msg) { echo json_encode(['code'=>$code,'msg'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

// 取连接（方便拿 insert_id）
function conn(Database $db): mysqli { return $db->getConnection(); }

// Redis 频道：每个 uid 一个
function chan_for_user(int $uid): string { return "chat:user:$uid"; }

// CORS（如需跨域，放开域名）
if (!headers_sent()) {
  header('Cache-Control: no-cache');
  // header('Access-Control-Allow-Origin: https://your-domain'); 
  // header('Access-Control-Allow-Credentials: true');
}
