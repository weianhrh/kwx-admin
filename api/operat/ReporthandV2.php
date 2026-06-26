<?php
require_once '../Database.php';
require_once '../lib/venue_scope.php';

header('Content-Type: application/json; charset=utf-8');

$database = new Database();
$connection = $database->getConnection();

$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->getUserBySessionToken($session_token);

if (!$user || !isset($user['role_id'])) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$role_id = intval($user['role_id']);
$venue_id = $user['venue_id'] ?? '';
$user_id = $user['uid'] ?? '';
$operator_id = $user['id'] ?? '';
$requested_venue_id = venue_scope_requested_id($_GET);

function json_out($code, $msg, $data = [])
{
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function is_admin_role($role_id)
{
    return $role_id == 1 || $role_id == 2;
}

function valid_report_status($status)
{
    $allow = ['未处理', '处理中', '已处理'];
    return in_array($status, $allow, true);
}

function valid_source_table($source_table)
{
    $allow = ['Reports', 'voice_reports'];
    return in_array($source_table, $allow, true);
}

function normalize_voice_report_row($row)
{
    $row['source_table'] = 'voice_reports';
    $row['source_label'] = '语音房举报';
    $row['report_type'] = intval($row['report_type']);
    $row['report_type_text'] = $row['report_type'] == 0 ? '房间举报' : ($row['report_type'] == 1 ? '弹幕举报' : '未知举报');

    if ($row['report_type'] == 0) {
        $row['target_kind'] = 'venue';
        $row['target_id'] = $row['handler_uid'];
        $row['target_name'] = $row['venue_name'] ?? '';
    } else {
        $row['target_kind'] = 'user';
        $row['target_id'] = $row['handler_uid'];
        $row['target_name'] = $row['reported_user_name'] ?? '';
    }

    return $row;
}

function normalize_device_report_row($row)
{
    $row['source_table'] = 'Reports';
    $row['source_label'] = '设备举报';
    $row['report_type_text'] = '设备举报';
    $row['target_kind'] = 'device';
    $row['target_id'] = $row['device_id'] ?? '';
    $row['target_name'] = $row['vehicle_name'] ?? '';
    $row['report_content'] = $row['report_content'] ?? '';
    $row['reporter_uid'] = $row['reporter_uid'] ?? '';
    $row['reporter_name'] = $row['reporter_name'] ?? '';
    return $row;
}

// ------------------------------
// 处理“无效”按钮请求
// ------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if ($role_id == 3 || $role_id == 4) {
        json_out(1007, '当前角色仅可查看，不能处理投诉');
    }

    if (!is_admin_role($role_id)) {
        json_out(1008, '无权处理投诉');
    }

    $delete_id = intval($_POST['delete_id']);
    $source_table = trim($_POST['source_table'] ?? 'Reports');

    if (!valid_source_table($source_table)) {
        json_out(1010, '非法来源表');
    }

    try {
        if ($source_table === 'voice_reports') {
            // voice_reports.handler_uid 表示被举报对象，不能覆盖
            $stmt = $connection->prepare("\n                UPDATE voice_reports\n                SET status = '已处理'\n                WHERE id = ?\n            ");

            if (!$stmt) {
                throw new Exception("Prepare failed: " . $connection->error);
            }

            $stmt->bind_param("i", $delete_id);
        } else {
            $stmt = $connection->prepare("\n                UPDATE Reports\n                SET status = '已处理', handler_uid = ?\n                WHERE id = ?\n            ");

            if (!$stmt) {
                throw new Exception("Prepare failed: " . $connection->error);
            }

            $stmt->bind_param("si", $operator_id, $delete_id);
        }

        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            json_out(0, '处理成功', [
                'operator_id' => $operator_id,
                'delete_id'   => $delete_id,
                'source_table'=> $source_table
            ]);
        } else {
            json_out(1005, '处理失败或记录不存在', [
                'operator_id' => $operator_id,
                'delete_id'   => $delete_id,
                'source_table'=> $source_table
            ]);
        }

        $stmt->close();
    } catch (Exception $e) {
        error_log('Delete error: ' . $e->getMessage());
        json_out(1006, '数据库处理错误');
    }
}

// ------------------------------
// 处理状态更新请求
// ------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_id']) && isset($_POST['status'])) {
    if ($role_id == 3 || $role_id == 4) {
        json_out(1007, '当前角色仅可查看，不能修改处理状态');
    }

    if (!is_admin_role($role_id)) {
        json_out(1008, '无权修改处理状态');
    }

    $new_status = trim($_POST['status']);
    $report_id = intval($_POST['report_id']);
    $source_table = trim($_POST['source_table'] ?? 'Reports');

    if (!valid_report_status($new_status)) {
        json_out(1009, '非法状态值');
    }

    if (!valid_source_table($source_table)) {
        json_out(1010, '非法来源表');
    }

    try {
        if ($source_table === 'voice_reports') {
            // voice_reports.handler_uid 表示被举报对象，不能覆盖
            $stmt = $connection->prepare("\n                UPDATE voice_reports\n                SET status = ?\n                WHERE id = ?\n            ");

            if (!$stmt) {
                throw new Exception("Prepare failed: " . $connection->error);
            }

            $stmt->bind_param("si", $new_status, $report_id);
        } else {
            $stmt = $connection->prepare("\n                UPDATE Reports\n                SET status = ?, handler_uid = ?\n                WHERE id = ?\n            ");

            if (!$stmt) {
                throw new Exception("Prepare failed: " . $connection->error);
            }

            $stmt->bind_param("ssi", $new_status, $operator_id, $report_id);
        }

        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            json_out(0, '状态更新成功', [
                'source_table' => $source_table,
                'operator_id'  => $operator_id
            ]);
        } else {
            json_out(1002, '状态更新失败或记录不存在', []);
        }

        $stmt->close();
    } catch (Exception $e) {
        error_log('Database error: ' . $e->getMessage());
        json_out(1003, '数据库错误，请稍后重试', []);
    }
}

// ------------------------------
// 查询投诉列表
// ------------------------------
try {
    $reports = [];

    // 1) 设备举报 Reports
    $deviceParams = [];
    $deviceSql = "
        SELECT
            r.id,
            r.device_id,
            r.insert_time,
            CAST(v.name AS CHAR) AS vehicle_name,
            v.bind_site,
            r.report_type,
            r.status,
            r.notes,
            r.image_url,
            r.handler_uid,
            r.report_type AS report_content,
            r.reporter_uid AS reporter_uid,
            '' AS reporter_name
        FROM Reports r
        JOIN vehicles v ON r.device_id = v.serial_number
        WHERE (r.status = '未处理' OR r.status = '处理中')
    ";

    if (is_admin_role($role_id)) {
        if ($requested_venue_id > 0) {
            $deviceSql .= " AND v.bind_site = ?";
            $deviceParams[] = (string)$requested_venue_id;
        }
    } elseif ($role_id == 3 || $role_id == 4) {
        $deviceSql .= venue_scope_apply_filter($database, $user, 'v.bind_site', $deviceParams, $requested_venue_id);
    } else {
        throw new Exception("不支持的角色 ID: " . $role_id);
    }

    $deviceSql .= " ORDER BY r.insert_time DESC";
    $deviceRows = $database->query($deviceSql, $deviceParams) ?: [];
    foreach ($deviceRows as $row) {
        $reports[] = normalize_device_report_row($row);
    }

    // 2) 语音房举报 voice_reports
    $voiceParams = [];
    $voiceSql = "
        SELECT
            vr.id,
            vr.reporter_uid,
            vr.report_content,
            vr.report_type,
            vr.status,
            vr.notes,
            vr.handler_uid,
            vr.insert_time,
            vr.image_url,
            '' AS device_id,
            '' AS vehicle_name,
            '' AS bind_site,
            COALESCE(vv.venue_name, '') AS venue_name,
            COALESCE(ru.nickname, '') AS reporter_name,
            COALESCE(uu.nickname, '') AS reported_user_name
        FROM voice_reports vr
        LEFT JOIN venues vv
            ON vr.report_type = 0 AND CAST(vr.handler_uid AS UNSIGNED) = vv.id
        LEFT JOIN users ru
            ON CAST(vr.reporter_uid AS UNSIGNED) = ru.uid
        LEFT JOIN users uu
            ON vr.report_type = 1 AND CAST(vr.handler_uid AS UNSIGNED) = uu.uid
        WHERE (vr.status = '未处理' OR vr.status = '处理中')
    ";

    if (is_admin_role($role_id)) {
        if ($requested_venue_id > 0) {
            // 管理员指定场地时，只取该场地的房间举报。
            $voiceSql .= " AND vr.report_type = 0 AND CAST(vr.handler_uid AS UNSIGNED) = ?";
            $voiceParams[] = (string)$requested_venue_id;
        }
    } elseif ($role_id == 3 || $role_id == 4) {
        // 场地方只看自己绑定场地的房间举报，不展示弹幕举报。
        $voiceSql .= " AND vr.report_type = 0";
        $voiceSql .= venue_scope_apply_filter($database, $user, 'CAST(vr.handler_uid AS UNSIGNED)', $voiceParams, $requested_venue_id);
    } else {
        throw new Exception("不支持的角色 ID: " . $role_id);
    }

    $voiceSql .= " ORDER BY vr.insert_time DESC";
    $voiceRows = $database->query($voiceSql, $voiceParams) ?: [];
    foreach ($voiceRows as $row) {
        $reports[] = normalize_voice_report_row($row);
    }

    usort($reports, function ($a, $b) {
        return strtotime($b['insert_time']) <=> strtotime($a['insert_time']);
    });

    if (empty($reports)) {
        json_out(1004, '暂无投诉信息', [$user_id]);
    } else {
        json_out(0, '获取未处理投诉信息成功', $reports);
    }
} catch (Exception $e) {
    error_log('Database error: ' . $e->getMessage());
    json_out(1003, '数据库错误，请稍后重试', []);
}
?>
