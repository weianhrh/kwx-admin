<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/venue_scope.php';

/** 当前账号有权查看搜索记录的 8899 场地及各自的二级副标题。 */
function venue_search_eligible_venues(Database $db, array $user, ?array $boundVenueIds = null): array
{
    if (!in_array((int)($user['role_id'] ?? 0), [3, 4], true)) {
        return [];
    }

    $ids = venue_scope_ints($boundVenueIds ?? venue_scope_user_ids($db, $user));
    if (!$ids) {
        return [];
    }

    $marks = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->query(
        "SELECT id, venue_name, TRIM(venue_unique_subtitle) AS venue_unique_subtitle
         FROM venues
         WHERE id IN ({$marks})
           AND venue_subtitle = '8899'
           AND venue_unique_subtitle IS NOT NULL
           AND TRIM(venue_unique_subtitle) <> ''
           AND TRIM(venue_unique_subtitle) <> '8899'
         ORDER BY id ASC",
        $ids
    );
    return is_array($rows) ? $rows : [];
}

/** 必须同时匹配场地 ID 和当前二级副标题，避免同码场地之间串数据。 */
function venue_search_record_scope(array $venues): array
{
    $pairs = [];
    $params = [];
    foreach ($venues as $venue) {
        $pairs[] = '(r.venue_id = ? AND r.venue_subtitle = ?)';
        $params[] = (string)(int)$venue['id'];
        $params[] = (string)$venue['venue_unique_subtitle'];
    }
    return [$pairs ? '(' . implode(' OR ', $pairs) . ')' : '1=0', $params];
}
