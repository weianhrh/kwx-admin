<?php
/**
 * /api/pay/GetDailyVenueRevenue.php
 *
 * 财务对账列表接口
 * - role_id = 1：可查看全部场地，可通过 GET venue_id 筛选某个场地
 * - role_id != 1：只能查看自己绑定 venue_id 的数据
 * - 全部角色都支持分页：page / limit
 * - 返回每条记录的 venue_id + venue_name，方便前端显示“场地ID - 场地名称”
 */

require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function out_json($code, $msg = '', $data = [], $extra = []) {
    echo json_encode(array_merge([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function req_int($key, $default = 0, $min = null, $max = null) {
    $val = isset($_GET[$key]) ? intval($_GET[$key]) : intval($default);
    if ($min !== null && $val < $min) $val = $min;
    if ($max !== null && $val > $max) $val = $max;
    return $val;
}

$database = new Database();

try {
    // -------------------- 会话校验 --------------------
    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        out_json(1001, '用户未登录或会话已过期', []);
    }

    $user = $database->getUserBySessionToken($session_token);
    if (!$user || empty($user['role_id'])) {
        out_json(1001, '用户未登录或无权访问', []);
    }

    $role_id       = intval($user['role_id']);
    $userVenueId   = intval($user['venue_id'] ?? 0);
    $isAdmin       = ($role_id === 1);

    // -------------------- 分页参数 --------------------
    $page  = req_int('page', 1, 1);
    $limit = req_int('limit', 31, 1, 100); // 最大 100，防止一次拉太多
    $offset = ($page - 1) * $limit;

    // role_id=1 才允许使用 venue_id 筛选
    $filterVenueId = req_int('venue_id', 0, 0);

    // -------------------- 查询条件 --------------------
    $where = ['1=1'];
    $params = [];

    if ($isAdmin) {
        // 管理员：不传 venue_id = 全部场地；传了 venue_id = 只看该场地
        if ($filterVenueId > 0) {
            $where[] = 'd.venue_id = ?';
            $params[] = $filterVenueId;
        }
    } else {
        // 非管理员：强制只能看自己的场地，忽略前端传入的 venue_id
        if ($userVenueId <= 0) {
            out_json(1002, '当前账号未绑定场地', []);
        }
        $where[] = 'd.venue_id = ?';
        $params[] = $userVenueId;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // -------------------- 总数 --------------------
    $countSql = "SELECT COUNT(*) AS count FROM DailyVenueRevenue d {$whereSql}";
    $countRes = $database->query($countSql, $params);
    $totalCount = intval($countRes[0]['count'] ?? 0);
    $totalPages = $totalCount > 0 ? intval(ceil($totalCount / $limit)) : 1;

    // 如果请求页码超过最大页，自动回到最大页
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    // -------------------- 列表 --------------------
    $dataSql = "
        SELECT
            d.id,
            d.venue_id,
            COALESCE(v.venue_name, '') AS venue_name,
            d.date,
            d.total_revenue,
            d.created_at,
            d.updated_at,
            d.is_settled,
            d.is_checked
        FROM DailyVenueRevenue d
        LEFT JOIN venues v ON v.id = d.venue_id
        {$whereSql}
        ORDER BY d.date DESC, d.id DESC
        LIMIT ?, ?
    ";

    $dataParams = $params;
    $dataParams[] = intval($offset);
    $dataParams[] = intval($limit);

    $data = $database->query($dataSql, $dataParams);
    if (!is_array($data)) $data = [];

    out_json(0, '成功', $data, [
        'count'            => $totalCount,
        'page'             => $page,
        'limit'            => $limit,
        'total_pages'      => $totalPages,
        'role_id'          => $role_id,
        'is_admin'         => $isAdmin ? 1 : 0,
        'current_venue_id' => $isAdmin ? $filterVenueId : $userVenueId,
    ]);

} catch (Throwable $e) {
    // 正式环境不建议把 $e->getMessage() 直接返回给前端
    out_json(500, '服务器异常，请稍后再试', []);
} finally {
    if (isset($database)) {
        $database->close();
    }
}
