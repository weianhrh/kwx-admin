<?php
// api/ks/report.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../Database.php';

// 可选鉴权：if (($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '') !== 'YOUR_SECRET') { http_response_code(403); exit; }

function read_json_body() {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return $j;
}

try {
    $db = new Database();
    $payload = read_json_body();
    if ($payload === null) { http_response_code(400); echo json_encode(['error'=>'invalid json']); exit; }

    // 支持单条或数组
    $items = array_keys($payload) === range(0, count($payload)-1) ? $payload : [$payload];

    $updated = 0; $logged = 0;
    foreach ($items as $it) {
        $venueId    = isset($it['venue_id']) ? intval($it['venue_id']) : null;
        $slug       = strval($it['slug'] ?? '');
        $httpStatus = isset($it['http_status']) ? intval($it['http_status']) : null;
        $resultCode = isset($it['result_code']) ? intval($it['result_code']) : null;
        $living     = $it['living'] ?? null; // bool 或 null
        $rawJson    = isset($it['raw']) ? json_encode($it['raw'], JSON_UNESCAPED_UNICODE) : null;

        // 1) 记日志
        $db->query(
            "INSERT INTO ks_byid_logs (venue_id, slug, http_status, result_code, living, raw_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [$venueId, $slug, $httpStatus, $resultCode, is_null($living) ? null : ($living ? 1 : 0), $rawJson],
            true
        );
        $logged++;

        // 2) 按规则更新 venues.show_live_stream
        if ($venueId && $resultCode === 1 && is_bool($living)) {
            $db->query("UPDATE venues SET show_live_stream=? WHERE id=?",
                       [$living ? 1 : 0, $venueId], true);
            $updated++;
        }
        // 400002 / 其它：不更新，只记日志
    }

    echo json_encode(['ok'=>true,'updated'=>$updated,'logged'=>$logged], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
