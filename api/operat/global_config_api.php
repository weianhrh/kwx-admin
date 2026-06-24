<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

function gc_val($input, $key, $default = '')
{
    return isset($input[$key]) ? $input[$key] : $default;
}

function gc_int($input, $key, $default = 0)
{
    return intval($input[$key] ?? $default);
}

$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
        $configs = $database->query("SELECT * FROM global_config ORDER BY id ASC");
        echo json_encode(['code' => 0, 'msg' => '查询成功', 'data' => $configs], JSON_UNESCAPED_UNICODE);
        break;

    case 'update':
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            echo json_encode(['code' => 400, 'msg' => '请求数据格式错误'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['code' => 400, 'msg' => 'ID错误'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $sql = "UPDATE global_config SET 
            alipay_enabled = ?, 
            wechatpay_enabled = ?, 
            douyin_login_enabled = ?, 
            kuaishou_login_enabled = ?, 
            appleid_login_enabled = ?, 
            wechat_login_enabled = ?, 

            app_domain = ?, 
            control_port = ?, 
            control_domain = ?, 
            video_stream_domain = ?,

            latest_version = ?,
            ios_latest_version = ?,
            ios_url = ?,
            apk_url = ?,
            version_name = ?,
            ios_version_name = ?,
            announcement_id = ?,
            announcement_info = ?,
            is_updated = ?,
            ios_is_updated = ?,

            updated_at = NOW()
            WHERE id = ?";

        $params = [
            gc_int($input, 'alipay_enabled'),
            gc_int($input, 'wechatpay_enabled'),
            gc_int($input, 'douyin_login_enabled'),
            gc_int($input, 'kuaishou_login_enabled'),
            gc_int($input, 'appleid_login_enabled'),
            gc_int($input, 'wechat_login_enabled'),

            gc_val($input, 'app_domain'),
            gc_val($input, 'control_port'),
            gc_val($input, 'control_domain'),
            gc_val($input, 'video_stream_domain'),

            gc_val($input, 'latest_version'),
            gc_val($input, 'ios_latest_version'),
            gc_val($input, 'ios_url'),
            gc_val($input, 'apk_url'),
            gc_val($input, 'version_name'),
            gc_val($input, 'ios_version_name'),
            gc_val($input, 'announcement_id'),
            gc_val($input, 'announcement_info'),
            gc_int($input, 'is_updated'),
            gc_int($input, 'ios_is_updated'),

            $id
        ];

        $result = $database->query($sql, $params, true);

        echo json_encode([
            'code' => 0,
            'msg' => '更新成功',
            'affected' => $result
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'add':
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            echo json_encode(['code' => 400, 'msg' => '请求数据格式错误'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $sql = "INSERT INTO global_config (
            alipay_enabled,
            wechatpay_enabled,
            douyin_login_enabled,
            kuaishou_login_enabled,
            appleid_login_enabled,
            wechat_login_enabled,

            app_domain,
            control_port,
            control_domain,
            video_stream_domain,

            latest_version,
            ios_latest_version,
            ios_url,
            apk_url,
            version_name,
            ios_version_name,
            announcement_id,
            announcement_info,
            is_updated,
            ios_is_updated,

            created_at,
            updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            NOW(), NOW()
        )";

        $params = [
            gc_int($input, 'alipay_enabled'),
            gc_int($input, 'wechatpay_enabled'),
            gc_int($input, 'douyin_login_enabled'),
            gc_int($input, 'kuaishou_login_enabled'),
            gc_int($input, 'appleid_login_enabled'),
            gc_int($input, 'wechat_login_enabled'),

            gc_val($input, 'app_domain'),
            gc_val($input, 'control_port'),
            gc_val($input, 'control_domain'),
            gc_val($input, 'video_stream_domain'),

            gc_val($input, 'latest_version'),
            gc_val($input, 'ios_latest_version'),
            gc_val($input, 'ios_url'),
            gc_val($input, 'apk_url'),
            gc_val($input, 'version_name'),
            gc_val($input, 'ios_version_name'),
            gc_val($input, 'announcement_id'),
            gc_val($input, 'announcement_info'),
            gc_int($input, 'is_updated'),
            gc_int($input, 'ios_is_updated')
        ];

        $result = $database->query($sql, $params, true);
        $insertId = $database->getConnection()->insert_id;

        echo json_encode([
            'code' => 0,
            'msg' => '添加成功',
            'insert_id' => $insertId
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'delete':
        $id = intval($_GET['id'] ?? 0);

        if ($id === 0) {
            echo json_encode(['code' => 400, 'msg' => 'ID错误'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $sql = "DELETE FROM global_config WHERE id = ?";
        $result = $database->query($sql, [$id], true);

        echo json_encode([
            'code' => 0,
            'msg' => '删除成功',
            'affected' => $result
        ], JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['code' => 404, 'msg' => '未知操作'], JSON_UNESCAPED_UNICODE);
        break;
}