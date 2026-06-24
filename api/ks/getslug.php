<?php
// api/ks/getslug.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../Database.php';

// 可选鉴权：if (($_GET['token'] ?? '') !== 'YOUR_SECRET') { http_response_code(403); exit; }

function extractSlug(?string $url): ?string {
    if (!$url) return null;
    $s = trim($url);
    $low = strtolower($s);
    if (strpos($low, 'kuaishou.com') === false && strpos($low, 'kwai://') === false) return null;
    if (stripos($s, 'kwai://profile/') === 0) return basename($s);
    if (preg_match('~https?://(?:www\.)?live\.kuaishou\.com/u/([^/?#]+)~i', $s, $m)) return $m[1];
    if (preg_match('~https?://(?:www\.)?kuaishou\.com/profile/([^/?#]+)~i', $s, $m)) return $m[1];
    if (preg_match('~https?://(?:www\.)?kuaishou\.com/u/([^/?#]+)~i', $s, $m)) return $m[1];
    $parts = explode('/', rtrim($s, '/'));
    return end($parts) ?: null;
}

try {
    $db = new Database();
    $limit = isset($_GET['limit']) ? max(0, intval($_GET['limit'])) : 0;

    $sql = "SELECT id, live_stream_url FROM venues
            WHERE live_stream_url IS NOT NULL AND TRIM(live_stream_url) <> ''
              AND (live_stream_url LIKE '%kuaishou.com%' OR live_stream_url LIKE 'kwai://%')
            ORDER BY id ASC";
    if ($limit > 0) $sql .= " LIMIT " . $limit;

    $rows = $db->query($sql);
    $out  = [];
    foreach ($rows as $r) {
        $slug = extractSlug($r['live_stream_url'] ?? '');
        if ($slug) $out[] = ['id' => intval($r['id']), 'slug' => $slug];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
