<?php
// 引入数据库类文件
require_once '../Database.php';  

// 创建数据库连接实例
$database = new Database();

// 从 Cookie 中获取 session_token，如果不存在则赋值为 null
$session_token = $_COOKIE['session_token'] ?? null;

// 验证 session_token 是否存在
if (!$session_token) {
    // 如果不存在，返回用户未登录或会话已过期的 JSON 响应
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

// 通过 session_token 获取用户信息
$user = $database->getUserBySessionToken($session_token);

// 检查用户信息和角色 ID 是否存在
if (!$user || !isset($user['role_id'])) {
    // 如果不存在，返回用户未登录或无权访问的 JSON 响应
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

// 获取用户的角色 ID
$role_id = $user['role_id'];

// 假设这里可以根据角色 ID 进行权限判断，暂时简化处理，只要有角色 ID 就认为有权限
try {
    // 根据不同请求类型执行不同操作
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update-btn'])) {
            // 执行更新操作
            $product_id = $_POST['product_id'] ?? null;
            $title = $_POST['title'];
            $price = $_POST['price'];
            $value = $_POST['value'];
            $extra_value = $_POST['extra_value'];

            if ($product_id) {
                $updateQuery = "UPDATE Products SET title = ?, price = ?, value = ?, extra_value = ? WHERE id = ?";
                $stmt = $database->getConnection()->prepare($updateQuery);
                $stmt->bind_param('sdddi', $title, $price, $value, $extra_value, $product_id);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    echo json_encode(['code' => 200, 'msg' => '产品更新成功']);
                } else {
                    echo json_encode(['code' => 400, 'msg' => '更新失败，可能是没有更改']);
                }
            } else {
                echo json_encode(['code' => 400, 'msg' => '缺少必要参数']);
            }
        } elseif (isset($_POST['delete-btn'])) {
            // 执行删除操作
            $product_id = $_POST['product_id'] ?? null;

            if ($product_id) {
                $deleteQuery = "DELETE FROM Products WHERE id = ?";
                $stmt = $database->getConnection()->prepare($deleteQuery);
                $stmt->bind_param('i', $product_id);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    echo json_encode(['code' => 200, 'msg' => '产品删除成功']);
                } else {
                    echo json_encode(['code' => 400, 'msg' => '删除失败']);
                }
            } else {
                echo json_encode(['code' => 400, 'msg' => '缺少必要参数']);
            }
        } elseif (isset($_POST['add-btn'])) {
            // 执行添加操作
            $title = $_POST['title'] ?? '';
            $price = $_POST['price'] ?? 0.00;
            $value = $_POST['value'] ?? 0.00;
            $extra_value = $_POST['extra_value'] ?? 0.00;

            $insertQuery = "INSERT INTO Products (title, price, value, extra_value) VALUES (?, ?, ?, ?)";
            $stmt = $database->getConnection()->prepare($insertQuery);
            $stmt->bind_param('sddd', $title, $price, $value, $extra_value);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                echo json_encode(['code' => 200, 'msg' => '产品添加成功']);
            } else {
                echo json_encode(['code' => 400, 'msg' => '添加失败']);
            }
        } else {
            echo json_encode(['code' => 400, 'msg' => '未识别的操作']);
        }
    } else {
        // 查询操作
        $query = "SELECT id, title, price, value, extra_value FROM Products";
        $stmt = $database->getConnection()->prepare($query);
        $stmt->execute();

        // 获取查询结果
        $result = $stmt->get_result();

        // 如果有查询结果
        if ($result->num_rows > 0) {
            $products = [];
            while ($row = $result->fetch_assoc()) {
                $products[] = [
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'price' => $row['price'],
                    'value' => $row['value'],
                    'extra_value' => $row['extra_value']
                ];
            }

            // 返回成功响应的 JSON 数据
            echo json_encode(['code' => 200, 'msg' => '成功', 'data' => $products]);
        } else {
            // 如果没有结果，返回空数组
            echo json_encode(['code' => 200, 'msg' => '没有找到产品', 'data' => []]);
        }
    }
} catch (Exception $e) {
    // 捕获数据库异常并返回错误信息的 JSON 数据
    echo json_encode(['code' => 500, 'msg' => '数据库错误: ' . $e->getMessage()]);
}
?>
