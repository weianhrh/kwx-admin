<?php
require_once '../Database.php';   // 确保路径正确

function getRange($mode) {
    // 统一用 [start, end) 口径
    if ($mode === 'day') {
        $start = date('Y-m-d 00:00:00');
        $end   = date('Y-m-d 00:00:00', strtotime('+1 day'));
    } elseif ($mode === 'week') {
        // 周一为一周开始（常用口径）
        $start = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $end   = date('Y-m-d 00:00:00', strtotime('monday next week'));
    } else { // month
        $start = date('Y-m-01 00:00:00');
        $end   = date('Y-m-01 00:00:00', strtotime('+1 month'));
    }
    return [$start, $end];
}
[$start,$end] = getRange('day');
echo json_encode([
    'code' => 0,
    'msg' => '',
    'data' => [
        'start' => $start,
          'end' => $end,
    ]
]);