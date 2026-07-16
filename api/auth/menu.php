<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

auth_json_headers();
auth_handle_options();

/**
 * 临时硬编码菜单接口
 *
 * 写法参考旧版 getMenuByRoleId($roleId)：
 * - 菜单直接在 PHP 数组里维护；
 * - 不读取 admin_menus 表；
 * - 仍保留 session_token 登录校验；
 * - 仍返回新后台需要的 data.menus + data.user 结构。
 */

function menu_leaf(int $id, int $parentId, string $name, string $title, string $jump, string $icon = '', int $sort = 0): array
{
    return [
        'id' => $id,
        'parent_id' => $parentId,
        'name' => $name,
        'title' => $title,
        'icon' => $icon,
        'jump' => $jump,
        'sort' => $sort,
    ];
}

function menu_group(int $id, string $name, string $title, string $icon, array $children, int $sort = 0): array
{
    return [
        'id' => $id,
        'parent_id' => 0,
        'name' => $name,
        'title' => $title,
        'icon' => $icon,
        'sort' => $sort,
        'children' => $children,
    ];
}

/**
 * 管理员菜单：role_id = 1 / 2 使用。
 * 后续临时改菜单，只改这里的数组即可。
 */
function menu_admin_tree(): array
{
    $baseMenu = [
        menu_leaf(9000, 0, 'dashboard', '工作台', '', 'home', 0),
    ];

    $baseMenu[] = menu_group(9001, 'device', '设备管理', 'device', [
        menu_leaf(25, 9001, 'device-list', '设备管理', '/iframe/link/deviceMgt', '', 1),
        menu_leaf(38, 9001, 'img-mgmt', '图传管理', '/iframe/link/ImgNumberMgmt', '', 3),
        menu_leaf(27, 9001, 'Faultapproval', '故障审批', '/iframe/link/Faultapproval', '', 4),
        menu_leaf(72, 9001, 'device_information', '摄像管理', '/iframe/link/device_information', 'layui-icon-circle-dot', 5),
    ], 1);

    $baseMenu[] = menu_group(9002, 'venue', '场地管理', 'venue', [
        menu_leaf(28, 9002, 'venue-mgmt', '场地管理', '/iframe/link/VenuesManagement', '', 1),
        menu_leaf(60, 9002, 'pricing_options', '场地套餐', '/iframe/link/pricing_options', '', 1),
        menu_leaf(29, 9002, 'zonemgt', '专区管理', '/iframe/link/zonemgt', '', 2),
    ], 2);

    $baseMenu[] = menu_group(9003, 'order', '订单管理', 'order', [
        menu_leaf(63, 9003, 'order_report', '订单申诉', '/iframe/link/order_report', '', 1),
        menu_leaf(65, 9003, 'AdminDrivingOrders', '订单查询', '/iframe/link/AdminDrivingOrders', 'layui-icon-search', 3),
        menu_leaf(73, 9003, 'recharge_orders_api', '最新支付记录', '/iframe/link/recharge_orders_api', '', 4),
    ], 3);

    $baseMenu[] = menu_group(9004, 'user', '用户管理', 'user', [
        menu_leaf(18, 9004, 'recharge', '用户管理', '/iframe/link/Balancerecharge', '', 0),
        menu_leaf(12, 9004, 'franchise-management', '加盟管理', '/iframe/link/FranchiseManagement', '', 1),
        menu_leaf(70, 9004, 'InvitationRank.html', '邀请排行', '/iframe/link/InvitationRank', '', 1),
        menu_leaf(71, 9004, 'finance_demo', '用户金额核对', '/iframe/link/finance_demo', '', 4),
        menu_leaf(9201, 9004, 'user-blacklist', '用户拉黑管理', '/iframe/link/black_user_gmt', 'user', 9201),
    ], 4);

    $baseMenu[] = menu_group(9005, 'finance', '财务管理', 'finance', [
        menu_leaf(87, 9005, 'venue_funds', '账户管理', '/iframe/link/venue_funds', '', 1),
        menu_leaf(61, 9005, 'CommodityTariff', '充值套餐', '/iframe/link/CommodityTariff', '', 2),
        menu_leaf(77, 9005, 'payment_global_config_manage', '支付管理', '/iframe/link/payment_global_config_manage', '', 3),
        menu_leaf(90051, 9005, 'venue-withdraw-config', '提现配置', '/iframe/link/venue_withdraw_config', '', 5),
        menu_leaf(59, 9005, 'withdraw', '提现审批', '/iframe/link/paylist', '', 6),
        menu_leaf(21, 9005, 'recharge-query', '充值查询', '/iframe/link/Rechargeinquiry', '', 8),
        menu_leaf(24, 9005, 'money-query', '金额查询', '/iframe/link/amountSearch', '', 11),
    ], 5);

    $baseMenu[] = menu_group(9006, 'ops', '运营配置', 'ops', [
        menu_leaf(31, 9006, 'ranking', '业绩排行', '/iframe/link/top', '', 1),
        menu_leaf(80, 9006, 'energygift', '赠送能量', '/iframe/link/energygift', '', 2),
        menu_leaf(67, 9006, 'MessageMgmt', '公告通知', '/iframe/link/MessageMgmt', '', 1),
        menu_leaf(68, 9006, 'redis_notification', '飘瓶消息', '/iframe/link/redis_notification', '', 2),
        menu_leaf(49, 9006, 'global_config_api', '全局配置', '/iframe/link/global_config_api', 'layui-icon-set-fill', 3),
        menu_leaf(79, 9006, 'send_notify', '手机通知', '/iframe/link/send_notify', '', 3),
        menu_leaf(57, 9006, 'app_images_admin', '轮播图管理', '/iframe/link/app_images_admin', '', 5),
    ], 6);

    $baseMenu[] = menu_group(9007, 'audit', '巡查审核', 'audit', [
        menu_leaf(40, 9007, 'pidtext', '图文审核', '/iframe/link/pidtrueAndtextPedding', '', 2),
        menu_leaf(64, 9007, 'reporthand', '订单投诉', '/iframe/link/reporthand', '', 2),
        // 临时隐藏违规词管理，需要恢复时取消下面这一行注释即可。
        // menu_leaf(81, 9007, 'barrage_words_mgt', '违规词管理', '/iframe/link/barrage_words_mgt', '', 2),
        menu_leaf(41, 9007, 'ai_pratol', 'AI 巡查面板', '/iframe/link/ai_pratol', '', 3),
        menu_leaf(42, 9007, 'pop_back', '人工巡查面板', '/iframe/link/pop_back', '', 4),
        menu_leaf(43, 9007, 'ban-record', '违规记录查询', '/iframe/link/ban_Record', '', 5),
    ], 7);

    $baseMenu[] = menu_group(9008, 'tools', '系统工具', 'tools', [
        menu_leaf(36, 9008, 'oss-list', '图床管理', '/iframe/link/ossImgGetlist', '', 1),
        menu_leaf(9101, 9008, 'system-profile', '基本资料', '/iframe/link/system_profile', 'user', 9101),
    ], 8);

    return $baseMenu;
}

/**
 * 加盟商菜单：role_id = 3 使用。
 */
function menu_franchise_tree(): array
{
    $baseMenu = [
        menu_leaf(9000, 0, 'dashboard', '首页', '', 'home', 0),
    ];

    $baseMenu[] = menu_group(9301, 'franchise-order', '订单管理', 'order', [
        menu_leaf(9311, 9301, 'franchise-orders', '订单管理', '/iframe/link/franchise_driving_orders', 'order', 10),
        menu_leaf(9312, 9301, 'franchise-refunds', '退款记录', '/iframe/link/refund_records_fi', 'finance', 20),
    ], 1);

    $baseMenu[] = menu_group(9302, 'franchise-device', '设备管理', 'device', [
        menu_leaf(9321, 9302, 'franchise-devices', '设备管理', '/iframe/link/vehicleslite', 'device', 10),
        menu_leaf(9322, 9302, 'franchise-device-lock', '挂车占有', '/iframe/link/fill_energy', 'tools', 20),
    ], 2);

    $baseMenu[] = menu_group(9303, 'franchise-venue', '场地管理', 'venue', [
        menu_leaf(9331, 9303, 'franchise-venues', '场地管理', '/iframe/link/venue', 'venue', 10),
    ], 3);

    $baseMenu[] = menu_group(9304, 'franchise-finance', '财务管理', 'finance', [
        menu_leaf(9341, 9304, 'franchise-income', '收入明细', '/iframe/link/incomedetails', 'finance', 10),
        menu_leaf(9342, 9304, 'franchise-withdraw', '提现申请', '/iframe/link/PaymentDisbursement_optimized', 'finance', 20),
    ], 4);

    $baseMenu[] = menu_group(9305, 'franchise-ops', '运营管理', 'ops', [
        menu_leaf(9351, 9305, 'franchise-violations', '违规记录', '/iframe/link/ban_Record', 'audit', 10),
        menu_leaf(9352, 9305, 'franchise-black-users', '拉黑用户', '/iframe/link/black_user_gmt', 'user', 20),
        menu_leaf(9353, 9305, 'franchise-feedback', '反馈信息', '/iframe/link/feedback_mgt', 'ops', 30),
        menu_leaf(9354, 9305, 'franchise-reporthand', '投诉处理', '/iframe/link/reporthand', 'audit', 40),
    ], 5);

    $baseMenu[] = menu_group(9390, 'franchise-tools', '系统工具', 'tools', [
        menu_leaf(9391, 9390, 'franchise-system-profile', '基本资料', '/iframe/link/system_profile', 'user', 10),
    ], 90);

    return $baseMenu;
}

/**
 * 参考旧项目 getMenuByRoleId 的入口函数。
 */
function getMenuByRoleId(int $roleId): array
{
    if ($roleId === 3) {
        return menu_franchise_tree();
    }

    // role_id = 1 / 2，以及其它后台角色，暂时都走管理员菜单。
    return menu_admin_tree();
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

$roleId = (int)($user['role_id'] ?? 0);
$db->close();

auth_out(0, 'ok', [
    'menus' => getMenuByRoleId($roleId),
    'user' => auth_user_payload($user),
]);
