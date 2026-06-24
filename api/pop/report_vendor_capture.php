<?php
// /api/pop/report_vendor_capture.php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Singapore');

require_once '../Database.php';

try {
    $vendor       = trim($_POST['vendor'] ?? '');
    $serialNumber = trim($_POST['serial_number'] ?? '');
    $imageUrl     = trim($_POST['image_url'] ?? '');
    $eventKey     = trim($_POST['event_key'] ?? '');
    $source       = trim($_POST['source'] ?? '');
    $extraJson    = trim($_POST['extra_json'] ?? '');
    if ($extraJson === '') {
        $extraJson = null;
    }
if (!in_array($vendor, ['ch', 'yl', 'xm', 'zego'], true)) {
        echo json_encode([
            'code' => 400,
            'msg'  => 'vendor 非法'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($eventKey === '') {
        echo json_encode([
            'code' => 400,
            'msg'  => '缺少 event_key'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = new Database();

    $sql = "
        INSERT INTO vendor_capture_stats
            (event_key, vendor, serial_number, image_url, source, extra_json, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            vendor = VALUES(vendor),
            serial_number = VALUES(serial_number),
            image_url = VALUES(image_url),
            source = VALUES(source),
            extra_json = VALUES(extra_json)
    ";

    $stmt = $db->prepare($sql);
    $stmt->bind_param(
        "ssssss",
        $eventKey,
        $vendor,
        $serialNumber,
        $imageUrl,
        $source,
        $extraJson
    );

    $ok = $stmt->execute();

    if (!$ok) {
        throw new Exception('SQL执行失败: ' . $stmt->error);
    }

    $stmt->close();
    $db->close();

    echo json_encode([
        'code' => 200,
        'msg'  => 'ok',
        'data' => [
            'vendor'    => $vendor,
            'event_key' => $eventKey
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'code' => 500,
        'msg'  => '服务异常: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}