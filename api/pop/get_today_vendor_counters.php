<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Singapore');

require_once '../Database.php';

try {
    $db = new Database();

    $sql = "
        SELECT
            SUM(CASE WHEN vendor = 'ch' THEN 1 ELSE 0 END) AS ch_cnt,
            SUM(CASE WHEN vendor = 'yl' THEN 1 ELSE 0 END) AS yl_cnt,
            SUM(CASE WHEN vendor = 'xm' THEN 1 ELSE 0 END) AS xm_cnt,
            SUM(CASE WHEN vendor = 'zego' THEN 1 ELSE 0 END) AS zego_cnt,
            COUNT(*) AS total_cnt
        FROM vendor_capture_stats
        WHERE DATE(created_at) = CURDATE()
    ";

    $rows = $db->query($sql);

    $data = [
        'ch_cnt'    => 0,
        'yl_cnt'    => 0,
        'xm_cnt'    => 0,
        'zego_cnt'  => 0,
        'total_cnt' => 0,
    ];

    if ($rows && isset($rows[0])) {
        $row = $rows[0];
        $data = [
            'ch_cnt'    => (int)($row['ch_cnt'] ?? 0),
            'yl_cnt'    => (int)($row['yl_cnt'] ?? 0),
            'xm_cnt'    => (int)($row['xm_cnt'] ?? 0),
            'zego_cnt'    => (int)($row['zego_cnt'] ?? 0),
            'total_cnt' => (int)($row['total_cnt'] ?? 0),
        ];
    }

    echo json_encode([
        'code' => 200,
        'msg'  => 'ok',
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'code' => 500,
        'msg'  => '服务异常：' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}