<?php
// api/checkin/next_prompt_redis.php
require_once '../Database.php';
require_once '../RedisHelper.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Taipei');
$db   = new Database();
// 从会话中获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;

// 验证 session_token 并获取用户信息
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $db->getUserBySessionToken($session_token);

// 检查用户是否存在和权限获取
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

function ok($data){
    echo json_encode(['code'=>200,'show'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}
function none(){
    echo json_encode(['code'=>200,'show'=>false,'data'=>null], JSON_UNESCAPED_UNICODE);
    exit;
}
function bad($msg){
    echo json_encode(['code'=>500,'msg'=>$msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try{
    $rh = new RedisHelper();
    $rh->connect('127.0.0.1',6379,2);
    $rh->selectDb(10);

    $now      = time();
    $period   = date('YmdH');                 // 当前小时
    $slotsKey = "checkin:slots:$period";
    $pinKey   = "checkin:pin:$period";
    $resignCandidateKey = "checkin:resign_candidate:$period";

    // ===== 1) 若已存在 pin（正常 slot / 补签），直接返回 =====
    $pin = $rh->get($pinKey);
    if ($pin) {
        $p = json_decode($pin, true);
        if (!empty($p['planned_at']) || !empty($p['is_makeup'])) {
            ok($p);
        }
    }

    // ===== 2) 正常随机节点逻辑 =====
    $slotsJson = $rh->get($slotsKey);
    if ($slotsJson) {
        $slots = json_decode($slotsJson, true);
        if (!is_array($slots)) {
            none();
        }

        // 最近 10 分钟内的已到时间点
        $windowSec = 600;
        $pastSlots = array_filter($slots, function($t) use ($now, $windowSec){
            $ts = strtotime($t);
            if ($ts === false) return false;
            if ($ts > $now) return false;                // 只看已到时间
            return ($now - $ts) <= $windowSec;           // 距现在不超过 10 分钟
        });

        if ($pastSlots) {
            $planned = end($pastSlots);  // 最近一个已到的 slot

            $ttl = random_int(120,180);
            $payload = [
                'planned_at'   => $planned,
                'is_makeup'    => false,         // 正常 slot
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

            if (method_exists($rh, 'setWithExpiration')) {
                $rh->setWithExpiration($pinKey, $json, $ttl);
            } else {
                $rh->set($pinKey, $json);
                if (method_exists($rh, 'expireAt')) {
                    $rh->expireAt($pinKey, time() + $ttl);
                }
            }

            ok($payload);
        }

        // 有 slotsKey，但最近 10 分钟内没有可用的 → 当成“正常情况里暂时没到点”
        // 此时不走补签，直接 none()
        none();
    }

    // ===== 3) 走到这里，说明 slotsKey 已经不存在（4 个随机节点 TTL 过期）=====
    // 检查有没有补签候选 key
    $candidateJson = $rh->get($resignCandidateKey);
    if (!$candidateJson) {
        none();
    }

    $candidate = json_decode($candidateJson, true);
    if (!is_array($candidate)) {
        // 数据异常，删掉候选 key
        $rh->delete($resignCandidateKey);
        none();
    }

    // 读取这个小时的时间范围
    $periodStr = $candidate['period'] ?? $period;
    $hStartStr = $candidate['hour_start'] ?? null;
    $hEndStr   = $candidate['hour_end']   ?? null;

    if (!$hStartStr || !$hEndStr) {
        // 兜底：自己算一次
        $y = substr($periodStr, 0, 4);
        $m = substr($periodStr, 4, 2);
        $d = substr($periodStr, 6, 2);
        $H = substr($periodStr, 8, 2);
        $hStartStr = "$y-$m-$d $H:00:00";
        $hEndStr   = "$y-$m-$d $H:59:59";
    }

    // 查这一小时的总签到数
 
    $sql  = "SELECT COUNT(*) AS c FROM checkin_log WHERE checkin_time BETWEEN ? AND ?";
    $rows = $db->query($sql, [$hStartStr, $hEndStr], false);
    $cnt  = 0;
    if (is_array($rows) && isset($rows[0]['c'])) {
        $cnt = (int)$rows[0]['c'];
    }

    if ($cnt >= 3) {
        // 已经够 3 次了，不需要补签，顺手删掉候选 key
        $rh->delete($resignCandidateKey);
        none();
    }

    // 现在：slots 已过期 & 候选存在 & 总签到数 < 3
    // => 触发“最后一次补签机会”，只触发一轮，所以删掉候选 key
    $rh->delete($resignCandidateKey);

    $ttl = random_int(120,180);
    $payload = [
        'planned_at'  => date('Y-m-d H:i:s', $now), // 显示用
        'is_makeup'   => true,                      // ★ 补签弹窗标记
        'period'      => $periodStr,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if (method_exists($rh, 'setWithExpiration')) {
        $rh->setWithExpiration($pinKey, $json, $ttl);
    } else {
        $rh->set($pinKey, $json);
        if (method_exists($rh, 'expireAt')) {
            $rh->expireAt($pinKey, time() + $ttl);
        }
    }

    ok($payload);

} catch(Throwable $e){
    bad($e->getMessage());
}
