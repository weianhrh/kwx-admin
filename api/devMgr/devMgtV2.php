<?php 
require_once '../Database.php';  
require_once '../RedisHelper.php';
// header('Content-Type: image/jpeg'); // 或 image/png 等
// header('Cache-Control: public, max-age=31536000');
// header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
// 日志记录函数 
function logMessage($message) { 
    $logFile = __DIR__ . '/operation_log.txt';  
    $timestamp = date('Y-m-d H:i:s'); 
    $logEntry = "[$timestamp] $message\n"; 
    file_put_contents($logFile, $logEntry, FILE_APPEND); 
} 

function logMessage_frozen($message) { 
    $logFile = __DIR__ . '/frozen_log.txt';  
    $timestamp = date('Y-m-d H:i:s'); 
    $logEntry = "[$timestamp] $message\n"; 
    file_put_contents($logFile, $logEntry, FILE_APPEND); 
} 

/**
 * 根据车辆 serial_number 查询 ZEGO room_id
 * 优先取 device_information.room_id
 * 如果查不到，且 image_device_serial 本身像 ZEGO 房间号，则 fallback 用 image_device_serial
 */
function getZegoRoomIdBySerialNumber(Database $database, string $serial_number): string
{
    $sql = "
        SELECT
            v.image_device_serial,
            di.room_id AS room_id
        FROM vehicles v
        LEFT JOIN device_information di
            ON CAST(di.id AS CHAR) = v.image_device_serial
        WHERE v.serial_number = ?
        LIMIT 1
    ";

    $rows = $database->query($sql, [$serial_number]);

    if (empty($rows)) {
        return '';
    }

    $roomId = trim((string)($rows[0]['room_id'] ?? ''));

    if ($roomId !== '') {
        return $roomId;
    }

    $imageDeviceSerial = trim((string)($rows[0]['image_device_serial'] ?? ''));

    // 兜底：你之前有 ZEGO 数字房间 ID 这种情况
    if ($imageDeviceSerial !== '' && strlen($imageDeviceSerial) < 8) {
        return $imageDeviceSerial;
    }

    return '';
}

/**
 * 调用单独 PHP，发送 ROOM_BAN#封禁原因
 * 这个失败不影响封禁结果，只记录日志
 */
function notifyRoomBanMessage(string $room_id, string $ban_reason, string $from_user_id = 'server_bot_1'): array
{
    // 改成你的真实访问地址
    $url = 'https://open.kwxapp.cn/api/devMgr/send_room_ban_message.php';

    $payload = [
        'room_id'      => $room_id,
        'from_user_id' => $from_user_id,
        'ban_reason'   => $ban_reason,
    ];

    // 如果 send_room_ban_message.php 里配置了 INTERNAL_API_KEY，这里一起传
    // $payload['internal_key'] = '你的内部密钥';

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $raw = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($curl);

    curl_close($curl);

    if ($curlErr) {
        return [
            'success' => false,
            'error' => 'cURL错误：' . $curlErr,
            'http_code' => $httpCode,
            'raw' => (string)$raw
        ];
    }

    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        return [
            'success' => false,
            'error' => '响应不是合法JSON',
            'http_code' => $httpCode,
            'raw' => (string)$raw
        ];
    }

    $code = (int)($data['code'] ?? -1);

    return [
        'success' => ($httpCode === 200 && $code === 0),
        'error' => $data['msg'] ?? '',
        'http_code' => $httpCode,
        'raw' => (string)$raw,
        'json' => $data
    ];
}

// 创建数据库连接 
$database = new Database(); 
// Redis记录封禁
$redisHelper = new RedisHelper();
$redisHelper->connect();
$redisHelper->selectDb(1); // 使用1号数据库作为“封禁记录池”

$session_token = $_COOKIE['session_token'] ?? null; 
if (!$session_token) { 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]); 
    exit; 
} 

$user = $database->getUserBySessionToken($session_token); 
if (!$user || !$user['role_id']) { 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]); 
    exit; 
} 

$role_id = $user['role_id']; 
$uid = $user['uid']; 
$inputJSON = file_get_contents('php://input'); 
$input = json_decode($inputJSON, TRUE); 


function formatHMSFromSeconds(int $seconds): string {
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs); // 支持 >24h，如 720:00:00
}

// function makeBanTimes(float $hours): array {
//     $seconds = (int)round($hours * 3600);
//     $ban_duration_str = formatHMSFromSeconds($seconds);
//     $ban_end_time = date('Y-m-d H:i:s', time() + $seconds);
//     return [$seconds, $ban_duration_str, $ban_end_time];
// }
function makeBanTimes(int $seconds): array {
    $seconds = max(0, $seconds);
    $ban_end_time = date('Y-m-d H:i:s', time() + $seconds);
    return [$seconds, $ban_end_time];
}

function getBanImageUrls(array $input): array {
    $urls = [];

    // 兼容前端传 image_urls 数组
    if (isset($input['image_urls']) && is_array($input['image_urls'])) {
        foreach ($input['image_urls'] as $url) {
            if (!is_scalar($url)) continue;

            $url = trim((string)$url);
            if ($url !== '') {
                $urls[] = $url;
            }
        }
    }

    // 兼容前端分别传 image_url / image_url_2 / image_url_3
    foreach (['image_url', 'image_url_2', 'image_url_3'] as $key) {
        if (!isset($input[$key]) || !is_scalar($input[$key])) continue;

        $url = trim((string)$input[$key]);
        if ($url !== '' && !in_array($url, $urls, true)) {
            $urls[] = $url;
        }
    }

    $urls = array_slice($urls, 0, 3);

    return [
        $urls[0] ?? null,
        $urls[1] ?? null,
        $urls[2] ?? null
    ];
}
/**
 * 计算某场地在 [DATE($createdAt)-7, DATE($createdAt)) 内的日营收总和
 * 这里用 DailyVenueRevenue(venue_id, stat_date, total_revenue)
 * 如你的表/字段不同，改一下表名和字段即可
 */
// function calcFrozenAmountForBan(Database $db, int $venueId, string $createdAt): float {
//     $sql = "
//         SELECT COALESCE(SUM(dvr.total_revenue), 0) AS amt
//         FROM DailyVenueRevenue dvr
//         WHERE dvr.venue_id = ?
//           AND dvr.date >= DATE(?) - INTERVAL 7 DAY
//           AND dvr.date  <  DATE(?)
//     ";
//     $row = $db->query($sql, [$venueId, $createdAt, $createdAt]);
//     return (float)($row[0]['amt'] ?? 0.0);
// }
function calcFrozenAmountForBan(Database $db, int $venueId, string $createdAt): float {

    // ① 计算上溯 7 天收入
    $sqlRevenue = "
        SELECT COALESCE(SUM(dvr.total_revenue), 0) AS amt
        FROM DailyVenueRevenue dvr
        WHERE dvr.venue_id = ?
          AND dvr.date >= DATE(?) - INTERVAL 7 DAY
          AND dvr.date  <  DATE(?)
    ";
    $rowRevenue = $db->query($sqlRevenue, [$venueId, $createdAt, $createdAt]);
    $totalRevenue = (float)($rowRevenue[0]['amt'] ?? 0.0);

    // ② 计算上溯 7 天中 已经提现的金额（必须扣除，否则会被冻结重复）
    $sqlWithdraw = "
        SELECT COALESCE(SUM(w.withdrawal_amount), 0) AS amt
        FROM withdrawal_requests w
        WHERE w.venue_id = ?
          AND w.application_time >= DATE(?) - INTERVAL 7 DAY
          AND w.application_time  < DATE(?)
          AND w.application_status IN (0,1,2)";
    $rowWithdraw = $db->query($sqlWithdraw, [$venueId, $createdAt, $createdAt]);
    $withdrawIn7Days = (float)($rowWithdraw[0]['amt'] ?? 0.0);

    // ③ 冻结金额 = 收入 - 上溯提现金额
    $frozenAmount = max(0.0, $totalRevenue - $withdrawIn7Days);

    return $frozenAmount;
}
/**
 * 把冻结金额写入 Redis：key=venue:{venue_id}:frozen:{ban_id}，TTL=从 created_at 起 7 天
 */
function writeVenueFreezeToRedis(RedisHelper $r, int $venueId, int $banId, string $createdAt, float $amount): array {
    $createdTs = strtotime($createdAt);
    $expireTs  = $createdTs + 7*24*3600;
    $ttl = max(0, $expireTs - time());
    if ($ttl > 0 && $amount > 0) {
        $key = "venue:{$venueId}:frozen:{$banId}";
        // 值直接写十进制字符串，便于累加
        $r->setWithExpiration($key, (string)$amount, $ttl);
    }
    return ['ttl' => $ttl, 'key' => $key ?? null];
}

/** 单设备封禁（等价于你现在的 device 分支逻辑） */
function banDevice(Database $database, RedisHelper $redisHelper, string $serial_number, int $uid, int $venue_id, int $banSeconds, string $ban_reason = '违规封禁', ?string $image_device_serial = null, ?string $image_url = null): bool {
    list($seconds, $ban_end_time) = makeBanTimes($banSeconds);

    $update_sql = "UPDATE vehicles SET is_banned = 1, sharing_status = '未共享', start_status = 'false' WHERE serial_number = ?";
    $database->query($update_sql, [$serial_number], true);

    if ($image_device_serial === null) {
        $row = $database->query("SELECT image_device_serial FROM vehicles WHERE serial_number = ?", [$serial_number]);
        $image_device_serial = $row[0]['image_device_serial'] ?? null;
    }

    $insert_sql = "INSERT INTO device_bans (serial_number, uid, venue_id, ban_duration, ban_end_time, ban_reason, image_device_serial, image_url, status)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";
    $params = [$serial_number, $uid, $venue_id, $seconds, $ban_end_time, $ban_reason, $image_device_serial, $image_url];
    $database->query($insert_sql, $params, true);

    $banKey  = 'device_ban:' . $serial_number;
    $banData = [
        'serial_number'       => $serial_number,
        'uid'                 => $uid,
        'venue_id'            => $venue_id,
        'ban_duration'        => $seconds,
        'ban_end_time'        => $ban_end_time,
        'ban_reason'          => $ban_reason,
        'image_device_serial' => $image_device_serial,
        'image_url'           => $image_url,
    ];
    $redisHelper->set($banKey, json_encode($banData, JSON_UNESCAPED_UNICODE));

    return true;
}

/** 封禁某场地的全部设备（循环调用 banDevice） */
function banVenueDevices(Database $database, RedisHelper $redisHelper, int $venue_id, int $uid, int $banSeconds, string $ban_reason = '违规封禁', ?string $image_url = null): array {
    $devices = $database->query(
        "SELECT serial_number, image_device_serial FROM vehicles WHERE bind_site = ?",
        [$venue_id]
    );

    $ok = []; 
    $fail = [];

    foreach ($devices as $d) {
        $sn  = $d['serial_number'];
        $ids = $d['image_device_serial'] ?? null;

        try {
            banDevice($database, $redisHelper, $sn, $uid, $venue_id, $banSeconds, $ban_reason, $ids, $image_url);
            $ok[] = $sn;
        } catch (Exception $e) {
            $fail[] = ['serial_number' => $sn, 'error' => $e->getMessage()];
        }
    }

    return ['ok' => $ok, 'fail' => $fail, 'total' => count($devices)];
}

if (isset($input['ban'])) { 
    $serial_number = $input['serial_number']; 
    $ban_status = $input['id']; // 0 = 解封，1 = 封禁 

    // if (!$serial_number) { 
    //     echo json_encode(['code' => 1, 'msg' => '缺少车辆序列号', 'data' => []]); 
    //     exit; 
    // } 
    if (isset($input['additionalData'])) { 
        $input = array_merge($input, $input['additionalData']); 
    } 

    $database->beginTransaction(); 
    try { 
        if ($ban_status == 1) {
            if ($input['banType'] === 'device') {
    $banSeconds = intval($input['ban_duration']);
    if ($banSeconds <= 0) {
        throw new Exception('封禁时长无效');
    }

    $banReason = $input['ban_reason'] ?? '违规封禁';

    banDevice(
        $database,
        $redisHelper,
        $serial_number,
        $uid,
        intval($input['venue_id']),
        $banSeconds,
        $banReason,
        null,
        $input['image_url'] ?? null
    );

    // ==============================
    // 设备封禁后，通知当前 ZEGO 房间
    // 发送：ROOM_BAN#封禁原因
    // 注意：通知失败不影响封禁成功
    // ==============================
    try {
        $roomId = getZegoRoomIdBySerialNumber($database, $serial_number);

        if ($roomId !== '') {
            $notifyResult = notifyRoomBanMessage($roomId, $banReason, 'server_bot_1');

            logMessage(
                "设备封禁ROOM_BAN通知 serial_number={$serial_number}, room_id={$roomId}, result=" .
                json_encode($notifyResult, JSON_UNESCAPED_UNICODE)
            );
        } else {
            logMessage(
                "设备封禁ROOM_BAN跳过：未找到room_id serial_number={$serial_number}"
            );
        }
    } catch (Exception $notifyException) {
        logMessage(
            "设备封禁ROOM_BAN异常 serial_number={$serial_number}, error=" .
            $notifyException->getMessage()
        );
    }
}
            elseif ($input['banType'] === 'venue') {
                $banSeconds = intval($input['ban_duration']);
                if ($banSeconds <= 0) {
                    throw new Exception('封禁时长无效');
                }
            
                $ban_end_time = date('Y-m-d H:i:s', time() + $banSeconds);
            
                // $insert_venue_ban_sql = "INSERT INTO venue_bans
                //     (venue_id, uid, ban_duration, ban_end_time, ban_reason, image_device_serial, image_url, status)
                //     VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
            
                // $insert_params = [
                //     intval($input['venue_id']),
                //     $uid,
                //     $banSeconds,
                //     $ban_end_time,
                //     $input['ban_reason'] ?? '违规封禁',
                //     $input['image_device_serial'] ?? null,
                //     $input['image_url'] ?? null
                // ];
            
                list($imageUrl1, $imageUrl2, $imageUrl3) = getBanImageUrls($input);

                $insert_venue_ban_sql = "INSERT INTO venue_bans
                    (venue_id, uid, ban_duration, ban_end_time, ban_reason, image_device_serial, image_url, image_url_2, image_url_3, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
                
                $insert_params = [
                    intval($input['venue_id']),
                    $uid,
                    $banSeconds,
                    $ban_end_time,
                    $input['ban_reason'] ?? '违规封禁',
                    $input['image_device_serial'] ?? null,
                    $imageUrl1,
                    $imageUrl2,
                    $imageUrl3
                ];
                
                $database->query($insert_venue_ban_sql, $insert_params, true);
            
                $lastBan = $database->query(
                    "SELECT id, created_at
                     FROM venue_bans
                     WHERE venue_id = ?
                       AND uid = ?
                       AND ban_end_time = ?
                     ORDER BY id DESC
                     LIMIT 1",
                    [intval($input['venue_id']), $uid, $ban_end_time]
                );
            
                if (empty($lastBan)) {
                    throw new Exception("venue_bans 插入后回查失败");
                }
            
                $banId     = (int)$lastBan[0]['id'];
                $createdAt = $lastBan[0]['created_at'];
            
                // $frozenAmount = calcFrozenAmountForBan($database, intval($input['venue_id']), $createdAt);
            
                // $freezeMeta = writeVenueFreezeToRedis(
                //     $redisHelper,
                //     intval($input['venue_id']),
                //     $banId,
                //     $createdAt,
                //     $frozenAmount
                // );
                $frozenAmount = 0;
                $freezeMeta = [
                    'ttl' => 0,
                    'key' => null,
                    'skipped' => true,
                    'reason' => '封禁时长不大于一天，不冻结'
                ];
                
                // 场地封禁时长 > 1天 才冻结
                // 注意：一天 = 86400 秒；必须大于 86400，等于一天不冻结
                if ($banSeconds > 86400) {
                    $frozenAmount = calcFrozenAmountForBan($database, intval($input['venue_id']), $createdAt);
                
                    $freezeMeta = writeVenueFreezeToRedis(
                        $redisHelper,
                        intval($input['venue_id']),
                        $banId,
                        $createdAt,
                        $frozenAmount
                    );
                
                    logMessage_frozen("场地封禁冻结成功 venue_id=" . intval($input['venue_id']) .
                        ", ban_id=" . $banId .
                        ", banSeconds=" . $banSeconds .
                        ", frozenAmount=" . $frozenAmount .
                        ", redisKey=" . ($freezeMeta['key'] ?? 'null') .
                        ", ttl=" . ($freezeMeta['ttl'] ?? 0)
                    );
                } else {
                    logMessage_frozen("场地封禁未冻结 venue_id=" . intval($input['venue_id']) .
                        ", ban_id=" . $banId .
                        ", banSeconds=" . $banSeconds .
                        ", reason=封禁时长不大于一天"
                    );
                }
                $venueBanKey = 'venue_ban:' . $input['venue_id'];
                $venueBanData = [
                    'venue_id' => intval($input['venue_id']),
                    'uid' => $uid,
                    'ban_duration' => $banSeconds,
                    'ban_end_time' => $ban_end_time,
                    'ban_reason' => $input['ban_reason'] ?? '违规封禁',
                    'image_device_serial' => $input['image_device_serial'] ?? null,
                    // 'image_url' => $input['image_url'] ?? null,
                    'image_url' => $imageUrl1,
                    'image_url_2' => $imageUrl2,
                    'image_url_3' => $imageUrl3,
                    'image_urls' => array_values(array_filter([$imageUrl1, $imageUrl2, $imageUrl3], function ($v) {
                        return $v !== null && $v !== '';
                    })),
                ];
                $redisHelper->set($venueBanKey, json_encode($venueBanData, JSON_UNESCAPED_UNICODE));
            
                $update_venue_sql = "UPDATE venues SET venue_status = '休息中', is_banned = 1 WHERE id = ?";
                $database->query($update_venue_sql, [intval($input['venue_id'])], true);
            
                $summary = banVenueDevices(
                    $database,
                    $redisHelper,
                    intval($input['venue_id']),
                    $uid,
                    $banSeconds,
                    $input['ban_reason'] ?? '违规封禁',
                    // $input['image_url'] ?? null
                     $imageUrl1
                );
                logMessage("banVenueDevices summary: " . json_encode($summary, JSON_UNESCAPED_UNICODE));
                logMessage("成功更新venues表为休息中，venue_id=" . $input['venue_id']);
            }



        }
        elseif ($ban_status == 0) {
    // 新增解封逻辑
    $update_sql = "UPDATE vehicles SET is_banned = 0, sharing_status = '正在共享', start_status = 'true' WHERE serial_number = ?";
    logMessage("准备解封车辆 serial_number=" . $serial_number);
    $affectedRows = $database->query($update_sql, [$serial_number], true);
    logMessage("车辆解封 affectedRows=" . $affectedRows);

    $update_ban_sql = "UPDATE device_bans SET status = 2 WHERE serial_number = ? AND status = 1";
    $database->query($update_ban_sql, [$serial_number], true);
    logMessage("封禁记录更新完成 serial_number=" . $serial_number);
}

        $database->commit(); 
        echo json_encode(['code' => 0, 'msg' => '操作成功', 'data' => [ 
            'serial_number' => $serial_number, 
            'is_banned' => $ban_status 
        ]]); 
    } catch (Exception $e) { 
        $database->rollback(); 
        echo json_encode(['code' => 1, 'msg' => $e->getMessage(), 'data' => []]); 
    } 
    exit; 
} 

if (isset($input['update_bind'])) { 
    $serial_number = $input['serial_number']; 
    if (!$serial_number) { 
        echo json_encode(['code' => 1, 'msg' => '缺少车辆序列号', 'data' => []]); 
        exit; 
    } 

    if (isset($input['bind_site'])) { 
        $bind_site = $input['bind_site']; 
        $update_sql = "UPDATE vehicles SET bind_site = ? WHERE serial_number = ?"; 
        $database->query($update_sql, [$bind_site, $serial_number]); 
        echo json_encode(['code' => 0, 'msg' => '场地ID更新成功', 'data' => ['serial_number' => $serial_number, 'bind_site' => $bind_site]]); 
    } else if (isset($input['uid'])) { 
        $uid = $input['uid']; 
        $update_sql = "UPDATE vehicles SET uid = ? WHERE serial_number = ?"; 
        $database->query($update_sql, [$uid, $serial_number]); 
        echo json_encode(['code' => 0, 'msg' => '用户ID更新成功', 'data' => ['serial_number' => $serial_number, 'uid' => $uid]]); 
    } 
    exit; 
} 

if (isset($_GET['get_vehicles'])) {
    // 解封车辆
    $database->query("UPDATE vehicles v
        JOIN (SELECT serial_number FROM device_bans WHERE ban_end_time <= NOW() AND status = 1) AS db
        ON v.serial_number = db.serial_number
        SET v.is_banned = 0, v.sharing_status = '正在共享', v.start_status = 'true'", [], true);
    
    // 更新设备封禁记录状态
    $database->query("UPDATE device_bans SET status = 2 WHERE ban_end_time <= NOW() AND status = 1", [], true);
    
    // 解封场地：根据 venue_bans 表
    $database->query("UPDATE venues v
        JOIN (SELECT venue_id FROM venue_bans WHERE ban_end_time <= NOW() AND status = 1) AS vb
        ON v.id = vb.venue_id
        SET v.is_banned = 0, v.venue_status = '营业中'", [], true);
    
    // 更新场地封禁记录状态
    $database->query("UPDATE venue_bans SET status = 2 WHERE ban_end_time <= NOW() AND status = 1", [], true);

    $venue_id = $_GET['venue_id'] ?? null;
    $global_search = $_GET['global_search'] ?? null;
    $params = [];

    // $sql = "SELECT uid, bind_site, serial_number, status, is_banned, name, share_name, sharing_status, photo_url, image_device_serial, bk_image_device_serial FROM vehicles";
    // $sql = "
    //   SELECT
    //     v.uid, v.bind_site, v.serial_number, v.status, v.is_banned,
    //     v.name, v.share_name, v.sharing_status, v.photo_url,
    //     v.image_device_serial, v.bk_image_device_serial,
    //     vcs.car_type
    //   FROM vehicles v
    //   LEFT JOIN vehicle_control_settings vcs
    //     ON vcs.serial_number = v.serial_number
    // ";
    
    $sql = "
      SELECT
        v.uid,
        v.bind_site,
        v.serial_number,
        v.status,
        v.is_banned,
        v.name,
        v.share_name,
        v.sharing_status,
        v.photo_url,
        v.image_device_serial,
        di.room_id AS image_device_room_id,
        v.bk_image_device_serial,
        vcs.car_type
      FROM vehicles v
      LEFT JOIN vehicle_control_settings vcs
        ON vcs.serial_number = v.serial_number
      LEFT JOIN device_information di
        ON CAST(di.id AS CHAR) = v.image_device_serial
    ";
    $where = [];

    // if ($venue_id && is_numeric($venue_id)) {
    //     $where[] = "bind_site = ?";
    //     $params[] = intval($venue_id);
    // }
    // if ($global_search) {
    //     $where[] = "(serial_number LIKE ? OR name LIKE ? OR image_device_serial LIKE ? OR bk_image_device_serial LIKE ?)";
    //     for ($i = 0; $i < 4; $i++) $params[] = '%' . $global_search . '%';
    // }
    if ($venue_id && is_numeric($venue_id)) {
        $where[] = "v.bind_site = ?";
        $params[] = intval($venue_id);
    }
    if ($global_search) {
        $where[] = "(
            v.serial_number LIKE ?
            OR v.name LIKE ?
            OR v.image_device_serial LIKE ?
            OR v.bk_image_device_serial LIKE ?
            OR di.room_id LIKE ?
            OR v.image_device_serial IN (
                SELECT CAST(di2.id AS CHAR)
                FROM device_information di2
                WHERE di2.room_id LIKE ?
            )
        )";
    
        $like = '%' . $global_search . '%';
    
        $params[] = $like; // serial_number
        $params[] = $like; // name
        $params[] = $like; // image_device_serial
        $params[] = $like; // bk_image_device_serial
        $params[] = $like; // di.room_id
        $params[] = $like; // 通过 room_id 反查 device_information.id
    }
    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $vehicles = $database->query($sql, $params);

    echo json_encode(['code' => 0, 'msg' => '成功', 'data' => ['vehicles' => $vehicles]]);
    exit;
}

if (isset($_GET['get_venues'])) { 
    $venues = $database->query("SELECT id, venue_name FROM venues"); 
    echo json_encode(['code' => 0, 'msg' => '成功', 'data' => ['venues' => $venues]]); 
    exit; 
} 

if (isset($_GET['get_ban_info'])) {
    $serial_number = $_GET['get_ban_info'];

    $check_ban_sql = "SELECT db.ban_end_time, db.ban_duration, v.is_banned
                      FROM device_bans db
                      JOIN vehicles v ON v.serial_number = db.serial_number
                      WHERE db.serial_number = ? AND db.status = 1 AND v.is_banned = 1
                      ORDER BY db.created_at DESC
                      LIMIT 1";

    $ban_info = $database->query($check_ban_sql, [$serial_number]);

    if (!empty($ban_info) && strtotime($ban_info[0]['ban_end_time']) <= time()) {
        $database->query(
            "UPDATE vehicles SET is_banned = 0, sharing_status = '正在共享', start_status = 'true' WHERE serial_number = ?",
            [$serial_number],
            true
        );
        $database->query(
            "UPDATE device_bans SET status = 2 WHERE serial_number = ? AND status = 1",
            [$serial_number],
            true
        );

        echo json_encode([
            'code' => 0,
            'msg' => '设备已自动解封',
            'data' => [
                'is_banned' => 0,
                'auto_unbanned' => true
            ]
        ]);
    } else {
        if (!empty($ban_info)) {
            $ban_info[0]['ban_duration'] = intval($ban_info[0]['ban_duration'] ?? 0);
            $ban_info[0]['remaining_seconds'] = max(0, strtotime($ban_info[0]['ban_end_time']) - time());
        }

        echo json_encode([
            'code' => 0,
            'msg' => '成功',
            'data' => !empty($ban_info) ? $ban_info[0] : []
        ]);
    }
    exit;
}

echo json_encode(['code' => 1002, 'msg' => '', 'data' => []]);
?>
