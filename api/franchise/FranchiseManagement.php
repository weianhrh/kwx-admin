<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../Database.php';
require_once '../lib/venue_scope.php';

function logMessage($message) {
    $logFile = __DIR__ . '/editAupdate.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function json_out(int $code, string $msg, array $data = []): void {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    json_out(1001, '用户未登录或会话已过期');
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !isset($user['role_id'])) {
    json_out(1001, '用户未登录或无权访问');
}

$role_id = (int)$user['role_id'];
if (!in_array($role_id, [1, 2], true)) {
    json_out(1003, '无权访问加盟管理');
}

function franchise_request_venue_ids(): array {
    $raw = $_POST['venue_ids'] ?? $_POST['venue_id'] ?? [];
    if (!is_array($raw)) {
        $raw = [$raw];
    }

    $ids = venue_scope_ints($raw);
    $primary = franchise_request_primary_id($ids);

    if ($primary > 0 && !in_array($primary, $ids, true)) {
        array_unshift($ids, $primary);
    }

    if ($primary > 0) {
        $ordered = [$primary];
        foreach ($ids as $id) {
            if ((int)$id !== $primary) {
                $ordered[] = (int)$id;
            }
        }
        return venue_scope_ints($ordered);
    }

    return $ids;
}

function franchise_request_primary_id(array $venueIds = []): int {
    $value = $_POST['primary_venue_id'] ?? $_POST['venue_id'] ?? '';
    if (is_scalar($value) && ctype_digit((string)$value) && (int)$value > 0) {
        return (int)$value;
    }
    return (int)($venueIds[0] ?? 0);
}

function franchise_primary_venue_id(array $venueIds, int $requestedPrimaryId = 0): ?int {
    if ($requestedPrimaryId > 0 && in_array($requestedPrimaryId, $venueIds, true)) {
        return $requestedPrimaryId;
    }
    return $venueIds[0] ?? null;
}

function franchise_venue_name(mysqli $conn, ?int $venueId): ?string {
    if (!$venueId) return null;
    $vs = $conn->prepare("SELECT venue_name FROM venues WHERE id = ? LIMIT 1");
    if (!$vs) return null;
    $vs->bind_param("i", $venueId);
    $vs->execute();
    $res = $vs->get_result();
    $name = null;
    if ($row = $res->fetch_assoc()) {
        $name = $row['venue_name'];
    }
    $vs->close();
    return $name;
}

function franchise_existing_venue_ids(Database $database, array $venueIds): array {
    $venueIds = venue_scope_ints($venueIds);
    if (!$venueIds) return [];

    $params = [];
    $placeholders = implode(',', array_fill(0, count($venueIds), '?'));
    foreach ($venueIds as $id) {
        $params[] = (string)$id;
    }

    $rows = $database->query("SELECT id FROM venues WHERE id IN ($placeholders)", $params) ?: [];
    $exists = [];
    foreach ($rows as $row) {
        $exists[(int)$row['id']] = true;
    }

    $filtered = [];
    foreach ($venueIds as $id) {
        if (isset($exists[(int)$id])) {
            $filtered[] = (int)$id;
        }
    }
    return $filtered;
}

function franchise_order_ids_with_primary(array $venueIds, int $primaryVenueId): array {
    $venueIds = venue_scope_ints($venueIds);
    if (!$venueIds) return [];
    if ($primaryVenueId <= 0 || !in_array($primaryVenueId, $venueIds, true)) {
        $primaryVenueId = (int)$venueIds[0];
    }

    $ordered = [$primaryVenueId];
    foreach ($venueIds as $id) {
        if ((int)$id !== $primaryVenueId) {
            $ordered[] = (int)$id;
        }
    }
    return venue_scope_ints($ordered);
}

function franchise_sync_user_venues(Database $database, int $adminUserId, array $venueIds, int $primaryVenueId = 0): void {
    if ($adminUserId <= 0 || !venue_scope_has_table($database, 'admin_user_venues')) {
        return;
    }

    $venueIds = franchise_existing_venue_ids($database, $venueIds);
    $venueIds = franchise_order_ids_with_primary($venueIds, $primaryVenueId);
    $primaryVenueId = (int)($venueIds[0] ?? 0);

    $conn = $database->getConnection();
    $del = $conn->prepare("DELETE FROM admin_user_venues WHERE admin_user_id = ? AND relation_type = 'franchise'");
    if ($del) {
        $del->bind_param("i", $adminUserId);
        $del->execute();
        $del->close();
    }

    if (!$venueIds) {
        return;
    }

    $ins = $conn->prepare("INSERT INTO admin_user_venues (admin_user_id, venue_id, relation_type, is_primary) VALUES (?, ?, 'franchise', ?)");
    if (!$ins) {
        throw new Exception('场地绑定写入失败: ' . $conn->error);
    }

    foreach ($venueIds as $venueId) {
        $isPrimary = ((int)$venueId === $primaryVenueId) ? 1 : 0;
        $ins->bind_param("iii", $adminUserId, $venueId, $isPrimary);
        if (!$ins->execute()) {
            $err = $ins->error;
            $ins->close();
            throw new Exception('场地绑定写入失败: ' . $err);
        }
    }
    $ins->close();
}

function franchise_update_legacy_primary(Database $database, int $adminUserId, ?int $primaryVenueId): void {
    $conn = $database->getConnection();
    $venueName = franchise_venue_name($conn, $primaryVenueId);
    $stmt = $conn->prepare("UPDATE admin_users SET venue_id = ?, venue_name = ?, updated_at = NOW() WHERE id = ?");
    if (!$stmt) {
        throw new Exception('更新主场地失败: ' . $conn->error);
    }
    $venueIdForBind = $primaryVenueId ?: null;
    $stmt->bind_param("isi", $venueIdForBind, $venueName, $adminUserId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception('更新主场地失败: ' . $err);
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(1, '无效请求，仅支持 POST 请求');
}

$action = $_POST['action'] ?? '';

try {
    if ($action === 'adduser') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? '3';
        $venueIds = franchise_request_venue_ids();
        $requestedPrimaryId = franchise_request_primary_id($venueIds);
        $venueIds = franchise_existing_venue_ids($database, $venueIds);
        $venueIds = franchise_order_ids_with_primary($venueIds, $requestedPrimaryId);
        $venue_id = franchise_primary_venue_id($venueIds, $requestedPrimaryId);
        $uid      = $_POST['uid'] ?? null;

        if ($username === '' || $password === '') {
            json_out(1, '用户名或密码不能为空');
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $roleInt = (int)$role;
        $conn = $database->getConnection();
        $venue_name = franchise_venue_name($conn, $venue_id);

        $insertSql = "INSERT INTO admin_users (
            username, password, role_id, venue_id, venue_name, uid, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $conn->prepare($insertSql);
        if (!$stmt) {
            throw new Exception("SQL准备失败: " . $conn->error);
        }

        $stmt->bind_param("ssisss", $username, $hashedPassword, $roleInt, $venue_id, $venue_name, $uid);
        if (!$stmt->execute()) {
            throw new Exception("SQL执行失败: " . $stmt->error);
        }

        $newId = (int)$conn->insert_id;
        $stmt->close();
        franchise_sync_user_venues($database, $newId, $venueIds, (int)$venue_id);

        json_out(0, '添加成功');
    }

    if ($action === 'updateuser') {
        $id       = (int)($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? '3';
        $venueIds = franchise_request_venue_ids();
        $requestedPrimaryId = franchise_request_primary_id($venueIds);
        $venueIds = franchise_existing_venue_ids($database, $venueIds);
        $venueIds = franchise_order_ids_with_primary($venueIds, $requestedPrimaryId);
        $venue_id = franchise_primary_venue_id($venueIds, $requestedPrimaryId);
        $uid      = $_POST['uid'] ?? null;

        if ($id <= 0) json_out(1, '缺少ID');
        if ($username === '') json_out(1, '用户名不能为空');

        $roleInt = (int)$role;
        $conn = $database->getConnection();
        $venue_name = franchise_venue_name($conn, $venue_id);

        $fields = "username = ?, role_id = ?, venue_id = ?, venue_name = ?, uid = ?, updated_at = NOW()";
        $types  = "sisss";
        $params = [$username, $roleInt, $venue_id, $venue_name, $uid];

        if ($password !== '') {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $fields = "password = ?, " . $fields;
            $types  = "s" . $types;
            array_unshift($params, $hashedPassword);
        }

        $fields .= " WHERE id = ?";
        $types  .= "i";
        $params[] = $id;

        $stmt = $conn->prepare("UPDATE admin_users SET " . $fields);
        if (!$stmt) {
            throw new Exception("SQL准备失败: " . $conn->error);
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new Exception("SQL执行失败: " . $stmt->error);
        }
        $stmt->close();

        franchise_sync_user_venues($database, $id, $venueIds, (int)$venue_id);
        json_out(0, '更新成功');
    }

    if ($action === 'update_user_venues') {
        $id = (int)($_POST['id'] ?? 0);
        $venueIds = franchise_request_venue_ids();
        $requestedPrimaryId = franchise_request_primary_id($venueIds);
        $venueIds = franchise_existing_venue_ids($database, $venueIds);
        $venueIds = franchise_order_ids_with_primary($venueIds, $requestedPrimaryId);
        $primaryVenueId = (int)($venueIds[0] ?? 0);

        if ($id <= 0) json_out(1, '缺少加盟商账号ID');
        if (!$venueIds) json_out(1, '请至少绑定一个场地');

        franchise_update_legacy_primary($database, $id, $primaryVenueId);
        franchise_sync_user_venues($database, $id, $venueIds, $primaryVenueId);
        json_out(0, '场地绑定已保存');
    }

    if ($action === 'loadingdata') {
        $query = "SELECT
            id, username, role_id, venue_name, venue_id, uid, created_at, updated_at, session_token
        FROM admin_users
        ORDER BY id DESC";

        $stmt = $database->getConnection()->prepare($query);
        if (!$stmt) throw new Exception('SQL准备失败: ' . $database->getConnection()->error);
        $stmt->execute();
        $result = $stmt->get_result();

        $list = [];
        while ($row = $result->fetch_assoc()) {
            $row['venue_ids'] = venue_scope_ints([$row['venue_id'] ?? null]);
            $row['venue_names'] = $row['venue_name'] ?? '';
            $row['primary_venue_id'] = (int)($row['venue_id'] ?? 0);
            $row['primary_venue_name'] = $row['venue_name'] ?? '';
            $row['venues'] = [];
            $list[] = $row;
        }
        $stmt->close();

        if ($list && venue_scope_has_table($database, 'admin_user_venues')) {
            $relationRows = $database->query("
                SELECT auv.admin_user_id, auv.venue_id, auv.relation_type, auv.is_primary, v.venue_name
                FROM admin_user_venues auv
                LEFT JOIN venues v ON v.id = auv.venue_id
                WHERE auv.relation_type = 'franchise'
                ORDER BY auv.admin_user_id ASC, auv.is_primary DESC, auv.venue_id ASC
            ") ?: [];

            $map = [];
            foreach ($relationRows as $rel) {
                $adminUserId = (int)($rel['admin_user_id'] ?? 0);
                $venueId = (int)($rel['venue_id'] ?? 0);
                if ($adminUserId <= 0 || $venueId <= 0) continue;

                if (!isset($map[$adminUserId])) {
                    $map[$adminUserId] = [
                        'ids' => [],
                        'names' => [],
                        'primary_id' => 0,
                        'primary_name' => '',
                        'venues' => [],
                    ];
                }

                $name = $rel['venue_name'] ?: ('场地 ' . $venueId);
                $isPrimary = (int)($rel['is_primary'] ?? 0) === 1;
                $map[$adminUserId]['ids'][] = $venueId;
                $map[$adminUserId]['names'][] = $name;
                $map[$adminUserId]['venues'][] = [
                    'venue_id' => $venueId,
                    'venue_name' => $name,
                    'relation_type' => $rel['relation_type'] ?? 'franchise',
                    'is_primary' => $isPrimary ? 1 : 0,
                ];
                if ($isPrimary || !$map[$adminUserId]['primary_id']) {
                    $map[$adminUserId]['primary_id'] = $venueId;
                    $map[$adminUserId]['primary_name'] = $name;
                }
            }

            foreach ($list as &$row) {
                $id = (int)($row['id'] ?? 0);
                if (!empty($map[$id]['ids'])) {
                    $row['venue_ids'] = $map[$id]['ids'];
                    $row['venue_names'] = implode('、', $map[$id]['names']);
                    $row['primary_venue_id'] = (int)$map[$id]['primary_id'];
                    $row['primary_venue_name'] = $map[$id]['primary_name'];
                    $row['venues'] = $map[$id]['venues'];
                }
            }
            unset($row);
        }

        json_out(200, '数据加载成功', $list);
    }

    if ($action === 'get_venues') {
        $query = "SELECT id, venue_name, venue_status FROM venues ORDER BY id DESC";
        $stmt = $database->getConnection()->prepare($query);
        if (!$stmt) throw new Exception('SQL准备失败: ' . $database->getConnection()->error);
        $stmt->execute();
        $result = $stmt->get_result();

        $list = [];
        while ($row = $result->fetch_assoc()) {
            $list[] = $row;
        }
        $stmt->close();

        json_out(200, '场地数据加载成功', $list);
    }

    if ($action === 'get_role') {
        $query = "SELECT id, role_name FROM roles ORDER BY id ASC";
        $stmt = $database->getConnection()->prepare($query);
        if (!$stmt) throw new Exception('SQL准备失败: ' . $database->getConnection()->error);
        $stmt->execute();
        $result = $stmt->get_result();

        $list = [];
        while ($row = $result->fetch_assoc()) {
            $list[] = $row;
        }
        $stmt->close();

        json_out(200, '角色数据加载成功', $list);
    }

    json_out(1, '无效的 action 参数');
} catch (Exception $e) {
    logMessage('FranchiseManagement error: ' . $e->getMessage());
    json_out(500, '操作失败: ' . $e->getMessage());
}
?>
