<?php
require_once '../Database.php';
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

$db = new Database();

// ====== 简易日志函数（不依赖 Database 类）======
function logf($msg, $file = __DIR__ . "/log/get_id_by_stream.log") {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ts = date("Y-m-d H:i:s");
    @file_put_contents($file, "[$ts] $msg\n", FILE_APPEND);
}

function out($code, $msg, $data=null){
  $r=["code"=>$code,"msg"=>$msg];
  if($data!==null) $r["data"]=$data;
  logf("OUT code=$code msg=$msg data=" . json_encode($data, JSON_UNESCAPED_UNICODE));
  echo json_encode($r, JSON_UNESCAPED_UNICODE);
  exit;
}

// ====== 读取参数 ======
$playing_stream_id = trim((string)($_GET["playing_stream_id"] ?? ""));
logf("REQ ip=" . ($_SERVER["REMOTE_ADDR"] ?? "") . " playing_stream_id=" . $playing_stream_id);

if ($playing_stream_id === "") out(400, "缺少参数 playing_stream_id");

// ✅ 改成你A服务器“服务器表”的真实表名
$serverTbl = "device_information";  // 你确认A库也真有这张表？
$sql = "SELECT id FROM {$serverTbl} WHERE playing_stream_id = ? LIMIT 1";
logf("SQL=$sql PARAM=" . $playing_stream_id);

// ====== 执行查询 ======
$rows = $db->query($sql, [$playing_stream_id]);

// query 失败（返回 false）
if ($rows === false) {
    $err = "";
    try {
        $conn = $db->getConnection();
        if ($conn) $err = $conn->error;
    } catch (Throwable $e) {}
    logf("DB_QUERY_FALSE mysql_error=" . $err);
    out(500, "查询 {$serverTbl} 失败", ["mysql_error" => $err]);
}

// 没查到
if (!$rows) {
    logf("NOT_FOUND rows=0");
    out(404, "未找到对应记录", ["playing_stream_id" => $playing_stream_id]);
}

// 查到了
$id = (int)($rows[0]["id"] ?? 0);
logf("FOUND id=" . $id . " rows=" . json_encode($rows, JSON_UNESCAPED_UNICODE));
out(200, "success", ["id" => $id]);
