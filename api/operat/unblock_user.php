<?php // api/operat/unblock_user.php
require_once __DIR__.'/_bootstrap.php';

$db = new Database();
$user = auth_or_die($db);
if (!can_block($user)) json_err('无权执行取消拉黑操作', 1003);

$req = input_array();
$roleId = intval($user['role_id'] ?? 0);
$isAdmin = in_array($roleId, [1, 2], true);
$action = trim((string)($req['action'] ?? 'single'));

function resolve_venue_id(array $req, array $user, bool $isAdmin): int {
    return $isAdmin
        ? intval($req['venue_id'] ?? ($user['venue_id'] ?? 0))
        : intval($user['venue_id'] ?? 0);
}

function parse_uids($value): array {
    $parts = is_array($value)
        ? $value
        : preg_split('/[\s,，]+/u', trim((string)$value), -1, PREG_SPLIT_NO_EMPTY);
    $uids = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '' || !ctype_digit($part) || intval($part) <= 0) {
            json_err('UID 格式错误，只支持正整数，并用换行或逗号分隔', 1002);
        }
        $uids[intval($part)] = intval($part);
    }
    if (!$uids) json_err('请至少输入一个 UID', 1002);
    if (count($uids) > 500) json_err('一次最多处理 500 个 UID', 1002);
    return array_values($uids);
}

try {
    if ($action === 'batch_venue') {
        $venueId = resolve_venue_id($req, $user, $isAdmin);
        if ($venueId <= 0) json_err('请选择要操作的场地', 1004);
        $uids = parse_uids($req['uids'] ?? '');
        $affected = 0;
        $db->beginTransaction();
        foreach ($uids as $uid) {
            $result = $db->query(
                'DELETE FROM venue_user_blacklist WHERE venue_id=? AND uid=?',
                [$venueId, $uid], true
            );
            if ($result === false) throw new RuntimeException('批量删除执行失败');
            $affected += $result;
        }
        $db->commit();
        json_ok(
            ['affected' => $affected, 'requested' => count($uids), 'venue_id' => $venueId],
            "批量取消完成，共删除 {$affected} 条拉黑记录"
        );
    }

    if ($action === 'uid_all_venues') {
        if (!$isAdmin) json_err('只有平台管理员可以取消用户在全部场地的拉黑', 1003);
        $uids = parse_uids($req['uids'] ?? ($req['uid'] ?? ''));
        if (count($uids) !== 1) json_err('此操作每次只能指定一个 UID', 1002);
        $uid = $uids[0];
        $affected = $db->query('DELETE FROM venue_user_blacklist WHERE uid=?', [$uid], true);
        if ($affected === false) throw new RuntimeException('全场地删除执行失败');
        json_ok(['affected' => $affected, 'uid' => $uid], "已取消 UID {$uid} 在全部场地的拉黑，共删除 {$affected} 条记录");
    }

    if ($action === 'venue_all') {
        $venueId = resolve_venue_id($req, $user, $isAdmin);
        if ($venueId <= 0) json_err('请选择要操作的场地', 1004);
        $affected = $db->query('DELETE FROM venue_user_blacklist WHERE venue_id=?', [$venueId], true);
        if ($affected === false) throw new RuntimeException('场地全部删除执行失败');
        json_ok(['affected' => $affected, 'venue_id' => $venueId], "已取消场地 {$venueId} 的全部拉黑，共删除 {$affected} 条记录");
    }

    $id = intval($req['id'] ?? 0);
    $targetUid = intval($req['uid'] ?? 0);
    if ($id > 0) {
        if (!$isAdmin) {
            $row = $db->query('SELECT venue_id FROM venue_user_blacklist WHERE id=?', [$id]);
            $rowVenue = intval($row[0]['venue_id'] ?? 0);
            $myVenue = intval($user['venue_id'] ?? 0);
            if ($rowVenue <= 0 || $rowVenue !== $myVenue) json_err('无权操作该记录', 1003);
        }
        $affected = $db->query('DELETE FROM venue_user_blacklist WHERE id=? LIMIT 1', [$id], true);
    } else {
        $venueId = resolve_venue_id($req, $user, $isAdmin);
        if ($targetUid <= 0) json_err('参数错误：uid 必填且为正整数', 1002);
        if ($venueId <= 0) json_err('请选择要操作的场地', 1004);
        $affected = $db->query(
            'DELETE FROM venue_user_blacklist WHERE venue_id=? AND uid=? LIMIT 1',
            [$venueId, $targetUid], true
        );
    }
    if ($affected === false) throw new RuntimeException('单条删除执行失败');
    if ($affected > 0) json_ok(['affected' => $affected], '已取消拉黑');
    json_err('未找到拉黑记录', 2004);
} catch (Throwable $e) {
    try { $db->rollBack(); } catch (Throwable $ignored) {}
    $db->logToFile('unblock_user error: '.$e->getMessage());
    json_err('取消拉黑失败，请重试或联系管理员', 2002);
}
