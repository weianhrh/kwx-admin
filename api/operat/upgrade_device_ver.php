<?php
require '../CameraUpgradeService.php';

$rawInput = $_POST['dev_ids'] ?? '';
$devIds = parseDevIds($rawInput);

if (empty($devIds)) {
    exit(json_encode(['error' => 'DevId 不能为空']));
}

$service = new CameraUpgradeService();

// 获取状态
// $result = $service->getStatus($devIds);

// 或者执行升级
$result = $service->upgrade($devIds);

header('Content-Type: application/json');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// 解析函数
function parseDevIds(string $input): array
{
    $lines = explode("\n", $input);
    $devIds = array_map('trim', $lines);
    $devIds = array_filter($devIds);
    $devIds = array_unique($devIds);
    return array_values($devIds);
}


?>