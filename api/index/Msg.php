<?php
require_once '../Database.php';

date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

$database = new Database();

function jsonOut($code, $msg, $data = null)
{
    $res = [
        'code' => $code,
        'msg'  => $msg
    ];

    if ($data !== null) {
        $res['data'] = $data;
    }

    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function postText($key, $default = '')
{
    $value = $_POST[$key] ?? $default;
    if (is_array($value)) {
        return $default;
    }
    return trim((string)$value);
}

function postInt($key, $default = 0)
{
    return (int)($_POST[$key] ?? $default);
}

function clampImportance($importance)
{
    $importance = (int)$importance;
    if ($importance < 1) {
        $importance = 1;
    }
    if ($importance > 4) {
        $importance = 4;
    }
    return $importance;
}

function fetchNoticeList($database)
{
    $sql = "SELECT id, title, content, color, read_status, importance, publish_time
            FROM official_notices
            WHERE status = 1
            ORDER BY publish_time DESC, id DESC";
    return $database->query($sql);
}

function fetchLogList($database)
{
    $sql = "SELECT id, version, platform, content, publish_time
            FROM update_logs
            WHERE status = 1
            ORDER BY publish_time DESC, id DESC";
    return $database->query($sql);
}

// 获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    jsonOut(1001, '用户未登录或会话已过期', []);
}

// 验证用户信息
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    jsonOut(1001, '用户未登录或无权访问', []);
}

$role_id = $user['role_id'];

// 处理 POST 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = postText('action');

    // 添加官方通知
    if ($action === 'addNotice') {
        try {
            $title = postText('title', '官方通知');
            $content = postText('content');
            $color = postText('color', '#e30d0d');
            $read_status = postText('read_status', '1'); // 0弹窗 1普通
            $importance = clampImportance(postInt('importance', 1));

            if ($title === '') {
                $title = '官方通知';
            }
            if ($content === '') {
                jsonOut(1003, '通知内容不能为空');
            }
            if ($read_status !== '0' && $read_status !== '1') {
                $read_status = '1';
            }
            if ($color === '') {
                $color = '#e30d0d';
            }

            $sql = "INSERT INTO official_notices
                        (title, content, color, read_status, importance, status, publish_time)
                    VALUES
                        (?, ?, ?, ?, ?, 1, NOW())";
            $database->query($sql, [$title, $content, $color, $read_status, $importance]);

            jsonOut(0, 'success');
        } catch (Exception $e) {
            jsonOut(1002, '添加通知失败：' . $e->getMessage());
        }
    }

    // 编辑官方通知
    if ($action === 'updateNotice') {
        try {
            $id = postInt('id');
            $title = postText('title', '官方通知');
            $content = postText('content');
            $color = postText('color', '#e30d0d');
            $read_status = postText('read_status', '1'); // 0弹窗 1普通
            $importance = clampImportance(postInt('importance', 1));

            if ($id <= 0) {
                jsonOut(1003, '通知ID不正确');
            }
            if ($title === '') {
                $title = '官方通知';
            }
            if ($content === '') {
                jsonOut(1003, '通知内容不能为空');
            }
            if ($read_status !== '0' && $read_status !== '1') {
                $read_status = '1';
            }
            if ($color === '') {
                $color = '#e30d0d';
            }

            $sql = "UPDATE official_notices
                    SET title = ?, content = ?, color = ?, read_status = ?, importance = ?
                    WHERE id = ? AND status = 1";
            $database->query($sql, [$title, $content, $color, $read_status, $importance, $id]);

            jsonOut(0, 'success');
        } catch (Exception $e) {
            jsonOut(1002, '编辑通知失败：' . $e->getMessage());
        }
    }

    // 添加更新日志
    if ($action === 'addLog') {
        try {
            $version = postText('version');
            $platform = postText('platform');
            $content = postText('content');

            if ($version === '') {
                jsonOut(1003, '版本号不能为空');
            }
            if ($platform === '') {
                jsonOut(1003, '平台不能为空');
            }
            if ($content === '') {
                jsonOut(1003, '更新内容不能为空');
            }

            $sql = "INSERT INTO update_logs
                        (version, platform, content, status, publish_time)
                    VALUES
                        (?, ?, ?, 1, NOW())";
            $database->query($sql, [$version, $platform, $content]);

            jsonOut(0, 'success');
        } catch (Exception $e) {
            jsonOut(1002, '添加日志失败：' . $e->getMessage());
        }
    }

    // 编辑更新日志
    if ($action === 'updateLog') {
        try {
            $id = postInt('id');
            $version = postText('version');
            $platform = postText('platform');
            $content = postText('content');

            if ($id <= 0) {
                jsonOut(1003, '日志ID不正确');
            }
            if ($version === '') {
                jsonOut(1003, '版本号不能为空');
            }
            if ($platform === '') {
                jsonOut(1003, '平台不能为空');
            }
            if ($content === '') {
                jsonOut(1003, '更新内容不能为空');
            }

            $sql = "UPDATE update_logs
                    SET version = ?, platform = ?, content = ?
                    WHERE id = ? AND status = 1";
            $database->query($sql, [$version, $platform, $content, $id]);

            jsonOut(0, 'success');
        } catch (Exception $e) {
            jsonOut(1002, '编辑日志失败：' . $e->getMessage());
        }
    }

    // 获取消息列表：type=notice / log / all
    if ($action === 'getMessages') {
        try {
            $type = postText('type', 'all');

            if ($type === 'notice') {
                jsonOut(0, 'success', fetchNoticeList($database));
            }

            if ($type === 'log') {
                jsonOut(0, 'success', fetchLogList($database));
            }

            if ($type === 'all') {
                jsonOut(0, 'success', [
                    'notices' => fetchNoticeList($database),
                    'logs'    => fetchLogList($database)
                ]);
            }

            jsonOut(1003, '消息类型不正确');
        } catch (Exception $e) {
            jsonOut(1002, '获取消息失败：' . $e->getMessage());
        }
    }

    // 删除消息：软删除，保留历史数据
    if ($action === 'deleteMessage') {
        try {
            $type = postText('type');
            $id = postInt('id');

            if ($id <= 0) {
                jsonOut(1003, '消息ID不正确');
            }

            if ($type === 'notice') {
                $sql = "UPDATE official_notices SET status = 0 WHERE id = ?";
                $database->query($sql, [$id]);
                jsonOut(0, 'success');
            }

            if ($type === 'log') {
                $sql = "UPDATE update_logs SET status = 0 WHERE id = ?";
                $database->query($sql, [$id]);
                jsonOut(0, 'success');
            }

            jsonOut(1003, '消息类型不正确');
        } catch (Exception $e) {
            jsonOut(1002, '删除失败：' . $e->getMessage());
        }
    }

    // 获取未读弹窗通知：保留原有逻辑
// 获取未读弹窗通知：根据 admin_users.created_at 排除账号创建前的历史弹窗
if ($action === 'getUnreadNotices') {
    try {
        $session_token = $_COOKIE['session_token'] ?? null;

        if (!$session_token) {
            jsonOut(1001, '用户未登录或会话已过期', []);
        }

        // 这里必须取 created_at，用来过滤账号创建之前的弹窗
        $admin_sql = "SELECT id, created_at FROM admin_users WHERE session_token = ? LIMIT 1";
        $admin = $database->query($admin_sql, [$session_token]);

        if (!$admin || empty($admin[0]['id'])) {
            jsonOut(1001, '用户未登录或会话已过期', []);
        }

        $admin_id = (int)$admin[0]['id'];

        // 如果老账号 created_at 为空，兜底用当前时间，避免弹出一堆历史弹窗
        $admin_created_at = $admin[0]['created_at'] ?? '';
        if (!$admin_created_at || $admin_created_at === '0000-00-00 00:00:00') {
            $admin_created_at = date('Y-m-d H:i:s');
        }

        $sql = "SELECT n.id, n.title, n.content
                FROM official_notices n
                LEFT JOIN notice_read_status rs
                    ON n.id = rs.notice_id AND rs.admin_id = ?
                WHERE n.status = 1
                  AND n.read_status = 0
                  AND n.publish_time >= ?
                  AND (rs.read_status IS NULL OR rs.read_status = 0)
                ORDER BY n.publish_time DESC, n.id DESC
                LIMIT 1";

        $unreadNotices = $database->query($sql, [$admin_id, $admin_created_at]);

        jsonOut(0, 'success', $unreadNotices ?: []);
    } catch (Exception $e) {
        jsonOut(1002, '获取未读通知失败：' . $e->getMessage());
    }
}

    // 标记弹窗通知已读：保留原有逻辑
    if ($action === 'markNoticeAsRead') {
        try {
            $session_token = $_COOKIE['session_token'] ?? null;

            if (!$session_token) {
                jsonOut(1001, '用户未登录或会话已过期', []);
            }

            $admin_sql = "SELECT id FROM admin_users WHERE session_token = ?";
            $admin = $database->query($admin_sql, [$session_token]);

            if (!$admin) {
                jsonOut(1001, '用户未登录或会话已过期', []);
            }

            $admin_id = $admin[0]['id'];
            $notice_id = postInt('notice_id');

            if ($notice_id <= 0) {
                jsonOut(1003, '通知ID不正确');
            }

            $sql = "INSERT INTO notice_read_status
                        (notice_id, admin_id, read_status, read_time)
                    VALUES
                        (?, ?, 1, NOW())
                    ON DUPLICATE KEY UPDATE
                        read_status = 1,
                        read_time = NOW()";

            $database->query($sql, [$notice_id, $admin_id]);
            jsonOut(0, 'success');
        } catch (Exception $e) {
            jsonOut(1002, '标记已读失败：' . $e->getMessage());
        }
    }

    jsonOut(1000, '无效操作');
}

// 处理 GET 请求：保留给其它页面读取首页公告/更新日志/违规提示
try {
    $notices = fetchNoticeList($database);
    $logs = fetchLogList($database);

    // 获取近一周的违规记录
    $violations_sql = "SELECT device_id
                      FROM Reports
                      WHERE status = '未处理'
                      AND report_type = '色情低俗'
                      AND insert_time >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
    $violations = $database->query($violations_sql);

    $violation_info = [];
    foreach ($violations as $violation) {
        $device_id = $violation['device_id'];

        $site_sql = "SELECT v.bind_site, vn.venue_name
                     FROM vehicles v
                     LEFT JOIN venues vn ON v.bind_site = vn.id
                     WHERE v.serial_number = ?";
        $site_info = $database->query($site_sql, [$device_id]);
        $venue_name = $site_info[0]['venue_name'] ?? '未知场地';

        $len = strlen($device_id);
        if ($len > 8) {
            $start = substr($device_id, 0, ($len - 8) / 2);
            $middle = str_repeat('*', 4);
            $end = substr($device_id, ($len - 8) / 2 + 4);
            $masked_device = $start . $middle . $end;
        } else {
            $masked_device = $device_id;
        }

        $violation_info[] = [
            'venue_name' => $venue_name,
            'device_id'  => $masked_device
        ];
    }

    jsonOut(0, 'success', [
        'notices'    => $notices,
        'logs'       => $logs,
        'violations' => $violation_info
    ]);
} catch (Exception $e) {
    jsonOut(1002, '获取数据失败：' . $e->getMessage(), []);
}
