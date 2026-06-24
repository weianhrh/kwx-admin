<?php
require_once 'Database.php';
require_once 'RedisHelper.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Taipei');

function ok($data = [], $msg = 'ok'){
    echo json_encode(['code'=>200,'msg'=>$msg,'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}
function bad($msg, $code = 500){
    echo json_encode(['code'=>$code,'msg'=>$msg,'data'=>[]], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1) 登录校验
$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) bad('用户未登录或会话已过期', 1001);

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) bad('用户未登录或无权访问', 1001);

$role_id  = $user['role_id'];
$venue_id = $user['venue_id'] ?? null;

// 2) 连接 Redis & 选库（比如 DB11）
$redisHelper = new RedisHelper();
$redisHelper->connect('127.0.0.1', 6379);
$redisHelper->selectDb(11);

$action = $_GET['action'] ?? 'list';

// 只允许的 key 字符
function check_key_safe($key){
    if (!preg_match('/^[A-Za-z0-9:_-]+$/', $key)) {
        bad('key 只能包含数字、字母、: _ - 这些字符');
    }
}

// 显示给前端：\uXXXX → 中文
function decode_value_for_display($raw){
    if ($raw === null) return null;
    return RedisHelper::unicodeToUtf8($raw);
}

// 存 Redis：中文 → \uXXXX
function encode_value_for_redis($txt){
    if ($txt === null) return null;
    return RedisHelper::utf8ToUnicode($txt);
}

// 生成一个新的 notification key（后缀自动生成）
function generate_notification_key() {
    // 例子：notification:nt_20251115153001_ab12cd
    $suffix = 'nt_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    $key = 'notification:' . $suffix;
    return $key;
}

// 3) 不同动作
switch ($action) {
    // 3.1 列表
    case 'list':
        $pattern = $_GET['pattern'] ?? 'notification:*';
        $keys = $redisHelper->scan($pattern, 200);
        $rows = [];
        foreach ($keys as $k) {
            $raw = $redisHelper->get($k);
            $rows[] = [
                'key'   => $k,
                'value' => decode_value_for_display($raw),
                'ttl'   => $redisHelper->ttl($k),
            ];
        }
        ok($rows);
        break;

    // 3.2 获取单个 key
    case 'get':
        $key = $_GET['key'] ?? '';
        if (!$key) bad('缺少 key');
        check_key_safe($key);

        if (!$redisHelper->exists($key)) {
            bad('key 不存在', 404);
        }
        $raw = $redisHelper->get($key);
        ok([
            'key'   => $key,
            'value' => decode_value_for_display($raw),
            'ttl'   => $redisHelper->ttl($key),
        ]);
        break;

    // 3.3 新增 / 修改：现在只允许编辑内容，key 后缀自动生成或使用原有 key
    case 'save':
        // POST: key(可选，编辑时用), value(带中文的 JSON 字符串)
        $key   = $_POST['key']   ?? '';
        $value = $_POST['value'] ?? '';

        if ($value === '') bad('缺少 value');

        // 校验 JSON 格式
        $test = json_decode($value, true);
        if ($test === null && json_last_error() !== JSON_ERROR_NONE) {
            bad('value 不是合法 JSON');
        }

        // 如果传了 key，优先更新该 key（编辑模式）
        if ($key !== '') {
            check_key_safe($key);

            if ($redisHelper->exists($key)) {
                $valueForRedis = encode_value_for_redis($value);
                // TTL 默认永久：不传 expire
                $redisHelper->save($key, $valueForRedis);
                ok(['key'=>$key], '保存成功');
            }

            // 如果传了 key 但不存在，就当作新建
        }

        // 新建：后端自动生成 notification:xxxxxx
        $newKey = generate_notification_key();
        $valueForRedis = encode_value_for_redis($value);
        $redisHelper->save($newKey, $valueForRedis);
        ok(['key'=>$newKey], '保存成功（新建）');
        break;

    // 3.4 删除
    case 'delete':
        $key = $_POST['key'] ?? '';
        if (!$key) bad('缺少 key');
        check_key_safe($key);

        if (!$redisHelper->exists($key)) {
            bad('key 不存在', 404);
        }
        $redisHelper->delete($key);
        ok([], '删除成功');
        break;

    default:
        bad('未知 action');
}
