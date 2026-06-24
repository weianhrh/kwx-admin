<?php
require_once '../Database.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Singapore');

$db = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

function jsonOut($code, $msg, $data = [])
{
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function canChooseVenue($roleId)
{
    // 只有 role_id == 1 保留下拉框
    return (int)$roleId === 1;
}

function canOperateVehicle($roleId)
{
    // role_id 1/2/3 都可操作
    return in_array((int)$roleId, [1, 2, 3, 4], true);
}

function buildDriverAlias($serialNumber)
{
    $serialNumber = strtoupper(trim((string)$serialNumber));
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $hash = hash('sha256', 'vehicle-driver-' . $serialNumber);

    $rand8 = '';
    for ($i = 0; $i < 8; $i++) {
        $hex = substr($hash, $i * 2, 2);
        $idx = hexdec($hex) % strlen($alphabet);
        $rand8 .= $alphabet[$idx];
    }

    return 'User_' . $rand8;
}

function isSystemHoldVehicle($row)
{
    $status = trim((string)($row['status'] ?? ''));
    $vehicleStatus = trim((string)($row['vehicle_status'] ?? ''));
    $driver = (string)($row['driver'] ?? '');
    $driverId = (int)($row['driver_id'] ?? 0);
    $ownerUid = (int)($row['uid'] ?? 0);
    $serial = (string)($row['serial_number'] ?? '');

    return $status === '占有'
        && $driverId > 0
        && $driverId === $ownerUid
        && $driver === buildDriverAlias($serial)
        && $vehicleStatus === '开始围观';
}

function resolveVenueId($db, $roleId, $userVenueId, $reqVenueId)
{
    if (canChooseVenue($roleId)) {
        if ($reqVenueId > 0) {
            return $reqVenueId;
        }
        if ($userVenueId > 0) {
            return $userVenueId;
        }

        $first = $db->query("SELECT id FROM venues ORDER BY id DESC LIMIT 1");
        return (int)($first[0]['id'] ?? 0);
    }

    return (int)$userVenueId;
}

function getVenueListByRole($db, $roleId, $venueId)
{
    if (canChooseVenue($roleId)) {
        return $db->query("SELECT id, venue_name FROM venues ORDER BY id DESC");
    }

    return $db->query(
        "SELECT id, venue_name FROM venues WHERE id = ? LIMIT 1",
        [$venueId]
    );
}

function getVehicleListByVenue($db, $venueId)
{
    return $db->query(
        "SELECT
            id,
            name,
            serial_number,
            status,
            vehicle_status,
            uid,
            bind_site,
            driver,
            driver_id,
            photo_url,
            updated_at
         FROM vehicles
         WHERE bind_site = ?
         ORDER BY id DESC",
        [$venueId]
    );
}

function appendVehicleFlags(&$row)
{
    $status = trim((string)($row['status'] ?? ''));
    $vehicleStatus = trim((string)($row['vehicle_status'] ?? ''));
    $driverId = (int)($row['driver_id'] ?? 0);
    $ownerUid = (int)($row['uid'] ?? 0);

    if ($status !== '' && $vehicleStatus !== '') {
        $row['current_status'] = $status . ' / ' . $vehicleStatus;
    } elseif ($status !== '') {
        $row['current_status'] = $status;
    } elseif ($vehicleStatus !== '') {
        $row['current_status'] = $vehicleStatus;
    } else {
        $row['current_status'] = '-';
    }

    $row['owner_uid'] = $ownerUid;
    $row['is_system_hold'] = isSystemHoldVehicle($row);

    // 真实玩家占有：
    // 1) driver_id 非空且不是车主 uid
    // 2) status=占有，但不是系统挂车占有
    $row['is_real_player_occupied'] = (
        !$row['is_system_hold'] && (
            ($driverId > 0 && $driverId !== $ownerUid)
            || ($status === '占有' && !$row['is_system_hold'])
        )
    );
}

if (!$session_token) {
    jsonOut(1001, '用户未登录或会话已过期');
}

$user = $db->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    jsonOut(1002, '用户无权限访问');
}

$role_id     = (int)$user['role_id'];
$userVenueId = (int)($user['venue_id'] ?? 0);
$action      = $_POST['action'] ?? $_GET['action'] ?? 'check';
$reqVenueId  = (int)($_POST['venue_id'] ?? $_GET['venue_id'] ?? 0);

$venue_id = resolveVenueId($db, $role_id, $userVenueId, $reqVenueId);

if (!$venue_id) {
    jsonOut(1003, '未绑定场地');
}

// ===== meta =====
if ($action === 'meta') {
    $venues = getVenueListByRole($db, $role_id, $venue_id);

    jsonOut(0, 'ok', [
        'role_id'          => $role_id,
        'venue_id'         => $venue_id,
        'venues'           => $venues,
        'can_choose_venue' => canChooseVenue($role_id),
        'can_operate'      => canOperateVehicle($role_id)
    ]);
}

// ===== check =====
if ($action === 'check') {
    $vehicles = getVehicleListByVenue($db, $venue_id);

    foreach ($vehicles as &$row) {
        appendVehicleFlags($row);
    }
    unset($row);

    jsonOut(0, '查询成功', [
        'role_id'          => $role_id,
        'venue_id'         => $venue_id,
        'count'            => count($vehicles),
        'can_choose_venue' => canChooseVenue($role_id),
        'can_operate'      => canOperateVehicle($role_id),
        'vehicles'         => $vehicles
    ]);
}

// ===== occupy =====
if ($action === 'occupy') {
    if (!canOperateVehicle($role_id)) {
        jsonOut(403, '无权限：仅 role_id=1、2、3、4可执行一键占有');
    }

    $vehicle_id = (int)($_POST['vehicle_id'] ?? $_GET['vehicle_id'] ?? 0);
    if ($vehicle_id <= 0) {
        jsonOut(1004, '缺少 vehicle_id');
    }

    $vehicleRows = $db->query(
        "SELECT
            id,
            name,
            serial_number,
            uid,
            bind_site,
            status,
            vehicle_status,
            driver,
            driver_id,
            updated_at
         FROM vehicles
         WHERE id = ?
           AND bind_site = ?
         LIMIT 1",
        [$vehicle_id, $venue_id]
    );

    if (empty($vehicleRows)) {
        jsonOut(1005, '设备不存在，或不属于当前场地');
    }

    $vehicle = $vehicleRows[0];
    $ownerUid = (int)($vehicle['uid'] ?? 0);

    if ($ownerUid <= 0) {
        jsonOut(1006, '该车辆未绑定车主uid（vehicles.uid 为空），无法一键占有');
    }

    // 已是系统挂车占有，直接返回
    if (isSystemHoldVehicle($vehicle)) {
        appendVehicleFlags($vehicle);
        jsonOut(0, '该车辆已是系统挂车占有状态', [
            'vehicle' => $vehicle
        ]);
    }

    $currentDriverId = (int)($vehicle['driver_id'] ?? 0);
    $currentStatus = trim((string)($vehicle['status'] ?? ''));
    $currentOwnerUid = (int)($vehicle['uid'] ?? 0);

    $occupiedByOther = (
        ($currentDriverId > 0 && $currentDriverId !== $currentOwnerUid)
        || ($currentStatus === '占有' && !isSystemHoldVehicle($vehicle))
    );

    if ($occupiedByOther) {
        jsonOut(1012, '该车辆当前正被真实玩家占有，不能执行一键占有');
    }

    $driverAlias = buildDriverAlias($vehicle['serial_number']);

    $rows = $db->query(
        "UPDATE vehicles
         SET
            driver_id = ?,
            driver = ?,
            status = '占有',
            vehicle_status = '开始围观'
         WHERE id = ?
           AND bind_site = ?
         LIMIT 1",
        [$ownerUid, $driverAlias, $vehicle_id, $venue_id],
        true
    );

    if ($rows <= 0) {
        $checkRows = $db->query(
            "SELECT
                id,
                name,
                serial_number,
                status,
                vehicle_status,
                uid,
                bind_site,
                driver,
                driver_id,
                updated_at
             FROM vehicles
             WHERE id = ?
             LIMIT 1",
            [$vehicle_id]
        );

        $ok = !empty($checkRows) && isSystemHoldVehicle($checkRows[0]);

        if (!$ok) {
            jsonOut(1007, '一键占有失败');
        }
    }

    $freshRows = $db->query(
        "SELECT
            id,
            name,
            serial_number,
            status,
            vehicle_status,
            uid,
            bind_site,
            driver,
            driver_id,
            updated_at
         FROM vehicles
         WHERE id = ?
         LIMIT 1",
        [$vehicle_id]
    );

    $fresh = $freshRows[0] ?? [];
    appendVehicleFlags($fresh);

    jsonOut(0, '一键占有成功', [
        'vehicle' => $fresh
    ]);
}

// ===== restore =====
if ($action === 'restore') {
    if (!canOperateVehicle($role_id)) {
        jsonOut(403, '无权限：仅 role_id=1、2、3 可执行恢复在线');
    }

    $vehicle_id = (int)($_POST['vehicle_id'] ?? $_GET['vehicle_id'] ?? 0);
    if ($vehicle_id <= 0) {
        jsonOut(1008, '缺少 vehicle_id');
    }

    $vehicleRows = $db->query(
        "SELECT
            id,
            name,
            serial_number,
            status,
            vehicle_status,
            uid,
            bind_site,
            driver,
            driver_id,
            updated_at
         FROM vehicles
         WHERE id = ?
           AND bind_site = ?
         LIMIT 1",
        [$vehicle_id, $venue_id]
    );

    if (empty($vehicleRows)) {
        jsonOut(1009, '设备不存在，或不属于当前场地');
    }

    $vehicle = $vehicleRows[0];

    if (!isSystemHoldVehicle($vehicle)) {
        jsonOut(1011, '当前车辆不是系统挂车占有状态，不能恢复在线');
    }

    // 注意：这里是数据库真正的 NULL，不是字符串 'NULL'
    $rows = $db->query(
        "UPDATE vehicles
         SET
            status = '在线',
            vehicle_status = '驾驶',
            driver_id = NULL,
            driver = NULL
         WHERE id = ?
           AND bind_site = ?
         LIMIT 1",
        [$vehicle_id, $venue_id],
        true
    );

    if ($rows <= 0) {
        $checkRows = $db->query(
            "SELECT
                id,
                name,
                serial_number,
                status,
                vehicle_status,
                uid,
                bind_site,
                driver,
                driver_id,
                updated_at
             FROM vehicles
             WHERE id = ?
             LIMIT 1",
            [$vehicle_id]
        );

        $ok = !empty($checkRows)
            && (string)($checkRows[0]['status'] ?? '') === '在线'
            && (string)($checkRows[0]['vehicle_status'] ?? '') === '驾驶'
            && is_null($checkRows[0]['driver'])
            && is_null($checkRows[0]['driver_id']);

        if (!$ok) {
            jsonOut(1010, '恢复在线失败');
        }
    }

    $freshRows = $db->query(
        "SELECT
            id,
            name,
            serial_number,
            status,
            vehicle_status,
            uid,
            bind_site,
            driver,
            driver_id,
            updated_at
         FROM vehicles
         WHERE id = ?
         LIMIT 1",
        [$vehicle_id]
    );

    $fresh = $freshRows[0] ?? [];
    appendVehicleFlags($fresh);

    jsonOut(0, '恢复在线成功', [
        'vehicle' => $fresh
    ]);
}

jsonOut(404, '未知操作');