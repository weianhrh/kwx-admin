<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function json_out($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

$database = new Database();

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) json_out(['code'=>1001,'msg'=>'无有效认证信息，请登录','data'=>[]]);

$userRows = $database->query("SELECT uid, role_id FROM admin_users WHERE session_token = ?", [$session_token]);
if (!$userRows || empty($userRows)) json_out(['code'=>1001,'msg'=>'用户未登录或不存在','data'=>[]]);

$user = $userRows[0];
$role_id = intval($user['role_id']);
$uid = intval($user['uid']);

if (!in_array($role_id, [1, 2], true)) json_out(['code'=>1003,'msg'=>'无权限：仅管理员可回复','data'=>[]]);

$feedback_id = intval($_POST['feedback_id'] ?? 0);
$reply_content = trim($_POST['reply_content'] ?? '');

if ($feedback_id <= 0) json_out(['code'=>1002,'msg'=>'参数错误：feedback_id','data'=>[]]);
if ($reply_content === '') json_out(['code'=>1002,'msg'=>'回复内容不能为空','data'=>[]]);
if (mb_strlen($reply_content,'UTF-8') > 4000) json_out(['code'=>1002,'msg'=>'回复内容过长(>4000字)','data'=>[]]);

// 验证反馈是否存在
$exist = $database->query("SELECT id FROM feedback WHERE id = ?", [strval($feedback_id)]);
if (!$exist || empty($exist)) json_out(['code'=>1004,'msg'=>'反馈不存在','data'=>[]]);

try {
    $database->beginTransaction();

    // 1) upsert 最新回复
    $sqlReply = "
      INSERT INTO feedback_reply (feedback_id, reply_content, replied_by_uid)
      VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE
        reply_content = VALUES(reply_content),
        replied_by_uid = VALUES(replied_by_uid),
        replied_at = CURRENT_TIMESTAMP
    ";
    $aff1 = $database->query($sqlReply, [strval($feedback_id), $reply_content, strval($uid)], true);

    // 2) 将反馈状态置为已回复
    $sqlStatus = "UPDATE feedback SET status = '1' WHERE id = ?";
    $aff2 = $database->query($sqlStatus, [strval($feedback_id)], true);

    $database->commit();

    $database->logToFile("feedback.reply fid={$feedback_id}, by_uid={$uid}");
    json_out(['code'=>0,'msg'=>'回复成功','data'=>['feedback_id'=>$feedback_id]]);
} catch (Throwable $e) {
    $database->rollBack();
    $database->logToFile("feedback.reply.error fid={$feedback_id}, err=".$e->getMessage());
    json_out(['code'=>500,'msg'=>'服务器错误','data'=>[]]);
}
