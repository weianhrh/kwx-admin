<?php
require_once '../Database.php';

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $database->getUserBySessionToken($session_token);

if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

$venue_id = $user['venue_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $querySql = "SELECT account_name, withdrawal_account, remarks, account_type, bank_name FROM venue_funds WHERE venue_id = ?";
    $stmt = $database->getConnection()->prepare($querySql);
    $stmt->bind_param("i", $venue_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['code' => 0, 'msg' => '已绑定', 'data' => $result->fetch_assoc()]);
    } else {
        echo json_encode(['code' => 0, 'msg' => '未绑定', 'data' => null]);
    }

    $stmt->close();
    $database->close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 检查是否已绑定
    $checkSql = "SELECT 1 FROM venue_funds WHERE venue_id = ?";
    $checkStmt = $database->getConnection()->prepare($checkSql);
    $checkStmt->bind_param("i", $venue_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows > 0) {
        echo json_encode(['code' => 1002, 'msg' => '账号已绑定，不能重复绑定']);
    } else {
        $account_name = $_POST['account_name'] ?? '';
        $withdrawal_account = $_POST['withdrawal_account'] ?? '';
        $remarks = $_POST['remarks'] ?? '';
        $account_type = $_POST['account_type'] ?? '银行卡';
        $bank_name = $_POST['bank_name'] ?? '';
        $bank_number = $_POST['bank_number'] ?? '';

        $insertSql = "INSERT INTO venue_funds (venue_id, account_name, withdrawal_account, remarks, account_type, bank_name, bank_number) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insertStmt = $database->getConnection()->prepare($insertSql);
        $insertStmt->bind_param("issssss", $venue_id, $account_name, $withdrawal_account, $remarks, $account_type, $bank_name, $bank_number);

        if ($insertStmt->execute()) {
            echo json_encode(['code' => 0, 'msg' => '绑定成功']);
        } else {
            echo json_encode(['code' => 1003, 'msg' => '绑定失败，请稍后重试']);
        }

        $insertStmt->close();
    }

    $checkStmt->close();
    $database->close();
}
?>
