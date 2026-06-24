<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function json_out($success, $message, $data = [])
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function parse_list_param($value)
{
    if (is_array($value)) {
        $arr = $value;
    } else {
        $value = trim((string)$value);
        if ($value === '') return [];

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $arr = $decoded;
        } else {
            $arr = explode(',', $value);
        }
    }

    $out = [];
    foreach ($arr as $v) {
        $v = trim((string)$v);
        if ($v !== '') $out[] = $v;
    }
    return array_values(array_unique($out));
}

try {
    $archiveKeys = parse_list_param($_POST['archive_keys'] ?? '');
    $ids = parse_list_param($_POST['ids'] ?? '');

    if (empty($archiveKeys) && empty($ids)) {
        json_out(false, '缺少 archive_keys 或 ids 参数');
    }

    // 防止一次提交过大，前端本来就是按分页勾选，一页最多 100 条
    if (count($archiveKeys) > 200 || count($ids) > 200) {
        json_out(false, '一次最多忽略 200 条，请分页处理');
    }

    $db = new Database();

    $conditions = [];
    $params = [];

    if (!empty($archiveKeys)) {
        $placeholders = implode(',', array_fill(0, count($archiveKeys), '?'));
        $conditions[] = "archive_key IN ({$placeholders})";
        $params = array_merge($params, $archiveKeys);
    }

    if (!empty($ids)) {
        $safeIds = [];
        foreach ($ids as $id) {
            $id = intval($id);
            if ($id > 0) $safeIds[] = $id;
        }
        $safeIds = array_values(array_unique($safeIds));
        if (!empty($safeIds)) {
            $placeholders = implode(',', array_fill(0, count($safeIds), '?'));
            $conditions[] = "id IN ({$placeholders})";
            $params = array_merge($params, $safeIds);
        }
    }

    if (empty($conditions)) {
        json_out(false, '没有有效的记录标识');
    }

    $targetWhere = '(' . implode(' OR ', $conditions) . ')';

    $countSql = "
        SELECT COUNT(*) AS total
        FROM device_violation_archive
        WHERE {$targetWhere}
          AND (review_ignore IS NULL OR review_ignore <> 1)
    ";
    $countRows = $db->query($countSql, $params);
    if ($countRows === false) {
        json_out(false, '统计待忽略记录失败');
    }
    $matched = intval($countRows[0]['total'] ?? 0);

    if ($matched <= 0) {
        json_out(true, '没有需要忽略的记录，可能已经处理过', [
            'matched' => 0,
            'hidden' => 0
        ]);
    }

    $updateSql = "
        UPDATE device_violation_archive
        SET review_ignore = 1,
            status_updated_at = NOW(),
            updated_at = NOW()
        WHERE {$targetWhere}
          AND (review_ignore IS NULL OR review_ignore <> 1)
    ";

    $ok = $db->query($updateSql, $params, true);
    if ($ok === false) {
        json_out(false, '忽略失败');
    }

    json_out(true, '已忽略选中的复审记录', [
        'matched' => $matched,
        'hidden' => $matched
    ]);

} catch (Exception $e) {
    json_out(false, '异常：' . $e->getMessage());
}
?>
