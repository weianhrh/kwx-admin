<?php
require_once 'Database.php';

$db = new Database();

// 获取请求参数
$uid = isset($_GET['uid']) ? intval($_GET['uid']) : 0;
$type = isset($_GET['type']) ? intval($_GET['type']) : 0; // 0=normal, 1=big, 2=super

if (!$uid || $type < 0 || $type > 2) {
    echo json_encode(['code' => 400, 'msg' => 'UID或锤子类型不正确']);
    exit;
}

// 1. 只重置普通锤次数
function checkAndResetHammer($uid, $db) {
    $today = date('Y-m-d');
    $user = $db->query("SELECT hammer_last_update FROM users WHERE uid = ?", [$uid]);
    if (!$user) return;

    if ($user[0]['hammer_last_update'] !== $today) {
        $db->query("UPDATE users SET hammer_normal_count = 3, hammer_last_update = ? WHERE uid = ?", [$today, $uid], true);
    }
}

checkAndResetHammer($uid, $db);

// 2. 检测次数是否允许使用
$hammerFields = ['hammer_normal_count', 'hammer_big_count', 'hammer_super_count'];
$hammerNames = ['普通锤', '强力锤', '超级锤'];
$field = $hammerFields[$type];
$name = $hammerNames[$type];

$user = $db->query("SELECT $field FROM users WHERE uid = ?", [$uid]);

if (!$user || $user[0][$field] <= 0) {
    $msg = $type === 0 ? '今日' . $name . '已用完' : '您暂无' . $name . '使用次数，请先购买';
    echo json_encode(['code' => 403, 'msg' => $msg]);
    exit;
}

// 3. 减少次数
$db->query("UPDATE users SET $field = $field - 1 WHERE uid = ?", [$uid], true);

// 4. 随机抽奖
$prizes = ['一等奖', '二等奖', '谢谢参与', '乐送奖', '三等奖'];
$prize = $prizes[random_int(0, count($prizes)-1)];

// 5. 记录订单
$db->query("INSERT INTO egg_smash_orders (uid, hammer_type, result, created_at) VALUES (?, ?, ?, NOW())", [$uid, $type, $prize], true);

// 6. 返回结果
echo json_encode([
    'code' => 200,
    'msg' => '成功砍蛋',
    'data' => [
        'result' => $prize,
        'hammer_left' => $user[0][$field] - 1
    ]
]);
