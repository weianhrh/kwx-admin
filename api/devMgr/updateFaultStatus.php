<?php
require_once '../Database.php'; 

$database = new Database();

// 获取参数
$serialNumber = $_GET['serial_number'] ?? null;
$status = $_GET['status'] ?? null;

if (!$serialNumber || !isset($status)) {
    echo json_encode(['code' => 1, 'msg' => '缺少必要参数']);
    exit;
}

$conn = $database->getConnection();

// 更新处理状态和处理时间
if ($status == 1) {
    $resolved_at = date('Y-m-d H:i:s');
    $sql = "UPDATE FaultRecords SET resolved_status = ?, resolved_at = ? WHERE serial_number = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $status, $resolved_at, $serialNumber);
} else {
    $sql = "UPDATE FaultRecords SET resolved_status = ?, resolved_at = NULL WHERE serial_number = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $status, $serialNumber);
}

if ($stmt->execute()) {
    $response = array(
        'code' => 0,
        'msg' => '更新成功'
    );
} else {
    $response = array(
        'code' => 1,
        'msg' => '更新失败: ' . $conn->error
    );
}

$stmt->close();
$database->close();

header('Content-Type: application/json');
echo json_encode($response);
?>