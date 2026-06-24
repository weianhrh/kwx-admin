<?php
require_once '../Database.php';
header('Content-Type: application/json');

$database = new Database();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action) {
    case 'get':
        handleGet($database);
        break;
    case 'add':
        handleAdd($database);
        break;
    case 'edit':
        handleEdit($database);
        break;
    case 'delete':
        handleDelete($database);
        break;
    default:
        echo json_encode(['code' => 1000, 'msg' => '无效操作', 'data' => []]);
        break;
}

function handleGet($db) {
    $menus = $db->query("SELECT * FROM admin_menus ORDER BY sort ASC");
    $tree = buildTree($menus);
    echo json_encode(['code' => 0, 'msg' => '', 'data' => flattenTree($tree)]);
}

function handleAdd($db) {
    $sql = "INSERT INTO admin_menus (parent_id, name, title, icon, jump, role_ids, sort)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $params = [
        $_POST['parent_id'], $_POST['name'], $_POST['title'], $_POST['icon'],
        $_POST['jump'], $_POST['role_ids'], $_POST['sort']
    ];
    $ok = $db->query($sql, $params, true);
    echo json_encode(['code' => $ok ? 0 : 1, 'msg' => $ok ? '添加成功' : '添加失败']);
}

function handleEdit($db) {
    $sql = "UPDATE admin_menus SET parent_id=?, name=?, title=?, icon=?, jump=?, role_ids=?, sort=? WHERE id=?";
    $params = [
        $_POST['parent_id'], $_POST['name'], $_POST['title'], $_POST['icon'],
        $_POST['jump'], $_POST['role_ids'], $_POST['sort'], $_POST['id']
    ];
    $ok = $db->query($sql, $params, true);
    echo json_encode(['code' => $ok ? 0 : 1, 'msg' => $ok ? '更新成功' : '更新失败']);
}

function handleDelete($db) {
    $sql = "DELETE FROM admin_menus WHERE id = ?";
    $params = [$_POST['id']];
    $ok = $db->query($sql, $params, true);
    echo json_encode(['code' => $ok ? 0 : 1, 'msg' => $ok ? '删除成功' : '删除失败']);
}

function buildTree($items, $parentId = 0, $level = 0) {
    $branch = [];
    foreach ($items as $item) {
        if ($item['parent_id'] == $parentId) {
            $item['level'] = $level;
            $children = buildTree($items, $item['id'], $level + 1);
            $item['children'] = $children;
            $branch[] = $item;
        }
    }
    return $branch;
}

function flattenTree($tree) {
    $result = [];
    foreach ($tree as $node) {
        $children = $node['children'] ?? [];
        unset($node['children']);
        $result[] = $node;
        $result = array_merge($result, flattenTree($children));
    }
    return $result;
}