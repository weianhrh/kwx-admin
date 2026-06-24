<?php
// api/checkin/click_redis.php
require_once '../Database.php';
require_once '../RedisHelper.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Taipei');

function ok($msg='ok', $extraData = []) {
    echo json_encode(['code'=>200,'msg'=>$msg,'data'=>$extraData], JSON_UNESCAPED_UNICODE);
    exit;
}
function bad($msg){
    echo json_encode(['code'=>500,'msg'=>$msg,'data'=>[]], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = new Database();
    $user = $db->getUserBySessionToken($_COOKIE['session_token'] ?? '');
    if (!$user) bad('未登录或会话失效');
    $uid = (int)$user['uid'];

    $isResign = isset($_POST['resign']) && $_POST['resign'] == '1';

    $rh = new RedisHelper();
    $rh->connect('127.0.0.1', 6379, 2);
    $rh->selectDb(10);

    // ========== 分支 1：补签 ==========
    if ($isResign) {
        // 补签是针对某一小时的 period：尽量用前端传来的
        $period = $_POST['period'] ?? date('YmdH');

        // 每个用户每个小时只能补签一次
        $resignFlagKey = "checkin:resign_used:$period:$uid";
        if ($rh->get($resignFlagKey)) {
            bad('本时间段你已经补签过了，无需重复补签');
        }

        // 插入一条补签记录，以当前时间为签到时间
        $db->query(
            "INSERT INTO checkin_log (uid, checkin_time) VALUES (?, NOW())",
            [(string)$uid],
            true
        );

        // 标记本小时该用户已补签，TTL 到本小时结束
        $y = substr($period, 0, 4);
        $m = substr($period, 4, 2);
        $d = substr($period, 6, 2);
        $H = substr($period, 8, 2);
        $hourStartTs = strtotime("$y-$m-$d $H:00:00");
        if ($hourStartTs === false) {
            $hourStartTs = strtotime(date('Y-m-d H:00:00'));
        }
        $hourEndTs   = $hourStartTs + 59*60 + 59;
        $ttl         = max(60, $hourEndTs - time() + 5);

        $flagValue = json_encode([
            'uid'    => $uid,
            'period' => $period,
            'at'     => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);

        if (method_exists($rh, 'setWithExpiration')) {
            $rh->setWithExpiration($resignFlagKey, $flagValue, $ttl);
        } else {
            $rh->set($resignFlagKey, $flagValue);
            if (method_exists($rh, 'expireAt')) {
                $rh->expireAt($resignFlagKey, time() + $ttl);
            }
        }

        // 删除这个小时的补签 pin，避免重复弹窗
        $pinKey = "checkin:pin:$period";
        $rh->delete($pinKey);

        ok('补签成功');
    }

    // ========== 分支 2：正常签到（来自随机 slot 弹窗） ==========
    $planned_at = $_POST['planned_at'] ?? '';
    if (!$planned_at) bad('缺少 planned_at');

    $pTs    = strtotime($planned_at);
    if ($pTs === false) bad('planned_at 格式不正确');
    $period = date('YmdH', $pTs);

    $pinKey   = "checkin:pin:$period";
    $slotsKey = "checkin:slots:$period";

    // 1) 确认 planned_at 在 slots 列表里（保证只处理一次）
    $slotsJson = $rh->get($slotsKey);
    if (!$slotsJson) {
        bad('本次签到已有人处理或已过期');
    }
    $slots = json_decode($slotsJson, true);
    if (!is_array($slots) || !in_array($planned_at, $slots, true)) {
        bad('本次签到已有人处理或已过期');
    }

    // 2) 删 pin
    $rh->delete($pinKey);

    // 3) 从 slots 里删掉本次的时间点（保留 TTL）
    $newSlots = array_values(array_filter($slots, function($t) use ($planned_at) {
        return $t !== $planned_at;
    }));

    if (count($newSlots) === 0) {
        $rh->delete($slotsKey);
    } else {
        $ttlRemain = method_exists($rh, 'ttl') ? $rh->ttl($slotsKey) : -1;
        if ($ttlRemain <= 0) {
            $periodEnd = strtotime(date('Y-m-d H:57:59', $pTs));
            $ttlRemain = max(1, $periodEnd - time() + 5);
        }
        $payload = json_encode($newSlots, JSON_UNESCAPED_UNICODE);
        if (method_exists($rh, 'setWithExpiration')) {
            $rh->setWithExpiration($slotsKey, $payload, $ttlRemain);
        } else {
            if (method_exists($rh, 'set')) $rh->set($slotsKey, $payload);
            if (method_exists($rh, 'expireAt')) $rh->expireAt($slotsKey, time() + $ttlRemain);
        }
    }

    // 4) 记正常签到日志
    $db->query(
        "INSERT INTO checkin_log (uid, checkin_time) VALUES (?, NOW())",
        [(string)$uid],
        true
    );

    ok('签到成功');

} catch(Throwable $e){
    bad($e->getMessage());
}
