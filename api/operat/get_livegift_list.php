<?php
// 直接从 Redis DB1 读 command_detail:* 的 JSON，取出 reservation_id / serial_number / command_name / uid / start_time
// 然后用 reservation_id、serial_number 去 MySQL 查名称，最后只返回前端需要的字段。
// 依赖：Database.php、RedisHelper.php（已添加 scanKeys）

require_once '../Database.php';
require_once '../RedisHelper.php';

header('Content-Type: application/json; charset=utf-8');

function ok($data){ echo json_encode(['code'=>200,'data'=>$data], JSON_UNESCAPED_UNICODE); exit; }
function bad($msg,$code=500){ echo json_encode(['code'=>$code,'msg'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    // 1) 连接 Redis -> DB1
    $r = new RedisHelper();
    $r->connect();         // 127.0.0.1:6379
    $r->selectDb(1);       // <<<<<< 1号池

    // 2) 扫描 command_detail:* 所有键
    if (!method_exists($r, 'scanKeys')) {
        bad('RedisHelper 未实现 scanKeys()，请先添加该方法', 500);
    }
    $keys = $r->scanKeys('command_detail:*', 1000);
    if (!$keys) ok([]);

    // 3) 读取并解析 JSON，收集要去 MySQL 查询的 id
    $items = [];
    $venueIds = [];   // reservation_id
    $serials  = [];   // serial_number

    foreach ($keys as $k) {
        $val = $r->get($k);
        if ($val === false || $val === null) continue;
        $obj = json_decode($val, true);
        if (!is_array($obj)) continue;

        // 兜底字段（你 Redis 里有：uid,reservation_id,command_name,serial_number,payment_amount,start_time）
        $reservation_id = $obj['reservation_id'] ?? null;
        $serial_number  = $obj['serial_number']  ?? null;
        $command_name   = $obj['command_name']   ?? null;
        $uid            = $obj['uid']            ?? null;
        $start_time     = $obj['start_time']     ?? null;

        // 收集映射键
        if ($reservation_id !== null && $reservation_id !== '') {
            $venueIds[(string)$reservation_id] = true;
        }
        if ($serial_number !== null && $serial_number !== '') {
            $serials[(string)$serial_number] = true;
        }

        // 先把原始记录缓存下来，后面补名称
        $items[] = [
            'reservation_id' => $reservation_id,
            'serial_number'  => $serial_number,
            'command_name'   => $command_name,
            'uid'            => $uid,
            // 你要的 “create_time // 创建时间” 这里直接用 Redis 的 start_time 返回
            'create_time'    => $start_time,
            'start_time'     => $start_time, // 若前端也想单独拿打赏时间
        ];
    }

    if (!$items) ok([]);

    // 4) 用 MySQL 一次性把名称查出来（IN 查询）
    $db = new Database();

    // 场地名称映射：venues.id -> venues.name
    $venueNameMap = [];
    if ($venueIds) {
        $ids = array_keys($venueIds);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        // 如果你的字段不是 name 而是 venue_name，这里改成 SELECT id, venue_name AS name ...
        $sql = "SELECT id, venue_name FROM venues WHERE id IN ($ph)";
        $rows = $db->query($sql, $ids);
        foreach ($rows as $v) {
            $venueNameMap[(string)$v['id']] = $v['venue_name'];
        }
    }

    // 设备名称映射：vehicles.serial_number -> vehicles.name
    $vehicleNameMap = [];
    if ($serials) {
        $sns = array_keys($serials);
        $ph  = implode(',', array_fill(0, count($sns), '?'));
        // 如果你的设备名字段不是 name（比如 device_name），改成 SELECT serial_number, device_name AS name ...
        $sql = "SELECT serial_number, name FROM vehicles WHERE serial_number IN ($ph)";
        $rows = $db->query($sql, $sns);
        foreach ($rows as $v) {
            $vehicleNameMap[(string)$v['serial_number']] = $v['name'];
        }
    }

    // 5) 组装前端需要的最终返回
    $out = [];
    foreach ($items as $it) {
        $rid = (string)($it['reservation_id'] ?? '');
        $sn  = (string)($it['serial_number']  ?? '');
        $out[] = [
            // “获取展示列表”的五项（+ 原值字段便于前端定位）
            'venue_name'    => $venueNameMap[$rid] ?? '',  // 场地名称：select venues.name where venues.id = reservation_id
            'vehicle_name'  => $vehicleNameMap[$sn] ?? '', // 设备名称：select vehicles.name where vehicles.serial_number = serial_number
            'uid'           => $it['uid'],                 // 用户ID
            'command_name'  => $it['command_name'],        // 打赏指令名称
            'start_time'    => $it['start_time'],          // 打赏时间（你给的是 start_time）
            // 额外回传你原始字段
            'reservation_id'=> $it['reservation_id'],
            'serial_number' => $it['serial_number'],
            'create_time'   => $it['create_time'],         // = start_time（按你“创建时间”要求）
        ];
    }

    ok($out);

} catch (Throwable $e) {
    bad('服务器异常：'.$e->getMessage(), 500);
}
