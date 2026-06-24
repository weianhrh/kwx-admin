<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Shanghai');

require_once '../Database.php';

/**
 * 锁定订单记录接口
 * 表：order_lock_records
 * 支持：
 * 1. action=list   分页列表
 * 2. action=venues 场地下拉框
 */

function json_out($code = 0, $msg = '', $extra = []) {
    echo json_encode(array_merge([
        'code' => $code,
        'msg'  => $msg,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function getDbConn($database) {
    foreach (['getConnection', 'getConn', 'getPdo', 'getPDO'] as $method) {
        if (method_exists($database, $method)) {
            $conn = $database->$method();
            if ($conn) {
                return $conn;
            }
        }
    }

    foreach (['pdo', 'conn', 'mysqli', 'db', 'link'] as $prop) {
        if (isset($database->$prop) && $database->$prop) {
            return $database->$prop;
        }
    }

    throw new Exception('Database.php 未暴露数据库连接，请在 getDbConn() 中改成你的连接变量');
}

function bindMysqliParams($stmt, &$params) {
    if (empty($params)) {
        return;
    }

    $types = '';
    foreach ($params as $v) {
        if (is_int($v)) {
            $types .= 'i';
        } elseif (is_float($v)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
    }

    $bind = [$types];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function dbFetchAll($conn, $sql, $params = []) {
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($conn instanceof mysqli) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        bindMysqliParams($stmt, $params);
        $stmt->execute();

        $result = $stmt->get_result();
        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }

        $stmt->close();
        return $rows;
    }

    throw new Exception('不支持的数据库连接类型');
}

function dbFetchOne($conn, $sql, $params = []) {
    $rows = dbFetchAll($conn, $sql, $params);
    return $rows[0] ?? null;
}

function dbValue($conn, $sql, $params = []) {
    $row = dbFetchOne($conn, $sql, $params);
    if (!$row) {
        return 0;
    }

    $values = array_values($row);
    return $values[0] ?? 0;
}

try {
    $database = new Database();
    $conn = getDbConn($database);

    if ($conn instanceof PDO) {
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    if ($conn instanceof mysqli) {
        $conn->set_charset('utf8mb4');
    }

    $session_token = $_COOKIE['session_token'] ?? '';
    if (!$session_token) {
        json_out(1001, '用户未登录或会话已过期', [
            'count' => 0,
            'data'  => []
        ]);
    }

    if (method_exists($database, 'getUserBySessionToken')) {
        $user = $database->getUserBySessionToken($session_token);
    } else {
        $user = dbFetchOne(
            $conn,
            "SELECT * FROM admin_users WHERE session_token = ? LIMIT 1",
            [$session_token]
        );
    }

    if (!$user) {
        json_out(1001, '用户未登录或会话已过期', [
            'count' => 0,
            'data'  => []
        ]);
    }

    $role_id = (int)($user['role_id'] ?? 0);

    // 管理员看全部，其他账号只看自己绑定场地
    $canViewAll = in_array($role_id, [1, 2], true);

    // 兼容不同字段名
    $userVenueId = (int)(
        $user['venue_id']
        ?? $user['bind_venue_id']
        ?? $user['bind_site']
        ?? 0
    );

    $action = $_GET['action'] ?? 'list';

    /**
     * 场地下拉框
     */
    if ($action === 'venues') {
        if ($canViewAll) {
            $venues = dbFetchAll(
                $conn,
                "SELECT id, venue_name 
                 FROM venues 
                 WHERE venue_status = '营业中'
                 ORDER BY id DESC"
            );
        } else {
            if ($userVenueId <= 0) {
                json_out(1002, '当前账号没有绑定场地', [
                    'data' => []
                ]);
            }

            $venues = dbFetchAll(
                $conn,
                "SELECT id, venue_name 
                 FROM venues 
                 WHERE id = ?
                 LIMIT 1",
                [$userVenueId]
            );
        }

        json_out(0, 'success', [
            'data' => $venues
        ]);
    }

    /**
     * 分页列表
     */
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = (int)($_GET['limit'] ?? $_GET['page_size'] ?? 20);
    $limit = max(1, min($limit, 100));
    $offset = ($page - 1) * $limit;

    $order_id = trim($_GET['order_id'] ?? '');
    $venue_id = (int)($_GET['venue_id'] ?? 0);

    $where = ['1=1'];
    $params = [];

    if ($order_id !== '') {
        $where[] = 'olr.order_id LIKE ?';
        $params[] = '%' . $order_id . '%';
    }

    if ($canViewAll) {
        if ($venue_id > 0) {
            $where[] = 'olr.venue_id = ?';
            $params[] = $venue_id;
        }
    } else {
        if ($userVenueId <= 0) {
            json_out(1002, '当前账号没有绑定场地，无法查看锁定订单', [
                'count' => 0,
                'data'  => []
            ]);
        }

        $where[] = 'olr.venue_id = ?';
        $params[] = $userVenueId;
    }

    $whereSql = implode(' AND ', $where);

    $countSql = "
        SELECT COUNT(*) AS total
        FROM order_lock_records olr
        WHERE {$whereSql}
    ";

    $total = (int)dbValue($conn, $countSql, $params);

    $listSql = "
        SELECT
            olr.id,
            olr.order_id,
            olr.venue_id,
            v.venue_name,
            olr.lock_amount,
            olr.status,
            olr.operator_uid,
            olr.create_at,
            olr.update_at
        FROM order_lock_records olr
        LEFT JOIN venues v ON v.id = olr.venue_id
        WHERE {$whereSql}
        ORDER BY olr.id DESC
        LIMIT {$offset}, {$limit}
    ";

    $rows = dbFetchAll($conn, $listSql, $params);

    foreach ($rows as &$row) {
        $row['lock_amount'] = number_format((float)$row['lock_amount'], 2, '.', '');

        if ((int)$row['status'] === 1) {
            $row['status_text'] = '锁定';
        } elseif ((int)$row['status'] === 2) {
            $row['status_text'] = '已解锁';
        } else {
            $row['status_text'] = '未知';
        }

        if (!$row['venue_name']) {
            $row['venue_name'] = '场地ID：' . $row['venue_id'];
        }
    }
    unset($row);

    json_out(0, '', [
        'count' => $total,
        'data'  => $rows
    ]);

} catch (Throwable $e) {
    json_out(500, '服务器错误：' . $e->getMessage(), [
        'count' => 0,
        'data'  => []
    ]);
}