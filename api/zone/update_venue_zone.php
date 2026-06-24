<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');

$database = new Database();

function json_out($code, $msg, $extra = []) {
    echo json_encode(array_merge([
        'code' => $code,
        'msg'  => $msg
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(405, '只支持 POST 请求');
}

if (!isset($_POST['venue_id']) || !isset($_POST['zone_id'])) {
    json_out(2, '参数错误：缺少 venue_id 或 zone_id');
}

$venue_id = intval($_POST['venue_id']);
$zone_id  = intval($_POST['zone_id']);

if ($venue_id <= 0) {
    json_out(2, '参数错误：venue_id 不正确');
}

if ($zone_id < 0) {
    json_out(2, '参数错误：zone_id 不正确');
}

try {
    // zone_id = 0 代表未分区；大于 0 时校验专区是否存在，避免手输无效数字。
    if ($zone_id > 0) {
        $checkStmt = $database->prepare("SELECT zone_id FROM zones WHERE zone_id = ? LIMIT 1");
        $checkStmt->bind_param("i", $zone_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if (!$checkResult || $checkResult->num_rows === 0) {
            json_out(3, '目标专区不存在');
        }
    }

    $stmt = $database->prepare("UPDATE venues SET zone_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $zone_id, $venue_id);

    if ($stmt->execute()) {
        json_out(0, '修改成功', [
            'data' => [
                'venue_id' => $venue_id,
                'zone_id'  => $zone_id
            ]
        ]);
    }

    json_out(1, '更新失败');
} catch (Throwable $e) {
    json_out(500, '服务器异常：' . $e->getMessage());
}
