<?php
require_once '../Database.php'; // 确保路径正确，或你是用autoload机制

$db = new Database();

// 定义 serial_number => photo_url 映射
$photoUpdates = [
    '8C4F00AC4D1C' => 'https://app.kwxapp.cn/app/imgv2/img/upload_1747629733_682ab6a512ab2.png',
    '80646F1DBEE0' => 'https://app.kwxapp.cn/app/imgv2/img/upload_1747629774_682ab6ceb6c1f.png',
    '3C8A1F0B2470' => 'https://app.kwxapp.cn/app/imgv2/img/upload_1747629800_682ab6e8e2abe.png',
    '3C8A1F0923DC' => 'https://app.kwxapp.cn/app/imgv2/img/upload_1747629820_682ab6fc17c22.png',
    '3C8A1F0A6BB8' => 'https://app.kwxapp.cn/app/imgv2/img/upload_1747629842_682ab712a17aa.png',
    'C4F00AC4DCC'  => 'https://app.kwxapp.cn/app/imgv2/img/upload_1747629871_682ab72fb73e0.png',
    '8C4F00AC4424' => 'https://app.kwxapp.cn/app/imgv2/img/upload_1747629889_682ab7411869e.png',
];

try {
    $db->beginTransaction();

    foreach ($photoUpdates as $serial => $url) {
        $sql = "UPDATE vehicles SET photo_url = ? WHERE serial_number = ?";
        $affected = $db->query($sql, [$url, $serial], true);

        if ($affected === false) {
            throw new Exception("更新失败 serial_number: $serial");
        }

        echo "已更新：$serial\n";
    }

    $db->commit();
    echo "全部更新完成。\n";
} catch (Exception $e) {
    $db->rollBack();
    echo "更新失败，已回滚事务。错误：" . $e->getMessage() . "\n";
    $db->logToFile("更新失败：" . $e->getMessage());
} finally {
    $db->close();
}
