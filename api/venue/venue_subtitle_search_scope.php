<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/venue_scope.php';

/** 当前账号有权查看搜索记录的 关联场地（不限制副标题）。 */
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
        "SELECT id, venue_name, venue_subtitle, TRIM(venue_unique_subtitle) AS venue_unique_subtitle
         FROM venues
         WHERE id IN ({$marks})
         ORDER BY id ASC",
        $ids
    );
    return is_array($rows) ? $rows : [];
}

/** 按账号关联的场地 ID 隔离记录，包含普通副标题、二级副标题及历史记录。 */
function venue_search_record_scope(array $venues): array
{
    $pairs = [];
    $params = [];
    foreach ($venues as $venue) {
        $pairs[] = '(r.venue_id = ?)';
        $params[] = (string)(int)$venue['id'];
    }
    return [$pairs ? '(' . implode(' OR ', $pairs) . ')' : '1=0', $params];
}
