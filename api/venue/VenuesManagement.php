<?php 
//  /api/venue/VenuesManagement.php
require_once '../Database.php';  
 
// 创建数据库连接 
$database = new Database(); 
 
// 获取 session_token 
$session_token = $_COOKIE['session_token'] ?? null; 
 
if (!$session_token) { 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]); 
    exit; 
} 
 
// 验证用户信息 
$user = $database->getUserBySessionToken($session_token); 
if (!$user || !$user['role_id']) { 
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]); 
    exit; 
} 
 function genInviteCode($venueId) {
    $n = intval($venueId);
    if ($n <= 0) return "----";
    if ($n < 10) return "100" . $n;       // 5 => 1005
    if ($n < 100) return "10" . $n;       // 55 => 1055
    if ($n < 1000) return "1" . $n;       // 555 => 1555
    return substr(strval($n), -4);        // >=1000 取后4位
}

// ================================
// 同步 DR 海外场地配置
// ================================
define('DR_SYNC_VENUE_URL', 'https://open.nodistanceco.com/api/venue/receive_rc_venue.php');
define('DR_SYNC_TOKEN', 'd051cddb5a8ca9792b30bdddda431abb572a32638f9b36e2e90503e1535ecefd');

function calcVenueStatusNum($venueStatus)
{
    $venueStatus = trim((string)$venueStatus);

    if ($venueStatus === '休息中') {
        return 1;
    }

    if ($venueStatus === '直播中') {
        return 2;
    }

    return 0; // 默认营业中
}

function normalizeVenueLevel($level)
{
    $level = strtoupper(trim((string)$level));
    $allowed = ['S', 'A', 'B', 'C', 'D'];

    return in_array($level, $allowed, true) ? $level : 'A';
}

function getZegoVenueConfig($appId)
{
    $configs = [
        '1847604878' => '酷玩星',
        '141962251' => 'RC物联',
    ];

    $appId = trim((string)$appId);
    return isset($configs[$appId])
        ? ['appid' => $appId, 'name' => $configs[$appId]]
        : null;
}

function syncVenueToDr($payload)
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => DR_SYNC_VENUE_URL,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . DR_SYNC_TOKEN,
        ],
        CURLOPT_POSTFIELDS => $json,
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'error' => $curlErr,
            'response' => $response,
        ];
    }

    $decoded = json_decode($response, true);

    if ($httpCode !== 200) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'error' => 'DR接口HTTP状态异常',
            'response' => $response,
        ];
    }

    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'error' => 'DR接口返回不是JSON',
            'response' => $response,
        ];
    }

    return [
        'ok' => isset($decoded['code']) && intval($decoded['code']) === 0,
        'http_code' => $httpCode,
        'msg' => $decoded['msg'] ?? '',
        'data' => $decoded['data'] ?? null,
        'response' => $decoded,
    ];
}

function removeVenueFromUserVenueListCaches($venueId)
{
    $venueId = intval($venueId);

    $result = [
        'ok' => false,
        'checked_keys' => 0,
        'changed_keys' => 0,
        'removed_items' => 0,
        'error' => ''
    ];

    if ($venueId <= 0) {
        $result['error'] = 'venueId无效';
        return $result;
    }

    if (!class_exists('Redis')) {
        $result['error'] = 'Redis扩展不存在';
        return $result;
    }

    try {
        $redis = new Redis();

        $ok = @$redis->connect('127.0.0.1', 6379, 1.5);
        if (!$ok) {
            $result['error'] = 'Redis连接失败';
            return $result;
        }

        $redis->select(0);

        $pattern = 'venue_info:list:v2:uid:*';
        $keys = $redis->keys($pattern);

        if (!is_array($keys)) {
            $keys = [];
        }

        foreach ($keys as $key) {
            $result['checked_keys']++;

            $raw = $redis->get($key);
            if ($raw === false || $raw === '') {
                continue;
            }

            $json = json_decode($raw, true);
            if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
                continue;
            }

            $oldCount = count($json['data']);

            $json['data'] = array_values(array_filter($json['data'], function ($item) use ($venueId) {
                return intval($item['id'] ?? 0) !== $venueId;
            }));

            $newCount = count($json['data']);
            $removed = $oldCount - $newCount;

            if ($removed > 0) {
                $ttl = $redis->ttl($key);
                $newRaw = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($ttl > 0) {
                    $redis->setex($key, $ttl, $newRaw);
                } else {
                    $redis->set($key, $newRaw);
                }

                $result['changed_keys']++;
                $result['removed_items'] += $removed;
            }
        }

        $redis->close();

        $result['ok'] = true;
        return $result;

    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        return $result;
    }
}

$role_id = $user['role_id']; 
header('Content-Type: application/json; charset=utf-8'); 
 
// 获取请求方法 
$method = $_SERVER['REQUEST_METHOD']; 
if ($method === 'POST') { 
    // 获取 action 参数 
    $action = $_POST['action'] ?? ''; 
 
    if ($action === 'addvenues') { 
        // 获取请求参数 
        $venue_name = $_POST['venue_name'] ?? ''; 
        $venue_subtitle = trim($_POST['venue_subtitle'] ?? '');

        if ($venue_subtitle !== '' && !preg_match('/^[A-Za-z0-9]+$/', $venue_subtitle)) {
            echo json_encode([
                'code' => 3,
                'msg' => '场地副标题仅允许填写英文字母和数字，不支持中文或符号',
                'data' => []
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $image_url = $_POST['image_url'] ?? ''; 
        $venue_description = $_POST['venue_description'] ?? null; 
        $venue_tags = $_POST['venue_tags'] ?? null; 
        $venue_type = $_POST['venue_type'] ?? 1; 
        $event_id = 0; // 默认活动id为0 
        $start_time = $_POST['start_time'] ?? '10:00 - 21:00'; 
        $queue_length = 0; // 默认排队人数为0 
        $venue_status = $_POST['venue_status'] ?? '营业中'; 
        $live_stream_url = $_POST['live_stream_url'] ?? null; 
        $show_live_stream = $_POST['show_live_stream'] ?? 0; 
        $is_claw_machine_venue = isset($_POST['is_claw_machine_venue']) ? intval($_POST['is_claw_machine_venue']) : 0;
        $venue_level = normalizeVenueLevel($_POST['venue_level'] ?? 'A');
        $zego_config = getZegoVenueConfig($_POST['zego_appid'] ?? '1847604878');

        if ($zego_config === null) {
            echo json_encode(['code' => 2, 'msg' => 'ZEGO配置选择无效', 'data' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        
        $sync_overseas = isset($_POST['sync_overseas']) && strval($_POST['sync_overseas']) === '1';

        // 这些字段当前添加表单里没有，就给默认值
        $venue_status_num = calcVenueStatusNum($venue_status);
        $is_banned = 0;
        $is_pinned = 0;
        $zone_id = 1;
        $image_status = 'approved';
        
        // 注意：RC 的 venue_room_id 是主播房ID，DR 那边你之前是竖屏roomID。
        // 如果老板确认就同步，否则建议传 null。
        $venue_room_id = isset($_POST['venue_room_id']) && $_POST['venue_room_id'] !== ''
            ? intval($_POST['venue_room_id'])
            : null;
        
        // 自动生成场地id（当前最大id + 1） 
        $getMaxIdSql = "SELECT MAX(id) as max_id FROM venues"; 
        $result = $database->query($getMaxIdSql); 
        $maxId = $result ? $result[0]['max_id'] : 0; 
        $id = $maxId + 1; 
         // 新增场地默认房间ID：120000 + 主键id
        $venue_room_id = 120000 + intval($id);
        // 插入新场地数据 
        $insertSql = "INSERT INTO venues (
            id,
            venue_name,
            venue_subtitle,
            image_url,
            venue_description,
            venue_tags,
            venue_type,
            event_id,
            start_time,
            queue_length,
            venue_status,
            venue_room_id,
            live_stream_url,
            show_live_stream,
            is_claw_machine_venue,
            venue_level,
            zego_appid
       ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [
            $id,
            $venue_name,
            $venue_subtitle,
            $image_url,
            $venue_description,
            $venue_tags,
            $venue_type,
            $event_id,
            $start_time,
            $queue_length,
            $venue_status,
            $venue_room_id,
            $live_stream_url,
            $show_live_stream,
            $is_claw_machine_venue,
            $venue_level,
            $zego_config['appid']
        ];
   if ($database->query($insertSql, $params, true)) {

    // ✅【新增】生成邀请码
    $invite_code = genInviteCode($id);

    // ✅【新增】写回 venues.invite_code（确保 venues 已加 invite_code 字段）
    $database->query(
        "UPDATE venues SET invite_code = ? WHERE id = ? LIMIT 1",
        [$invite_code, (string)$id],
        true
    );

    // ✅【新增】可选：同步写入 venue_invite_codes（你截图那张表）
    // 如果你老板还要这张表继续用，就保留；不需要就删掉这段
    $database->query(
        "INSERT INTO venue_invite_codes (venue_id, invite_code, is_enabled)
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE invite_code = VALUES(invite_code), is_enabled = 1",
        [(string)$id, $invite_code],
        true
    );

    $syncResult = null;
    
    if ($sync_overseas) {
        $syncPayload = [
            // 不建议同步 id，避免 DR 主键冲突，让 DR 自己自增
            'rc_venue_id' => intval($id),
    
            'venue_name' => $venue_name,
            'image_url' => $image_url,
            'venue_description' => $venue_description,
            'venue_tags' => $venue_tags,
            'venue_type' => intval($venue_type),
            'event_id' => intval($event_id),
            'start_time' => $start_time,
    
            'venue_status' => $venue_status,
            'venue_status_num' => intval($venue_status_num),
            'live_stream_url' => $live_stream_url,
            'show_live_stream' => intval($show_live_stream),
            'is_banned' => intval($is_banned),
            'is_pinned' => intval($is_pinned),
            'zone_id' => intval($zone_id),
            'image_status' => $image_status,
            'venue_room_id' => $venue_room_id,
        ];
    
        $syncResult = syncVenueToDr($syncPayload);
        
        if ($syncResult && !empty($syncResult['ok'])) {
            $drVenueId = 0;
        
            if (isset($syncResult['data']['dr_venue_id'])) {
                $drVenueId = intval($syncResult['data']['dr_venue_id']);
            } elseif (isset($syncResult['data']['id'])) {
                $drVenueId = intval($syncResult['data']['id']);
            }
        
            if ($drVenueId > 0) {
                $writeBackOk = $database->query(
                    "UPDATE venues SET dr_venue_id = ? WHERE id = ? LIMIT 1",
                    [$drVenueId, $id],
                    true
                );
        
                $syncResult['dr_venue_id'] = $drVenueId;
                $syncResult['rc_write_back'] = $writeBackOk ? 1 : 0;
        
                if (!$writeBackOk) {
                    $syncResult['ok'] = false;
                    $syncResult['error'] = 'DR创建成功，但RC写回dr_venue_id失败';
                }
            } else {
                $syncResult['ok'] = false;
                $syncResult['error'] = 'DR创建成功，但没有返回dr_venue_id';
            }
        }
    }
    
    $drVenueIdForReturn = 0;
    
    if ($syncResult && isset($syncResult['dr_venue_id'])) {
        $drVenueIdForReturn = intval($syncResult['dr_venue_id']);
    }
    
    echo json_encode([
        'code' => 0,
        'msg' => $sync_overseas
            ? ($syncResult && !empty($syncResult['ok']) ? '场地添加成功，海外同步成功' : '场地添加成功，但海外同步失败')
            : '场地添加成功',
        'id' => $id,
        'venue_room_id' => $venue_room_id,
        'invite_code' => $invite_code,
        'venue_level' => $venue_level,
        'zego_appid' => $zego_config['appid'],
        'zego_app_name' => $zego_config['name'],
        'dr_venue_id' => $drVenueIdForReturn,
        'sync_overseas' => $syncResult
    ], JSON_UNESCAPED_UNICODE);

} else {
    echo json_encode(['code' => 1, 'msg' => '场地添加失败', 'data' => []], JSON_UNESCAPED_UNICODE);
}
 
        // 关闭数据库连接 
        $database->close(); 
        }
elseif ($action === 'unban_venue') {

    $venue_id = $_POST['venue_id'] ?? null;
    $unban_reason = trim($_POST['unban_reason'] ?? '');

    if (!$venue_id || !is_numeric($venue_id)) {
        echo json_encode(['code' => 1, 'msg' => '非法场地ID']);
        exit;
    }

    if ($unban_reason === '' || mb_strlen($unban_reason) < 5) {
        echo json_encode(['code' => 2, 'msg' => '请填写解封原因（至少5个字）']);
        exit;
    }

    $admin_id = $user['uid']; // 当前操作人

    $conn = $database->getConnection();
    $conn->begin_transaction();

    try {
        // 1️⃣ 查找当前生效封禁
        $banSql = "SELECT id FROM venue_bans WHERE venue_id = ? AND status = 1 ORDER BY id DESC LIMIT 1";
        $stmt = $conn->prepare($banSql);
        $stmt->bind_param('i', $venue_id);
        $stmt->execute();
        $ban = $stmt->get_result()->fetch_assoc();

        if (!$ban) {
            throw new Exception('未找到可解封的封禁记录');
        }

        $ban_id = $ban['id'];

        // 2️⃣ 写入解封留存表
        $logSql = "
            INSERT INTO venue_unban_logs 
            (venue_id, ban_id, unban_reason, unban_status, unban_by)
            VALUES (?, ?, ?, 1, ?)
        ";
        $stmt = $conn->prepare($logSql);
        $stmt->bind_param('iisi', $venue_id, $ban_id, $unban_reason, $admin_id);
        $stmt->execute();

        // 3️⃣ 更新封禁记录
        $updateBanSql = "
            UPDATE venue_bans 
            SET status = 2, ban_end_time = NOW() 
            WHERE id = ?
        ";
        $stmt = $conn->prepare($updateBanSql);
        $stmt->bind_param('i', $ban_id);
        $stmt->execute();

        // 4️⃣ 更新场地状态
        $updateVenueSql = "
            UPDATE venues 
            SET is_banned = 0, venue_status = '营业中'
            WHERE id = ?
        ";
        $stmt = $conn->prepare($updateVenueSql);
        $stmt->bind_param('i', $venue_id);
        $stmt->execute();

        $conn->commit();

        echo json_encode(['code' => 0, 'msg' => '解封成功']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['code' => 500, 'msg' => $e->getMessage()]);
    }

    exit;
    
}
elseif ($action === 'set_venue_status') {

    $venue_id = intval($_POST['venue_id'] ?? 0);
    $venue_status = trim($_POST['venue_status'] ?? '');
    // $allowedStatuses = ['营业中', '休息中', '建设中'];
    $allowedStatuses = ['营业中', '休息中'];

    if ($venue_id <= 0) {
        echo json_encode(['code' => 1, 'msg' => '场地ID无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!in_array($venue_status, $allowedStatuses, true)) {
        echo json_encode(['code' => 2, 'msg' => '场地状态参数错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 改成休息中时，顺手取消直播推荐位，避免状态不一致
    if ($venue_status === '营业中') {
        $sql = "UPDATE venues 
                SET venue_status = ?
                WHERE id = ?
                LIMIT 1";
        $params = [$venue_status, $venue_id];
    } else {
        $sql = "UPDATE venues 
                SET venue_status = ?,
                    is_live = 0,
                    is_recommend = 0,
                    live_marked_at = NULL
                WHERE id = ?
                LIMIT 1";
        $params = [$venue_status, $venue_id];
    }

    $ok = $database->query($sql, $params, true);

    if ($ok) {
        echo json_encode([
            'code' => 0,
            'msg' => '场地状态已修改为：' . $venue_status
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['code' => 3, 'msg' => '场地状态修改失败'], JSON_UNESCAPED_UNICODE);
    }

    exit;

}
elseif ($action === 'set_live_stream_flag') {

    $venue_id = intval($_POST['venue_id'] ?? 0);
    $is_live = intval($_POST['show_live_stream'] ?? 0); // 前端先不改参数名，兼容旧调用

    if ($venue_id <= 0) {
        echo json_encode(['code' => 1, 'msg' => '场地ID无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!in_array($is_live, [0, 1], true)) {
        echo json_encode(['code' => 2, 'msg' => '直播参数错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($is_live === 1) {
        $sql = "UPDATE venues 
                SET is_live = 1,
                    is_recommend = 1,
                    live_marked_at = NOW(),
                    venue_status = '营业中',
                    need_live_review = 0,
                    live_review_priority = 0,
                    last_live_cleared_at = NULL
                WHERE id = ?
                LIMIT 1";
        $ok = $database->query($sql, [$venue_id], true);
    } else {
        $sql = "UPDATE venues 
                SET is_live = 0,
                    is_recommend = 0,
                    live_marked_at = NULL,
                    need_live_review = 0,
                    live_review_priority = 0
                WHERE id = ?
                LIMIT 1";
        $ok = $database->query($sql, [$venue_id], true);
    }

    if ($ok) {
        echo json_encode([
            'code' => 0,
            'msg' => $is_live === 1 ? '已标记为直播推荐位' : '已取消直播推荐位'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['code' => 3, 'msg' => '更新失败'], JSON_UNESCAPED_UNICODE);
    }

    exit;
}
elseif ($action === 'set_venue_sound_disabled') {

    $venue_id = intval($_POST['venue_id'] ?? 0);
    $is_disabled_sound = intval($_POST['is_disabled_sound'] ?? 0);

    if ($venue_id <= 0) {
        echo json_encode(['code' => 1, 'msg' => '场地ID无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!in_array($is_disabled_sound, [0, 1], true)) {
        echo json_encode(['code' => 2, 'msg' => '参数错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql = "UPDATE venues 
            SET is_disabled_sound = ?
            WHERE id = ?
            LIMIT 1";

    $ok = $database->query($sql, [$is_disabled_sound, $venue_id], true);

    if ($ok) {
        echo json_encode([
            'code' => 0,
            'msg' => $is_disabled_sound === 1 ? '已封禁场地喇叭麦克风' : '已解除场地喇叭麦克风封禁'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['code' => 3, 'msg' => '更新失败'], JSON_UNESCAPED_UNICODE);
    }

    exit;
}
elseif ($action === 'set_user_mic_enabled') {

    $venue_id = intval($_POST['venue_id'] ?? 0);
    $is_user_mic_enabled = intval($_POST['is_user_mic_enabled'] ?? 0);

    if ($venue_id <= 0) {
        echo json_encode(['code' => 1, 'msg' => '场地ID无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!in_array($is_user_mic_enabled, [0, 1], true)) {
        echo json_encode(['code' => 2, 'msg' => '用户上麦参数错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql = "UPDATE venues
            SET is_user_mic_enabled = ?
            WHERE id = ?
            LIMIT 1";

    $ok = $database->query($sql, [$is_user_mic_enabled, $venue_id], true);

    if ($ok) {
        // 清用户端场地列表缓存，避免 App 侧仍看到旧状态
        if (function_exists('removeVenueFromUserVenueListCaches')) {
            removeVenueFromUserVenueListCaches($venue_id);
        }

        echo json_encode([
            'code' => 0,
            'msg'  => $is_user_mic_enabled === 1 ? '已开启用户上麦' : '已关闭用户上麦',
            'data' => [
                'venue_id' => $venue_id,
                'is_user_mic_enabled' => $is_user_mic_enabled
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['code' => 3, 'msg' => '用户上麦状态修改失败'], JSON_UNESCAPED_UNICODE);
    }

    exit;
}
elseif ($action === 'set_voice_room_scene_type') {

    if (!in_array((int)$role_id, [1, 2], true)) {
        echo json_encode([
            'code' => 1003,
            'msg'  => '权限不足，仅 role_id=1/2 可修改语音房场景'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $venue_id = intval($_POST['venue_id'] ?? 0);
    $voice_room_scene_type = intval($_POST['voice_room_scene_type'] ?? 0);

    if ($venue_id <= 0) {
        echo json_encode(['code' => 1, 'msg' => '场地ID无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!in_array($voice_room_scene_type, [1, 2], true)) {
        echo json_encode(['code' => 2, 'msg' => '场景参数错误，只能是 1=室内 或 2=户外'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql = "UPDATE venues 
            SET voice_room_scene_type = ?
            WHERE id = ?
            LIMIT 1";

    $ok = $database->query($sql, [$voice_room_scene_type, $venue_id], true);

    if ($ok) {
        // 清用户端场地列表缓存，避免 App 侧仍看到旧场景
        if (function_exists('removeVenueFromUserVenueListCaches')) {
            removeVenueFromUserVenueListCaches($venue_id);
        }

        echo json_encode([
            'code' => 0,
            'msg'  => $voice_room_scene_type === 1 ? '已切换为室内' : '已切换为户外',
            'data' => [
                'venue_id' => $venue_id,
                'voice_room_scene_type' => $voice_room_scene_type
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['code' => 3, 'msg' => '语音房场景修改失败'], JSON_UNESCAPED_UNICODE);
    }

    exit;
}
elseif ($action === 'set_voice_room_ban') {

    $venue_id = intval($_POST['venue_id'] ?? 0);
    $ban_duration = intval($_POST['ban_duration'] ?? 0);
    $ban_reason = trim($_POST['ban_reason'] ?? '');

    if ($venue_id <= 0) {
        echo json_encode(['code' => 1, 'msg' => '场地ID无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ban_duration <= 0) {
        echo json_encode(['code' => 2, 'msg' => '请选择封禁时长'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ban_reason === '') {
        $ban_reason = '直播内容存在违规，已关闭语音房功能';
    }

    $operator = intval($user['uid'] ?? 0);

    $conn = $database->getConnection();
    $conn->begin_transaction();

    try {
        // 1. 查询场地和房间号
        $stmt = $conn->prepare("
            SELECT id, venue_name, venue_room_id, is_voice_room_banned
            FROM venues
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $venue_id);
        $stmt->execute();
        $venue = $stmt->get_result()->fetch_assoc();

        if (!$venue) {
            throw new Exception('场地不存在');
        }

        // 2. 防止重复封禁
        $stmt = $conn->prepare("
            SELECT id
            FROM voice_room_ban_records
            WHERE venue_id = ?
              AND status = 1
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $venue_id);
        $stmt->execute();
        $activeBan = $stmt->get_result()->fetch_assoc();

        if ($activeBan) {
            throw new Exception('该语音房已处于封禁中');
        }

        // 3. 更新 venues 封禁状态
        $stmt = $conn->prepare("
            UPDATE venues
            SET is_voice_room_banned = 1
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $venue_id);
        $stmt->execute();

        // 4. 写入封禁记录
        $sql = "
            INSERT INTO voice_room_ban_records
                (venue_id, ban_duration, ban_start_time, ban_end_time, status, operator, ban_reason)
            VALUES
                (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL {$ban_duration} SECOND), 1, ?, ?)
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iiis', $venue_id, $ban_duration, $operator, $ban_reason);
        $stmt->execute();

        $recordId = $conn->insert_id;

        $conn->commit();
        $cacheResult = removeVenueFromUserVenueListCaches($venue_id);
        // 5. 提交成功后再发 ZEGO 自定义信令
        $roomId = trim((string)($venue['venue_room_id'] ?? ''));
        $sendResult = null;

        if ($roomId !== '') {
            $sendUrl = 'https://open.kwxapp.cn/api/devMgr/send_voice_room_ban_message.php';

            $payload = json_encode([
                'room_id' => $roomId,
                'ban_reason' => $ban_reason,
                'from_user_id' => 'server_bot_1'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $sendUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json'
                ],
            ]);

            $sendRaw = curl_exec($ch);
            $sendErr = curl_error($ch);
            curl_close($ch);

            $sendResult = [
                'raw' => $sendRaw,
                'error' => $sendErr
            ];
        }

        echo json_encode([
            'code' => 0,
            'msg' => '语音房已封禁',
            'data' => [
                'record_id' => $recordId,
                'venue_id' => $venue_id,
                'room_id' => $roomId,
                'ban_duration' => $ban_duration,
                'ban_reason' => $ban_reason,
                'send_result' => $sendResult,
                'cache_result' => $cacheResult,
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        $conn->rollback();

        echo json_encode([
            'code' => 500,
            'msg' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}
elseif ($action === 'set_voice_room_unban') {

    $venue_id = intval($_POST['venue_id'] ?? 0);

    if ($venue_id <= 0) {
        echo json_encode(['code' => 1, 'msg' => '场地ID无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $conn = $database->getConnection();
    $conn->begin_transaction();

    try {
        // 1. 结束当前进行中的语音房封禁记录
        $stmt = $conn->prepare("
            UPDATE voice_room_ban_records
            SET status = 2,
                ban_end_time = NOW()
            WHERE venue_id = ?
              AND status = 1
        ");
        $stmt->bind_param('i', $venue_id);
        $stmt->execute();

        // 2. 恢复 venues 状态
        $stmt = $conn->prepare("
            UPDATE venues
            SET is_voice_room_banned = 0
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $venue_id);
        $stmt->execute();

        $conn->commit();

        echo json_encode([
            'code' => 0,
            'msg' => '语音房已解除封禁'
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        $conn->rollback();

        echo json_encode([
            'code' => 500,
            'msg' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}
elseif ($action === 'set_venue_level') {

    $venue_id = intval($_POST['venue_id'] ?? 0);
    $venue_level = normalizeVenueLevel($_POST['venue_level'] ?? '');

    if ($venue_id <= 0) {
        echo json_encode(['code' => 1, 'msg' => '场地ID无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!in_array($venue_level, ['S', 'A', 'B', 'C', 'D'], true)) {
        echo json_encode(['code' => 2, 'msg' => '评级参数错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ok = $database->query(
        "UPDATE venues SET venue_level = ? WHERE id = ? LIMIT 1",
        [$venue_level, $venue_id],
        true
    );

    if ($ok) {
        echo json_encode([
            'code' => 0,
            'msg' => '场地评级已修改为 ' . $venue_level
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['code' => 3, 'msg' => '评级修改失败'], JSON_UNESCAPED_UNICODE);
    }

    exit;
}
elseif ($action === 'loadingdata') {
        try { 
            // 查询 venues 表中的数据 列出已有场地list 
            $query = "SELECT 
                        v.id, 
                        v.dr_venue_id,
                        v.venue_name,
                        v.venue_subtitle,
                        COALESCE(NULLIF(TRIM(v.venue_level), ''), 'A') AS venue_level,
                        v.is_disabled_sound,
                        v.is_user_mic_enabled,
                        v.venue_room_id,
                        v.is_voice_room_banned,
                        v.voice_room_scene_type,
                        (
                            SELECT vr.ban_end_time
                            FROM voice_room_ban_records vr
                            WHERE vr.venue_id = v.id
                              AND vr.status = 1
                            ORDER BY vr.id DESC
                            LIMIT 1
                        ) AS voice_room_ban_end_time,
                        v.is_spending_alert,
                        v.image_url, 
                        v.venue_description, 
                        v.venue_tags, 
                        v.venue_type,  
                        v.event_id, 
                        v.start_time, 
                        v.queue_length, 
                        v.venue_status, 
                        v.live_stream_url, 
                        v.show_live_stream,
                        v.is_live,
                        v.is_recommend,
                        v.live_marked_at,
                        v.need_live_review,
                        v.live_review_priority,
                        v.last_live_cleared_at,
                        EXISTS (
                            SELECT 1 FROM venue_bans vb 
                            WHERE vb.venue_id = v.id AND vb.status = 1
                        ) AS is_banned_venue
                    FROM 
                        venues v";

            $stmt = $database->getConnection()->prepare($query); 
            $stmt->execute(); 
            // 获取查询结果 
            $result = $stmt->get_result(); 
 
            // 如果有查询结果 
            $list = []; 
            if ($result->num_rows > 0) { 
                while ($row = $result->fetch_assoc()) { 
                    $list[] = $row; 
                } 
            } 
 
            // 返回成功响应 
            echo json_encode(['code' => 200, 'msg' => '数据加载成功', 'data' => $list,'role' => $role_id]); 
        } catch (PDOException $e) { 
            // 处理数据库查询错误 
            echo json_encode(['code' => 500, 'msg' => '数据库查询出错: ' . $e->getMessage(), 'data' => []]); 
        } 
    } 
} 
?> 
