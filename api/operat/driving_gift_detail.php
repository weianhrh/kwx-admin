<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/venue_scope.php';

const DRIVING_GIFT_SELECTION_LIMIT = 5;

function driving_gift_ok(array $data = [], string $msg = 'ok'): void
{
    echo json_encode([
        'ok' => 1,
        'code' => 0,
        'msg' => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function driving_gift_bad(string $msg, int $httpCode = 400): void
{
    http_response_code($httpCode);
    echo json_encode([
        'ok' => 0,
        'code' => $httpCode,
        'msg' => $msg,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function driving_gift_payload(): array
{
    $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return is_array($_POST ?? null) ? $_POST : [];
}

function driving_gift_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        driving_gift_bad('请使用 POST 请求', 405);
    }
}

function driving_gift_int($value, int $default = 0): int
{
    return ($value === null || $value === '') ? $default : (int)$value;
}

function driving_gift_switch($value, int $default = 0): int
{
    if ($value === null || $value === '') {
        return $default;
    }
    return (int)$value === 1 ? 1 : 0;
}

function driving_gift_text($value, int $maxLength, bool $required = false): string
{
    $text = trim((string)$value);
    if ($required && $text === '') {
        driving_gift_bad('礼物名称不能为空');
    }

    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length > $maxLength) {
        driving_gift_bad('输入内容长度超过限制');
    }
    return $text;
}

function driving_gift_price($value, bool $allowEmpty = false): int
{
    if (($value === null || $value === '') && $allowEmpty) {
        return 0;
    }
    if ($value === null || $value === '' || !is_numeric($value)) {
        driving_gift_bad('礼物价格必须是整数');
    }
    $number = (float)$value;
    if ($number < 0 || $number > 9999999999 || floor($number) !== $number) {
        driving_gift_bad('礼物价格必须是 0 到 9999999999 的整数');
    }
    return (int)$number;
}

function driving_gift_is_platform_admin(int $roleId): bool
{
    return in_array($roleId, [1, 2], true);
}

function driving_gift_is_franchise(int $roleId): bool
{
    return in_array($roleId, [3, 4], true);
}

function driving_gift_source_column_ready(Database $db): bool
{
    return venue_scope_has_column($db, 'driving_gift_detail', 'source_gift_id');
}

function driving_gift_require_source_column(Database $db): void
{
    if (!driving_gift_source_column_ready($db)) {
        driving_gift_bad('礼物库数据库结构未升级，请先执行 api/sql/driving_gift_public_library.sql', 500);
    }
}

function driving_gift_resolve_venue(Database $db, array $user, $value, bool $required = true): int
{
    $venueId = driving_gift_int($value);
    if ($venueId <= 0 && !$required) {
        return 0;
    }
    if ($venueId <= 0) {
        $visible = venue_scope_visible_venues($db, $user);
        if (count($visible) === 1) {
            $venueId = (int)($visible[0]['id'] ?? 0);
        }
    }
    if ($venueId <= 0) {
        driving_gift_bad('请选择场地');
    }
    if (!venue_scope_can_access($db, $user, $venueId)) {
        driving_gift_bad('无权操作该场地', 403);
    }
    $rows = $db->query('SELECT id FROM venues WHERE id = ? LIMIT 1', [$venueId]);
    if (!$rows) {
        driving_gift_bad('所选场地不存在', 404);
    }
    return $venueId;
}

function driving_gift_require_public_item(Database $db, int $id): array
{
    $sourceExpr = driving_gift_source_column_ready($db) ? 'source_gift_id' : 'NULL AS source_gift_id';
    $rows = $db->query(
        "SELECT id, venue_id, {$sourceExpr} FROM driving_gift_detail WHERE id = ? AND COALESCE(venue_id, 0) = 0 LIMIT 1",
        [$id]
    );
    if (!$rows) {
        driving_gift_bad('公共礼物不存在', 404);
    }
    return $rows[0];
}

function driving_gift_require_venue_item(Database $db, array $user, int $id): array
{
    $sourceExpr = driving_gift_source_column_ready($db) ? 'source_gift_id' : 'NULL AS source_gift_id';
    $rows = $db->query(
        "SELECT id, venue_id, {$sourceExpr} FROM driving_gift_detail WHERE id = ? AND COALESCE(venue_id, 0) > 0 LIMIT 1",
        [$id]
    );
    if (!$rows) {
        driving_gift_bad('场地驾驶礼物不存在', 404);
    }
    $row = $rows[0];
    $venueId = (int)($row['venue_id'] ?? 0);
    if ($venueId <= 0 || !venue_scope_can_access($db, $user, $venueId)) {
        driving_gift_bad('无权操作该驾驶礼物', 403);
    }
    return $row;
}

function driving_gift_insert_public(Database $db, array $item): int
{
    $giftName = driving_gift_text($item['gift_name'] ?? '', 50, true);
    $giftPrice = driving_gift_price($item['gift_price'] ?? null, true);
    $imageUrl = driving_gift_text($item['image_url'] ?? '', 255);
    if ($imageUrl === '') {
        driving_gift_bad('礼物图片不能为空');
    }
    $gifUrl = driving_gift_text($item['gif_url'] ?? '', 255);
    $isDisplay = driving_gift_switch($item['is_display'] ?? 1, 1);
    $topBanner = driving_gift_switch($item['is_top_banner_show'] ?? 1, 1);
    $playSvga = $gifUrl === '' ? 0 : driving_gift_switch($item['is_play_svga'] ?? 1, 1);

    $sourceColumn = driving_gift_source_column_ready($db) ? ', source_gift_id' : '';
    $sourceValue = driving_gift_source_column_ready($db) ? ', NULL' : '';
    $affected = $db->query(
        'INSERT INTO driving_gift_detail
            (gift_name, gift_price, is_display, image_url, gif_url,
             is_top_banner_show, is_play_svga, venue_id' . $sourceColumn . ')
         VALUES (?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, 0' . $sourceValue . ')',
        [$giftName, $giftPrice, $isDisplay, $imageUrl, $gifUrl, $topBanner, $playSvga],
        true
    );
    if ($affected === false) {
        throw new RuntimeException('新增公共礼物失败');
    }
    return (int)$db->getConnection()->insert_id;
}

$token = (string)($_COOKIE['session_token'] ?? ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? ''));
if ($token === '') {
    driving_gift_bad('未登录或会话已过期', 401);
}

$db = new Database();
$user = $db->getUserBySessionToken($token);
if (!$user) {
    $db->close();
    driving_gift_bad('登录已过期或会话无效', 401);
}

$roleId = (int)($user['role_id'] ?? 0);
if (!in_array($roleId, [1, 2, 3, 4], true)) {
    $db->close();
    driving_gift_bad('无权管理驾驶礼物', 403);
}

$action = strtolower(trim((string)($_GET['act'] ?? 'list')));

try {
    switch ($action) {
        case 'meta':
            $schemaReady = driving_gift_source_column_ready($db);
            if (driving_gift_is_platform_admin($roleId)) {
                $countRows = $db->query('SELECT COUNT(*) AS total FROM driving_gift_detail WHERE COALESCE(venue_id, 0) = 0') ?: [];
                driving_gift_ok([
                    'mode' => 'admin',
                    'role_id' => $roleId,
                    'selection_limit' => DRIVING_GIFT_SELECTION_LIMIT,
                    'schema_ready' => $schemaReady ? 1 : 0,
                    'public_count' => (int)($countRows[0]['total'] ?? 0),
                ]);
            }

            $venueId = driving_gift_resolve_venue($db, $user, $_GET['venue_id'] ?? 0);
            $panelColumn = venue_scope_has_column($db, 'venues', 'is_gift_command_panel_display')
                ? 'COALESCE(is_gift_command_panel_display, 0) AS is_gift_command_panel_display'
                : '0 AS is_gift_command_panel_display';
            $venueRows = $db->query("SELECT id, venue_name, {$panelColumn} FROM venues WHERE id = ? LIMIT 1", [$venueId]) ?: [];
            $countRows = $db->query('SELECT COUNT(*) AS total FROM driving_gift_detail WHERE venue_id = ?', [$venueId]) ?: [];
            $venue = $venueRows[0] ?? ['id' => $venueId, 'venue_name' => ''];
            driving_gift_ok([
                'mode' => 'franchise',
                'role_id' => $roleId,
                'selection_limit' => DRIVING_GIFT_SELECTION_LIMIT,
                'schema_ready' => $schemaReady ? 1 : 0,
                'selected_count' => (int)($countRows[0]['total'] ?? 0),
                'venue' => [
                    'id' => $venueId,
                    'venue_name' => (string)($venue['venue_name'] ?? ''),
                    'is_gift_command_panel_display' => (int)($venue['is_gift_command_panel_display'] ?? 0),
                ],
            ]);

        case 'venues':
            $venueRows = venue_scope_visible_venues($db, $user);
            $venues = [];
            foreach ($venueRows as $venue) {
                $venueId = (int)($venue['id'] ?? 0);
                if ($venueId <= 0) {
                    continue;
                }
                $venues[] = [
                    'id' => $venueId,
                    'venue_name' => (string)($venue['venue_name'] ?? ''),
                ];
            }
            driving_gift_ok(['list' => $venues, 'multiple' => count($venues) > 1]);

        case 'list':
            $page = max(1, driving_gift_int($_GET['page'] ?? 1, 1));
            $pageSize = max(1, min(100, driving_gift_int($_GET['page_size'] ?? 20, 20)));
            $offset = ($page - 1) * $pageSize;
            $keyword = trim((string)($_GET['q'] ?? ''));
            $display = (string)($_GET['is_display'] ?? '');
            $sourceExpr = driving_gift_source_column_ready($db) ? 'dg.source_gift_id' : 'NULL AS source_gift_id';

            $where = ' WHERE 1=1';
            $params = [];
            if (driving_gift_is_platform_admin($roleId)) {
                $where .= ' AND COALESCE(dg.venue_id, 0) = 0';
            } else {
                $venueId = driving_gift_resolve_venue($db, $user, $_GET['venue_id'] ?? 0);
                $where .= ' AND dg.venue_id = ?';
                $params[] = (string)$venueId;
            }
            if ($keyword !== '') {
                $where .= ' AND (dg.gift_name LIKE CONCAT("%", ?, "%") OR CAST(dg.id AS CHAR) = ?)';
                $params[] = $keyword;
                $params[] = $keyword;
            }
            if ($display === '0' || $display === '1') {
                $where .= ' AND dg.is_display = ?';
                $params[] = $display;
            }

            $countRows = $db->query('SELECT COUNT(*) AS total FROM driving_gift_detail dg' . $where, $params);
            if ($countRows === false) {
                throw new RuntimeException('统计驾驶礼物失败');
            }
            $rows = $db->query(
                'SELECT dg.id, dg.gift_name, dg.gift_price, dg.is_display,
                        dg.image_url, dg.gif_url, dg.venue_id, ' . $sourceExpr . ',
                        COALESCE(dg.is_top_banner_show, 0) AS is_top_banner_show,
                        COALESCE(dg.is_play_svga, 0) AS is_play_svga
                   FROM driving_gift_detail dg' . $where .
                " ORDER BY dg.id DESC LIMIT {$offset}, {$pageSize}",
                $params
            );
            if ($rows === false) {
                throw new RuntimeException('查询驾驶礼物失败');
            }
            $total = (int)($countRows[0]['total'] ?? 0);
            driving_gift_ok([
                'list' => $rows,
                'pagination' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => $total,
                    'total_page' => (int)ceil($total / max(1, $pageSize)),
                ],
            ]);

        case 'library':
            if (!driving_gift_is_franchise($roleId)) {
                driving_gift_bad('该接口仅供加盟商选择公共礼物', 403);
            }
            driving_gift_require_source_column($db);
            $venueId = driving_gift_resolve_venue($db, $user, $_GET['venue_id'] ?? 0);
            $keyword = trim((string)($_GET['q'] ?? ''));
            $where = ' WHERE COALESCE(dg.venue_id, 0) = 0 AND dg.is_display = 1';
            $params = [];
            if ($keyword !== '') {
                $where .= ' AND (dg.gift_name LIKE CONCAT("%", ?, "%") OR CAST(dg.id AS CHAR) = ?)';
                $params[] = $keyword;
                $params[] = $keyword;
            }
            $rows = $db->query(
                'SELECT dg.id, dg.gift_name, dg.gift_price, dg.image_url, dg.gif_url,
                        COALESCE(dg.is_top_banner_show, 0) AS is_top_banner_show,
                        COALESCE(dg.is_play_svga, 0) AS is_play_svga,
                        CASE WHEN EXISTS (
                            SELECT 1 FROM driving_gift_detail picked
                            WHERE picked.venue_id = ? AND picked.source_gift_id = dg.id
                        ) THEN 1 ELSE 0 END AS selected
                   FROM driving_gift_detail dg' . $where .
                ' ORDER BY dg.id DESC LIMIT 200',
                array_merge([(string)$venueId], $params)
            );
            if ($rows === false) {
                throw new RuntimeException('读取公共礼物库失败');
            }
            driving_gift_ok(['list' => $rows, 'selection_limit' => DRIVING_GIFT_SELECTION_LIMIT]);

        case 'create':
            driving_gift_require_post();
            if (!driving_gift_is_platform_admin($roleId)) {
                driving_gift_bad('加盟商不能直接新建公共礼物，请从公共礼物库选择', 403);
            }
            driving_gift_require_source_column($db);
            $payload = driving_gift_payload();
            $newId = driving_gift_insert_public($db, $payload);
            driving_gift_ok(['id' => $newId], '公共礼物新增成功');

        case 'batch_create':
            driving_gift_require_post();
            if (!driving_gift_is_platform_admin($roleId)) {
                driving_gift_bad('只有管理员可以批量上传公共礼物', 403);
            }
            driving_gift_require_source_column($db);
            $payload = driving_gift_payload();
            $items = $payload['items'] ?? [];
            if (!is_array($items) || !$items) {
                driving_gift_bad('没有可导入的礼物');
            }
            if (count($items) > 50) {
                driving_gift_bad('单次最多批量导入 50 个礼物');
            }
            $ids = [];
            $db->beginTransaction();
            try {
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        throw new RuntimeException('批量礼物数据格式错误');
                    }
                    $ids[] = driving_gift_insert_public($db, $item);
                }
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            driving_gift_ok(['ids' => $ids, 'count' => count($ids)], '批量导入成功');

        case 'select_batch':
            driving_gift_require_post();
            if (!driving_gift_is_franchise($roleId)) {
                driving_gift_bad('该操作仅供加盟商使用', 403);
            }
            driving_gift_require_source_column($db);
            $payload = driving_gift_payload();
            $venueId = driving_gift_resolve_venue($db, $user, $payload['venue_id'] ?? 0);
            $rawIds = $payload['gift_ids'] ?? [];
            if (!is_array($rawIds)) {
                driving_gift_bad('请选择公共礼物');
            }
            $giftIds = [];
            foreach ($rawIds as $rawId) {
                $giftId = (int)$rawId;
                if ($giftId > 0) {
                    $giftIds[$giftId] = true;
                }
            }
            $giftIds = array_keys($giftIds);
            if (!$giftIds) {
                driving_gift_bad('请选择至少 1 个公共礼物');
            }

            $currentRows = $db->query('SELECT COUNT(*) AS total FROM driving_gift_detail WHERE venue_id = ?', [$venueId]) ?: [];
            $currentCount = (int)($currentRows[0]['total'] ?? 0);
            if ($currentCount >= DRIVING_GIFT_SELECTION_LIMIT) {
                driving_gift_bad('该场地已达到 5 个驾驶礼物上限');
            }

            $ph = implode(',', array_fill(0, count($giftIds), '?'));
            $sourceRows = $db->query(
                "SELECT id, gift_name, gift_price, image_url, gif_url,
                        COALESCE(is_top_banner_show, 0) AS is_top_banner_show,
                        COALESCE(is_play_svga, 0) AS is_play_svga
                   FROM driving_gift_detail
                  WHERE COALESCE(venue_id, 0) = 0 AND is_display = 1 AND id IN ({$ph})",
                array_map('strval', $giftIds)
            ) ?: [];
            if (count($sourceRows) !== count($giftIds)) {
                driving_gift_bad('选择中包含已下架或不存在的公共礼物');
            }

            $duplicateRows = $db->query(
                "SELECT source_gift_id FROM driving_gift_detail WHERE venue_id = ? AND source_gift_id IN ({$ph})",
                array_merge([(string)$venueId], array_map('strval', $giftIds))
            ) ?: [];
            $duplicateIds = [];
            foreach ($duplicateRows as $row) {
                $duplicateIds[(int)($row['source_gift_id'] ?? 0)] = true;
            }
            $toInsert = array_values(array_filter($sourceRows, static function (array $row) use ($duplicateIds): bool {
                return !isset($duplicateIds[(int)($row['id'] ?? 0)]);
            }));
            if (!$toInsert) {
                driving_gift_bad('所选礼物已经添加到该场地');
            }
            if ($currentCount + count($toInsert) > DRIVING_GIFT_SELECTION_LIMIT) {
                driving_gift_bad('每个场地最多只能选择 5 个驾驶礼物');
            }

            $newIds = [];
            $db->beginTransaction();
            try {
                foreach ($toInsert as $source) {
                    $affected = $db->query(
                        'INSERT INTO driving_gift_detail
                            (gift_name, gift_price, is_display, image_url, gif_url,
                             is_top_banner_show, is_play_svga, venue_id, source_gift_id)
                         VALUES (?, ?, 1, ?, NULLIF(?, \'\'), ?, ?, ?, ?)',
                        [
                            (string)$source['gift_name'],
                            (string)$source['gift_price'],
                            (string)$source['image_url'],
                            (string)($source['gif_url'] ?? ''),
                            (string)$source['is_top_banner_show'],
                            (string)$source['is_play_svga'],
                            (string)$venueId,
                            (string)$source['id'],
                        ],
                        true
                    );
                    if ($affected === false) {
                        throw new RuntimeException('添加场地礼物失败');
                    }
                    $newIds[] = (int)$db->getConnection()->insert_id;
                }
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            driving_gift_ok([
                'ids' => $newIds,
                'count' => count($newIds),
                'selected_count' => $currentCount + count($newIds),
                'selection_limit' => DRIVING_GIFT_SELECTION_LIMIT,
            ], '礼物已添加到场地');

        case 'update':
            driving_gift_require_post();
            $payload = driving_gift_payload();
            $id = driving_gift_int($payload['id'] ?? 0);
            if ($id <= 0) {
                driving_gift_bad('礼物 ID 不正确');
            }

            if (driving_gift_is_platform_admin($roleId)) {
                driving_gift_require_public_item($db, $id);
            } else {
                driving_gift_require_venue_item($db, $user, $id);
            }

            $giftName = driving_gift_text($payload['gift_name'] ?? '', 50, true);
            $giftPrice = driving_gift_price($payload['gift_price'] ?? null, driving_gift_is_platform_admin($roleId));
            $imageUrl = driving_gift_text($payload['image_url'] ?? '', 255);
            if ($imageUrl === '') {
                driving_gift_bad('礼物图片不能为空');
            }
            $gifUrl = driving_gift_text($payload['gif_url'] ?? '', 255);
            $isDisplay = driving_gift_switch($payload['is_display'] ?? 1, 1);
            $topBanner = driving_gift_switch($payload['is_top_banner_show'] ?? 0);
            $playSvga = $gifUrl === '' ? 0 : driving_gift_switch($payload['is_play_svga'] ?? 0);

            $affected = $db->query(
                'UPDATE driving_gift_detail
                    SET gift_name = ?, gift_price = ?, is_display = ?, image_url = ?,
                        gif_url = NULLIF(?, \'\'), is_top_banner_show = ?, is_play_svga = ?
                  WHERE id = ?',
                [$giftName, $giftPrice, $isDisplay, $imageUrl, $gifUrl, $topBanner, $playSvga, $id],
                true
            );
            if ($affected === false) {
                throw new RuntimeException('更新驾驶礼物失败');
            }
            driving_gift_ok(['affected' => $affected], '保存成功');

        case 'toggle_display':
            driving_gift_require_post();
            $payload = driving_gift_payload();
            $id = driving_gift_int($payload['id'] ?? 0);
            if ($id <= 0) {
                driving_gift_bad('礼物 ID 不正确');
            }
            if (driving_gift_is_platform_admin($roleId)) {
                driving_gift_require_public_item($db, $id);
            } else {
                driving_gift_require_venue_item($db, $user, $id);
            }
            $isDisplay = driving_gift_switch($payload['is_display'] ?? 0);
            $affected = $db->query('UPDATE driving_gift_detail SET is_display = ? WHERE id = ?', [$isDisplay, $id], true);
            if ($affected === false) {
                throw new RuntimeException('更新显示状态失败');
            }
            driving_gift_ok(['affected' => $affected], '状态已更新');

        case 'toggle_panel':
            driving_gift_require_post();
            $payload = driving_gift_payload();
            $venueId = driving_gift_resolve_venue($db, $user, $payload['venue_id'] ?? 0);
            if (!venue_scope_has_column($db, 'venues', 'is_gift_command_panel_display')) {
                driving_gift_bad('venues 缺少 is_gift_command_panel_display 字段', 500);
            }
            $display = driving_gift_switch($payload['is_gift_command_panel_display'] ?? 0);
            $affected = $db->query(
                'UPDATE venues SET is_gift_command_panel_display = ? WHERE id = ? LIMIT 1',
                [$display, $venueId],
                true
            );
            if ($affected === false) {
                throw new RuntimeException('礼物命令显示状态更新失败');
            }
            driving_gift_ok([
                'venue_id' => $venueId,
                'is_gift_command_panel_display' => $display,
            ], $display === 1 ? '驾驶页礼物命令已显示' : '驾驶页礼物命令已隐藏');

        case 'delete':
            driving_gift_require_post();
            $payload = driving_gift_payload();
            $id = driving_gift_int($payload['id'] ?? 0);
            if ($id <= 0) {
                driving_gift_bad('礼物 ID 不正确');
            }

            if (driving_gift_is_platform_admin($roleId)) {
                driving_gift_require_public_item($db, $id);
                $db->beginTransaction();
                try {
                    if (driving_gift_source_column_ready($db)) {
                        $clear = $db->query('UPDATE driving_gift_detail SET source_gift_id = NULL WHERE source_gift_id = ?', [$id], true);
                        if ($clear === false) {
                            throw new RuntimeException('解除公共礼物关联失败');
                        }
                    }
                    $affected = $db->query('DELETE FROM driving_gift_detail WHERE id = ? AND COALESCE(venue_id, 0) = 0', [$id], true);
                    if ($affected === false) {
                        throw new RuntimeException('删除公共礼物失败');
                    }
                    $db->commit();
                } catch (Throwable $e) {
                    $db->rollBack();
                    throw $e;
                }
                driving_gift_ok(['affected' => $affected], '公共礼物已删除，场地已选副本不受影响');
            }

            $row = driving_gift_require_venue_item($db, $user, $id);
            $affected = $db->query('DELETE FROM driving_gift_detail WHERE id = ? AND venue_id = ?', [$id, (int)$row['venue_id']], true);
            if ($affected === false) {
                throw new RuntimeException('移除场地礼物失败，该礼物可能已关联订单');
            }
            driving_gift_ok(['affected' => $affected], '场地礼物已移除');

        default:
            driving_gift_bad('未知操作 act');
    }
} catch (Throwable $e) {
    error_log('driving_gift_detail.php error: ' . $e->getMessage());
    try {
        $db->close();
    } catch (Throwable $ignore) {
    }
    driving_gift_bad($e->getMessage(), 500);
}
