<?php
// /app/user/checkin_status.php
require_once '../Database.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

function out($code, $msg, $data = null) {
    $resp = ["code" => $code, "msg" => $msg];
    if ($data !== null) $resp["data"] = $data;
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();

$uid = trim((string)($_GET['uid'] ?? ''));
if ($uid === '' || !ctype_digit($uid) || intval($uid) <= 0) {
    out(1, "uid 参数错误");
}
$uid = (int)$uid;

date_default_timezone_set('Asia/Shanghai');
$today = date('Y-m-d');

// 7天奖励
$rewards = [100, 100, 150, 150, 200, 200, 300];

try {
    // 1) 判断今天是否已签到
    $rowsToday = $database->query(
        "SELECT id, streak, reward_energy FROM user_checkin WHERE uid = ? AND checkin_date = ? LIMIT 1",
        [$uid, $today]
    );
    $todaySigned = (!empty($rowsToday));

    // 2) 取最近一次签到记录（用于计算当前连续天数）
    $lastRows = $database->query(
        "SELECT checkin_date, streak FROM user_checkin WHERE uid = ? ORDER BY checkin_date DESC LIMIT 1",
        [$uid]
    );

    $signedDays = 0;
    if (!empty($lastRows)) {
        $signedDays = (int)$lastRows[0]['streak'];
        if ($signedDays < 0) $signedDays = 0;
        if ($signedDays > 7) $signedDays = 7; // 这里只是展示用，规则你可调整
    }

    // 3) 今天若未签，计算“下一天奖励”
    $nextDayIndex = min(7, max(1, $signedDays + 1)); // 1~7
    $todayReward = $rewards[$nextDayIndex - 1];

    out(0, "ok", [
        "uid" => $uid,
        "signed_days" => $signedDays,               // 当前连续天数(1~7)，没签过就是0
        "today_signed" => $todaySigned ? 1 : 0,     // 1已签 0未签
        "today_reward" => $todaySigned ? 0 : $todayReward, // 今日可领能量(未签时)
    ]);

} catch (Exception $e) {
    out(2, "服务器错误: " . $e->getMessage());
}
