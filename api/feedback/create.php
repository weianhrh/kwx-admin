<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function json_out($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

$database = new Database();

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) json_out(['code'=>1001,'msg'=>'无有效认证信息，请登录','data'=>[]]);

// 查角色 & 场地
$userRows = $database->query("SELECT uid, role_id, venue_id FROM admin_users WHERE session_token = ?", [$session_token]);
if (!$userRows || empty($userRows)) json_out(['code'=>1001,'msg'=>'用户未登录或不存在','data'=>[]]);

$user = $userRows[0];
$role_id = intval($user['role_id']);
$bind_venue_id = intval($user['venue_id']);

// if ($role_id !== 3) json_out(['code'=>1003,'msg'=>'无权限：仅加盟商可提交反馈','data'=>[]]);

// 读取参数
$message = trim($_POST['message'] ?? '');
if ($message === '') json_out(['code'=>1002,'msg'=>'反馈内容不能为空','data'=>[]]);
if (mb_strlen($message,'UTF-8') > 4000) json_out(['code'=>1002,'msg'=>'反馈内容过长(>4000字)','data'=>[]]);

$sql = "INSERT INTO feedback (venue_id, message) VALUES (?, ?)";
$aff = $database->query($sql, [strval($bind_venue_id), $message], true);
if ($aff === false) json_out(['code'=>500,'msg'=>'提交失败','data'=>[]]);

$feedback_id = $database->getConnection()->insert_id;
$database->logToFile("feedback.create venue={$bind_venue_id}, fid={$feedback_id}");

json_out([
  'code'=>0,
  'msg'=>'提交成功，等待平台回复',
  'data'=>['feedback_id'=>$feedback_id]
]);
