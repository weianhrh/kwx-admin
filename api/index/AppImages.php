<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Taipei');

$db = new Database();

// 读取 session_token
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '未登录', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 主页轮播：默认取 type = 0
    $type = isset($_GET['type']) ? intval($_GET['type']) : 2;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    if ($limit <= 0 || $limit > 50) $limit = 10;

    // 注意：LIMIT 不能用 ? 绑定在部分 MySQL 配置下会有坑，所以这里做 intval 后直接拼
    $rows = $db->query(
        "SELECT id, image_url, redirect_url, type
         FROM app_images
         WHERE type = ?
         ORDER BY id DESC
         LIMIT {$limit}",
        [$type]
    );

    // 只返回前端需要字段
    $data = array_map(function($r){
        return [
            'id' => intval($r['id']),
            'image_url' => $r['image_url'] ?? '',
            'redirect_url' => $r['redirect_url'] ?? '',
            'type' => intval($r['type'] ?? 2),
        ];
    }, $rows);

    echo json_encode(['code' => 0, 'msg' => 'ok', 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['code' => 500, 'msg' => 'server error: '.$e->getMessage(), 'data' => []], JSON_UNESCAPED_UNICODE);
}
