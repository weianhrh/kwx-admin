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
        '我的工具',
        '语音房', 'voice_room', 'voiceroom', 'voice-room', 'voice room',
        '礼物', 'gift',
        '金币', 'gold', 'gold_package', 'coin', 'coins',
        '娃娃机', '娃娃', 'doll', 'claw', '抓中', '发货',
        '开播时长', '场地开关播记录', '开关播记录', '开关播',
        'live_duration', 'live_record', 'live_log', 'live_logs',
        '主播认证管理', '主播认证', 'anchor_auth', 'anchorauth',
        '移动工作台', 'mobile_workbench', 'mobileworkbench', 'mobile_workspace',
        'rc后台管理', 'rc 后台管理', 'rc后台', 'rc_admin', 'rc admin', 'adminbackstage',
        '修改密码', 'system_password', 'system-password', 'updatepassword', 'setpasswd',
        '平台数据面板', '数据面板', '全平台数据看板', 'iframe/link/kb', '/kb',
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

    if (menu_contains($text, ['reporthand', 'order_complaint', 'order-complaint', '订单投诉', '投诉处理'])) {
        return 'audit';
    }
    if (menu_contains($text, ['admindrivingorders', 'order_report', 'recharge_orders_api', '订单查询', '订单申诉', '最新支付记录'])) {
        return 'order';
    }
    if (menu_contains($text, ['dev', 'device', 'vehicle', 'vehicles', 'camera', 'fault', 'imgnumber', 'photol', 'stream', '设备', '摄像', '故障'])) {
        return 'device';
    }
    if (menu_contains($text, [ 'zone', 'reserva', '场地', '专区', '预约', '开播'])) {
        return 'venue';
    }
    if (menu_contains($text, ['order', 'doll', 'shipping', 'driving', '订单', '抓中', '发货'])) {
        return 'order';
    }
    if (menu_contains($text, ['pay', 'payment', 'withdraw', 'refund',  'comsum', 'amount', 'fund', 'funds', 'income', 'revenue', 'tariff', 'commodity', 'pricing', 'gold_package', '财务', '支付', '提现', '退款', '充值', '流水', '账户', '套餐'])) {
        return 'finance';
    }
    if (menu_contains($text, ['user', 'franchise', 'anchor', 'invitation', 'nickname', '用户','recharge', '加盟', '主播', '邀请'])) {
        return 'user';
    }
    if (menu_contains($text, ['report', 'patrol', 'violation', 'ban', 'audit', 'barrage', 'pid', 'complaint', '巡查', '审核', '违规', '投诉'])) {
        return 'audit';
    }
    if (menu_contains($text, ['gift', 'energy', 'message', 'notify', 'redis', 'voice', 'global_config', 'config', 'kanban', 'kb', 'app_images', 'points', 'checkin', '运营', '消息', '礼物', '公告','业绩', '配置', '数据'])) {
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

function menu_append_system_tools(array &$groups, int $roleId): void
{
    if ($roleId <= 0 || $roleId === 3) {
        return;
    }

    $toolsId = (int)$groups['tools']['id'];
    $append = function (array $item) use (&$groups): void {
        foreach ($groups['tools']['children'] as $child) {
            if (($child['jump'] ?? '') === $item['jump'] || ($child['title'] ?? '') === $item['title']) {
                return;
            }
        }
        $groups['tools']['children'][] = $item;
    };

    $append([
        'id' => 9101,
        'parent_id' => $toolsId,
        'name' => 'system-profile',
        'title' => '基本资料',
        'icon' => 'user',
        'jump' => '/iframe/link/system_profile',
        'sort' => 9101,
    ]);
}
function menu_append_user_blacklist_menu(array &$groups, int $roleId): void
{
    if (!in_array($roleId, [1, 2], true) || !isset($groups['user'])) {
        return;
    }

    $targetJump = '/iframe/link/black_user_gmt';

    foreach ($groups as $group) {
        foreach (($group['children'] ?? []) as $child) {
            if (($child['jump'] ?? '') === $targetJump) {
                return;
            }
        }
    }

    $groups['user']['children'][] = [
        'id' => 9201,
        'parent_id' => (int)$groups['user']['id'],
        'name' => 'user-blacklist',
        'title' => '用户拉黑管理',
        'icon' => 'user',
        'jump' => $targetJump,
        'sort' => 9201,
    ];
}
function menu_franchise_tree(): array
{
    return [
        [
            'id' => 9000,
            'parent_id' => 0,
            'name' => 'dashboard',
            'title' => '首页',
            'icon' => 'home',
            'jump' => '',
            'sort' => 0,
        ],
        [
            'id' => 9301,
            'parent_id' => 0,
            'name' => 'franchise-order',
            'title' => '订单管理',
            'icon' => 'order',
            'children' => [
                ['id' => 9311, 'parent_id' => 9301, 'name' => 'franchise-orders', 'title' => '订单管理', 'icon' => 'order', 'jump' => '/iframe/link/franchise_driving_orders', 'sort' => 10],
                ['id' => 9312, 'parent_id' => 9301, 'name' => 'franchise-refunds', 'title' => '退款记录', 'icon' => 'finance', 'jump' => '/iframe/link/refund_records_fi', 'sort' => 20],
            ],
        ],
        [
            'id' => 9302,
            'parent_id' => 0,
            'name' => 'franchise-device',
            'title' => '设备管理',
            'icon' => 'device',
            'children' => [
                ['id' => 9321, 'parent_id' => 9302, 'name' => 'franchise-devices', 'title' => '设备管理', 'icon' => 'device', 'jump' => '/iframe/link/vehicleslite', 'sort' => 10],
                ['id' => 9322, 'parent_id' => 9302, 'name' => 'franchise-device-lock', 'title' => '挂车占有', 'icon' => 'tools', 'jump' => '/iframe/link/fill_energy', 'sort' => 20],
            ],
        ],
        [
            'id' => 9303,
            'parent_id' => 0,
            'name' => 'franchise-venue',
            'title' => '场地管理',
            'icon' => 'venue',
            'children' => [
                ['id' => 9331, 'parent_id' => 9303, 'name' => 'franchise-venues', 'title' => '场地管理', 'icon' => 'venue', 'jump' => '/iframe/link/venue', 'sort' => 10],
                ['id' => 9332, 'parent_id' => 9303, 'name' => 'franchise-pricing', 'title' => '资费套餐', 'icon' => 'finance', 'jump' => '/iframe/link/pricing_options', 'sort' => 20],
            ],
        ],
        [
            'id' => 9304,
            'parent_id' => 0,
            'name' => 'franchise-finance',
            'title' => '财务管理',
            'icon' => 'finance',
            'children' => [
                ['id' => 9341, 'parent_id' => 9304, 'name' => 'franchise-income', 'title' => '收入明细', 'icon' => 'finance', 'jump' => '/iframe/link/incomedetails', 'sort' => 10],
                ['id' => 9342, 'parent_id' => 9304, 'name' => 'franchise-withdraw', 'title' => '提现申请', 'icon' => 'finance', 'jump' => '/iframe/link/PaymentDisbursement_optimized', 'sort' => 20],
            ],
        ],
        [
            'id' => 9305,
            'parent_id' => 0,
            'name' => 'franchise-ops',
            'title' => '运营管理',
            'icon' => 'ops',
            'children' => [
                ['id' => 9351, 'parent_id' => 9305, 'name' => 'franchise-violations', 'title' => '违规记录', 'icon' => 'audit', 'jump' => '/iframe/link/ban_Record', 'sort' => 10],
                ['id' => 9352, 'parent_id' => 9305, 'name' => 'franchise-black-users', 'title' => '拉黑用户', 'icon' => 'user', 'jump' => '/iframe/link/black_user_gmt', 'sort' => 20],
                ['id' => 9353, 'parent_id' => 9305, 'name' => 'franchise-feedback', 'title' => '反馈信息', 'icon' => 'ops', 'jump' => '/iframe/link/feedback_mgt', 'sort' => 30],
                ['id' => 9354, 'parent_id' => 9305, 'name' => 'franchise-reporthand', 'title' => '投诉处理', 'icon' => 'audit', 'jump' => '/iframe/link/reporthand', 'sort' => 40],
            ],
        ],
        [
            'id' => 9390,
            'parent_id' => 0,
            'name' => 'franchise-tools',
            'title' => '系统工具',
            'icon' => 'tools',
            'children' => [
                [
                    'id' => 9391,
                    'parent_id' => 9390,
                    'name' => 'franchise-system-profile',
                    'title' => '基本资料',
                    'icon' => 'user',
                    'jump' => '/iframe/link/system_profile',
                    'sort' => 10,
                ],
            ],
        ],
    ];
}

function menu_compact_tree(array $menus, int $roleId): array
{
    if ($roleId === 3) {
        return menu_franchise_tree();
    }

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

        if (menu_contains(menu_text($menu), ['user-list', 'user/user/list', '网站用户'])) {
            $menu['name'] = 'franchise-management';
            $menu['title'] = '加盟管理';
            $jump = '/iframe/link/FranchiseManagement';
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

    menu_append_user_blacklist_menu($groups, $roleId);
    menu_append_system_tools($groups, $roleId);

    $tree = [[
        'id' => 9000,
        'parent_id' => 0,
        'name' => 'dashboard',
        'title' => $roleId === 3 ? '首页' : '工作台',
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
