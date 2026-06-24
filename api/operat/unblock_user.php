<?php // api/operat/unblock_user.php
// require_once __DIR__.'/_bootstrap.php';

// $db   = new Database();
// $user = auth_or_die($db);
// if(!can_block($user)) json_err('无权执行取消拉黑操作', 1003);

// $req = input_array();
// $target_uid = isset($req['uid']) ? intval($req['uid']) : 0;
// $venue_id   = intval($user['venue_id'] ?? 0);

// if($target_uid <= 0) json_err('参数错误：uid 必填且为正整数', 1002);
// if($venue_id  <= 0) json_err('当前用户未绑定场地，无法操作', 1004);

// try{
//     $sql = "DELETE FROM venue_user_blacklist WHERE venue_id=? AND uid=? LIMIT 1";
//     $aff = $db->query($sql, [$venue_id, $target_uid], true);
//     $db->logToFile("unblock_user: venue={$venue_id}, uid={$target_uid}, affected={$aff}");

//     if($aff > 0) json_ok(['affected'=>$aff], '已取消拉黑');
//     else         json_err('未找到拉黑记录', 2004);
// }catch(Throwable $e){
//     $db->logToFile('unblock_user error: '.$e->getMessage());
//     json_err('取消拉黑失败，请重试或联系管理员', 2002);
// }
 // api/operat/unblock_user.php
require_once __DIR__.'/_bootstrap.php';

$db   = new Database();
$user = auth_or_die($db);
if(!can_block($user)) json_err('无权执行取消拉黑操作', 1003);

$req       = input_array();
$id        = isset($req['id'])  ? intval($req['id'])  : 0;
$targetUid = isset($req['uid']) ? intval($req['uid']) : 0;

// 1) 优先按 id 删（前端从列表带过来最准确）
if ($id > 0) {
    // 非管理员只能删自己场地的记录
    if (intval($user['role_id'] ?? 0) !== 1) {
        $row = $db->query("SELECT venue_id FROM venue_user_blacklist WHERE id=?", [$id]);
        $rowVenue = intval($row[0]['venue_id'] ?? 0);
        $myVenue  = intval($user['venue_id'] ?? 0);
        if ($rowVenue <= 0 || $rowVenue !== $myVenue) {
            json_err('无权操作该记录', 1003);
        }
    }
    try{
        $aff = $db->query("DELETE FROM venue_user_blacklist WHERE id=? LIMIT 1", [$id], true);
        if ($aff > 0) json_ok(['affected'=>$aff], '已取消拉黑');
        else          json_err('未找到拉黑记录', 2004);
    }catch(Throwable $e){
        $db->logToFile('unblock_user by id error: '.$e->getMessage());
        json_err('取消拉黑失败，请重试或联系管理员', 2002);
    }
    exit;
}

// 2) 兼容旧方式：管理员可指定 venue_id，普通用户强制用自己场地
$roleId   = intval($user['role_id'] ?? 0);
$venue_id = ($roleId === 1)
    ? (int)($req['venue_id'] ?? ($user['venue_id'] ?? 0))  // 管理员：请求里没有时，退回自己绑定的场地
    : (int)($user['venue_id'] ?? 0);

if ($targetUid <= 0)  json_err('参数错误：uid 必填且为正整数', 1002);
if ($venue_id  <= 0)  json_err($user['venue_id'], 1004);

try{
    $aff = $db->query(
        "DELETE FROM venue_user_blacklist WHERE venue_id=? AND uid=? LIMIT 1",
        [$venue_id, $targetUid],
        true
    );
    if($aff > 0) json_ok(['affected'=>$aff], '已取消拉黑');
    else         json_err('未找到拉黑记录', 2004);
}catch(Throwable $e){
    $db->logToFile('unblock_user error: '.$e->getMessage());
    json_err('取消拉黑失败，请重试或联系管理员', 2002);
}
