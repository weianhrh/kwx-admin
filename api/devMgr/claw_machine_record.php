<?php
// open.kwxapp.cn/single/api/devMgr/claw_machine_record.php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

function json_out($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function logMessage_log($message) {
    $logFile = __DIR__ . '/claw_machine_record_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// 创建数据库连接
$database = new Database();

// 从 Cookie 获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    json_out(1001, '用户未登录或会话已过期', []);
}

// 验证 token 并获取用户
$user = $database->getUserBySessionToken($session_token);
if (!$user || empty($user['role_id'])) {
    json_out(1001, '用户未登录或无权访问', []);
}

// 兼容 application/json 请求体
$raw = file_get_contents('php://input');
if ($raw) {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $_REQUEST = array_merge($_REQUEST, $json);
    }
}

// 获取参数（兼容 GET / POST / JSON）
$action = trim($_REQUEST['action'] ?? '');
$serial_number = strtoupper(trim($_REQUEST['serial_number'] ?? ''));

if ($serial_number === '') {
    json_out(1002, '缺少参数 serial_number', []);
}

// =============== 1) 默认：查询 ===============
if ($action === '' || $action === 'get') {
    $sql = "SELECT id, serial_number, product_name, billing_plan, product_image, product_introduction, created_at, updated_at
            FROM claw_machine_records
            WHERE serial_number = ?
            LIMIT 1";
    $rows = $database->query($sql, [$serial_number]);

    if ($rows === false) {
        json_out(2001, '查询失败', []);
    }

    if (empty($rows)) {
        json_out(0, 'ok', null);
    }

    json_out(0, 'ok', $rows[0]);
}

// =============== 2) update/save：有则更新，无则新增 ===============
if ($action === 'update' || $action === 'save') {

    $product_name = trim($_REQUEST['product_name'] ?? '');
    $billing_plan_raw = trim($_REQUEST['billing_plan'] ?? '');
    $product_image = trim($_REQUEST['product_image'] ?? '');
    $product_introduction = trim($_REQUEST['product_introduction'] ?? '');

    // billing_plan 默认值
    $billing_plan = ($billing_plan_raw === '' || !is_numeric($billing_plan_raw)) ? 3 : (int)$billing_plan_raw;
    if ($billing_plan <= 0) {
        $billing_plan = 3;
    }

    // 至少要有一个字段参与保存
    if ($product_name === '' && $billing_plan_raw === '' && $product_image === '' && $product_introduction === '') {
        json_out(1003, '没有可保存的字段', []);
    }

    // 先查是否存在
    $checkSql = "SELECT id, serial_number, product_name, billing_plan, product_image, product_introduction, created_at, updated_at
                 FROM claw_machine_records
                 WHERE serial_number = ?
                 LIMIT 1";
    $exists = $database->query($checkSql, [$serial_number]);

    if ($exists === false) {
        json_out(2001, '查询失败', []);
    }

    // ---------------------------------
    // A. 存在：更新
    // ---------------------------------
    if (!empty($exists)) {
        $sets = [];
        $params = [];

        if ($product_name !== '') {
            $sets[] = "product_name = ?";
            $params[] = $product_name;
        }
        if ($billing_plan_raw !== '') {
            $sets[] = "billing_plan = ?";
            $params[] = $billing_plan;
        }
        if ($product_image !== '') {
            $sets[] = "product_image = ?";
            $params[] = $product_image;
        }
        if ($product_introduction !== '') {
            $sets[] = "product_introduction = ?";
            $params[] = $product_introduction;
        }

        if (empty($sets)) {
            // 理论上不会走到这里，因为上面已经校验过
            json_out(1004, '没有可更新的字段', []);
        }

        $sql = "UPDATE claw_machine_records 
                SET " . implode(", ", $sets) . "
                WHERE serial_number = ?
                LIMIT 1";
        $params[] = $serial_number;

        $affected = $database->query($sql, $params, true);
        if ($affected === false) {
            logMessage_log("更新失败 serial_number={$serial_number}");
            json_out(2002, '更新失败', []);
        }

        $getSql = "SELECT id, serial_number, product_name, billing_plan, product_image, product_introduction, created_at, updated_at
                   FROM claw_machine_records
                   WHERE serial_number = ?
                   LIMIT 1";
        $rows = $database->query($getSql, [$serial_number]);

        json_out(0, 'ok', [
            'mode' => 'update',
            'affected_rows' => $affected,
            'record' => $rows ? $rows[0] : null
        ]);
    }

    // ---------------------------------
    // B. 不存在：新增
    // ---------------------------------

    // 新增时 product_name 最好必填
    if ($product_name === '') {
        // json_out(1005, '记录不存在，新增时 product_name 不能为空', []);
        $product_name === 'https://app.kwxapp.cn/pic/1.png';
    }

    // 你的表里 product_image / product_introduction 是 NOT NULL
    // 所以这里给空字符串也可以，避免 insert 失败
    if ($product_image === '') {
        $product_image = '';
    }
    if ($product_introduction === '') {
        $product_introduction = '';
    }

    $insertSql = "INSERT INTO claw_machine_records
                    (serial_number, product_name, billing_plan, product_image, product_introduction)
                  VALUES (?, ?, ?, ?, ?)";

    $stmt = $database->prepare($insertSql);
    $stmt->bind_param(
        "ssiss",
        $serial_number,
        $product_name,
        $billing_plan,
        $product_image,
        $product_introduction
    );

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        logMessage_log("新增失败 serial_number={$serial_number} error={$err}");
        json_out(2003, '新增失败：' . $err, []);
    }

    $insertId = $stmt->insert_id;
    $stmt->close();

    $getSql = "SELECT id, serial_number, product_name, billing_plan, product_image, product_introduction, created_at, updated_at
               FROM claw_machine_records
               WHERE id = ?
               LIMIT 1";
    $rows = $database->query($getSql, [$insertId]);

    logMessage_log("新增成功 serial_number={$serial_number} id={$insertId}");

    json_out(0, 'ok', [
        'mode' => 'insert',
        'affected_rows' => 1,
        'record' => $rows ? $rows[0] : null
    ]);
}

json_out(1006, '不支持的 action', []);