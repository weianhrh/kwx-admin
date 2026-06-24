<?php
// /app/user/checkin.php
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

$uid = trim((string)($_POST['uid'] ?? $_GET['uid'] ?? ''));
if ($uid === '' || !ctype_digit($uid) || intval($uid) <= 0) {
    out(1, "uid 参数错误");
}
$uid = (int)$uid;

date_default_timezone_set('Asia/Shanghai');
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

// 7天奖励（按你的图）
$rewards = [100, 100, 150, 150, 200, 200, 300];

$conn = $database->getConnection();

try {
    $database->beginTransaction();

    // 1) 锁住用户行，避免并发加能量错乱
    $stmtUser = $conn->prepare("SELECT uid, energy FROM users WHERE uid = ? LIMIT 1 FOR UPDATE");
    if (!$stmtUser) throw new Exception("prepare users failed: " . $conn->error);
    $stmtUser->bind_param("i", $uid);
    $stmtUser->execute();
    $resUser = $stmtUser->get_result();
    $userRow = $resUser->fetch_assoc();
    $stmtUser->close();

    if (!$userRow) {
        $database->rollBack();
        out(3, "用户不存在");
    }

    // 2) 今天是否已签到（用签到表唯一键也能挡，但这里先查更友好）
    $stmtToday = $conn->prepare("SELECT id FROM user_checkin WHERE uid = ? AND checkin_date = ? LIMIT 1 FOR UPDATE");
    if (!$stmtToday) throw new Exception("prepare checkin today failed: " . $conn->error);
    $stmtToday->bind_param("is", $uid, $today);
    $stmtToday->execute();
    $resToday = $stmtToday->get_result();
    $todayRow = $resToday->fetch_assoc();
    $stmtToday->close();

    if ($todayRow) {
        $database->rollBack();
        out(4, "今天已签到", [
            "uid" => $uid,
            "today_signed" => 1
        ]);
    }

    // 3) 取最近一次签到记录（锁住那行，计算是否连续）
    $stmtLast = $conn->prepare("SELECT checkin_date, streak FROM user_checkin WHERE uid = ? ORDER BY checkin_date DESC LIMIT 1 FOR UPDATE");
    if (!$stmtLast) throw new Exception("prepare last checkin failed: " . $conn->error);
    $stmtLast->bind_param("i", $uid);
    $stmtLast->execute();
    $resLast = $stmtLast->get_result();
    $lastRow = $resLast->fetch_assoc();
    $stmtLast->close();

    $newStreak = 1;
    if ($lastRow) {
        $lastDate = (string)$lastRow['checkin_date'];
        $lastStreak = (int)$lastRow['streak'];

        if ($lastDate === $yesterday) {
            $newStreak = $lastStreak + 1;
        } else {
            $newStreak = 1;
        }
    }

    // 规则：封顶到 7（你也可以改成循环：>7 变 1）
    if ($newStreak > 7) $newStreak = 7;

    // 4) 计算本次奖励
    $reward = $rewards[$newStreak - 1];

    // 5) 写入签到记录（唯一键防止极端并发）
    $stmtIns = $conn->prepare("INSERT INTO user_checkin (uid, checkin_date, streak, reward_energy) VALUES (?, ?, ?, ?)");
    if (!$stmtIns) throw new Exception("prepare insert checkin failed: " . $conn->error);
    $stmtIns->bind_param("isii", $uid, $today, $newStreak, $reward);
    $okIns = $stmtIns->execute();
    $insErr = $stmtIns->error;
    $stmtIns->close();

    if (!$okIns) {
        // 如果是重复插入（并发），直接当做已签到
        if (strpos($insErr, 'Duplicate') !== false) {
            $database->rollBack();
            out(4, "今天已签到", [
                "uid" => $uid,
                "today_signed" => 1
            ]);
        }
        throw new Exception("insert checkin failed: " . $insErr);
    }

    // 6) 给用户加能量（energy 是 decimal(10,2)，这里按整数加）
    $stmtUp = $conn->prepare("UPDATE users SET energy = energy + ? WHERE uid = ? LIMIT 1");
    if (!$stmtUp) throw new Exception("prepare update energy failed: " . $conn->error);
    $stmtUp->bind_param("ii", $reward, $uid);
    $okUp = $stmtUp->execute();
    $stmtUp->close();
    if (!$okUp) throw new Exception("update energy failed");

    // 7) 读一下最新能量余额返回
    $stmtNow = $conn->prepare("SELECT energy FROM users WHERE uid = ? LIMIT 1");
    if (!$stmtNow) throw new Exception("prepare select energy failed: " . $conn->error);
    $stmtNow->bind_param("i", $uid);
    $stmtNow->execute();
    $resNow = $stmtNow->get_result();
    $nowRow = $resNow->fetch_assoc();
    $stmtNow->close();

    $database->commit();

    out(0, "ok", [
        "uid" => $uid,
        "today_signed" => 1,
        "signed_days" => $newStreak,
        "reward" => $reward,
        "energy_balance" => $nowRow ? $nowRow["energy"] : null
    ]);

} catch (Exception $e) {
    try { $database->rollBack(); } catch (Exception $ignore) {}
    out(2, "服务器错误: " . $e->getMessage());
}
