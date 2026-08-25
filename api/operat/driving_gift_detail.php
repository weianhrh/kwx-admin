<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../Database.php';

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
if (!in_array($roleId, [1, 2], true)) {
    $db->close();
    driving_gift_bad('无权管理驾驶礼物', 403);
}

$action = strtolower(trim((string)($_GET['act'] ?? 'list')));

try {
    switch ($action) {
        case 'list':
            $page = max(1, driving_gift_int($_GET['page'] ?? 1, 1));
            $pageSize = max(1, min(100, driving_gift_int($_GET['page_size'] ?? 20, 20)));
            $offset = ($page - 1) * $pageSize;
            $keyword = trim((string)($_GET['q'] ?? ''));
            $display = (string)($_GET['is_display'] ?? '');

            $where = ' WHERE 1=1';
            $params = [];
            if ($keyword !== '') {
                $where .= ' AND (gift_name LIKE CONCAT("%", ?, "%") OR CAST(id AS CHAR) = ?)';
                $params[] = $keyword;
                $params[] = $keyword;
            }
            if ($display === '0' || $display === '1') {
                $where .= ' AND is_display = ?';
                $params[] = $display;
            }

            $countRows = $db->query(
                'SELECT COUNT(*) AS total FROM driving_gift_detail' . $where,
                $params
            );
            if ($countRows === false) {
                throw new RuntimeException('统计驾驶礼物失败');
            }

            $rows = $db->query(
                'SELECT id, gift_name, gift_price, is_display, image_url, gif_url,
                        COALESCE(is_top_banner_show, 0) AS is_top_banner_show,
                        COALESCE(is_play_svga, 0) AS is_play_svga
                   FROM driving_gift_detail' . $where .
                " ORDER BY id DESC LIMIT {$offset}, {$pageSize}",
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
                     is_top_banner_show, is_play_svga)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$giftName, $giftPrice, $isDisplay, $imageUrl, $gifUrl, $topBanner, $playSvga],
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
                        gif_url = ?, is_top_banner_show = ?, is_play_svga = ?
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
