<?php
require_once __DIR__ . '/../auth/_common.php';
auth_json_headers();

try {
    $token = $_COOKIE[AUTH_COOKIE] ?? '';
    if (!is_string($token) || $token === '') auth_out(1001, '请先登录');
    $db = new Database();
    $where = auth_has_column($db, 'admin_users', 'session_expires') ? ' AND session_expires > NOW()' : '';
    $users = $db->query('SELECT role_id FROM admin_users WHERE session_token = ?' . $where . ' LIMIT 1', [$token]);
    if (empty($users)) auth_out(1001, '登录已过期');
    if (!in_array((int)$users[0]['role_id'], [1, 2], true)) auth_out(1003, '仅管理员可配置首页弹窗');

    $rows = $db->query('SELECT announcement, announcement_image, is_display, notice_version FROM kwx_home_event_notice WHERE id = 1');
    if (empty($rows[0])) auth_out(500, '未读取到配置，请确认已执行 event_notice.sql');
    $saved = $rows[0];
    $saved['is_display'] = (int)$saved['is_display'];
    $saved['notice_version'] = (int)$saved['notice_version'];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') auth_out(0, 'ok', $saved);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        auth_out(400, '请使用 JSON 提交配置');
    }

    $data = auth_request_data();
    if (($data['notice_version'] ?? null) !== $saved['notice_version']) auth_out(409, '配置已更新，请刷新后重新编辑');
    $text = $data['announcement'] ?? null;
    $image = $data['announcement_image'] ?? null;
    $display = $data['is_display'] ?? null;
    if (!is_string($text) || strlen($text) > 65535 || !is_string($image) || !in_array($display, [0, 1], true)) {
        auth_out(400, '请检查公告文字、图片和弹出设置');
    }
    $text = trim($text);
    if ($image !== '' && $image !== $saved['announcement_image'] &&
        !preg_match('~\Ahttps://app\.kwxapp\.cn/app/imgv2/img/[A-Za-z0-9_-]+\.jpg\z~D', $image)) {
        auth_out(400, '请选择并上传弹窗图片');
    }
    if (strlen($image) > 512) auth_out(400, '图片地址过长');
    if ($text !== $saved['announcement']) $display = 0;
    if ($display === 0 && $text === '' && $image === '') auth_out(400, '弹窗至少需要文字或图片');
    if ($text === $saved['announcement'] && $image === $saved['announcement_image'] && $display === $saved['is_display']) {
        auth_out(0, '内容未变更', $saved);
    }

    $count = $db->query(
        'UPDATE kwx_home_event_notice SET announcement = ?, announcement_image = ?, is_display = ?, notice_version = notice_version + 1 WHERE id = 1 AND notice_version = ?',
        [$text, $image, $display, $saved['notice_version']], true
    );
    if ($count === false) auth_out(500, '保存失败，请稍后重试');
    if ($count !== 1) auth_out(409, '配置已更新，请刷新后重新编辑');
    auth_out(0, '已保存', ['announcement' => $text, 'announcement_image' => $image, 'is_display' => $display, 'notice_version' => $saved['notice_version'] + 1]);
} catch (Throwable $e) {
    error_log('event_notice admin: ' . $e->getMessage());
    auth_out(500, '首页弹窗配置暂时不可用');
}
