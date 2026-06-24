<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../Database.php';

try {
    // 仅 POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed']); exit;
    }

    // —— 可选：同源校验（按你的前端域名修改/或删掉）——
    $origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $allowHost = 'open.rcwulian.cn';
    $okOrigin = false;
    foreach ([$origin, $referer] as $src) {
        if ($src && parse_url($src, PHP_URL_HOST) === $allowHost) { $okOrigin = true; break; }
    }
    if (!$okOrigin) {
        echo json_encode(['success'=>false,'message'=>'非法来源']); exit;
    }

    $db = new Database();

    // ===== 1) 通过 HttpOnly Cookie 识别操作者（不需要 Authorization 头）=====
    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        echo json_encode(['success'=>false, 'message'=>'未登录']); exit;
    }

    // 你示例里是 admin_users 表；注意 query 返回的是「数组」，要取第 0 条
    $rows = $db->query("SELECT uid, role_id FROM admin_users WHERE session_token = ?", [$session_token]);
    if (!$rows || !isset($rows[0])) {
        echo json_encode(['success'=>false, 'message'=>'非法用户']); exit;
    }
    $admin = $rows[0];
    $role_id = intval($admin['role_id'] ?? 0);

    // 只有平台管理员允许改他人昵称（按你权限规则改）
    if (!in_array((int)$role_id, [1, 2], true)) {
        echo json_encode(['success'=>false, 'message'=>'无权操作']); exit;
    }

    // ===== 2) 读参数 =====
    $targetUid    = intval($_POST['uid'] ?? 0);
    $newNickname  = isset($_POST['nickname']) ? trim((string)$_POST['nickname']) : '';

    if ($targetUid <= 0) {
        echo json_encode(['success'=>false, 'message'=>'缺少或非法的 uid']); exit;
    }
    if ($newNickname === '') {
        echo json_encode(['success'=>false, 'message'=>'昵称不能为空']); exit;
    }
    if (mb_strlen($newNickname, 'UTF-8') > 25) {
        echo json_encode(['success'=>false, 'message'=>'昵称长度不能超过25个字符']); exit;
    }

    // 去除控制字符 / 可选去 Emoji
    $newNickname = preg_replace('/[\x00-\x1F\x7F]/u', '', $newNickname);
    // $newNickname = preg_replace('/[\x{1F000}-\x{1FFFF}]/u', '', $newNickname);
// RC物联官方-车辆管理部
    // ===== 3) 敏感词 =====
    $badWords = [
        '管理员','系统','客服','版主','站长','运营','审查','审核','投诉',
        '傻','蠢','煞笔','傻逼','傻子','妈的','草','操','操你','狗','狗比','死',
        '垃圾','畜生','王八','贱','变态','淫','逼','干你','fuck','shit','nmsl','sb','cnm',
        '滚','脑残','残废','废物','白痴','智障','智障儿','低能','nt','nm','nmms','nmd',
        '去死','你妈','你娘','孙子','爹','爷','我操','艹','丢你','舔狗','收钱','中奖',
        '微信','qq','vx','vx号','微信号','qq号','VX','QQ','VX号','Q群','null','NULL'
    ];
    foreach ($badWords as $w) {
        if (stripos($newNickname, $w) !== false) {
            echo json_encode(['success'=>false, 'message'=>'昵称包含敏感词，请更换']); exit;
        }
    }

    // ===== 4) 重名检查 =====
    $dup = $db->query("SELECT COUNT(*) AS c FROM users WHERE nickname = ?", [$newNickname]);
    $count = (isset($dup[0]['c']) ? intval($dup[0]['c']) : 0);
    if ($count > 0) {
        echo json_encode(['success'=>false, 'message'=>'该昵称已被使用，请选择其他昵称']); exit;
    }

    // ===== 5) 执行更新 =====
    $aff = $db->query("UPDATE users SET nickname = ? WHERE uid = ?", [$newNickname, $targetUid], true);
    if ($aff > 0) {
        echo json_encode(['success'=>true, 'message'=>'昵称更新成功']);
    } else {
        echo json_encode(['success'=>false, 'message'=>'用户不存在或昵称未更改']);
    }
} catch (Throwable $e) {
    echo json_encode(['success'=>false, 'message'=>'更新失败: '.$e->getMessage()]);
}
