<?php // api/operat/is_blacklisted.php
require_once __DIR__.'/_bootstrap.php';

$db   = new Database();
$user = auth_or_die($db);

$req = array_merge($_GET ?? [], input_array());
$target_uid = isset($req['uid']) ? intval($req['uid']) : 0;
$venue_id   = intval($user['venue_id'] ?? 0);
if($target_uid <= 0) json_err('参数错误：uid 必填且为正整数', 1002);
if($venue_id  <= 0) json_err('当前用户未绑定场地，无法操作', 1004);

$sql = "SELECT id, handler_uid, reason, created_at 
        FROM venue_user_blacklist 
        WHERE venue_id=? AND uid=? LIMIT 1";
$row = $db->query($sql, [$venue_id, $target_uid]);
if($row && count($row)){
    json_ok(['blacklisted'=>true,'record'=>$row[0]]);
}else{
    json_ok(['blacklisted'=>false]);
}
