
<?php
/**
 * /api/operat/venue_mute_records_api.php
 *
 * 用户禁言记录管理接口
 * action=list    查询 banned_users 禁言记录，支持 venue_id / banned_uid 筛选
 * action=mute    新增禁言：role_id=1 可指定 venue_id；role_id=3 默认使用当前账号 venue_id
 * action=unmute  解除禁言：把指定记录 end_time 改为 NOW()，保留历史记录
 * action=delete  unmute 的别名，不做物理删除
 */

require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function out_json($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function req_str($key, $default = '') {
    return trim((string)($_REQUEST[$key] ?? $default));
}

function req_int($key, $default = 0) {
    if (!isset($_REQUEST[$key])) {
        return $default;
    }
    return intval($_REQUEST[$key]);
}

function parse_mysql_time_active($endTime) {
    if ($endTime === null || $endTime === '') {
        return true;
    }
    $ts = strtotime((string)$endTime);
    if ($ts === false) {
        return false;
    }
    return $ts > time();
}

try {
    $database = new Database();

    // ===== 后台登录鉴权 =====
    $sessionToken = $_COOKIE['session_token'] ?? '';
    if (!$sessionToken) {
        out_json(1001, '用户未登录或会话已过期', []);
    }

    $loginUser = $database->getUserBySessionToken($sessionToken);
    if (!$loginUser || empty($loginUser['role_id'])) {
        out_json(1001, '用户未登录或无权访问', []);
    }

    $roleId = intval($loginUser['role_id']);
    $loginVenueId = intval($loginUser['venue_id'] ?? 0);
    $loginUid = intval($loginUser['uid'] ?? $loginUser['user_uid'] ?? 0);
    $isAdmin = in_array($roleId, [1, 2], true);
    $canAddMute = in_array($roleId, [1, 3], true);

    // ===== 不能被禁言的 UID 白名单：平台官方巡查 / 系统账号 =====
    // 注意：这里判断的是“被封禁人的 UID”，不是操作人的 UID。
    $MUTE_PROTECTED_UIDS = [
        // 10001,
        10027,
        10107,
        10130,
        // 24570,
        // 37703,
        // 87472,
    ];

    $action = strtolower(req_str('action', 'list'));

    // ===== 新增禁言 =====
    if ($action === 'mute' || $action === 'add') {
        if (!$canAddMute) {
            out_json(403, '当前角色无权添加禁言', []);
        }

        if ($loginUid <= 0) {
            out_json(400, '当前登录账号缺少uid，无法作为封禁人', []);
        }

        $bannedUid = req_int('banned_uid', req_int('uid'));
        if ($bannedUid <= 0) {
            out_json(400, '被封禁人的uid参数错误', []);
        }

        if (in_array($bannedUid, $MUTE_PROTECTED_UIDS, true)) {
            out_json(403, '该UID为平台官方巡查/系统账号，禁止添加禁言', [
                'banned_uid' => $bannedUid,
                'protected'  => 1
            ]);
        }

        // role_id=1：前端传 venue_id；role_id=3：不传 venue_id，默认使用当前账号绑定场地
        if ($roleId === 1) {
            $venueId = req_int('venue_id');
            if ($venueId <= 0) {
                out_json(400, '请选择封禁场地', []);
            }
        } else {
            $venueId = $loginVenueId;
            if ($venueId <= 0) {
                out_json(403, '当前账号未绑定场地，无法添加禁言', []);
            }
        }

        $reason = req_str('reason', '后台手动禁言');
        if ($reason === '') {
            $reason = '后台手动禁言';
        }

        // 校验场地是否存在
        $venueRows = $database->query("SELECT id FROM venues WHERE id = ? LIMIT 1", [$venueId]);
        if (!$venueRows || count($venueRows) === 0) {
            out_json(404, '封禁场地不存在', []);
        }

        // 校验被禁言用户是否存在
        $userRows = $database->query("SELECT uid FROM users WHERE uid = ? LIMIT 1", [$bannedUid]);
        if (!$userRows || count($userRows) === 0) {
            out_json(404, '被封禁用户不存在', []);
        }

        // 如果已存在有效禁言，不重复插入
        $activeSql = "SELECT id, venue_id, banned_uid, end_time
                      FROM banned_users
                      WHERE venue_id = ?
                        AND banned_uid = ?
                        AND (end_time IS NULL OR end_time > NOW())
                      ORDER BY id DESC
                      LIMIT 1";
        $activeRows = $database->query($activeSql, [$venueId, $bannedUid]);
        if ($activeRows && count($activeRows) > 0) {
            out_json(0, '该用户已在此场地禁言中', [
                'id'         => intval($activeRows[0]['id']),
                'venue_id'   => $venueId,
                'banned_uid' => $bannedUid,
                'admin_uid'  => $loginUid,
                'is_active'  => 1
            ]);
        }

        // 如果之前有历史记录，重新封禁时把 end_time 置 NULL；没有历史记录则插入
        $historySql = "SELECT id
                       FROM banned_users
                       WHERE venue_id = ?
                         AND banned_uid = ?
                       ORDER BY id DESC
                       LIMIT 1";
        $historyRows = $database->query($historySql, [$venueId, $bannedUid]);

        if ($historyRows && count($historyRows) > 0) {
            $recordId = intval($historyRows[0]['id']);
            $updateSql = "UPDATE banned_users
                          SET admin_uid = ?,
                              reason = ?,
                              start_time = NOW(),
                              end_time = NULL,
                              updated_at = NOW()
                          WHERE id = ?";
            $ok = $database->query($updateSql, [$loginUid, $reason, $recordId], true);
        } else {
            $recordId = 0;
            $insertSql = "INSERT INTO banned_users
                            (admin_uid, venue_id, banned_uid, reason, start_time, end_time, created_at, updated_at)
                          VALUES
                            (?, ?, ?, ?, NOW(), NULL, NOW(), NOW())";
            $ok = $database->query($insertSql, [$loginUid, $venueId, $bannedUid, $reason], true);
        }

        if ($ok === false) {
            out_json(500, '添加禁言失败', []);
        }

        out_json(0, '添加禁言成功', [
            'id'         => $recordId,
            'venue_id'   => $venueId,
            'banned_uid' => $bannedUid,
            'admin_uid'  => $loginUid,
            'is_active'  => 1
        ]);
    }

    // ===== 解除禁言：软删除，保留记录 =====
    if ($action === 'unmute' || $action === 'delete') {
        $id = req_int('id');
        $venueId = req_int('venue_id');
        $bannedUid = req_int('banned_uid', req_int('uid'));

        if ($id <= 0) {
            out_json(400, 'id参数错误', []);
        }

        $rowSql = "SELECT id, venue_id, banned_uid, end_time
                   FROM banned_users
                   WHERE id = ?
                   LIMIT 1";
        $rows = $database->query($rowSql, [$id]);
        if (!$rows || count($rows) === 0) {
            out_json(404, '禁言记录不存在', []);
        }

        $row = $rows[0];
        $rowVenueId = intval($row['venue_id']);
        $rowBannedUid = intval($row['banned_uid']);

        if (!$isAdmin && $rowVenueId !== $loginVenueId) {
            out_json(403, '只能解除自己场地的禁言记录', []);
        }

        if ($venueId > 0 && $venueId !== $rowVenueId) {
            out_json(400, 'venue_id与记录不匹配', []);
        }

        if ($bannedUid > 0 && $bannedUid !== $rowBannedUid) {
            out_json(400, 'banned_uid与记录不匹配', []);
        }

        if (!parse_mysql_time_active($row['end_time'] ?? null)) {
            out_json(0, '该记录已解除或已过期', [
                'id'         => $id,
                'venue_id'   => $rowVenueId,
                'banned_uid' => $rowBannedUid,
                'is_active'  => 0
            ]);
        }

        // $updateSql = "UPDATE banned_users
        //               SET end_time = NOW(),
        //                   updated_at = NOW()
        //               WHERE id = ?
        //                 AND (end_time IS NULL OR end_time > NOW())";
        // $ok = $database->query($updateSql, [$id], true);

        // if ($ok === false) {
        //     out_json(500, '解除禁言失败', []);
        // }

        // out_json(0, '解除禁言成功', [
        //     'id'         => $id,
        //     'venue_id'   => $rowVenueId,
        //     'banned_uid' => $rowBannedUid,
        //     'is_active'  => 0
        // ]);
        
        $deleteSql = "DELETE FROM banned_users
              WHERE id = ?
                AND venue_id = ?
                AND banned_uid = ?";

        $ok = $database->query($deleteSql, [$id, $rowVenueId, $rowBannedUid], true);
        
        if ($ok === false) {
            out_json(500, '删除禁言记录失败', []);
        }
        
        out_json(0, '解除禁言成功', [
            'id'         => $id,
            'venue_id'   => $rowVenueId,
            'banned_uid' => $rowBannedUid,
            'deleted'    => 1,
            'is_active'  => 0
        ]);
    }

    // ===== 查询列表 =====
    if ($action !== 'list') {
        out_json(400, 'action参数错误，仅支持 list / mute / unmute', []);
    }

    $page = req_int('page', 1);
    if ($page <= 0) {
        $page = 1;
    }

    $pageSize = req_int('page_size', 20);
    if ($pageSize <= 0) {
        $pageSize = 20;
    }
    if ($pageSize > 100) {
        $pageSize = 100;
    }

    $venueId = req_int('venue_id');
    $bannedUidKeyword = req_str('banned_uid', req_str('uid'));
    $status = strtolower(req_str('status', ''));

    $where = ['1=1'];
    $params = [];

    if ($isAdmin) {
        if ($venueId > 0) {
            $where[] = 'b.venue_id = ?';
            $params[] = $venueId;
        }
    } else {
        if ($loginVenueId <= 0) {
            out_json(403, '当前账号未绑定场地，无法查看禁言记录', []);
        }
        $where[] = 'b.venue_id = ?';
        $params[] = $loginVenueId;
    }

    if ($bannedUidKeyword !== '') {
        if (!preg_match('/^\d+$/', $bannedUidKeyword)) {
            out_json(400, '被禁言UID只能输入数字', []);
        }
        // 支持输入完整 UID 或部分 UID 搜索
        $where[] = 'CAST(b.banned_uid AS CHAR) LIKE ?';
        $params[] = '%' . $bannedUidKeyword . '%';
    }

    if ($status === 'active') {
        $where[] = '(b.end_time IS NULL OR b.end_time > NOW())';
    } elseif ($status === 'ended') {
        $where[] = '(b.end_time IS NOT NULL AND b.end_time <= NOW())';
    }

    $whereSql = implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) AS total
                 FROM banned_users b
                 WHERE {$whereSql}";
    $countRows = $database->query($countSql, $params);
    $total = intval($countRows[0]['total'] ?? 0);

    $offset = ($page - 1) * $pageSize;
    $totalPages = $total > 0 ? (int)ceil($total / $pageSize) : 1;
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $pageSize;
    }

    $listSql = "SELECT
                    b.id,
                    b.admin_uid,
                    b.venue_id,
                    b.banned_uid,
                    b.reason,
                    DATE_FORMAT(b.start_time, '%Y-%m-%d %H:%i:%s') AS start_time,
                    DATE_FORMAT(b.end_time, '%Y-%m-%d %H:%i:%s') AS end_time,
                    DATE_FORMAT(b.created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
                    DATE_FORMAT(b.updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at,
                    v.venue_name AS venue_name,
                    u.nickname AS banned_nickname,
                    au.nickname AS admin_nickname,
                    CASE
                        WHEN b.end_time IS NULL OR b.end_time > NOW() THEN 1
                        ELSE 0
                    END AS is_active
                FROM banned_users b
                LEFT JOIN venues v ON v.id = b.venue_id
                LEFT JOIN users u ON u.uid = b.banned_uid
                LEFT JOIN users au ON au.uid = b.admin_uid
                WHERE {$whereSql}
                ORDER BY b.id DESC
                LIMIT {$pageSize} OFFSET {$offset}";

    $rows = $database->query($listSql, $params);
    if (!$rows) {
        $rows = [];
    }

    out_json(0, 'success', [
        'rows'           => $rows,
        'total'          => $total,
        'page'           => $page,
        'page_size'      => $pageSize,
        'total_pages'    => $totalPages,
        'is_admin'       => $isAdmin ? 1 : 0,
        'role_id'        => $roleId,
        'login_venue_id' => $loginVenueId,
        'login_uid'      => $loginUid,
        'can_add_mute'   => $canAddMute ? 1 : 0
    ]);
} catch (Throwable $e) {
    out_json(500, '接口异常：' . $e->getMessage(), []);
}
?>
