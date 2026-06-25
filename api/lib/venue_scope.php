<?php
declare(strict_types=1);

function venue_scope_has_table(Database $db, string $table): bool
{
    static $cache = [];
    $key = strtolower($table);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    // MySQL 不支持用 mysqli prepare 给 SHOW TABLES LIKE ? 绑定参数，
    // 会导致“预处理SQL语句失败 ... near '?'”，然后多场地关系表被误判为不存在。
    // 改查 information_schema，既能安全绑定参数，也能兼容当前 Database::query()。
    $rows = $db->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
        [$table]
    );
    $cache[$key] = !empty($rows);
    return $cache[$key];
}

function venue_scope_has_column(Database $db, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    // 同上，避免 SHOW COLUMNS ... LIKE ? 在 mysqli prepare 下失败。
    $rows = $db->query(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [$table, $column]
    );
    $cache[$key] = !empty($rows);
    return $cache[$key];
}

function venue_scope_ints(array $values): array
{
    $ids = [];
    foreach ($values as $value) {
        if (is_array($value)) {
            foreach (venue_scope_ints($value) as $nestedId) {
                $ids[$nestedId] = true;
            }
            continue;
        }
        if ($value === null || $value === '') {
            continue;
        }
        foreach (preg_split('/[,\s]+/', (string)$value) ?: [] as $part) {
            if ($part !== '' && ctype_digit($part) && (int)$part > 0) {
                $ids[(int)$part] = true;
            }
        }
    }
    return array_keys($ids);
}

function venue_scope_user_ids(Database $db, array $user): array
{
    $ids = venue_scope_ints([
        $user['venue_id'] ?? null,
        $user['venue_ids'] ?? null,
        $user['managed_venue_ids'] ?? null,
    ]);

    if (venue_scope_has_table($db, 'admin_user_venues')) {
        $userId = (int)($user['id'] ?? 0);
        if ($userId > 0) {
            $rows = $db->query(
                'SELECT venue_id FROM admin_user_venues WHERE admin_user_id = ? ORDER BY is_primary DESC, venue_id ASC',
                [$userId]
            );
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $ids[] = (int)($row['venue_id'] ?? 0);
                }
            }
        }
    }

    return venue_scope_ints($ids);
}

function venue_scope_is_platform_admin(array $user): bool
{
    return in_array((int)($user['role_id'] ?? 0), [1, 2], true);
}

function venue_scope_requested_id(array $source = null): int
{
    $source = $source ?? array_merge($_GET, $_POST);
    $value = $source['venue_id'] ?? $source['venueId'] ?? '';
    return (is_scalar($value) && ctype_digit((string)$value)) ? (int)$value : 0;
}

function venue_scope_allowed_ids(Database $db, array $user, bool $platformAllMeansEmpty = true): array
{
    if (venue_scope_is_platform_admin($user) && $platformAllMeansEmpty) {
        return [];
    }
    return venue_scope_user_ids($db, $user);
}

function venue_scope_can_access(Database $db, array $user, int $venueId): bool
{
    if ($venueId <= 0) {
        return true;
    }
    if (venue_scope_is_platform_admin($user)) {
        return true;
    }
    return in_array($venueId, venue_scope_user_ids($db, $user), true);
}

function venue_scope_resolve_single_id(Database $db, array $user, int $requestedVenueId = 0): int
{
    if ($requestedVenueId > 0) {
        return venue_scope_can_access($db, $user, $requestedVenueId) ? $requestedVenueId : 0;
    }

    if (venue_scope_is_platform_admin($user)) {
        return (int)($user['venue_id'] ?? 0);
    }

    $ids = venue_scope_user_ids($db, $user);
    return (int)($ids[0] ?? 0);
}

function venue_scope_filter_sql(string $column, array $ids, array &$params): string
{
    $ids = venue_scope_ints($ids);
    if (!$ids) {
        return ' AND 1=0';
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    foreach ($ids as $id) {
        $params[] = (string)$id;
    }
    return " AND {$column} IN ({$placeholders})";
}

function venue_scope_apply_filter(Database $db, array $user, string $column, array &$params, int $requestedVenueId = 0): string
{
    if ($requestedVenueId > 0) {
        if (!venue_scope_can_access($db, $user, $requestedVenueId)) {
            return ' AND 1=0';
        }
        $params[] = (string)$requestedVenueId;
        return " AND {$column} = ?";
    }

    if (venue_scope_is_platform_admin($user)) {
        return '';
    }

    return venue_scope_filter_sql($column, venue_scope_user_ids($db, $user), $params);
}

function venue_scope_visible_venues(Database $db, array $user): array
{
    if (venue_scope_is_platform_admin($user)) {
        return $db->query('SELECT id, venue_name, image_url, venue_status FROM venues ORDER BY id DESC LIMIT 500') ?: [];
    }

    $ids = venue_scope_user_ids($db, $user);
    if (!$ids) {
        return [];
    }

    $params = [];
    $where = venue_scope_filter_sql('id', $ids, $params);
    return $db->query(
        'SELECT id, venue_name, image_url, venue_status FROM venues WHERE 1=1' . $where . ' ORDER BY id DESC',
        $params
    ) ?: [];
}
