<?php // api/operat/block_user.php
require_once __DIR__.'/_bootstrap.php';
define('OPEN_INTERNAL_KEY', 'open_send_zego_internal_20260529_xxxxxxxx');
$db   = new Database();
$user = auth_or_die($db);
if(!can_block($user)) json_err('无权执行拉黑操作', 1003);

$req = input_array();
$target_uid  = isset($req['uid']) ? intval($req['uid']) : 0;
$reason      = trim($req['reason'] ?? '此用户违反该场地正常运营条例,特此拉黑!');
$venue_id    = intval($user['venue_id'] ?? 0);
$handler_uid = intval($user['uid'] ?? 0);
$remove_voice_room = intval($req['remove_voice_room'] ?? 0);



if ($target_uid <= 0) json_err('参数错误：uid 必填且为正整数', 1002);
if ($reason === '')   $reason = '未填写原因';

// === 新增：一键拉黑所有场地，仅管理员（role_id == 1）可用 ===
$type = isset($_GET['type']) ? trim($_GET['type']) : '';
if ($type === 'all') {
    if (!in_array((int)($user['role_id'] ?? 0), [1, 2], true)) {
        json_err('仅管理员可执行一键拉黑', 1005);
    }
    try {
        $db->beginTransaction();

        // 拉取所有场地 ID（如有状态字段可加 WHERE 过滤）
        $venueRows = $db->query("SELECT id FROM venues", [], false);
        if ($venueRows === false) throw new Exception('读取场地失败');

        // 统一预处理语句
        $sql = "INSERT INTO venue_user_blacklist(uid, handler_uid, venue_id, reason)
                VALUES(?,?,?,?)
                ON DUPLICATE KEY UPDATE handler_uid=VALUES(handler_uid),
                                        reason=VALUES(reason),
                                        created_at=NOW()";

        $affectedTotal = 0;
        foreach ($venueRows as $row) {
            $vid = intval($row['id']);
            // 逐场地插入/覆盖
            $aff = $db->query($sql, [$target_uid, $handler_uid, $vid, $reason], true);
            if ($aff === false) throw new Exception('写入失败: venue_id='.$vid);
            $affectedTotal += intval($aff);
        }

        $db->logToFile("block_user_all: uid={$target_uid}, handler={$handler_uid}, venues=".count($venueRows).", affected={$affectedTotal}");
        $db->commit();

        json_ok(
            ['uid'=>$target_uid, 'venues'=>count($venueRows), 'affected'=>$affectedTotal],
            '已对所有场地拉黑（已存在的将更新原因）'
        );
    } catch (Throwable $e) {
        $db->rollBack();
        $db->logToFile('block_user_all error: '.$e->getMessage());
        json_err('一键拉黑失败，请重试或联系管理员', 2002);
    }
    // 提前 return
    return;
}

// === 原逻辑：仅当前用户所在场地 ===
if ($venue_id <= 0) json_err('当前用户未绑定场地，无法操作', 1004);

function call_open_send_zego_custom_msg($target_uid, $venue_id) {
    $postData = http_build_query([
        'internal_key' => OPEN_INTERNAL_KEY,
        'target_uid'  => $target_uid,
        'venue_id'    => $venue_id,
        'ban_reason'  => '您已被主播移出房间'
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://open.rcwulian.cn/api/operat/send_zego_custom_msg.php',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return [
            'ok' => false,
            'msg' => '调用open ZEGO接口失败：' . $err,
            'raw' => null
        ];
    }

    $json = json_decode($resp, true);

    return [
        'ok' => ($code === 200 && is_array($json) && intval($json['code'] ?? -1) === 0),
        'msg' => is_array($json) ? ($json['msg'] ?? 'open ZEGO接口已返回') : 'open ZEGO接口返回非JSON',
        'raw' => $json ?: $resp
    ];
}


try{
    $db->beginTransaction();

    $sql = "INSERT INTO venue_user_blacklist(uid, handler_uid, venue_id, reason)
            VALUES(?,?,?,?)
            ON DUPLICATE KEY UPDATE handler_uid=VALUES(handler_uid),
                                    reason=VALUES(reason),
                                    created_at=NOW()";
    $aff = $db->query($sql, [$target_uid, $handler_uid, $venue_id, $reason], true);
    $id  = $db->getConnection()->insert_id;

    $db->logToFile("block_user: venue={$venue_id}, uid={$target_uid}, handler={$handler_uid}, reason={$reason}, affected={$aff}");
$db->commit();

$extra = [
    'insert_id' => $id,
    'affected' => $aff
];

$msg = $aff === 2 ? '已更新拉黑原因' : '已拉黑';

if ($remove_voice_room === 1) {
    $zegoResult = call_open_send_zego_custom_msg(
        $target_uid,
        $venue_id
    );

    $extra['remove_voice_room'] = true;
    $extra['zego_remove_success'] = $zegoResult['ok'];
    $extra['zego_remove_msg'] = $zegoResult['msg'];
    $extra['zego_remove_raw'] = $zegoResult['raw'];

    $msg .= $zegoResult['ok']
        ? '，已发送移除语音房指令'
        : '，但移除语音房指令发送失败或者用户不在房间';
}

json_ok($extra, $msg);
}catch(Throwable $e){
    $db->rollBack();
    $db->logToFile('block_user error: '.$e->getMessage());
    json_err('拉黑失败，请重试或联系管理员', 2001);
}
