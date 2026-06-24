<?php

require '../CameraUpgradeService.php';

$rawInput = $_POST['dev_ids'] ?? '';
$devIds = parseDevIds($rawInput);

$service = new CameraUpgradeService();
$result = $service->getVersion($devIds);

header('Content-Type: application/json');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// 解析函数同前
function parseDevIds(string $input): array {
    $lines = explode("\n", $input);
    $devIds = array_map('trim', $lines);
    $devIds = array_filter($devIds);
    $devIds = array_unique($devIds);
    return array_values($devIds);
}
?>