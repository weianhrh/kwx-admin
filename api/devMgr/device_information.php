<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../Database.php';

$database = new Database();

/**
 * 统一输出
 */
function j($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 从 rtc_user_id 里解析设备编号：
 * user023968912_80037 => 80037
 * 80037 => 80037
 */
function parseDeviceNoFromRtcUserId($rtcUserId) {
    $rtcUserId = trim((string)$rtcUserId);
    if ($rtcUserId === '') return '';

    if (preg_match('/_(\d+)$/', $rtcUserId, $m)) {
        return $m[1];
    }

    if (preg_match('/^\d+$/', $rtcUserId)) {
        return $rtcUserId;
    }

    return '';
}

/**
 * 登录校验（你给的模板）
 */
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) j(1001, '用户未登录或会话已过期', []);
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) j(1001, '用户未登录或无权访问', []);

/**
 * 你这个表：device_information
 * 字段：id, device_id, playing_stream_id, room_id, rtc_user_id
 */
$action = $_GET['action'] ?? 'list';

/**
 * 基础权限：可新增、可修改、不可删除
 */
$permissions = [
    'can_add'    => true,
    'can_edit'   => true,
    'can_delete' => false,   // ✅ 不允许删除
];

try {

    if ($action === 'list') {
        // ✅ 默认展开：直接返回全部记录
        $sql = "SELECT id, device_id, playing_stream_id, room_id, rtc_user_id
                FROM device_information
                ORDER BY id  DESC";
        $rows = $database->query($sql);

        if ($rows === false) j(400, '数据加载失败', []);

        j(200, '数据加载成功', [
            'permissions' => $permissions,
            'rows' => $rows
        ]);
    }

    if ($action === 'create') {
        // ✅ 新增：POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') j(405, '请使用 POST 请求', []);

        $device_id = trim($_POST['device_id'] ?? '');
        $playing_stream_id = trim($_POST['playing_stream_id'] ?? '');
        $room_id = trim($_POST['room_id'] ?? '');
        $rtc_user_id = trim($_POST['rtc_user_id'] ?? '');

        // ✅ 兜底：如果只传 rtc_user_id=user023968912_80037，则自动解析 80037 填充前三项
        $parsedNo = parseDeviceNoFromRtcUserId($rtc_user_id);
        if ($parsedNo !== '') {
            if ($device_id === '') $device_id = $parsedNo;
            if ($playing_stream_id === '') $playing_stream_id = $parsedNo;
            if ($room_id === '') $room_id = $parsedNo;
        }

        if ($device_id === '' || $playing_stream_id === '' || $room_id === '') {
            j(422, '参数缺失：device_id / playing_stream_id / room_id 必填', []);
        }

        $rtc_user_id = ($rtc_user_id === '') ? null : $rtc_user_id;

        // 避免 device_id 重复
        $check = $database->query("SELECT id FROM device_information WHERE device_id = ? LIMIT 1", [$device_id]);
        if ($check && count($check) > 0) {
            j(409, 'device_id 已存在，不能重复新增', []);
        }

        $database->beginTransaction();

        $ins = "INSERT INTO device_information (device_id, playing_stream_id, room_id, rtc_user_id)
                VALUES (?, ?, ?, ?)";
        $affected = $database->query($ins, [$device_id, $playing_stream_id, $room_id, $rtc_user_id], true);

        if ($affected === false || $affected < 1) {
            $database->rollBack();
            j(400, '新增失败', []);
        }

        $newIdRow = $database->query("SELECT LAST_INSERT_ID() AS id");
        $database->commit();

        j(200, '新增成功', [
            'permissions' => $permissions,
            'id' => $newIdRow && isset($newIdRow[0]['id']) ? intval($newIdRow[0]['id']) : null
        ]);
    }

    if ($action === 'batch_create') {
        // ✅ 批量新增：POST items = JSON 数组
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') j(405, '请使用 POST 请求', []);

        $itemsJson = $_POST['items'] ?? '';
        $items = json_decode($itemsJson, true);

        if (!is_array($items)) {
            j(422, 'items 格式错误，需要 JSON 数组', []);
        }

        if (count($items) === 0) {
            j(422, '批量新增内容不能为空', []);
        }

        if (count($items) > 200) {
            j(422, '单次最多批量新增 200 条', []);
        }

        $cleanItems = [];
        $seenDeviceIds = [];
        $errors = [];
        $skipped = [];

        foreach ($items as $idx => $item) {
            $lineNo = $idx + 1;

            if (!is_array($item)) {
                $errors[] = "第 {$lineNo} 行格式错误";
                continue;
            }

            $device_id = trim($item['device_id'] ?? '');
            $playing_stream_id = trim($item['playing_stream_id'] ?? '');
            $room_id = trim($item['room_id'] ?? '');
            $rtc_user_id = trim($item['rtc_user_id'] ?? '');

            $parsedNo = parseDeviceNoFromRtcUserId($rtc_user_id);
            if ($parsedNo !== '') {
                if ($device_id === '') $device_id = $parsedNo;
                if ($playing_stream_id === '') $playing_stream_id = $parsedNo;
                if ($room_id === '') $room_id = $parsedNo;
            }

            if ($device_id === '' || $playing_stream_id === '' || $room_id === '') {
                $errors[] = "第 {$lineNo} 行缺少 device_id / playing_stream_id / room_id，rtc_user_id 示例：user023968912_80037";
                continue;
            }

            if (isset($seenDeviceIds[$device_id])) {
                $skipped[] = [
                    'line' => $lineNo,
                    'device_id' => $device_id,
                    'reason' => '本次提交内 device_id 重复'
                ];
                continue;
            }

            $seenDeviceIds[$device_id] = true;

            $cleanItems[] = [
                'line' => $lineNo,
                'device_id' => $device_id,
                'playing_stream_id' => $playing_stream_id,
                'room_id' => $room_id,
                'rtc_user_id' => ($rtc_user_id === '') ? null : $rtc_user_id,
            ];
        }

        if (count($cleanItems) === 0) {
            j(422, '没有可新增的数据', [
                'errors' => $errors,
                'skipped' => $skipped
            ]);
        }

        $database->beginTransaction();

        $successCount = 0;
        $successRows = [];

        foreach ($cleanItems as $item) {
            // 避免 device_id 重复
            $check = $database->query("SELECT id FROM device_information WHERE device_id = ? LIMIT 1", [$item['device_id']]);
            if ($check && count($check) > 0) {
                $skipped[] = [
                    'line' => $item['line'],
                    'device_id' => $item['device_id'],
                    'reason' => 'device_id 已存在'
                ];
                continue;
            }

            $ins = "INSERT INTO device_information (device_id, playing_stream_id, room_id, rtc_user_id)
                    VALUES (?, ?, ?, ?)";
            $affected = $database->query($ins, [
                $item['device_id'],
                $item['playing_stream_id'],
                $item['room_id'],
                $item['rtc_user_id']
            ], true);

            if ($affected === false || $affected < 1) {
                $database->rollBack();
                j(400, '批量新增失败，已回滚', [
                    'failed_line' => $item['line'],
                    'device_id' => $item['device_id']
                ]);
            }

            $successCount++;
            $successRows[] = $item['device_id'];
        }

        $database->commit();

        j(200, '批量新增完成', [
            'permissions' => $permissions,
            'success_count' => $successCount,
            'success_device_ids' => $successRows,
            'skip_count' => count($skipped),
            'skipped' => $skipped,
            'error_count' => count($errors),
            'errors' => $errors
        ]);
    }

    if ($action === 'update') {
        // ✅ 修改：POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') j(405, '请使用 POST 请求', []);

        $id = trim($_POST['id'] ?? '');
        $device_id = trim($_POST['device_id'] ?? '');
        $playing_stream_id = trim($_POST['playing_stream_id'] ?? '');
        $room_id = trim($_POST['room_id'] ?? '');
        $rtc_user_id = trim($_POST['rtc_user_id'] ?? '');

        if ($id === '') j(422, '参数缺失：id 必填', []);

        // ✅ 兜底：rtc_user_id=user023968912_80037 时，可自动补齐空字段
        $parsedNo = parseDeviceNoFromRtcUserId($rtc_user_id);
        if ($parsedNo !== '') {
            if ($device_id === '') $device_id = $parsedNo;
            if ($playing_stream_id === '') $playing_stream_id = $parsedNo;
            if ($room_id === '') $room_id = $parsedNo;
        }

        if ($device_id === '' || $playing_stream_id === '' || $room_id === '') {
            j(422, '参数缺失：device_id / playing_stream_id / room_id 必填', []);
        }

        $rtc_user_id = ($rtc_user_id === '') ? null : $rtc_user_id;

        // 确认记录存在
        $exists = $database->query("SELECT id FROM device_information WHERE id = ? LIMIT 1", [$id]);
        if (!$exists || count($exists) === 0) j(404, '记录不存在', []);

        // 避免改成重复 device_id
        $dup = $database->query("SELECT id FROM device_information WHERE device_id = ? AND id <> ? LIMIT 1", [$device_id, $id]);
        if ($dup && count($dup) > 0) {
            j(409, 'device_id 已被其他记录占用', []);
        }

        $database->beginTransaction();

        $upd = "UPDATE device_information
                SET device_id = ?, playing_stream_id = ?, room_id = ?, rtc_user_id = ?
                WHERE id = ?";
        $affected = $database->query($upd, [$device_id, $playing_stream_id, $room_id, $rtc_user_id, $id], true);

        if ($affected === false) {
            $database->rollBack();
            j(400, '修改失败', []);
        }

        $database->commit();

        j(200, '修改成功', [
            'permissions' => $permissions,
            'affected_rows' => intval($affected)
        ]);
    }

    if ($action === 'delete') {
        // 🚫 明确禁止删除
        j(403, '禁止删除（只允许新增/修改）', [
            'permissions' => $permissions
        ]);
    }

    j(400, '未知 action', [
        'allow' => ['list', 'create', 'batch_create', 'update']
    ]);

} catch (Exception $e) {
    // 避免把敏感信息直接返回给前端
    j(500, '服务器异常', []);
}
