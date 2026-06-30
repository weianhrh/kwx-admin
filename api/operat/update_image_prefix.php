<?php
require_once '../Database.php';

$database = new Database();

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录']);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1002, 'msg' => '无权访问']);
    exit;
}

// 获取前端传的模式
$mode = $_POST['mode'] ?? 'local';

if ($mode === 'local') {
    $from = 'https://ruoche.oss-cn-beijing.aliyuncs.com/app/';
    $to   = 'https://app.kwxapp.cn/app/imgv2/';
} elseif ($mode === 'oss') {
    $from = 'https://app.kwxapp.cn/app/imgv2/';
    $to   = 'https://ruoche.oss-cn-beijing.aliyuncs.com/app/';
} else {
    echo json_encode(['code' => 1003, 'msg' => '无效的模式']);
    exit;
}

// 要更新的表和字段
$tables = [
    // ['table' => 'app_images', 'field' => 'image_url'],
    // ['table' => 'vehicles', 'field' => 'photol_url'],
    // ['table' => 'VenueImages', 'field' => 'image_url'],
    // ['table' => 'venues', 'field' => 'image_url'],
    // ['table' => 'zones', 'field' => 'zone_image'],
];

// 日志记录函数
function logMessage($message) {
    $logFile = __DIR__ . '/operation_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

try {
    $database->beginTransaction();
    logMessage("开始批量切换模式：$mode");

    foreach ($tables as $item) {
        $table = $item['table'];
        $field = $item['field'];

        $sql = "UPDATE `$table` 
                SET `$field` = REPLACE(`$field`, ?, ?)
                WHERE `$field` LIKE ?";

        $likePattern = $from . '%';
        logMessage("准备更新表 `$table` 字段 `$field`，替换 `$from` => `$to`");

        $affectedRows = $database->query($sql, [$from, $to, $likePattern], true);

        if ($affectedRows === false) {
            logMessage("表 `$table` 更新失败！");
            throw new Exception("更新表 `$table` 失败！");
        }

        logMessage("表 `$table` 更新成功，影响行数：$affectedRows");
    }

    $database->commit();
    logMessage("批量切换成功，事务已提交。");

    echo json_encode(['code' => 0, 'msg' => '批量切换成功' .__DIR__]);
} catch (Exception $e) {
    $database->rollBack();
    logMessage("批量切换失败，事务已回滚。错误信息：" . $e->getMessage());
    echo json_encode(['code' => 500, 'msg' => '更新失败: ' . $e->getMessage()]);
}
?>
