<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/venue_scope.php';

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

function driving_gift_price($value): int
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        driving_gift_bad('礼物价格必须是整数');
    }
    $number = (float)$value;
    if ($number < 0 || $number > 9999999999 || floor($number) !== $number) {
        driving_gift_bad('礼物价格必须是 0 到 9999999999 的整数');
    }
    return (int)$number;
}

function driving_gift_venue_id(Database $db, array $user, $value): int
{
    $venueId = driving_gift_int($value);
    if ($venueId <= 0) {
        driving_gift_bad('请选择场地');
    }

    if (!venue_scope_can_access($db, $user, $venueId)) {
        driving_gift_bad('无权操作该场地', 403);
    }

    $venueRows = $db->query('SELECT id FROM venues WHERE id = ? LIMIT 1', [$venueId]);
    if (!$venueRows) {
        driving_gift_bad('所选场地不存在');
    }
    return $venueId;
}

function driving_gift_require_item_access(Database $db, array $user, int $id): array
{
    $rows = $db->query(
        'SELECT id, venue_id FROM driving_gift_detail WHERE id = ? LIMIT 1',
        [$id]
    );
    if (!$rows) {
        driving_gift_bad('驾驶礼物不存在', 404);
    }

    $row = $rows[0];
    $venueId = (int)($row['venue_id'] ?? 0);
    if ($venueId <= 0 || !venue_scope_can_access($db, $user, $venueId)) {
        driving_gift_bad('无权操作该驾驶礼物', 403);
    }
    return $row;
}

function driving_gift_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        driving_gift_bad('请使用 POST 请求', 405);
    }
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
            driving_gift_ok([
                'list' => $venues,
                'multiple' => count($venues) > 1,
            ]);

        case 'list':
            $page = max(1, driving_gift_int($_GET['page'] ?? 1, 1));
            $pageSize = max(1, min(100, driving_gift_int($_GET['page_size'] ?? 20, 20)));
            $offset = ($page - 1) * $pageSize;
            $keyword = trim((string)($_GET['q'] ?? ''));
            $display = (string)($_GET['is_display'] ?? '');
            $venueId = driving_gift_int($_GET['venue_id'] ?? 0);

            $where = ' WHERE 1=1';
            $params = [];
            if ($keyword !== '') {
                $where .= ' AND (dg.gift_name LIKE CONCAT("%", ?, "%") OR CAST(dg.id AS CHAR) = ?)';
                $params[] = $keyword;
                $params[] = $keyword;
            }
            if ($display === '0' || $display === '1') {
                $where .= ' AND dg.is_display = ?';
                $params[] = $display;
            }
            $where .= venue_scope_apply_filter($db, $user, 'dg.venue_id', $params, $venueId);

            $countRows = $db->query(
                'SELECT COUNT(*) AS total FROM driving_gift_detail dg' . $where,
                $params
            );
            if ($countRows === false) {
                throw new RuntimeException('统计驾驶礼物失败');
            }

            $rows = $db->query(
                'SELECT dg.id, dg.gift_name, dg.gift_price, dg.is_display,
                        dg.image_url, dg.gif_url, dg.venue_id, v.venue_name,
                        COALESCE(dg.is_top_banner_show, 0) AS is_top_banner_show,
                        COALESCE(dg.is_play_svga, 0) AS is_play_svga
                   FROM driving_gift_detail dg
              LEFT JOIN venues v ON v.id = dg.venue_id' . $where .
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

        case 'create':
            driving_gift_require_post();
            $payload = driving_gift_payload();
            $venueId = driving_gift_venue_id($db, $user, $payload['venue_id'] ?? 0);
            $giftName = driving_gift_text($payload['gift_name'] ?? '', 50, true);
            $giftPrice = driving_gift_price($payload['gift_price'] ?? null);
            $imageUrl = driving_gift_text($payload['image_url'] ?? '', 255);
            $gifUrl = driving_gift_text($payload['gif_url'] ?? '', 255);
            $isDisplay = driving_gift_switch($payload['is_display'] ?? 0);
            $topBanner = driving_gift_switch($payload['is_top_banner_show'] ?? 0);
            $playSvga = driving_gift_switch($payload['is_play_svga'] ?? 0);

            $affected = $db->query(
                'INSERT INTO driving_gift_detail
                    (gift_name, gift_price, is_display, image_url, gif_url,
                     is_top_banner_show, is_play_svga, venue_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$giftName, $giftPrice, $isDisplay, $imageUrl, $gifUrl, $topBanner, $playSvga, $venueId],
                true
            );
            if ($affected === false) {
                throw new RuntimeException('新增驾驶礼物失败');
            }
            $newId = (int)$db->getConnection()->insert_id;
            driving_gift_ok(['id' => $newId], '新增成功');

        case 'update':
            driving_gift_require_post();
            $payload = driving_gift_payload();
            $id = driving_gift_int($payload['id'] ?? 0);
            if ($id <= 0) {
                driving_gift_bad('礼物 ID 不正确');
            }

            driving_gift_require_item_access($db, $user, $id);
            $venueId = driving_gift_venue_id($db, $user, $payload['venue_id'] ?? 0);
            $giftName = driving_gift_text($payload['gift_name'] ?? '', 50, true);
            $giftPrice = driving_gift_price($payload['gift_price'] ?? null);
            $imageUrl = driving_gift_text($payload['image_url'] ?? '', 255);
            $gifUrl = driving_gift_text($payload['gif_url'] ?? '', 255);
            $isDisplay = driving_gift_switch($payload['is_display'] ?? 0);
            $topBanner = driving_gift_switch($payload['is_top_banner_show'] ?? 0);
            $playSvga = driving_gift_switch($payload['is_play_svga'] ?? 0);

            $affected = $db->query(
                'UPDATE driving_gift_detail
                    SET gift_name = ?, gift_price = ?, is_display = ?, image_url = ?,
                        gif_url = ?, is_top_banner_show = ?, is_play_svga = ?, venue_id = ?
                  WHERE id = ?',
                [$giftName, $giftPrice, $isDisplay, $imageUrl, $gifUrl, $topBanner, $playSvga, $venueId, $id],
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
            driving_gift_require_item_access($db, $user, $id);
            $isDisplay = driving_gift_switch($payload['is_display'] ?? 0);
            $affected = $db->query(
                'UPDATE driving_gift_detail SET is_display = ? WHERE id = ?',
                [$isDisplay, $id],
                true
            );
            if ($affected === false) {
                throw new RuntimeException('更新显示状态失败');
            }
            driving_gift_ok(['affected' => $affected], '状态已更新');

        case 'delete':
            driving_gift_require_post();
            $payload = driving_gift_payload();
            $id = driving_gift_int($payload['id'] ?? 0);
            if ($id <= 0) {
                driving_gift_bad('礼物 ID 不正确');
            }
            driving_gift_require_item_access($db, $user, $id);
            $affected = $db->query(
                'DELETE FROM driving_gift_detail WHERE id = ?',
                [$id],
                true
            );
            if ($affected === false) {
                throw new RuntimeException('删除失败，该礼物可能已关联订单');
            }
            driving_gift_ok(['affected' => $affected], '删除成功');

        default:
            driving_gift_bad('未知操作 act');
    }
} catch (Throwable $e) {
    error_log('driving_gift_detail.php error: ' . $e->getMessage());
    $db->close();
    driving_gift_bad($e->getMessage(), 500);
}
