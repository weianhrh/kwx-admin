<?php
require_once "../Database.php";
$db = new Database();
$data = json_decode(file_get_contents('php://input'), true);
$list = $data['data'] ?? [];

if (empty($list)) {
    echo json_encode(['code' => 1, 'msg' => '无数据']);
    exit;
}

$stmt = $db->prepare("UPDATE vehicles SET photo_url = ? WHERE serial_number = ?");

foreach ($list as $item) {
    $url = trim($item['photo_url'] ?? '');
    $serial = trim($item['serial_number'] ?? '');
    if ($url && $serial) {
        $stmt->bind_param("ss", $url, $serial);
        $stmt->execute();
    }
}

$stmt->close();
$db->close();

echo json_encode(['code' => 0, 'msg' => '更新成功']);
