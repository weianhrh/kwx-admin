<?php
declare(strict_types=1);

// KWX-VENUE-SEARCH-V11-20260812-SUBTITLE-VENUE-OWNER

require_once __DIR__ . '/../auth/_common.php';
require_once __DIR__ . '/../lib/venue_scope.php';

auth_json_headers();
auth_handle_options();

function venue_search_period_range(string $period): array
{
    $today = new DateTimeImmutable('today');

    if ($period === 'week') {
        $start = $today->modify('monday this week');
        $end = $start->modify('+6 days');
    } elseif ($period === 'month') {
        $start = $today->modify('first day of this month');
        $end = $today->modify('last day of this month');
    } else {
        $period = 'day';
        $start = $today;
        $end = $today;
    }

    return [
        'period' => $period,
        'start_date' => $start->format('Y-m-d\T00:00'),
        'end_date' => $end->format('Y-m-d\T23:59'),
    ];
}

function venue_search_valid_datetime(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    return $date !== false && $date->format('Y-m-d\TH:i') === $value;
}

$token = (string)($_COOKIE[AUTH_COOKIE] ?? '');
if ($token === '') {
    auth_out(1001, '未登录或会话已过期');
}

$db = new Database();
if (auth_has_column($db, 'admin_users', 'session_expires')) {
    $users = $db->query('SELECT * FROM admin_users WHERE session_token = ? AND session_expires > NOW() LIMIT 1', [$token]);
} else {
    $users = $db->query('SELECT * FROM admin_users WHERE session_token = ? LIMIT 1', [$token]);
}

$user = $users[0] ?? null;
if (!$user || empty($user['role_id'])) {
    $db->close();
    auth_clear_cookie();
    auth_out(1001, '未登录或会话已过期');
}

if (!venue_scope_is_platform_admin($user)) {
    $db->close();
    auth_out(1003, '当前账号无权查看该页面');
}

if (!venue_scope_has_table($db, 'venue_subtitle_search_records')) {
    $db->close();
    auth_out(500, '记录表尚未创建，请先执行 api/sql/create_venue_subtitle_search_records.sql');
}

// 合并当前场地与历史记录中的副标题，使用 UNION 去重，避免相同副标题重复出现在下拉框。
$subtitleOptionRows = $db->query("
    SELECT subtitle.venue_subtitle
    FROM (
        SELECT TRIM(venue_subtitle) AS venue_subtitle
        FROM venues
        WHERE venue_subtitle IS NOT NULL AND TRIM(venue_subtitle) <> ''
        UNION
        SELECT TRIM(venue_subtitle) AS venue_subtitle
        FROM venue_subtitle_search_records
        WHERE venue_subtitle IS NOT NULL AND TRIM(venue_subtitle) <> ''
    ) subtitle
    ORDER BY subtitle.venue_subtitle ASC
") ?: [];
$subtitleOptions = [];
foreach ($subtitleOptionRows as $subtitleOptionRow) {
    $subtitleValue = trim((string)($subtitleOptionRow['venue_subtitle'] ?? ''));
    if ($subtitleValue !== '') {
        $subtitleOptions[] = $subtitleValue;
    }
}

$period = (string)($_GET['period'] ?? 'day');
$period = in_array($period, ['day', 'week', 'month'], true) ? $period : 'day';
$defaultRange = venue_search_period_range($period);

$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));
$startDate = venue_search_valid_datetime($startDate) ? $startDate : $defaultRange['start_date'];
$endDate = venue_search_valid_datetime($endDate) ? $endDate : $defaultRange['end_date'];

if ($startDate > $endDate) {
    $db->close();
    auth_out(400, '开始日期不能晚于结束日期');
}

$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = min(100, max(10, (int)($_GET['page_size'] ?? 20)));
$offset = ($page - 1) * $pageSize;
$keyword = trim((string)($_GET['keyword'] ?? ''));
$venueSubtitle = trim((string)($_GET['venue_subtitle'] ?? ''));
$venueSubtitleLength = function_exists('mb_strlen') ? mb_strlen($venueSubtitle, 'UTF-8') : strlen($venueSubtitle);
if ($venueSubtitleLength > 100) {
    $db->close();
    auth_out(400, '场地副标题长度不能超过 100 个字符');
}
$venueIdRaw = trim((string)($_GET['venue_id'] ?? ''));
$venueId = ctype_digit($venueIdRaw) && (int)$venueIdRaw > 0 ? (int)$venueIdRaw : 0;

$scopeWhere = [];
$scopeParams = [];

if ($venueId > 0) {
    $scopeWhere[] = 'r.venue_id = ?';
    $scopeParams[] = (string)$venueId;
}

if ($venueSubtitle !== '') {
    $scopeWhere[] = 'r.venue_subtitle = ?';
    $scopeParams[] = $venueSubtitle;
}

if ($keyword !== '') {
    $like = '%' . $keyword . '%';
    $scopeWhere[] = '(CAST(r.uid AS CHAR) LIKE ? OR CAST(r.venue_id AS CHAR) LIKE ? OR r.venue_name LIKE ? OR r.venue_subtitle LIKE ?)';
    array_push($scopeParams, $like, $like, $like, $like);
}

$where = array_merge([
    'r.first_searched_at >= ?',
    'r.first_searched_at < DATE_ADD(?, INTERVAL 1 MINUTE)',
], $scopeWhere);
$params = array_merge([
    str_replace('T', ' ', $startDate) . ':00',
    str_replace('T', ' ', $endDate) . ':00',
], $scopeParams);
$whereSql = ' WHERE ' . implode(' AND ', $where);
$historyWhereSql = $scopeWhere ? ' WHERE ' . implode(' AND ', $scopeWhere) : '';

// 每个副标题的历史人数忽略时间，但保留场地、副标题和关键词条件。
$historySubtitleRows = $db->query("
    SELECT
        r.venue_subtitle,
        COUNT(DISTINCT r.uid) AS history_user_total,
        COUNT(DISTINCT r.venue_id) AS history_venue_total
    FROM venue_subtitle_search_records r
    {$historyWhereSql}
    GROUP BY r.venue_subtitle
", $scopeParams) ?: [];
$historySubtitleVenues = $db->query("
    SELECT
        r.venue_subtitle,
        r.venue_id,
        MAX(NULLIF(TRIM(r.venue_name), '')) AS venue_name
    FROM venue_subtitle_search_records r
    {$historyWhereSql}
    GROUP BY r.venue_subtitle, r.venue_id
    ORDER BY r.venue_subtitle ASC, r.venue_id ASC
", $scopeParams) ?: [];

// 顶部统计、副标题汇总与明细列表使用完全相同的场地、副标题、日期和关键词条件。
$summaryRows = $db->query("
    SELECT
        COUNT(*) AS record_total,
        COUNT(DISTINCT r.uid) AS user_total,
        COUNT(DISTINCT r.venue_subtitle) AS subtitle_total,
        COUNT(DISTINCT r.venue_id) AS venue_total
    FROM venue_subtitle_search_records r
    {$whereSql}
", $params) ?: [];

$total = (int)($summaryRows[0]['record_total'] ?? 0);
$filteredSubtitleRows = $db->query("
    SELECT
        r.venue_subtitle,
        COUNT(DISTINCT r.uid) AS user_total,
        COUNT(DISTINCT r.venue_id) AS venue_total
    FROM venue_subtitle_search_records r
    {$whereSql}
    GROUP BY r.venue_subtitle
    ORDER BY user_total DESC, r.venue_subtitle ASC
", $params) ?: [];

// 以历史副标题为完整列表，再叠加时间范围数据；没有时间内记录时显示 0 人。
$subtitleSummaryMap = [];
foreach ($historySubtitleRows as $historySubtitleRow) {
    $subtitleKey = (string)($historySubtitleRow['venue_subtitle'] ?? '');
    if ($subtitleKey === '') {
        continue;
    }
    $subtitleSummaryMap[$subtitleKey] = [
        'venue_subtitle' => $subtitleKey,
        'history_user_total' => (int)($historySubtitleRow['history_user_total'] ?? 0),
        'history_venue_total' => (int)($historySubtitleRow['history_venue_total'] ?? 0),
        'user_total' => 0,
        'venue_total' => 0,
        'venues' => [],
    ];
}
foreach ($historySubtitleVenues as $historySubtitleVenue) {
    $subtitleKey = (string)($historySubtitleVenue['venue_subtitle'] ?? '');
    if ($subtitleKey === '' || !isset($subtitleSummaryMap[$subtitleKey])) {
        continue;
    }
    $subtitleSummaryMap[$subtitleKey]['venues'][] = [
        'venue_id' => (int)($historySubtitleVenue['venue_id'] ?? 0),
        'venue_name' => (string)($historySubtitleVenue['venue_name'] ?? ''),
    ];
}
foreach ($filteredSubtitleRows as $filteredSubtitleRow) {
    $subtitleKey = (string)($filteredSubtitleRow['venue_subtitle'] ?? '');
    if ($subtitleKey === '') {
        continue;
    }
    if (!isset($subtitleSummaryMap[$subtitleKey])) {
        $subtitleSummaryMap[$subtitleKey] = [
            'venue_subtitle' => $subtitleKey,
            'history_user_total' => 0,
            'history_venue_total' => 0,
            'user_total' => 0,
            'venue_total' => 0,
            'venues' => [],
        ];
    }
    $subtitleSummaryMap[$subtitleKey]['user_total'] = (int)($filteredSubtitleRow['user_total'] ?? 0);
    $subtitleSummaryMap[$subtitleKey]['venue_total'] = (int)($filteredSubtitleRow['venue_total'] ?? 0);
}
$subtitleSummary = array_values($subtitleSummaryMap);
usort($subtitleSummary, static function (array $left, array $right): int {
    $timeCompare = $right['user_total'] <=> $left['user_total'];
    if ($timeCompare !== 0) {
        return $timeCompare;
    }
    $historyCompare = $right['history_user_total'] <=> $left['history_user_total'];
    if ($historyCompare !== 0) {
        return $historyCompare;
    }
    return strnatcasecmp((string)$left['venue_subtitle'], (string)$right['venue_subtitle']);
});
$summaryVenues = $db->query("
    SELECT
        r.venue_id,
        MAX(NULLIF(TRIM(r.venue_name), '')) AS venue_name
    FROM venue_subtitle_search_records r
    {$whereSql}
    GROUP BY r.venue_id
    ORDER BY r.venue_id ASC
", $params) ?: [];
$rows = $db->query("
    SELECT r.id, r.uid, r.venue_id, r.venue_name, r.venue_subtitle, r.first_searched_at
    FROM venue_subtitle_search_records r
    {$whereSql}
    ORDER BY r.first_searched_at DESC, r.id DESC
    LIMIT {$pageSize} OFFSET {$offset}
", $params) ?: [];

$db->close();

auth_out(0, 'ok', [
    'period' => $defaultRange['period'],
    'range' => [
        'start_date' => $startDate,
        'end_date' => $endDate,
    ],
    'venue_id' => $venueId,
    'venue_subtitle' => $venueSubtitle,
    'subtitle_options' => $subtitleOptions,
    'page' => $page,
    'page_size' => $pageSize,
    'total' => $total,
    'summary' => [
        'record_total' => $total,
        'user_total' => (int)($summaryRows[0]['user_total'] ?? 0),
        'subtitle_total' => (int)($summaryRows[0]['subtitle_total'] ?? 0),
        'venue_total' => (int)($summaryRows[0]['venue_total'] ?? 0),
    ],
    'subtitle_summary' => $subtitleSummary,
    'summary_venues' => $summaryVenues,
    'rows' => $rows,
]);
