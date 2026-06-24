<?php
require_once '../Database.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Bangkok');

function json_out($code, $msg, $data = null)
{
    $resp = ['code' => $code, 'msg' => $msg];
    if ($data !== null) {
        $resp['data'] = $data;
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

function post_string($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function post_int($key, $default = 0)
{
    return isset($_POST[$key]) ? (int)$_POST[$key] : $default;
}

function post_float($key, $default = 0)
{
    return isset($_POST[$key]) ? (float)$_POST[$key] : $default;
}

function post_array($key)
{
    if (!isset($_POST[$key])) {
        return [];
    }
    return is_array($_POST[$key]) ? $_POST[$key] : [$_POST[$key]];
}

function get_int($key, $default = 0)
{
    return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
}

function normalize_platform_types($values)
{
    $allow = ['ios', 'web', 'android'];
    $picked = [];

    foreach ($values as $value) {
        $value = strtolower(trim((string)$value));
        if (in_array($value, $allow, true) && !in_array($value, $picked, true)) {
            $picked[] = $value;
        }
    }

    $ordered = [];
    foreach ($allow as $platform) {
        if (in_array($platform, $picked, true)) {
            $ordered[] = $platform;
        }
    }

    return implode(',', $ordered);
}

function is_valid_currency($currency)
{
    return preg_match('/^[A-Z]{3,16}$/', $currency) === 1;
}

try {
    $database = new Database();
    $conn = $database->getConnection();

    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        json_out(1001, '用户未登录或会话已过期');
    }

    $user = $database->getUserBySessionToken($session_token);
    if (!$user || !isset($user['role_id'])) {
        json_out(1001, '用户未登录或无权访问');
    }

    $roleId = (int)$user['role_id'];
    if (!in_array($roleId, [1, 2], true)) {
        json_out(1002, '无权访问该接口');
    }

    $action = $_POST['action'] ?? $_GET['action'] ?? 'list';
    $action = trim((string)$action);

    if ($action === 'list') {
        $sql = 'SELECT * FROM iap_gold_products ORDER BY sort_order ASC, id ASC';
        $rows = $database->query($sql);
        json_out(200, 'ok', $rows ?: []);
    }

    if ($action === 'detail') {
        $id = get_int('id');
        if ($id <= 0) {
            json_out(400, '参数错误');
        }

        $stmt = $conn->prepare('SELECT * FROM iap_gold_products WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            json_out(404, '套餐不存在');
        }

        json_out(200, 'ok', $row);
    }

    if ($action === 'save') {
        $id = post_int('id');
        $product_id = post_string('product_id');
        $platform_type = normalize_platform_types(post_array('platform_type'));
        $product_name = post_string('product_name');
        $gold_amount = post_int('gold_amount');
        $bonus_gold = post_int('bonus_gold');
        $price = round(post_float('price'), 2);
        $currency = strtoupper(post_string('currency', 'RMB'));
        $is_active = post_int('is_active', 1) ? 1 : 0;

        if ($product_id === '' || $product_name === '') {
            json_out(400, '商品ID和商品名称不能为空');
        }

        if ($platform_type === '') {
            json_out(400, '请至少选择一个平台');
        }

        if (!is_valid_currency($currency)) {
            json_out(400, '币种格式不正确');
        }

        // 现在按你新的商品 ID 规则校验
if (!preg_match('/^com\.rcwulian\.gold\.(\d+)$/', $product_id, $m1)) {
    json_out(400, '商品ID格式必须是 com.rcwulian.gold.x');
}
        if (!preg_match('/^(\d+)金币$/u', $product_name, $m2)) {
            json_out(400, '商品名称格式必须是 x金币');
        }

        $x1 = (int)$m1[1];
        $x2 = (int)$m2[1];
        $x3 = (int)$gold_amount;

        if (!($x1 === $x2 && $x2 === $x3)) {
            json_out(400, '商品ID、商品名称、金币数量三者必须对应同一个 x');
        }

        if ($gold_amount < 0 || $bonus_gold < 0 || $price < 0) {
            json_out(400, '金币数量、赠送金币、价格不能小于 0');
        }

        if ($id > 0) {
            $checkStmt = $conn->prepare('SELECT id FROM iap_gold_products WHERE product_id = ? AND id <> ? LIMIT 1');
            $checkStmt->bind_param('si', $product_id, $id);
        } else {
            $checkStmt = $conn->prepare('SELECT id FROM iap_gold_products WHERE product_id = ? LIMIT 1');
            $checkStmt->bind_param('s', $product_id);
        }

        $checkStmt->execute();
        $checkRes = $checkStmt->get_result();
        if ($checkRes && $checkRes->fetch_assoc()) {
            $checkStmt->close();
            json_out(400, 'product_id 已存在，请换一个');
        }
        $checkStmt->close();

        if ($id > 0) {
            $stmt = $conn->prepare(
                'UPDATE iap_gold_products
                 SET product_id = ?, platform_type = ?, product_name = ?, gold_amount = ?, bonus_gold = ?, price = ?, currency = ?, is_active = ?, updated_at = NOW()
                 WHERE id = ? LIMIT 1'
            );
            $stmt->bind_param(
                'sssiidsii',
                $product_id,
                $platform_type,
                $product_name,
                $gold_amount,
                $bonus_gold,
                $price,
                $currency,
                $is_active,
                $id
            );

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                json_out(500, '修改失败：' . $err);
            }
            $stmt->close();

            json_out(200, '金币套餐修改成功');
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                'INSERT INTO iap_gold_products (
                    product_id, platform_type, product_name, gold_amount, bonus_gold, price, currency, is_active, sort_order, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())'
            );
            $stmt->bind_param(
                'sssiidsi',
                $product_id,
                $platform_type,
                $product_name,
                $gold_amount,
                $bonus_gold,
                $price,
                $currency,
                $is_active
            );

            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new Exception('新增失败：' . $err);
            }

            $newId = (int)$conn->insert_id;
            $stmt->close();

            $autoSort = $newId * 10;
            $stmt2 = $conn->prepare('UPDATE iap_gold_products SET sort_order = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
            $stmt2->bind_param('ii', $autoSort, $newId);

            if (!$stmt2->execute()) {
                $err = $stmt2->error;
                $stmt2->close();
                throw new Exception('排序写入失败：' . $err);
            }

            $stmt2->close();
            $conn->commit();

            json_out(200, '金币套餐新增成功', [
                'id' => $newId,
                'sort_order' => $autoSort
            ]);
        } catch (Throwable $e) {
            $conn->rollback();
            json_out(500, $e->getMessage());
        }
    }

    if ($action === 'delete') {
        $id = post_int('id');
        if ($id <= 0) {
            json_out(400, '参数错误');
        }

        $stmt = $conn->prepare('DELETE FROM iap_gold_products WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            json_out(500, '删除失败：' . $err);
        }

        $stmt->close();
        json_out(200, '金币套餐删除成功');
    }

    if ($action === 'toggle') {
        $id = post_int('id');
        $is_active = post_int('is_active') ? 1 : 0;

        if ($id <= 0) {
            json_out(400, '参数错误');
        }

        $stmt = $conn->prepare('UPDATE iap_gold_products SET is_active = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
        $stmt->bind_param('ii', $is_active, $id);

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            json_out(500, '状态切换失败：' . $err);
        }

        $stmt->close();
        json_out(200, $is_active ? '套餐已启用' : '套餐已停用');
    }

    json_out(400, '未知操作');
} catch (Throwable $e) {
    json_out(500, '服务异常：' . $e->getMessage());
}