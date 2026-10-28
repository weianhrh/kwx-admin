<?php
// APP用户密码重置；不修改后台管理员密码。
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function resetPasswordOut($code, $msg, $data = null) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    require_once __DIR__ . '/../Database.php';
    $database = new Database();
    $token = $_COOKIE['session_token'] ?? '';
    $operator = $token ? $database->getUserBySessionToken($token) : null;
    if (!$operator) resetPasswordOut(1001, '未登录或会话已过期');
    if (!in_array((int)($operator['role_id'] ?? 0), [1, 2], true)) {
        resetPasswordOut(1002, '无权限恢复用户密码');
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method === 'GET') resetPasswordOut(0, 'ok', ['can_reset' => true]);
    if ($method !== 'POST') resetPasswordOut(1003, '仅支持POST操作');
    // 仅接受同源页面的JSON请求，不接受跨站表单提交。
    if (strtolower($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site' ||
        strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        resetPasswordOut(1003, '请求格式错误');
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $uid = filter_var($input['uid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$uid || ($input['confirmed'] ?? false) !== true) resetPasswordOut(1003, '缺少有效UID或确认信息');
    $rows = $database->query('SELECT uid, unionid FROM users WHERE uid = ? LIMIT 1', [$uid]);
    if ($rows === false) resetPasswordOut(1005, '查询失败，请稍后重试');
    if (!$rows) resetPasswordOut(1004, '用户不存在');
    if ($rows[0]['unionid'] !== null) resetPasswordOut(1006, '仅unionid为NULL的用户可恢复默认密码，该用户不符合条件');
    // 与现有APP手机号密码登录的MD5格式保持一致。
    $result = $database->query('UPDATE users SET password = ? WHERE uid = ? AND unionid IS NULL', [md5('123456'), $uid], true);
    // affected_rows=0也可能是密码本来就是默认值。
    if ($result === false) resetPasswordOut(1005, '恢复失败，请稍后重试');
    if ($result === 0) {
        $check = $database->query('SELECT uid FROM users WHERE uid = ? AND unionid IS NULL AND password = ? LIMIT 1', [$uid, md5('123456')]);
        if ($check === false) resetPasswordOut(1005, '未能确认重置结果，请稍后核实');
        if (!$check) resetPasswordOut(1006, '用户状态已变化或unionid不为空，未恢复默认密码');
    }
    resetPasswordOut(0, '已将用户 UID ' . $uid . ' 的密码恢复为123456，请提醒用户及时修改');
} catch (Throwable $e) {
    error_log('resetUserPassword: ' . $e->getMessage());
    resetPasswordOut(1005, '恢复失败，请稍后重试');
}
