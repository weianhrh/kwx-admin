<?php
// /api/operat/app_images.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once '../Database.php';

function ok($data = [], $msg = 'ok') {
    echo json_encode(['ok' => 1, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function bad($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => 0, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function read_payload(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $arr = json_decode($raw, true);
            if (is_array($arr)) return $arr;
        }
        return [];
    }
    return $_POST ?? [];
}
function toInt($v, $d = 0){ if ($v === null || $v === '') return $d; return (int)$v; }
function strOrNull($v){ $v = trim((string)$v); return $v === '' ? null : $v; }
function strOrEmpty($v): string { return trim((string)$v); }

function parseType($v, $allowEmpty = false) {
    if ($allowEmpty && ($v === '' || $v === null)) return null;
    $t = toInt($v, 0);
    if (!in_array($t, [0,1,2,3], true)) bad('type 非法（仅允许 0/1/2/3）');
    return $t;
}

function parseIsShow($v, $allowEmpty = false) {
    if ($allowEmpty && ($v === '' || $v === null)) return null;
    $n = toInt($v, 1);
    if (!in_array($n, [0,1], true)) bad('is_show 非法（仅允许 0/1）');
    return $n;
}
// --- 登录校验（沿用你的 token 逻辑） ---
$token = $_COOKIE['session_token'] ?? ($_SERVER['HTTP_X_SESSION_TOKEN'] ?? '');
if (!$token) bad('未登录或缺少 session_token', 401);

$db = new Database();
$user = $db->getUserBySessionToken($token);
if (!$user) bad('登录已过期或 token 无效', 401);

// --- 路由 ---
$act = $_GET['act'] ?? 'list';

try {
    switch ($act) {
        // ===== 列表（分页 + 关键词搜索 redirect_url + type 筛选）=====
case 'list': {
    $page     = max(1, toInt($_GET['page'] ?? 1));
    $pageSize = min(100, max(1, toInt($_GET['page_size'] ?? 20)));
    $offset   = ($page - 1) * $pageSize;

    $q       = trim($_GET['q'] ?? '');
    $type    = $_GET['type'] ?? '';
    $is_show = $_GET['is_show'] ?? '';

    $typeVal   = parseType($type, true);
    $isShowVal = parseIsShow($is_show, true);

    $where  = ' WHERE 1=1 ';
    $params = [];

    if ($q !== '') {
        $where .= ' AND (name LIKE CONCAT("%", ?, "%") OR redirect_url LIKE CONCAT("%", ?, "%")) ';
        $params[] = $q;
        $params[] = $q;
    }
    if ($typeVal !== null) {
        $where .= ' AND type = ? ';
        $params[] = $typeVal;
    }
    if ($isShowVal !== null) {
        $where .= ' AND is_show = ? ';
        $params[] = $isShowVal;
    }

    $cntSql  = "SELECT COUNT(*) AS c FROM app_images {$where}";
    $cntRows = $db->query($cntSql, $params);
    $total   = ($cntRows && isset($cntRows[0]['c'])) ? (int)$cntRows[0]['c'] : 0;

    $listSql = "SELECT 
                    id,
                    IFNULL(name,'') AS name,
                    type,
                    image_url,
                    IFNULL(redirect_url,'') AS redirect_url,
                    is_show,
                    sort_order
                FROM app_images {$where}
                ORDER BY sort_order ASC, id ASC
                LIMIT {$offset}, {$pageSize}";
    $rows = $db->query($listSql, $params);

    ok([
        'list' => $rows ?: [],
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_page' => (int)ceil($total / $pageSize),
        ],
    ]);
}

        // ===== 新增 =====
case 'create': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('请使用 POST');
    $p = read_payload();

    $name         = strOrEmpty($p['name'] ?? '');
    $type         = parseType($p['type'] ?? 0, false);
    $image_url    = strOrNull($p['image_url'] ?? '');
    $redirect_url = strOrEmpty($p['redirect_url'] ?? '');
    $is_show      = parseIsShow($p['is_show'] ?? 1, false);
    $sort_order   = toInt($p['sort_order'] ?? 0, 0);

    if (!$image_url) bad('image_url 不能为空');

    $db->beginTransaction();
    try {
        $sql = "INSERT INTO app_images (name, type, image_url, redirect_url, is_show, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)";
        $db->query($sql, [$name, $type, $image_url, $redirect_url, $is_show, $sort_order], true);
        $newId = $db->getConnection()->insert_id;
        $db->commit();
        ok(['id' => $newId], '创建成功');
    } catch (Throwable $e) {
        $db->rollBack();
        bad('创建失败：'.$e->getMessage(), 500);
    }
}

        // ===== 详情 =====
case 'detail': {
    $id = toInt($_GET['id'] ?? 0);
    if ($id <= 0) bad('缺少 id');

    $rows = $db->query("SELECT 
                            id,
                            IFNULL(name,'') AS name,
                            type,
                            image_url,
                            IFNULL(redirect_url,'') AS redirect_url,
                            is_show,
                            sort_order
                        FROM app_images
                        WHERE id=?", [$id]);

    if (!$rows) bad('未找到', 404);
    ok($rows[0]);
}
        // ===== 更新 =====
        case 'update': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('请使用 POST');
            $p  = read_payload();
            $id = toInt($p['id'] ?? 0);
            if ($id <= 0) bad('缺少 id');

            $fields = [];
            $params = [];

            if (array_key_exists('type', $p)) {
                $t = parseType($p['type'], false);
                $fields[] = 'type=?';
                $params[] = $t;
            }
            if (array_key_exists('image_url', $p)) {
                $fields[] = 'image_url=?';
                $params[] = strOrNull($p['image_url']);
            }
            if (array_key_exists('redirect_url', $p)) {
                $fields[] = 'redirect_url=?';
                $params[] = strOrEmpty($p['redirect_url']);
            }
            if (array_key_exists('name', $p)) {
                $fields[] = 'name=?';
                $params[] = strOrEmpty($p['name']);
            }
            
            if (array_key_exists('is_show', $p)) {
                $fields[] = 'is_show=?';
                $params[] = parseIsShow($p['is_show'], false);
            }
            
            if (array_key_exists('sort_order', $p)) {
                $fields[] = 'sort_order=?';
                $params[] = toInt($p['sort_order'], 0);
            }
            if (!$fields) bad('没有可更新的字段');

            $params[] = $id;

            $db->beginTransaction();
            try {
                $sql = "UPDATE app_images SET ".implode(',', $fields)." WHERE id=?";
                $affected = $db->query($sql, $params, true);
                $db->commit();
                ok(['affected'=>$affected], '更新成功');
            } catch (Throwable $e) {
                $db->rollBack();
                $db->logToFile('[app_images.update] '.$e->getMessage());
                bad('更新失败：'.$e->getMessage(), 500);
            }
        }

        // ===== 删除（单条）=====
        case 'delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('请使用 POST');
            $p  = read_payload();
            $id = toInt($p['id'] ?? 0);
            if ($id <= 0) bad('缺少 id');

            $db->beginTransaction();
            try {
                $affected = $db->query("DELETE FROM app_images WHERE id=?", [$id], true);
                $db->commit();
                ok(['affected'=>$affected], '删除成功');
            } catch (Throwable $e) {
                $db->rollBack();
                $db->logToFile('[app_images.delete] '.$e->getMessage());
                bad('删除失败：'.$e->getMessage(), 500);
            }
        }

        // ===== 批量删除 =====
        case 'batch_delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('请使用 POST');
            $p = read_payload();
            $ids = $p['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) bad('缺少 ids');
            $ids = array_values(array_filter(array_map('intval',$ids), fn($v)=>$v>0));
            if (!$ids) bad('ids 非法');

            $in = implode(',', array_fill(0, count($ids), '?'));
            $params = array_map('strval', $ids);

            $db->beginTransaction();
            try {
                $sql = "DELETE FROM app_images WHERE id IN ($in)";
                $affected = $db->query($sql, $params, true);
                $db->commit();
                ok(['affected'=>$affected], '批量删除成功');
            } catch (Throwable $e) {
                $db->rollBack();
                $db->logToFile('[app_images.batch_delete] '.$e->getMessage());
                bad('批量删除失败：'.$e->getMessage(), 500);
            }
        }

        default:
            bad('未知操作 act');
    }
} catch (Throwable $e) {
    $db->logToFile('[app_images.fatal] '.$e->getMessage());
    bad('服务器异常：'.$e->getMessage(), 500);
}
