<?php
header('Content-Type: application/json; charset=utf-8');

// === 按你的项目实际路径 require ===
require_once '../RedisHelper.php';
require_once '../Database.php';

function json_ok($payload) {
  echo json_encode(["code"=>0,"data"=>$payload,"msg"=>"ok"], JSON_UNESCAPED_UNICODE);
  exit;
}
function json_err($msg, $code = 1) {
  echo json_encode(["code"=>$code,"data"=>[],"msg"=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

/**
 * 把 Redis value（你截图那种 JSON 字符串）解析为数组
 */
function parseRedisJson($raw) {
  if (!$raw) return null;
  $arr = json_decode($raw, true);
  if (json_last_error() !== JSON_ERROR_NONE) return null;
  return $arr;
}

/**
 * 根据 serial_number 批量查 MySQL 设备信息（表名/字段名按你自己的改）
 * 你现在设备管理接口返回的是 vehicles，所以这里默认用 vehicles 表
 */
function fetchVehiclesBySerials(Database $db, array $serials) {
  if (empty($serials)) return [];

  $resultMap = [];

  // 分片避免 IN 过长
  $chunkSize = 300;
  for ($i=0; $i<count($serials); $i+=$chunkSize) {
    $chunk = array_slice($serials, $i, $chunkSize);
    $placeholders = implode(',', array_fill(0, count($chunk), '?'));

    // ⚠️ 按你 vehicles 表字段改：name/photo_url/bind_site/image_device_serial/bk_image_device_serial/uid
    $sql = "SELECT serial_number, name, photo_url, bind_site, image_device_serial, bk_image_device_serial, uid
            FROM vehicles
            WHERE serial_number IN ($placeholders)";

    $rows = $db->query($sql, $chunk);
    if ($rows === false) continue;

    foreach ($rows as $r) {
      $resultMap[$r['serial_number']] = $r;
    }
  }

  return $resultMap;
}

try {
  $rh = new RedisHelper();
  $rh->connect('127.0.0.1', 6379, 1);

  // 选 DB4（你说 DB4 池子）
  $rh->selectDb(4);

  // 用 SCAN（别用 KEYS，线上会卡）
  // pattern 你也可以改成更精确，比如 '????????????' 或 '00*' 等
  $keys = $rh->scan('*', 500);

  $onlineSerials = [];
  $redisInfoMap = [];

  foreach ($keys as $k) {
    // 只要 TTL > 0 就认为“在线”（你截图 TTL=2秒，很典型在线心跳池）
    $ttl = $rh->ttl($k);
    if ($ttl > 0) {
      $raw = $rh->get($k);
      $parsed = parseRedisJson($raw);

      // 如果 value 没解析出来，也照样当在线（至少 key 在线）
      $onlineSerials[] = $k;
      $redisInfoMap[$k] = [
        "ttl" => $ttl,
        "raw" => $raw,
        "parsed" => $parsed
      ];
    }
  }

  // 去重
  $onlineSerials = array_values(array_unique($onlineSerials));

  // 查 MySQL 合并设备信息
  $db = new Database();
  $vehicleMap = fetchVehiclesBySerials($db, $onlineSerials);

  $devices = [];
  foreach ($onlineSerials as $sn) {
    $v = $vehicleMap[$sn] ?? null;
    $ri = $redisInfoMap[$sn] ?? null;

    $devices[] = [
      "serial_number" => $sn,
      // MySQL 信息（查不到就给空）
      "name" => $v['name'] ?? "",
      "photo_url" => $v['photo_url'] ?? "",
      "bind_site" => $v['bind_site'] ?? "",
      "image_device_serial" => $v['image_device_serial'] ?? "",
      "bk_image_device_serial" => $v['bk_image_device_serial'] ?? "",
      "uid" => $v['uid'] ?? "",

      // Redis 信息
      "redis" => [
        "ttl" => $ri['ttl'] ?? 0,
        "voltage" => $ri['parsed']['voltage'] ?? null,
        "longitude" => $ri['parsed']['longitude'] ?? null,
        "latitude" => $ri['parsed']['latitude'] ?? null,
        "speed" => $ri['parsed']['speed'] ?? null,
      ]
    ];
  }

  // 可选：按 TTL 倒序（更“新鲜”的在线）
  usort($devices, function($a,$b){
    return ($b['redis']['ttl'] ?? 0) <=> ($a['redis']['ttl'] ?? 0);
  });

  json_ok([
    "server_time" => date('Y-m-d H:i:s'),
    "devices" => $devices
  ]);

} catch (Exception $e) {
  json_err("接口异常: " . $e->getMessage(), 2);
}
