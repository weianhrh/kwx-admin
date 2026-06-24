<?php
require_once '../Database.php';
header('Content-Type: application/json; charset=utf-8');

$db = new Database();

function json_out($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_json_body() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

// ========== 登录校验 ==========
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) json_out(1001, '用户未登录或会话已过期', []);

$user = $db->getUserBySessionToken($session_token);
if (!$user || !isset($user['role_id'])) json_out(1001, '用户未登录或无权访问', []);

$role_id = (int)$user['role_id'];
$user_venue_id = isset($user['venue_id']) ? (int)$user['venue_id'] : 0;

$table = 'venue_funds';

// ========== 权限下 venue_id ==========
function resolve_venue_id($role_id, $user_venue_id) {
    $gid = isset($_GET['venue_id']) ? (int)$_GET['venue_id'] : 0;
    if ($role_id === 1) {
        return $gid > 0 ? $gid : 0; // 0 代表全场地
    }
    return $user_venue_id; // 非管理员强制自己的
}

// ========== action 路由 ==========
$action = $_GET['action'] ?? '';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($action === '') {
    if ($method === 'GET') $action = 'list';
    else if ($method === 'POST') $action = 'create';
}

$body = ($_POST ?: get_json_body());

// ===================== 1) 列表 list =====================
if ($action === 'list') {
    $venue_id = resolve_venue_id($role_id, $user_venue_id);

    $page = max(1, (int)($_GET['page'] ?? 1));
    $page_size = min(200, max(1, (int)($_GET['page_size'] ?? 20)));
    $offset = ($page - 1) * $page_size;

    $kw = trim((string)($_GET['kw'] ?? ''));

    $where = [];
    $params = [];

    if ($venue_id > 0) {
        $where[] = "venue_id = ?";
        $params[] = (string)$venue_id;
    }

    if ($kw !== '') {
        $where[] = "(withdrawal_account LIKE ? OR account_type LIKE ? OR account_name LIKE ? OR bank_number LIKE ? OR bank_name LIKE ? OR remarks LIKE ?)";
        $like = "%{$kw}%";
        $params = array_merge($params, [$like,$like,$like,$like,$like,$like]);
    }

    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

    // 总数
    $countRows = $db->query("SELECT COUNT(*) AS c FROM {$table} {$whereSql}", $params);
    if ($countRows === false) json_out(400, '查询失败', []);
    $total = (int)($countRows[0]['c'] ?? 0);

    // 列表
    $sql = "SELECT id, venue_id, account_balance, withdrawal_account, account_type, account_name,
                   created_at, updated_at, remarks, bank_number, bank_name
            FROM {$table}
            {$whereSql}
            ORDER BY id DESC
            LIMIT {$page_size} OFFSET {$offset}";

    $rows = $db->query($sql, $params);
    if ($rows === false) json_out(400, '数据加载失败', []);

    json_out(200, 'ok', [
        'list' => $rows,
        'page' => $page,
        'page_size' => $page_size,
        'total' => $total
    ]);
}

// ===================== 2) 单条 get =====================
if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) json_out(422, '缺少 id', []);

    $venue_id = resolve_venue_id($role_id, $user_venue_id);

    $sql = "SELECT id, venue_id, account_balance, withdrawal_account, account_type, account_name,
                   created_at, updated_at, remarks, bank_number, bank_name
            FROM {$table}
            WHERE id = ?";

    $params = [(string)$id];

    // 非管理员必须加 venue_id 限制；管理员如果指定了 venue_id 也加限制
    if ($role_id !== 1) {
        $sql .= " AND venue_id = ?";
        $params[] = (string)$venue_id;
    } else if ($venue_id > 0) {
        $sql .= " AND venue_id = ?";
        $params[] = (string)$venue_id;
    }

    $sql .= " LIMIT 1";

    $rows = $db->query($sql, $params);
    if ($rows === false) json_out(400, '查询失败', []);
    if (!$rows) json_out(404, '记录不存在或无权限', []);

    json_out(200, 'ok', $rows[0]);
}

// ===================== 3) 新增 create =====================
if ($action === 'create') {
    // venue_id：管理员必须传；非管理员强制自己的
    $venue_id = 0;
    if ($role_id === 1) {
        $venue_id = (int)($body['venue_id'] ?? 0);
        if ($venue_id <= 0) json_out(422, '管理员创建必须提供 venue_id', []);
    } else {
        $venue_id = $user_venue_id;
        if ($venue_id <= 0) json_out(403, '账号未绑定场地，无法操作', []);
    }

    $account_balance = (string)($body['account_balance'] ?? '0.00');
    $withdrawal_account = trim((string)($body['withdrawal_account'] ?? ''));
    $account_type = trim((string)($body['account_type'] ?? ''));
    $account_name = trim((string)($body['account_name'] ?? ''));
    $remarks = (string)($body['remarks'] ?? '');
    $bank_number = trim((string)($body['bank_number'] ?? ''));
    $bank_name = trim((string)($body['bank_name'] ?? ''));

    if ($withdrawal_account === '' || $account_type === '' || $account_name === '') {
        json_out(422, 'withdrawal_account / account_type / account_name 为必填', []);
    }

    $sql = "INSERT INTO {$table}
            (venue_id, account_balance, withdrawal_account, account_type, account_name, created_at, updated_at, remarks, bank_number, bank_name)
            VALUES
            (?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?)";

    $params = [
        (string)$venue_id,
        (string)$account_balance,
        $withdrawal_account,
        $account_type,
        $account_name,
        $remarks,
        $bank_number,
        $bank_name
    ];

    $affected = $db->query($sql, $params, true);
    if ($affected === false) json_out(400, '新增失败', []);

    json_out(200, 'ok', ['venue_id' => $venue_id, 'affected' => $affected]);
}

// ===================== 4) 修改 update =====================
if ($action === 'update') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) json_out(422, '缺少 id', []);

    $venue_id_scope = resolve_venue_id($role_id, $user_venue_id);

    // 先查权限
    $checkSql = "SELECT id, venue_id FROM {$table} WHERE id = ?";
    $checkParams = [(string)$id];

    if ($role_id !== 1) {
        $checkSql .= " AND venue_id = ?";
        $checkParams[] = (string)$venue_id_scope;
    } else if ($venue_id_scope > 0) {
        $checkSql .= " AND venue_id = ?";
        $checkParams[] = (string)$venue_id_scope;
    }

    $exist = $db->query($checkSql . " LIMIT 1", $checkParams);
    if ($exist === false) json_out(400, '查询失败', []);
    if (!$exist) json_out(404, '记录不存在或无权限', []);

    $set = [];
    $params = [];

    $fields = ['account_balance','withdrawal_account','account_type','account_name','remarks','bank_number','bank_name'];
    foreach ($fields as $f) {
        if (array_key_exists($f, $body)) {
            $set[] = "{$f} = ?";
            $params[] = (string)$body[$f];
        }
    }

    // 管理员才允许改 venue_id
    if ($role_id === 1 && array_key_exists('venue_id', $body)) {
        $newVenue = (int)$body['venue_id'];
        if ($newVenue <= 0) json_out(422, 'venue_id 不合法', []);
        $set[] = "venue_id = ?";
        $params[] = (string)$newVenue;
    }

    if (!$set) json_out(422, '没有可更新字段', []);

    $sql = "UPDATE {$table} SET " . implode(", ", $set) . ", updated_at = NOW() WHERE id = ?";
    $params[] = (string)$id;

    // 非管理员：再次限制 venue_id
    if ($role_id !== 1) {
        $sql .= " AND venue_id = ?";
        $params[] = (string)$venue_id_scope;
    }

    $affected = $db->query($sql, $params, true);
    if ($affected === false) json_out(400, '更新失败', []);

    json_out(200, 'ok', ['id' => $id, 'affected' => $affected]);
}

// ===================== 5) 删除 delete =====================
if ($action === 'delete') {
    $id = (int)($body['id'] ?? ($_GET['id'] ?? 0));
    if ($id <= 0) json_out(422, '缺少 id', []);

    $venue_id_scope = resolve_venue_id($role_id, $user_venue_id);

    $sql = "DELETE FROM {$table} WHERE id = ?";
    $params = [(string)$id];

    if ($role_id !== 1) {
        $sql .= " AND venue_id = ?";
        $params[] = (string)$venue_id_scope;
    } else if ($venue_id_scope > 0) {
        // 管理员如果指定 venue_id，就限制一下
        $sql .= " AND venue_id = ?";
        $params[] = (string)$venue_id_scope;
    }

    $affected = $db->query($sql, $params, true);
    if ($affected === false) json_out(400, '删除失败', []);

    json_out(200, 'ok', ['id' => $id, 'affected' => $affected]);
}

json_out(404, '未知 action', []);
