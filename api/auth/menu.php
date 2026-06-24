<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

auth_json_headers();
auth_handle_options();

function menu_visible_for_role(array $menu, int $roleId): bool
{
    $roleIds = trim((string)($menu['role_ids'] ?? ''));
    if ($roleIds === '') {
        return false;
    }
    $allowed = array_filter(array_map('trim', explode(',', $roleIds)), 'strlen');
    return in_array((string)$roleId, $allowed, true);
}

function menu_normalize_jump(?string $jump): string
{
    $jump = trim((string)$jump);
    if ($jump === '' || $jump === '/') {
        return '';
    }
    return '/' . ltrim($jump, '/');
}

function menu_text(array $menu): string
{
    return strtolower(
        (string)($menu['name'] ?? '') . ' ' .
        (string)($menu['title'] ?? '') . ' ' .
        (string)($menu['jump'] ?? '')
    );
}

function menu_contains(string $text, array $needles): bool
{
    foreach ($needles as $needle) {
        if ($needle !== '' && strpos($text, strtolower($needle)) !== false) {
            return true;
        }
    }
    return false;
}

function menu_hidden_for_rebuild(array $menu): bool
{
    $text = menu_text($menu);

    return menu_contains($text, [
        '语音房', 'voice_room', 'voiceroom', 'voice-room', 'voice room',
        '礼物', 'gift',
        '金币', 'gold', 'gold_package', 'coin', 'coins',
        '娃娃机', '娃娃', 'doll', 'claw', '抓中', '发货',
        '开播时长', '场地开关播记录', '开关播记录', '开关播',
        'live_duration', 'live_record', 'live_log', 'live_logs',
        '主播认证管理', '主播认证', 'anchor_auth', 'anchorauth',
        '移动工作台', 'mobile_workbench', 'mobileworkbench', 'mobile_workspace',
        'rc后台管理', 'rc 后台管理', 'rc后台', 'rc_admin', 'rc admin', 'adminbackstage',
    ]);
}

function menu_hidden_ids(array $menus): array
{
    $hiddenIds = [];

    foreach ($menus as $menu) {
        if (menu_hidden_for_rebuild($menu)) {
            $hiddenIds[(int)$menu['id']] = true;
        }
    }

    do {
        $changed = false;
        foreach ($menus as $menu) {
            $id = (int)$menu['id'];
            $parentId = (int)($menu['parent_id'] ?? 0);
            if (!isset($hiddenIds[$id]) && isset($hiddenIds[$parentId])) {
                $hiddenIds[$id] = true;
                $changed = true;
            }
        }
    } while ($changed);

    return $hiddenIds;
}

function menu_group_key(array $menu): string
{
    $text = menu_text($menu);

    if (menu_contains($text, ['dev', 'device', 'vehicle', 'vehicles', 'camera', 'fault', 'imgnumber', 'photol', 'stream', '设备', '摄像', '故障'])) {
        return 'device';
    }
    if (menu_contains($text, ['venue', 'zone', 'reserva', '场地', '专区', '预约', '开播'])) {
        return 'venue';
    }
    if (menu_contains($text, ['order', 'doll', 'shipping', 'driving', '订单', '抓中', '发货'])) {
        return 'order';
    }
    if (menu_contains($text, ['pay', 'payment', 'withdraw', 'refund', 'recharge', 'comsum', 'amount', 'fund', 'funds', 'income', 'revenue', 'tariff', 'commodity', 'pricing', 'gold_package', '财务', '支付', '提现', '退款', '充值', '流水', '账户', '套餐'])) {
        return 'finance';
    }
    if (menu_contains($text, ['user', 'franchise', 'anchor', 'invitation', 'nickname', '用户', '加盟', '主播', '邀请'])) {
        return 'user';
    }
    if (menu_contains($text, ['report', 'patrol', 'violation', 'ban', 'audit', 'barrage', 'pid', 'complaint', '巡查', '审核', '违规', '投诉'])) {
        return 'audit';
    }
    if (menu_contains($text, ['gift', 'energy', 'message', 'notify', 'redis', 'voice', 'global_config', 'config', 'kanban', 'kb', 'app_images', 'points', 'checkin', '运营', '消息', '礼物', '公告', '配置', '数据'])) {
        return 'ops';
    }
    return 'tools';
}

function menu_feature_key(array $menu): string
{
    $text = menu_text($menu);
    if (menu_contains($text, ['devicemgt', 'device-list', '设备管理'])) return 'device_list';
    if (menu_contains($text, ['adddev', 'device-add', '添加设备'])) return 'device_add';
    if (menu_contains($text, ['fault', '故障'])) return 'device_fault';
    if (menu_contains($text, ['camera', 'device_information', '摄像'])) return 'device_camera';
    if (menu_contains($text, ['vehicles_manage', 'device_bind', '设备绑定'])) return 'device_binding';
    if (menu_contains($text, ['photo', 'photol', 'imgnumber', '图片', '图传'])) return 'device_photo';
    return '';
}

function menu_compact_tree(array $menus, int $roleId): array
{
    $groups = [
        'device' => ['id' => 9001, 'name' => 'device', 'title' => '设备管理', 'icon' => 'device', 'children' => []],
        'venue' => ['id' => 9002, 'name' => 'venue', 'title' => '场地管理', 'icon' => 'venue', 'children' => []],
        'order' => ['id' => 9003, 'name' => 'order', 'title' => '订单管理', 'icon' => 'order', 'children' => []],
        'user' => ['id' => 9004, 'name' => 'user', 'title' => '用户管理', 'icon' => 'user', 'children' => []],
        'finance' => ['id' => 9005, 'name' => 'finance', 'title' => '财务管理', 'icon' => 'finance', 'children' => []],
        'ops' => ['id' => 9006, 'name' => 'ops', 'title' => '运营配置', 'icon' => 'ops', 'children' => []],
        'audit' => ['id' => 9007, 'name' => 'audit', 'title' => '巡查审核', 'icon' => 'audit', 'children' => []],
        'tools' => ['id' => 9008, 'name' => 'tools', 'title' => '系统工具', 'icon' => 'tools', 'children' => []],
    ];

    $hiddenMenuIds = menu_hidden_ids($menus);
    $seen = [];
    foreach ($menus as $menu) {
        if (isset($hiddenMenuIds[(int)$menu['id']])) {
            continue;
        }

        if (!menu_visible_for_role($menu, $roleId)) {
            continue;
        }

        $jump = menu_normalize_jump($menu['jump'] ?? '');
        if ($jump === '') {
            continue;
        }

        $featureKey = menu_feature_key($menu);
        $dedupeKey = $featureKey !== '' ? $featureKey : ($jump !== '' ? $jump : (string)$menu['name']);
        if (isset($seen[$dedupeKey])) {
            continue;
        }
        $seen[$dedupeKey] = true;

        $groupKey = menu_group_key($menu);
        $groups[$groupKey]['children'][] = [
            'id' => (int)$menu['id'],
            'parent_id' => $groups[$groupKey]['id'],
            'name' => (string)$menu['name'],
            'title' => (string)$menu['title'],
            'icon' => (string)($menu['icon'] ?? ''),
            'jump' => $jump,
            'sort' => (int)($menu['sort'] ?? 0),
        ];
    }

    $tree = [[
        'id' => 9000,
        'parent_id' => 0,
        'name' => 'dashboard',
        'title' => '工作台',
        'icon' => 'home',
        'jump' => '',
        'sort' => 0,
    ]];

    foreach ($groups as $group) {
        if (!empty($group['children'])) {
            $tree[] = $group;
        }
    }

    return $tree;
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
if (!$user) {
    $db->close();
    auth_clear_cookie();
    auth_out(1001, '未登录或会话已过期');
}

$menus = $db->query('SELECT id, parent_id, name, title, icon, jump, role_ids, sort FROM admin_menus ORDER BY sort ASC, id ASC');
$db->close();

auth_out(0, 'ok', [
    'menus' => menu_compact_tree($menus ?: [], (int)$user['role_id']),
    'user' => auth_user_payload($user),
]);
