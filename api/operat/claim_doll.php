<?php
// claim_doll.php
header('Content-Type: text/plain; charset=utf-8');

require_once '../Database.php';

try {
    // 允许 POST（设备端）和 GET（手工调试）
    $doll_id = isset($_POST['doll_id']) ? $_POST['doll_id'] : ($_GET['doll_id'] ?? '');
    $mac     = isset($_POST['mac'])     ? $_POST['mac']     : ($_GET['mac'] ?? '');

    $doll_id = strtoupper(trim($doll_id));   // 统一大写
    $mac     = trim($mac);

    if ($doll_id === '') {
        http_response_code(400);
        echo "ERR"; // 参数缺失
        exit;
    }

    // 可选：简单校验 EPC 只含 0-9A-F
    if (!preg_match('/^[0-9A-F]+$/', $doll_id)) {
        http_response_code(400);
        echo "ERR";
        exit;
    }

    $db = new Database();

    // 原子更新：只有当前是 1 才能改为 2
    // 受影响行数 >0 说明这次从 1 -> 2 成功；=0 说明不存在或已是 2
    $sql = "UPDATE dolls SET report_status = 2 WHERE doll_id = ? AND report_status = 1 LIMIT 1";
    $affected = $db->query($sql, [$doll_id], /*isUpdate*/ true);

    // 记录到文件，便于排查（可选）
    $db->logToFile("claim_doll: doll_id={$doll_id}, mac={$mac}, affected={$affected}");

    if ($affected === false) {
        http_response_code(500);
        echo "ERR";            // 数据库错误
    } elseif ($affected > 0) {
        echo "OK";             // 本次占用成功（1->2）
    } else {
        echo "NO";             // 已经是 2 或不存在
    }
} catch (Throwable $e) {
    // 异常兜底
    error_log("claim_doll exception: " . $e->getMessage());
    http_response_code(500);
    echo "ERR";
}
