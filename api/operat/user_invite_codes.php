<?php
// /www/wwwroot/open.rcwulian.cn/api/operat/user_invite_codes.php

require_once '../Database.php';

date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

$database = new Database();

function jsonOut($code, $msg, $data = [])
{
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function paramText($key, $default = '')
{
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    if (is_array($value)) {
        return $default;
    }
    return trim((string)$value);
}

function paramInt($key, $default = 0)
{
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    if (is_array($value)) {
        return $default;
    }
    return (int)$value;
}

function getUidParam()
{
    $uid = paramInt('user_uid', 0);
    if ($uid <= 0) {
        $uid = paramInt('uid', 0);
    }
    return $uid;
}

function limitText($text, $max = 255)
{
    $text = trim((string)$text);

    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) {
        return mb_substr($text, 0, $max, 'UTF-8');
    }

    if (!function_exists('mb_strlen') && strlen($text) > $max) {
        return substr($text, 0, $max);
    }

    return $text;
}

function normalizeInviteCode($code)
{
    $code = strtoupper(trim((string)$code));
    $code = preg_replace('/\s+/', '', $code);
    return $code;
}

function getUserByUid($database, $uid)
{
    if ($uid <= 0) {
        return null;
    }

    $rows = $database->query(
        "SELECT uid, nickname, headimgurl FROM users WHERE uid = ? LIMIT 1",
        [$uid]
    );

    return ($rows && isset($rows[0])) ? $rows[0] : null;
}

function getInviteByUid($database, $uid)
{
    $rows = $database->query(
        "SELECT 
            c.id,
            c.uid,
            u.nickname,
            c.invite_code,
            c.status,
            c.promotion_level,
            c.source_channel,
            c.created_at,
            c.updated_at,
            c.disabled_at,
            c.remark
         FROM user_invite_codes c
         LEFT JOIN users u ON c.uid = u.uid
         WHERE c.uid = ?
         LIMIT 1",
        [$uid]
    );

    return ($rows && isset($rows[0])) ? $rows[0] : null;
}

function getInviteByCode($database, $inviteCode)
{
    $rows = $database->query(
        "SELECT 
            id,
            uid,
            invite_code,
            status,
            promotion_level,
            source_channel,
            created_at,
            updated_at,
            disabled_at,
            remark
         FROM user_invite_codes
         WHERE invite_code = ?
         LIMIT 1",
        [$inviteCode]
    );

    return ($rows && isset($rows[0])) ? $rows[0] : null;
}

function generateInviteCode($database, $length = 6)
{
    // 去掉容易混淆的 0/O/1/I
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $maxIndex = strlen($chars) - 1;

    for ($try = 0; $try < 50; $try++) {
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $index = function_exists('random_int') ? random_int(0, $maxIndex) : mt_rand(0, $maxIndex);
            $code .= $chars[$index];
        }

        if (!getInviteByCode($database, $code)) {
            return $code;
        }
    }

    throw new Exception('邀请码生成失败，请重试');
}

// ===== 登录校验 =====
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    jsonOut(1001, '用户未登录或会话已过期', []);
}

$currentUser = $database->getUserBySessionToken($session_token);
if (!$currentUser || empty($currentUser['role_id'])) {
    jsonOut(1001, '用户未登录或无权访问', []);
}

$role_id = (int)$currentUser['role_id'];

// 只允许管理员操作
if (!in_array($role_id, [1, 2], true)) {
    jsonOut(1002, '无权限，仅管理员可操作', []);
}

$action = paramText('action', 'list');

try {

    // ===== 邀请码列表 =====
    if ($action === 'list') {
        $uid = getUidParam();
        $status = paramText('status', '');

        $page = paramInt('page', 1);
        if ($page <= 0) {
            $page = 1;
        }

        $pageSize = paramInt('page_size', 20);
        if ($pageSize <= 0) {
            $pageSize = 20;
        }
        if ($pageSize > 100) {
            $pageSize = 100;
        }

        $offset = ($page - 1) * $pageSize;

        $where = [];
        $params = [];

        if ($uid > 0) {
            $where[] = "c.uid = ?";
            $params[] = $uid;
        }

        if ($status !== '' && in_array($status, ['active', 'disabled'], true)) {
            $where[] = "c.status = ?";
            $params[] = $status;
        }

        $whereSql = '';
        if (!empty($where)) {
            $whereSql = ' WHERE ' . implode(' AND ', $where);
        }

        $fromSql = " FROM user_invite_codes c
                     LEFT JOIN users u ON c.uid = u.uid ";

        $countRows = $database->query(
            "SELECT COUNT(*) AS total " . $fromSql . $whereSql,
            $params
        );

        $total = (int)($countRows[0]['total'] ?? 0);

        $listSql = "SELECT
                        c.id,
                        c.uid,
                        u.nickname,
                        c.invite_code,
                        c.status,
                        c.promotion_level,
                        c.source_channel,
                        c.created_at,
                        c.updated_at,
                        c.disabled_at,
                        c.remark
                    " . $fromSql . $whereSql . "
                    ORDER BY c.id DESC
                    LIMIT {$offset}, {$pageSize}";

        $rows = $database->query($listSql, $params);

        jsonOut(0, 'success', [
            'list'      => $rows ?: [],
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize
        ]);
    }

    // ===== 等级奖励规则说明 =====
    if ($action === 'reward_rules') {
        $rows = $database->query(
            "SELECT
                id,
                rule_name,
                level_code,
                reward_rate,
                min_order_amount,
                max_reward_per_order,
                max_reward_per_invitee,
                settle_days,
                status,
                start_time,
                end_time,
                remark
             FROM invite_reward_rules
             ORDER BY id ASC"
        );

        jsonOut(0, 'success', [
            'list' => $rows ?: []
        ]);
    }
// ===== 新增绑定邀请关系 =====
if ($action === 'create_relation') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonOut(1008, '请使用 POST 请求', []);
    }

    $inviterUid = paramInt('inviter_uid', 0);
    $inviteeUid = paramInt('invitee_uid', 0);
    $inviteCode = normalizeInviteCode(paramText('invite_code', ''));

    $sourceChannel = paramText('relation_source_channel', '后端');
    if ($sourceChannel === '') {
        $sourceChannel = '后端';
    }

    $remark = limitText(paramText('relation_remark', ''), 255);

    if ($inviterUid <= 0) {
        jsonOut(1101, '请输入正确的邀请人UID', []);
    }

    if ($inviteeUid <= 0) {
        jsonOut(1102, '请输入正确的被邀请人UID', []);
    }

    if ($inviterUid === $inviteeUid) {
        jsonOut(1103, '邀请人和被邀请人不能是同一个用户', []);
    }

    // 判断邀请人是否存在 users 表
    $inviter = getUserByUid($database, $inviterUid);
    if (!$inviter) {
        jsonOut(1104, 'users 表中不存在该邀请人UID', [
            'inviter_uid' => $inviterUid
        ]);
    }

    // 判断被邀请人是否存在 users 表
    $invitee = getUserByUid($database, $inviteeUid);
    if (!$invitee) {
        jsonOut(1105, 'users 表中不存在该被邀请人UID', [
            'invitee_uid' => $inviteeUid
        ]);
    }

    // 被邀请人只能绑定一个邀请人
    $existsRelation = $database->query(
        "SELECT
            r.id,
            r.inviter_uid,
            inviter.nickname AS inviter_nickname,
            r.invitee_uid,
            invitee.nickname AS invitee_nickname,
            r.invite_code,
            r.status,
            r.bind_time
         FROM invite_relations r
         LEFT JOIN users inviter ON r.inviter_uid = inviter.uid
         LEFT JOIN users invitee ON r.invitee_uid = invitee.uid
         WHERE r.invitee_uid = ?
         LIMIT 1",
        [$inviteeUid]
    );

    if ($existsRelation && isset($existsRelation[0])) {
        jsonOut(1106, '该被邀请人已经绑定过邀请关系，不能重复绑定', [
            'relation' => $existsRelation[0]
        ]);
    }

    // 如果没填邀请码，则自动取邀请人的 active 邀请码
    if ($inviteCode === '') {
        $codeRows = $database->query(
            "SELECT id, uid, invite_code, status, promotion_level
             FROM user_invite_codes
             WHERE uid = ?
               AND status = 'active'
             ORDER BY id DESC
             LIMIT 1",
            [$inviterUid]
        );

        if (!$codeRows || !isset($codeRows[0])) {
            jsonOut(1107, '该邀请人没有启用中的邀请码，请先生成邀请码', [
                'inviter_uid' => $inviterUid
            ]);
        }

        $inviteCode = $codeRows[0]['invite_code'];
    } else {
        // 如果手动填了邀请码，必须存在、启用，并且属于这个邀请人
        $codeOwner = getInviteByCode($database, $inviteCode);

        if (!$codeOwner) {
            jsonOut(1108, '该邀请码不存在', [
                'invite_code' => $inviteCode
            ]);
        }

        if ((int)$codeOwner['uid'] !== $inviterUid) {
            jsonOut(1109, '该邀请码不属于当前填写的邀请人UID', [
                'inviter_uid' => $inviterUid,
                'invite_code' => $inviteCode,
                'code_owner_uid' => $codeOwner['uid']
            ]);
        }

        if ($codeOwner['status'] !== 'active') {
            jsonOut(1110, '该邀请码不是启用状态，不能绑定', [
                'invite_code' => $inviteCode,
                'status' => $codeOwner['status']
            ]);
        }
    }

    $database->query(
        "INSERT INTO invite_relations
            (inviter_uid, invitee_uid, invite_code, status, source_channel, bind_time, invalid_at, created_at, updated_at, remark)
         VALUES
            (?, ?, ?, 'active', ?, NOW(), NULL, NOW(), NOW(), ?)",
        [$inviterUid, $inviteeUid, $inviteCode, $sourceChannel, $remark]
    );

    jsonOut(0, '绑定邀请关系成功', [
        'relation' => [
            'inviter_uid' => $inviterUid,
            'inviter_nickname' => $inviter['nickname'] ?? '',
            'invitee_uid' => $inviteeUid,
            'invitee_nickname' => $invitee['nickname'] ?? '',
            'invite_code' => $inviteCode,
            'status' => 'active',
            'source_channel' => $sourceChannel
        ]
    ]);
}
    // ===== 邀请对应关系列表 =====
    if ($action === 'relation_list') {
        $inviterUid = paramInt('inviter_uid', 0);
        $inviteeUid = paramInt('invitee_uid', 0);
        $inviteCode = normalizeInviteCode(paramText('invite_code', ''));
        $relationStatus = paramText('relation_status', '');

        $page = paramInt('page', 1);
        if ($page <= 0) {
            $page = 1;
        }

        $pageSize = paramInt('page_size', 20);
        if ($pageSize <= 0) {
            $pageSize = 20;
        }
        if ($pageSize > 100) {
            $pageSize = 100;
        }

        $offset = ($page - 1) * $pageSize;

        $where = [];
        $params = [];

        if ($inviterUid > 0) {
            $where[] = "r.inviter_uid = ?";
            $params[] = $inviterUid;
        }

        if ($inviteeUid > 0) {
            $where[] = "r.invitee_uid = ?";
            $params[] = $inviteeUid;
        }

        if ($inviteCode !== '') {
            $where[] = "r.invite_code = ?";
            $params[] = $inviteCode;
        }

        if ($relationStatus !== '' && in_array($relationStatus, ['active', 'invalid', 'disabled', 'risk_pending'], true)) {
            $where[] = "r.status = ?";
            $params[] = $relationStatus;
        }

        $whereSql = '';
        if (!empty($where)) {
            $whereSql = ' WHERE ' . implode(' AND ', $where);
        }

        $fromSql = " FROM invite_relations r
                     LEFT JOIN users inviter ON r.inviter_uid = inviter.uid
                     LEFT JOIN users invitee ON r.invitee_uid = invitee.uid ";

        $countRows = $database->query(
            "SELECT COUNT(*) AS total " . $fromSql . $whereSql,
            $params
        );

        $total = (int)($countRows[0]['total'] ?? 0);

        $listSql = "SELECT
                        r.id,
                        r.inviter_uid,
                        inviter.nickname AS inviter_nickname,
                        r.invitee_uid,
                        invitee.nickname AS invitee_nickname,
                        r.invite_code,
                        r.status,
                        r.source_channel,
                        r.bind_time,
                        r.invalid_at,
                        r.created_at,
                        r.updated_at,
                        r.remark
                    " . $fromSql . $whereSql . "
                    ORDER BY r.id DESC
                    LIMIT {$offset}, {$pageSize}";

        $rows = $database->query($listSql, $params);

        jsonOut(0, 'success', [
            'list'      => $rows ?: [],
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize
        ]);
    }

    // ===== 检查邀请人 UID 是否存在 =====
    if ($action === 'check_user') {
        $uid = getUidParam();

        if ($uid <= 0) {
            jsonOut(1003, '请输入正确的邀请人UID', []);
        }

        $userRow = getUserByUid($database, $uid);
        if (!$userRow) {
            jsonOut(1004, 'users 表中不存在该邀请人UID', [
                'user_uid' => $uid
            ]);
        }

        $invite = getInviteByUid($database, $uid);

        jsonOut(0, '邀请人存在', [
            'user'   => $userRow,
            'invite' => $invite
        ]);
    }

    // ===== 创建邀请码 =====
    if ($action === 'create') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonOut(1008, '请使用 POST 请求', []);
        }

        $uid = getUidParam();

        if ($uid <= 0) {
            jsonOut(1003, '请输入正确的邀请人UID', []);
        }

        $userRow = getUserByUid($database, $uid);
        if (!$userRow) {
            jsonOut(1004, 'users 表中不存在该邀请人UID，不能创建邀请码', [
                'user_uid' => $uid
            ]);
        }

        // 一个邀请人只允许一个邀请码
        $exists = getInviteByUid($database, $uid);
        if ($exists) {
            jsonOut(1006, '该邀请人已经有邀请码，不重复创建', [
                'user'   => $userRow,
                'invite' => $exists
            ]);
        }

        $inviteCode = normalizeInviteCode(paramText('invite_code', ''));

        // 不填写则自动生成 6 位
        if ($inviteCode === '') {
            $inviteCode = generateInviteCode($database, 6);
        }

        if (strlen($inviteCode) > 6) {
            jsonOut(1005, '邀请码总长不能超过 6 位', []);
        }

        if (!preg_match('/^[A-Z0-9]{1,6}$/', $inviteCode)) {
            jsonOut(1005, '邀请码只能使用英文字母和数字，最多 6 位', []);
        }

        $codeOwner = getInviteByCode($database, $inviteCode);
        if ($codeOwner) {
            jsonOut(1007, '该邀请码已被占用，请换一个', [
                'invite_code' => $inviteCode
            ]);
        }

        $sourceChannel = paramText('source_channel', '后端');
        if ($sourceChannel === '') {
            $sourceChannel = '后端';
        }

        $remark = limitText(paramText('remark', ''), 255);

        $database->query(
            "INSERT INTO user_invite_codes
                (uid, invite_code, status, source_channel, created_at, updated_at, disabled_at, remark)
             VALUES
                (?, ?, 'active', ?, NOW(), NOW(), NULL, ?)",
            [$uid, $inviteCode, $sourceChannel, $remark]
        );

        $newInvite = getInviteByUid($database, $uid);

        jsonOut(0, '创建成功', [
            'user'   => $userRow,
            'invite' => $newInvite
        ]);
    }

    // ===== 停用邀请码 =====
    if ($action === 'disable') {
        $id = paramInt('id', 0);

        if ($id <= 0) {
            jsonOut(1003, '邀请码 ID 不正确', []);
        }

        $database->query(
            "UPDATE user_invite_codes
             SET status = 'disabled',
                 disabled_at = NOW(),
                 updated_at = NOW()
             WHERE id = ?",
            [$id]
        );

        jsonOut(0, '已停用', []);
    }

    // ===== 启用邀请码 =====
    if ($action === 'enable') {
        $id = paramInt('id', 0);

        if ($id <= 0) {
            jsonOut(1003, '邀请码 ID 不正确', []);
        }

        $database->query(
            "UPDATE user_invite_codes
             SET status = 'active',
                 disabled_at = NULL,
                 updated_at = NOW()
             WHERE id = ?",
            [$id]
        );

        jsonOut(0, '已启用', []);
    }

    jsonOut(1000, '无效操作', []);

} catch (Throwable $e) {
    jsonOut(1009, '服务器异常：' . $e->getMessage(), []);
}