<?php
require_once '../Mydatabases.php';
// require_once "CurlHelper.php";
$db = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $db->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}
$role_id = $user['role_id'];
$venue_id = $role_id != 1 ? $user['venue_id'] : null;
echo json_encode([
    'data'=> [
        'uid' => $user['uid'],
        'username' => $user['username'],
        'venue_id' => $user['venue_id']]
    ]);
// // 1️⃣ 插入用户
// $db->create('tests', [
//     'username' => 'taotao',
//     'email' => 'taotao@example.com'
// ]);

// // 2️⃣ 查询用户（带缓存）
// $user = $db->find('tests', ['id' => 1], 'user:id:1');
// print_r($user);

// // 3️⃣ 更新用户
// $db->update('tests', ['email' => 'newmail@example.com'], ['id' => 1]);

// 4️⃣ 删除用户
// $db->delete('tests', ['id' => 1]);

// 5️⃣ 自定义查询
// $tests = $db->query("SELECT * FROM venues where venue_status  = ?", ['营业中']);
// echo json_encode([
//     'count' => count($tests),
//     'data' => $tests
// ]);

// // 6️⃣ 事务示例
// try {
//     $db->beginTransaction();
//     $db->create('tests', ['username' => 'jack', 'email' => 'jack@example.com']);
//     $db->update('tests', ['status' => 'banned'], ['id' => 1]);
//     $db->commit();
// } catch (Exception $e) {
//     $db->rollback();
//     echo "失败: " . $e->getMessage();
// }

// 7️⃣ 关闭连接
$db->close();
?>