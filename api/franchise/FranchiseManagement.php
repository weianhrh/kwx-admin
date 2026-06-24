<?php
// 开启会话
session_start();

require_once '../Database.php';
function logMessage($message) {
    $logFile = __DIR__ . '/editAupdate.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}
// 创建数据库连接
$database = new Database();

// 获取 session_token
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

// 验证用户信息
$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

$role_id = $user['role_id'];

// 获取请求方法
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // 获取 action 参数
    $action = $_POST['action'] ?? '';

    // ===== 1) 新增用户 =====
    if ($action === 'adduser') {
        // 获取请求参数
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? '3';      // 默认为加盟商
        $venue_id = $_POST['venue_id'] ?? null; // 场地 ID
        $uid      = $_POST['uid'] ?? null;      // 绑定的用户 uid

        if (!$username || !$password) {
            echo json_encode(['code' => 1, 'msg' => '用户名或密码不能为空', 'data' => []]);
            exit;
        }

        // 密码加密
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $roleInt        = (int)$role;

        try {
            // 开启错误报告
            error_reporting(E_ALL);
            ini_set('display_errors', 1);

            // 获取数据库连接
            $conn = $database->getConnection();
            if (!$conn) {
                throw new Exception("数据库连接失败");
            }

            // ===== 先根据 venue_id 查出 venue_name =====
            $venue_name = null;
            if (!empty($venue_id)) {
                $vs = $conn->prepare("SELECT venue_name FROM venues WHERE id = ? LIMIT 1");
                if ($vs) {
                    $vs->bind_param("i", $venue_id);
                    $vs->execute();
                    $res = $vs->get_result();
                    if ($row = $res->fetch_assoc()) {
                        $venue_name = $row['venue_name'];
                    }
                    $vs->close();
                }
            }

            // 调试日志
            logMessage("接收到的参数：");
            logMessage("username: " . $username);
            logMessage("role: " . $roleInt);
            logMessage("venue_id: " . $venue_id);
            logMessage("venue_name: " . $venue_name);
            logMessage("uid: " . $uid);

            // 把 venue_name 一起写入
            $insertSql = "INSERT INTO admin_users (
                username,
                password,
                role_id,
                venue_id,
                venue_name,
                uid,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";

            $params = [
                $username,
                $hashedPassword,
                $roleInt,
                $venue_id,
                $venue_name,
                $uid
            ];

            logMessage("执行的SQL: " . $insertSql);
            logMessage("SQL参数: " . json_encode($params));

            $stmt = $conn->prepare($insertSql);
            if (!$stmt) {
                throw new Exception("SQL准备失败: " . $conn->error);
            }

            // s(s) s(s) i(i) s(venue_id 当字符串) s(venue_name) s(uid)
            $stmt->bind_param("ssisss", $username, $hashedPassword, $roleInt, $venue_id, $venue_name, $uid);

            if (!$stmt->execute()) {
                throw new Exception("SQL执行失败: " . $stmt->error);
            }

            echo json_encode(['code' => 0, 'msg' => '添加成功', 'data' => []]);
            exit;

        } catch (Exception $e) {
            logMessage("错误详情: " . $e->getMessage());
            echo json_encode([
                'code' => 1,
                'msg'  => '用户注册失败,用户名已存在,请重新换的未注册的用户名: ' . $e->getMessage(),
                'data' => []
            ]);
            exit;
        }

        // $database->close(); // 一般放到脚本末尾或交给 PHP 结束时自动关闭

    // ===== 2) 更新用户 =====
    } elseif ($action === 'updateuser') {
        // 更新加盟商信息
        $id       = $_POST['id'] ?? null;
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? '3';
        $venue_id = $_POST['venue_id'] ?? null;
        $uid      = $_POST['uid'] ?? null;

        if (!$id) {
            echo json_encode(['code' => 1, 'msg' => '缺少ID', 'data' => []]);
            exit;
        }

        if (!$username) {
            echo json_encode(['code' => 1, 'msg' => '用户名不能为空', 'data' => []]);
            exit;
        }

        $roleInt = (int)$role;

        try {
            $conn = $database->getConnection();
            if (!$conn) {
                throw new Exception("数据库连接失败");
            }

            // 先根据 venue_id 查 venue_name
            $venue_name = null;
            if (!empty($venue_id)) {
                $vs = $conn->prepare("SELECT venue_name FROM venues WHERE id = ? LIMIT 1");
                if ($vs) {
                    $vs->bind_param("i", $venue_id);
                    $vs->execute();
                    $res = $vs->get_result();
                    if ($row = $res->fetch_assoc()) {
                        $venue_name = $row['venue_name'];
                    }
                    $vs->close();
                }
            }

            // 根据有没有填写密码，决定是否更新密码
            // 把 venue_name 一起更新
            $fields = "username = ?, role_id = ?, venue_id = ?, venue_name = ?, uid = ?, updated_at = NOW()";
            $types  = "sisss"; // username(s), role(i), venue_id(s), venue_name(s), uid(s)
            $params = [$username, $roleInt, $venue_id, $venue_name, $uid];

            if (!empty($password)) {
                // 用户有输入新密码，则更新密码
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $fields = "password = ?, " . $fields;
                $types  = "s" . $types;
                array_unshift($params, $hashedPassword);
            }

            // WHERE 条件
            $fields .= " WHERE id = ?";
            $types  .= "i";
            $params[] = $id;

            $sql = "UPDATE admin_users SET " . $fields;

            // 调试日志
            logMessage("UPDATE SQL: " . $sql);
            logMessage("UPDATE params: " . json_encode($params));

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("SQL准备失败: " . $conn->error);
            }

            // 动态绑定参数
            $stmt->bind_param($types, ...$params);

            if (!$stmt->execute()) {
                throw new Exception("SQL执行失败: " . $stmt->error);
            }

            echo json_encode(['code' => 0, 'msg' => '更新成功', 'data' => []]);
            exit;

        } catch (Exception $e) {
            logMessage("更新用户错误: " . $e->getMessage());
            echo json_encode([
                'code' => 1,
                'msg'  => '用户更新失败,用户名已存在,请重新换的未注册的用户名: ' . $e->getMessage(),
                'data' => []
            ]);
            exit;
        }

    // ===== 3) 加载 admin_users 列表 =====
    } elseif ($action === 'loadingdata') {
        try {
            $query = "SELECT
                id,
                username,
                role_id,
                venue_name,
                venue_id,
                uid,
                created_at,
                updated_at,
                session_token
            FROM admin_users";

            $stmt = $database->getConnection()->prepare($query);
            $stmt->execute();
            $result = $stmt->get_result();

            $list = [];
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $list[] = $row;
                }
            }

            echo json_encode(['code' => 200, 'msg' => '数据加载成功', 'data' => $list]);
        } catch (Exception $e) {
            echo json_encode(['code' => 500, 'msg' => '数据库查询出错: ' . $e->getMessage(), 'data' => []]);
        }

    // ===== 4) 场地列表 =====
    } elseif ($action === 'get_venues') {
        try {
            $query = "SELECT id, venue_name FROM venues";
            $stmt = $database->getConnection()->prepare($query);
            $stmt->execute();
            $result = $stmt->get_result();

            $list = [];
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $list[] = $row;
                }
            }

            echo json_encode(['code' => 200, 'msg' => '场地数据加载成功', 'data' => $list]);
        } catch (Exception $e) {
            echo json_encode(['code' => 500, 'msg' => '数据库查询出错: ' . $e->getMessage(), 'data' => []]);
        }

    // ===== 5) 角色列表 =====
    } elseif ($action === 'get_role') {
        try {
            $query = "SELECT id, role_name FROM roles";
            $stmt = $database->getConnection()->prepare($query);
            $stmt->execute();
            $result = $stmt->get_result();

            $list = [];
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $list[] = $row;
                }
            }

            echo json_encode(['code' => 200, 'msg' => '角色数据加载成功', 'data' => $list]);
        } catch (Exception $e) {
            echo json_encode(['code' => 500, 'msg' => '数据库查询出错: ' . $e->getMessage(), 'data' => []]);
        }

    } else {
        echo json_encode(['code' => 1, 'msg' => '无效的 action 参数', 'data' => []]);
    }

} else {
    echo json_encode(['code' => 1, 'msg' => '无效请求，仅支持 POST 请求', 'data' => []]);
}
?>
