<?php
require_once '../Database.php';
require_once '../RedisHelper.php';

// 创建数据库连接
$database = new Database();

// 从会话中获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;

// 验证 session_token 并获取用户信息
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $database->getUserBySessionToken($session_token);

// 检查用户是否存在和权限获取
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

$role_id  = $user['role_id'];
$venue_id = $user['venue_id'];
$username = $user['username'];


// ✅ 处理 POST 请求：清除能量（支持指定数量）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId       = $_POST['uid'] ?? null;
    $energyAmount = isset($_POST['amount']) ? floatval($_POST['amount']) : null;

    if (!$userId) {
        echo json_encode(['code' => 1003, 'msg' => '缺少用户ID']);
        exit;
    }

    // 查询当前能量
    $sql = "SELECT energy FROM energy_records WHERE user_uid = ? AND venue_id = ?";
    $result = $database->query($sql, [$userId, $venue_id]);
    $currentEnergy = $result ? floatval($result[0]['energy']) : 0;

    if ($energyAmount !== null) {
        $newEnergy = max(0, $currentEnergy - $energyAmount);
        $updateSql = "UPDATE energy_records SET energy = ? WHERE user_uid = ? AND venue_id = ?";
        $database->query($updateSql, [$newEnergy, $userId, $venue_id]);
        $msg = "已减少 $energyAmount 单位能量";
    } else {
        $updateSql = "UPDATE energy_records SET energy = 0 WHERE user_uid = ? AND venue_id = ?";
        $database->query($updateSql, [$userId, $venue_id]);
        $msg = "已清空能量";
    }

    echo json_encode(['code' => 0, 'msg' => '清除能量成功', 'detail' => $msg]);
    exit;
}


// 获取搜索条件和分页参数
$id       = $_GET['id'] ?? '';
$username = $_GET['username'] ?? '';
$phone    = $_GET['phone'] ?? '';
$page     = isset($_GET['page']) ? (int)$_GET['page'] : 1;   // 当前页码，默认为第一页
$limit    = isset($_GET['limit']) ? (int)$_GET['limit'] : 20; // 每页显示条数，默认为20
$invitation_code = $_GET['invitation_code'] ?? '';
$is_streamer     = $_GET['is_streamer'] ?? '';
$streamer_venue = trim($_GET['streamer_venue'] ?? '');
// 计算分页的起始位置
$offset = ($page - 1) * $limit;

// 构造基础 SQL（不再限制 deleted）
if (!in_array($role_id, [1, 2], true)) {
    // 非管理员
    $sql = "SELECT uid AS id,
                   nickname AS username,
                   IFNULL(headimgurl, 'https://app.kwxapp.cn/img/logo.png') AS avatar,
                   created_at,
                   wallet,
                   gold_balance,
                   last_login AS jointime,
                   deleted,
               IFNULL(is_mute, 0) AS is_mute
            FROM users
            WHERE 1=1";
} else {
    // 管理员
    $sql = "SELECT uid AS id,
                   nickname AS username,
                   IFNULL(headimgurl, 'https://app.kwxapp.cn/img/logo.png') AS avatar,
                   phone_number AS phone,
                   sex,
                   wallet,
                   gold_balance,
                   invitation_code,
                   last_login AS jointime,
                   deleted,
                   terrace,
                   IFNULL(is_streamer, 0) AS is_streamer,
                   streamer_venue,
               IFNULL(is_mute, 0) AS is_mute
            FROM users
            WHERE 1=1";
}

// 条件查询
$params = [];
$filterSql = '';

if ($id !== '') {
    $filterSql .= " AND uid = ?";
    $params[] = $id;
}

if ($username !== '') {
    $filterSql .= " AND nickname LIKE ?";
    $params[] = "%$username%";
}

if ($phone !== '') {
    $filterSql .= " AND phone_number LIKE ?";
    $params[] = "%$phone%";
}

if ($invitation_code !== '') {
    $filterSql .= " AND invitation_code LIKE ?";
    $params[] = "%$invitation_code%";
}

if ($is_streamer !== '') {
    if ($is_streamer === '1,2') {
        $filterSql .= " AND IFNULL(is_streamer, 0) IN (1,2)";
    } elseif ($is_streamer === '0' || $is_streamer === '1' || $is_streamer === '2') {
        $filterSql .= " AND IFNULL(is_streamer, 0) = ?";
        $params[] = (int)$is_streamer;
    }
}

if ($streamer_venue !== '' && ctype_digit($streamer_venue)) {
    $filterSql .= " AND streamer_venue = ?";
    $params[] = (int)$streamer_venue;
}

// 拼接筛选条件
$sql .= $filterSql;

// 先准备 count，注意要在 LIMIT 参数加入前复制 params
$countSql = "SELECT COUNT(*) AS count FROM users WHERE 1=1" . $filterSql;
$countParams = $params;

// 添加排序和分页
$sql .= " ORDER BY created_at DESC LIMIT ?, ?";
$params[] = $offset;
$params[] = $limit;

// 执行列表查询
$result = $database->query($sql, $params);

// 执行总数查询
$countResult = $database->query($countSql, $countParams);
$totalCount  = $countResult ? $countResult[0]['count'] : 0;

// 为每个用户查询能量（不区分管理员 / 非管理员，统一加上 energy 字段）
if ($result) {
    foreach ($result as $key => $u) {
        if (is_array($u) && array_key_exists('id', $u)) {
            $queryBalance = "SELECT energy FROM energy_records WHERE user_uid = ? AND venue_id = ?";
            $energyResult = $database->query($queryBalance, [$u['id'], $venue_id]);
            $u['energy']  = $energyResult ? $energyResult[0]['energy'] : '0.00';  // 没记录默认为0
            $result[$key] = $u;
        }
    }
}

// 计算总数以支持分页（同样不限制 deleted）
$countSql    = "SELECT COUNT(*) AS count FROM users WHERE 1=1";
$countResult = $database->query($countSql);
$totalCount  = $countResult ? $countResult[0]['count'] : 0;

if ($result !== false) {
    echo json_encode([
        'code'  => 0,
        'msg'   => '',
        'count' => $totalCount,
        'data'  => $result
    ]);
} else {
    echo json_encode([
        'code'  => 1,
        'msg'   => '获取用户列表失败',
        'count' => 0,
        'data'  => []
    ]);
}

$database->close();
?>
