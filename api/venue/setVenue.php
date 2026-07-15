<?php
// /api/venue/setVenue.php
header('Content-Type: application/json; charset=utf-8');
require_once '../Database.php';
require_once '../lib/venue_scope.php';
require_once '../RedisHelper.php';
require_once './_venue_locks.php';

$locks    = new VenueLocks();
$database = new Database();
$redis    = new RedisHelper();
$redis->connect();
$redis->selectDb(3);

// ========== 公共函数 ==========
function loadSensitiveWords($filePath = '../sensitive_words.json') {
    return file_exists($filePath) ? json_decode(file_get_contents($filePath), true) : [];
}
function containsSensitiveWords($text, $words) {
    foreach ($words as $word) {
        if (mb_strpos((string)$text, $word) !== false) {
            return ['pass' => false, 'msg' => "包含敏感词：$word"];
        }
    }
    return ['pass' => true];
}
function getZegoVenueConfig($appId) {
    $configs = ['1847604878', '141962251'];
    $appId = trim((string)$appId);
    return in_array($appId, $configs, true) ? $appId : null;
}
// ================== 直播封禁（Redis TTL）==================
// key: live:ban:venue:{venue_id}
function liveBanKey($venue_id) {
    return "live:ban:venue:" . intval($venue_id);
}

// 读取封禁状态（给本接口内部用；前端建议走单独 getLiveBanStatus.php）
function getLiveBanStatus($redis, $venue_id) {
    $key = liveBanKey($venue_id);
    $ttl = $redis->ttl($key); // -2 不存在，-1 永不过期，其它为秒
    if ($ttl === null) $ttl = -2;

    $payload = $redis->get($key);
    $data = $payload ? json_decode($payload, true) : null;

    return [
        'banned' => ($ttl > 0),
        'ttl'    => $ttl,
        'data'   => $data
    ];
}

// ========== 登录校验 ==========
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '无有效认证信息，请登录', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];

// 允许部分更新，不强制要求一定带 venue_name
$sql  = "SELECT id, uid, role_id, venue_id FROM admin_users WHERE session_token = ?";
$user = $database->query($sql, [$session_token]);
if (!$user) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或不存在', 'data' => []], JSON_UNESCAPED_UNICODE);
    $database->close();
    exit;
}

$role_id      = (int)$user[0]['role_id'];
$venue_id     = $data['id'] ?? $user[0]['venue_id'];
$operator_uid = (int)$user[0]['uid'];

if (!$venue_id) {
    echo json_encode(['code' => 1002, 'msg' => '缺少场地ID', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!venue_scope_can_access($database, $user[0], (int)$venue_id)) {
    echo json_encode(['code' => 1003, 'msg' => '权限不足，无法修改该场地', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== 获取原始信息 ==========
$venueInfo = $database->query("
    SELECT id, is_banned, venue_name, venue_description, start_time, venue_status, live_stream_url, show_live_stream, income_30d_lock
    FROM venues WHERE id = ?
", [$venue_id]);

if (!$venueInfo) {
    echo json_encode(['code' => 1004, 'msg' => '未找到场地信息', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}
if ((int)$venueInfo[0]['is_banned'] === 1) {
    echo json_encode(['code' => 1005, 'msg' => '该场地已被封禁，无法修改', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$old_venue_name = trim((string)$venueInfo[0]['venue_name']);
$old_desc       = trim((string)($venueInfo[0]['venue_description'] ?? ''));
$words          = loadSensitiveWords();
$is_sensitive   = false;
$pending_review = false;          // 本次是否提交了审核
$pending_fields = [];             // 进入审核的字段：['名称','描述']

// ========== 管理员封禁/解封操作（role_id 1/2）==========
$liveBanChanged = false;
$liveBanInfoForResponse = null;

// 仅管理员可封禁/解封
if (in_array($role_id, [1, 2], true)) {
    // 封禁：前端传 ban_live=1, ban_live_ttl=xxx(秒), ban_reason=xxx(可选)
    if (isset($data['ban_live']) && (int)$data['ban_live'] === 1) {
        $ttl = isset($data['ban_live_ttl']) ? (int)$data['ban_live_ttl'] : 0;
        if ($ttl <= 0) $ttl = 24 * 3600; // 默认 24h

        // 允许的最大封禁时长（防误操作），比如最多 30 天
        $MAX_TTL = 30 * 24 * 3600;
        if ($ttl > $MAX_TTL) $ttl = $MAX_TTL;

        $reason = trim((string)($data['ban_reason'] ?? ''));

        $key = liveBanKey($venue_id);
        $payload = [
            'venue_id'   => (int)$venue_id,
            'by_uid'     => (int)$operator_uid,  // 你要求的封禁人 uid
            'by_role'    => (int)$role_id,
            'reason'     => $reason,
            'ts'         => time(),
            'expire_at'  => time() + $ttl
        ];

        $redis->save($key, json_encode($payload, JSON_UNESCAPED_UNICODE), $ttl);
        // ban 池：方便你以后后台查所有被封禁的 venue
        $redis->getNative()->sAdd('live:ban:pool', (string)$venue_id);
$database->query("UPDATE venues SET show_live_stream = 0 WHERE id = ?", [$venue_id], true);
        $liveBanChanged = true;
        $liveBanInfoForResponse = [
            'banned' => true,
            'ttl'    => $redis->ttl($key),
            'data'   => $payload
        ];
    }

    // 解封：前端传 unban_live=1
    if (isset($data['unban_live']) && (int)$data['unban_live'] === 1) {
        $key = liveBanKey($venue_id);
        $redis->delete($key);
        $redis->getNative()->sRem('live:ban:pool', (string)$venue_id);
$database->query("UPDATE venues SET show_live_stream = 1 WHERE id = ?", [$venue_id], true);
        $liveBanChanged = true;
        $liveBanInfoForResponse = [
            'banned' => false,
            'ttl'    => -2,
            'data'   => null
        ];
    }
}

// ========== role_id=3 被封禁时：禁止修改直播相关字段（防抓包）==========
$banStatus = getLiveBanStatus($redis, $venue_id);
if ((int)$role_id === 3 && $banStatus['banned']) {
    // 只要带了这两项任意一个，就拦截
    if (array_key_exists('live_stream_url', $data) || array_key_exists('show_live_stream', $data)) {
        echo json_encode([
            'code' => 1035,
            'msg'  => '直播功能已被封禁，暂不可修改（请联系管理员）',
            'data' => [
                'ban' => [
                    'ttl'   => $banStatus['ttl'],
                    'until' => date('c', time() + (int)$banStatus['ttl']),
                    'info'  => $banStatus['data']
                ]
            ]
        ], JSON_UNESCAPED_UNICODE);
        $database->close();
        exit;
    }
}
// ===== 名称审核（仅当传了 venue_name 且与旧值不同）=====
if (array_key_exists('venue_name', $data)) {
    $new_venue_name = trim((string)$data['venue_name']);
    if ($new_venue_name !== $old_venue_name) {

        // A) 冷却锁：仅非管理员受限制
        if (!in_array($role_id, [1, 2], true) && $locks->isLocked('name', $venue_id)) {
            $info = $locks->get('name', $venue_id);
            echo json_encode([
                'code' => 1020,
                'msg'  => '该场地名称处于10天冷却期，暂不可再次修改。解锁时间：' . ($info['until_iso'] ?? ''),
                'data' => ['lock' => $info]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // B) 管理员直改：跳过审核池，直接写库（不受锁限制）
        if ($role_id === 1 || $role_id === 2) {
            $updateRes = $database->query(
                "UPDATE venues SET venue_name = ? WHERE id = ?",
                [$new_venue_name, $venue_id],
                true
            );
            if ($updateRes === false) {
                echo json_encode(['code'=>1,'msg'=>'名称更新失败：数据库更新失败'], JSON_UNESCAPED_UNICODE);
                $database->close();
                exit;
            }
            // 可选：仅做审计记录（不会限制管理员）
            // $locks->set('name', $venue_id, '超管直改名称（不受冷却限制）', $operator_uid);

            // 已直接落库，不进入审核池
            $is_sensitive = false;

        } else {
            // C) 普通用户：保持原逻辑（敏感词 + 审核池）
            $check = containsSensitiveWords($new_venue_name, $words);
            $is_sensitive = !$check['pass'];
            
            // ✅ 新增：标记“进入审核：名称”
            $pending_review = true;
            if (!in_array('名称', $pending_fields, true)) $pending_fields[] = '名称';
            
            $auditKey  = "venue_name_audit:$venue_id";
            $auditData = [
                'venue_id'   => (int)$venue_id,
                'venue_name' => $new_venue_name,
                'status'     => 'pending',
                'reason'     => '',
                'timestamp'  => time()
            ];
            $existing     = $redis->get($auditKey);
            $existingData = $existing ? json_decode($existing, true) : null;
            if (!$existingData || $existingData['venue_name'] !== $new_venue_name || $existingData['status'] !== 'pending') {
                $redis->save($auditKey, json_encode($auditData, JSON_UNESCAPED_UNICODE), 86400);
                $redis->getNative()->sAdd('venue_name_audit_pool', $auditKey);
            }
        }
    }
}


// ===== 描述审核（仅当传了 venue_description 且与旧值不同）=====
if (array_key_exists('venue_description', $data)) {
    $new_desc = trim((string)$data['venue_description']);
    if ($new_desc !== $old_desc) {
        $check = containsSensitiveWords($new_desc, $words);
        $is_sensitive = $is_sensitive || !$check['pass'];
        // ✅ 新增：标记“进入审核：描述”
        $pending_review = true;
        if (!in_array('描述', $pending_fields, true)) $pending_fields[] = '描述';
        
        $descKey  = "venue_description_audit:$venue_id";
        $descData = [
            'venue_id'          => (int)$venue_id,
            'venue_description' => $new_desc,
            'status'            => 'pending',
            'reason'            => '',
            'timestamp'         => time()
        ];
        $existing     = $redis->get($descKey);
        $existingData = $existing ? json_decode($existing, true) : null;
        if (!$existingData || $existingData['venue_description'] !== $new_desc || $existingData['status'] !== 'pending') {
            $redis->save($descKey, json_encode($descData, JSON_UNESCAPED_UNICODE), 86400);
            $redis->getNative()->sAdd('venue_description_audit_pool', $descKey);
        }
    }
}

// ========== 组装“非敏感字段”动态更新 ==========
$allowed_status = ['营业中','休息中','建设中'];

// 只在前端真的传了这些键时才更新
$updates = [];
$params  = [];

// start_time
if (array_key_exists('start_time', $data)) {
    $start_time = trim((string)$data['start_time']);
    $updates[]  = 'start_time = ?';
    $params[]   = $start_time;
}

// venue_status（校验白名单）
$will_check_income = false;
if (array_key_exists('venue_status', $data)) {
    $venue_status = (string)$data['venue_status'];
    if (!in_array($venue_status, $allowed_status, true)) {
        echo json_encode(['code' => 1006, 'msg' => '非法的场地状态', 'data' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $updates[] = 'venue_status = ?';
    $params[]  = $venue_status;

    // 当前端明确要改为“营业中”时：
    // 1. 做 30 天收入锁校验
    // 2. 顺便把 venue_tags 改成“推荐”
    if ($venue_status === '营业中') {
        $will_check_income = true;

        $updates[] = 'venue_tags = ?';
        $params[]  = '推荐';

        // 可选：让接口返回 data 里也能看到 venue_tags
        $data['venue_tags'] = '推荐';
    }
}
// $will_check_income = false;
// if (array_key_exists('venue_status', $data)) {
//     $venue_status = (string)$data['venue_status'];
//     if (!in_array($venue_status, $allowed_status, true)) {
//         echo json_encode(['code' => 1006, 'msg' => '非法的场地状态', 'data' => []], JSON_UNESCAPED_UNICODE);
//         exit;
//     }
//     $updates[] = 'venue_status = ?';
//     $params[]  = $venue_status;

//     // 只有当前端明确要改为“营业中”时才做 30 天收入校验
//     if ($venue_status === '营业中') $will_check_income = true;
// }

// live_stream_url
if (array_key_exists('live_stream_url', $data)) {
    $live_stream_url = trim((string)$data['live_stream_url']);
    $updates[] = 'live_stream_url = ?';
    $params[]  = $live_stream_url;
}

// show_live_stream（0/1）
if (array_key_exists('show_live_stream', $data)) {
    $show_live_stream = (int)$data['show_live_stream'] === 1 ? 1 : 0;
    $updates[] = 'show_live_stream = ?';
    $params[]  = $show_live_stream;
}

// ZEGO AppID：仅 role_id=1/2 可修改，并通过后端白名单校验。
if (array_key_exists('zego_appid', $data)) {
    if (!in_array($role_id, [1, 2], true)) {
        echo json_encode([
            'code' => 1021,
            'msg' => '仅 role_id=1 或 2 可修改 ZEGO 配置',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $zegoConfig = getZegoVenueConfig($data['zego_appid']);
    if ($zegoConfig === null) {
        echo json_encode([
            'code' => 1022,
            'msg' => 'ZEGO配置选择无效',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $updates[] = 'zego_appid = ?';
    $params[] = $zegoConfig;
}

// venue_room_id：仅管理员可修改
if (array_key_exists('venue_room_id', $data)) {
    if (!in_array($role_id, [1, 2], true)) {
        echo json_encode([
            'code' => 1009,
            'msg'  => '仅管理员可修改 venue_room_id',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $venue_room_id_raw = $data['venue_room_id'];

    if ($venue_room_id_raw === null || $venue_room_id_raw === '') {
        $updates[] = 'venue_room_id = NULL';
    } else {
        if (!is_numeric($venue_room_id_raw) || intval($venue_room_id_raw) < 0) {
            echo json_encode([
                'code' => 1010,
                'msg'  => 'venue_room_id 参数非法',
                'data' => []
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $updates[] = 'venue_room_id = ?';
        $params[]  = intval($venue_room_id_raw);
    }
}
// dr_venue_id：仅 role_id=1 / 2 可修改
if (array_key_exists('dr_venue_id', $data)) {
    if (!in_array((int)$role_id, [1, 2], true)) {
        echo json_encode([
            'code' => 1015,
            'msg'  => '仅 role_id=1 或 2 可修改 dr_venue_id',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dr_venue_id_raw = $data['dr_venue_id'];

    // 允许清空，表示未绑定海外场地
    if ($dr_venue_id_raw === null || $dr_venue_id_raw === '') {
        $updates[] = 'dr_venue_id = NULL';
    } else {
        if (!is_numeric($dr_venue_id_raw)) {
            echo json_encode([
                'code' => 1016,
                'msg'  => 'dr_venue_id 参数非法，只能为空或数字',
                'data' => []
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $dr_venue_id = intval($dr_venue_id_raw);

        if ($dr_venue_id < 1 || $dr_venue_id > 9999) {
            echo json_encode([
                'code' => 1017,
                'msg'  => 'dr_venue_id 必须为空，或者在 1~9999 范围内',
                'data' => []
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $updates[] = 'dr_venue_id = ?';
        $params[]  = $dr_venue_id;
    }
}
// venue_status_num：仅 role_id=1 可修改
// venue_status_num：仅 role_id=1 / 2 可修改
if (array_key_exists('venue_status_num', $data)) {
    if (!in_array((int)$role_id, [1, 2], true)) {
        echo json_encode([
            'code' => 1012,
            'msg'  => '仅 role_id=1 或 2 可修改 venue_status_num',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $venue_status_num = intval($data['venue_status_num']);

    if (!in_array($venue_status_num, [0, 2], true)) {
        echo json_encode([
            'code' => 1013,
            'msg'  => 'venue_status_num 仅允许 0(关闭) 或 2(语音房)',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $updates[] = 'venue_status_num = ?';
    $params[]  = $venue_status_num;
}
// voice_room_scene_type：仅 role_id=1 / 2 可修改
if (array_key_exists('voice_room_scene_type', $data)) {
    if (!in_array((int)$role_id, [1, 2], true)) {
        echo json_encode([
            'code' => 1018,
            'msg'  => '仅 role_id=1 或 2 可修改 voice_room_scene_type',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $voice_room_scene_type = intval($data['voice_room_scene_type']);

    if (!in_array($voice_room_scene_type, [1, 2], true)) {
        echo json_encode([
            'code' => 1019,
            'msg'  => 'voice_room_scene_type 仅允许 1(室内) 或 2(户外)',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $updates[] = 'voice_room_scene_type = ?';
    $params[]  = $voice_room_scene_type;
}
// voice_room_bg_url：仅 role_id=1 / 2 可修改
if (array_key_exists('voice_room_bg_url', $data)) {
    if (!in_array((int)$role_id, [1, 2], true)) {
        echo json_encode([
            'code' => 1014,
            'msg'  => '仅 role_id=1 或 2 可修改 voice_room_bg_url',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $voice_room_bg_url = trim((string)$data['voice_room_bg_url']);

    $updates[] = 'voice_room_bg_url = ?';
    $params[]  = $voice_room_bg_url;
}
// ========== “营业中”收入校验（仅当本次提交真的要改为营业中）==========
// if ($will_check_income && (int)$role_id === 3) {
//     // 是否有过任何实付订单（且非“能量”）
//     $everPaid = $database->query("
//         SELECT 1
//         FROM orders o
//         WHERE o.reservation_id = ?
//           AND o.pays_type != '能量'
//           AND o.payment_amount > 0
//         LIMIT 1
//     ", [$venue_id]);

//     if (!empty($everPaid)) {
//         $checkSql = "
//             SELECT COALESCE(SUM(o.payment_amount), 0) AS total_payment_30d
//             FROM venues v
//             LEFT JOIN orders o
//               ON o.reservation_id = v.id
//              AND o.pays_type != '能量'
//              AND o.payment_amount > 0
//              AND o.end_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
//              AND o.end_time <  DATE_ADD(CURDATE(), INTERVAL 1 DAY)
//             WHERE v.id = ?
//             GROUP BY v.id
//         ";
//         $checkRes = $database->query($checkSql, [$venue_id]);
//         $total_payment_30d = (float)($checkRes[0]['total_payment_30d'] ?? 0.0);

//         if ($total_payment_30d == 0.0) {
//             echo json_encode([
//                 'code' => 1010,
//                 'msg'  => '该场地近 30 天没有收入，请联系平台管理员',
//                 'data' => []
//             ], JSON_UNESCAPED_UNICODE);
//             $database->close();
//             exit;
//         }
//     }
// }

// ========== “营业中”收入校验（仅当本次提交真的要改为营业中）==========
// 仅限制 role_id === 3；管理员(1) 及其它角色不受此限制
if ($will_check_income && (int)$role_id === 3) {
    if ((int)($venueInfo[0]['income_30d_lock'] ?? 0) === 1) {
        echo json_encode([
            'code' => 1011,
            'msg'  => '该场地近30天无收入，已被锁定，请联系平台管理员解锁',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        $database->close();
        exit;
    }
}

// ========== 执行更新（有字段才更新）==========
if (!empty($updates)) {
    $sql = "UPDATE venues SET " . implode(', ', $updates) . " WHERE id = ?";
    $params[] = $venue_id;
    $updateResult = $database->query($sql, $params, true);

    if ($updateResult === false) {
        echo json_encode([
            'code' => 1,
            'msg'  => '场地信息修改失败，数据库更新失败',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        $database->close();
        exit;
    }
} // 如果没有任何非敏感字段需要更新，也继续往下返回成功/待审核提示

// 管理员可手动清除 30天收入锁（前端传 unlock_income_30d=1）
if (in_array($role_id, [1, 2], true)&& isset($data['unlock_income_30d']) && (int)$data['unlock_income_30d'] === 1) {
    // 1) 先解锁数据库字段
    $database->query("UPDATE venues SET income_30d_lock = 0 WHERE id = ?", [$venue_id], true);
    $venueInfo[0]['income_30d_lock'] = 0;

    // 2) 写入 Redis 豁免标记（3 天 = 259200 秒）
    $EXPIRE_SECONDS = 3 * 24 * 3600;
    $key = "income30d:unlock:venue:$venue_id";

    $payload = [
        'venue_id'  => (int)$venue_id,
        'by_uid'    => (int)$operator_uid,
        'by_role'   => (int)$role_id,
        'reason'    => 'admin-manual-unlock',
        'ts'        => time(),
        'expire_at' => time() + $EXPIRE_SECONDS
    ];

    // 关键：单个 venue 的“豁免键”用 TTL 控制过期
    $redis->save($key, json_encode($payload, JSON_UNESCAPED_UNICODE), $EXPIRE_SECONDS);

    // 额外维护一个集合，方便计划任务一次拿到所有待豁免 venue_id
    $redis->getNative()->sAdd('income30d:unlock:set', (string)$venue_id);

    // 可选：把剩余 TTL 回给前端查看
    $ttl = $redis->ttl($key);
    $responseData['income30d_unlock'] = [
        'ttl'   => $ttl,
        'until' => date('c', time() + max(0, (int)$ttl))
    ];
}


// ========== 统一返回 ==========
if ($role_id === 1 || $role_id === 2) {
    // 管理员：无论是否触发审核标记，都返回成功（你的需求）
    $successCode = 0;
    $successMsg  = '场地信息修改成功';
} else {
    if ($pending_review) {
        $successCode = 1008;
        $successMsg  = '已提交审核：' . implode('、', $pending_fields) . '；其它信息已保存';
    } else {
        $successCode = 0;
        $successMsg  = '场地信息修改成功（名称或描述未变更或无需审核）';
    }
}


// 这里的 data 回显“这次提交的字段”，前端不依赖 locks，这里可不返回；如需兼容可保留
$responseData = $data;
// 附带直播封禁状态（便于前端刷新按钮）
$responseData['live_ban'] = $liveBanChanged
    ? $liveBanInfoForResponse
    : getLiveBanStatus($redis, $venue_id);

// 可选：附带锁查看（不会在此处修改锁）
$responseData['locks'] = [
    'name'  => $locks->get('name',  $venue_id),
    'image' => $locks->get('image', $venue_id),
];

echo json_encode([
    'code' => $successCode,
    'msg'  => $successMsg,
    'data' => $responseData
], JSON_UNESCAPED_UNICODE);

$database->close();
