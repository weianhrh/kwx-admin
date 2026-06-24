<?php
require_once '../Database.php';
// open.rcwulian.cn/single/api/devMgr/claw_machine_record_add.php
header('Content-Type: application/json; charset=utf-8');

/**
 * 日志记录
 */
function logMessage_log($message) {
    $logFile = __DIR__ . '/claw_machine_record_add_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

/**
 * 统一 JSON 输出
 */
function json_out($code, $msg, $data = []) {
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $database = new Database();

    // =========================
    // 1. 会话校验
    // =========================
    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        json_out(1001, '用户未登录或会话已过期', []);
    }

    $user = $database->getUserBySessionToken($session_token);
    if (!$user || empty($user['role_id'])) {
        json_out(1001, '用户未登录或无权访问', []);
    }

    // =========================
    // 2. 只允许 POST
    // =========================
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(1002, '请求方式错误，仅支持 POST', []);
    }

    // =========================
    // 3. 兼容 JSON 和表单提交
    // =========================
    $input = $_POST;

    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $input = array_merge($input, $json);
        }
    }

    // =========================
    // 4. 获取参数
    // =========================
    $serial_number         = trim((string)($input['serial_number'] ?? ''));
    $product_name          = trim((string)($input['product_name'] ?? ''));
    $billing_plan          = isset($input['billing_plan']) ? (int)$input['billing_plan'] : 3;
    $product_image         = trim((string)($input['product_image'] ?? ''));
    $product_introduction  = trim((string)($input['product_introduction'] ?? ''));

    logMessage_log("收到新增请求：admin_uid=" . ($user['uid'] ?? 'unknown')
        . "，serial_number={$serial_number}，product_name={$product_name}，billing_plan={$billing_plan}");

    // =========================
    // 5. 参数校验
    // =========================
    if ($serial_number === '') {
        json_out(1003, 'serial_number 不能为空', []);
    }

    if ($product_name === '') {
        json_out(1004, 'product_name 不能为空', []);
    }

    if ($billing_plan <= 0) {
        $billing_plan = 3;
    }

    // 如果你希望图片和介绍必填，就打开下面这两段
    /*
    if ($product_image === '') {
        json_out(1005, 'product_image 不能为空', []);
    }

    if ($product_introduction === '') {
        json_out(1006, 'product_introduction 不能为空', []);
    }
    */

    // =========================
    // 6. 检查 serial_number 是否已存在
    // =========================
    $checkStmt = $database->prepare("SELECT id FROM claw_machine_records WHERE serial_number = ? LIMIT 1");
    $checkStmt->bind_param("s", $serial_number);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult && $checkResult->num_rows > 0) {
        $checkStmt->close();
        json_out(1007, '该 serial_number 已存在，不能重复新增', []);
    }
    $checkStmt->close();

    // =========================
    // 7. 执行新增
    // =========================
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
        logMessage_log("新增失败：" . $stmt->error);
        $stmt->close();
        json_out(1008, '新增失败：' . $stmt->error, []);
    }

    $insert_id = $stmt->insert_id;
    $stmt->close();

    logMessage_log("新增成功，id={$insert_id}，serial_number={$serial_number}");

    // =========================
    // 8. 返回新增后的数据
    // =========================
    $detailStmt = $database->prepare("SELECT * FROM claw_machine_records WHERE id = ? LIMIT 1");
    $detailStmt->bind_param("i", $insert_id);
    $detailStmt->execute();
    $detailResult = $detailStmt->get_result();
    $row = $detailResult ? $detailResult->fetch_assoc() : null;
    $detailStmt->close();

    json_out(200, '新增成功', [
        'id' => $insert_id,
        'record' => $row
    ]);

} catch (Exception $e) {
    logMessage_log("异常：" . $e->getMessage());
    json_out(500, '服务器异常：' . $e->getMessage(), []);
}