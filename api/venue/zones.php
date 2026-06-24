<?php
require_once '../Database.php';
header('Content-Type: application/json');

// 创建数据库连接
$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token || !$database->getUserBySessionToken($session_token)) {
    echo json_encode(['code' => 1001, 'msg' => '未登录或无权限']);
    exit;
}

$action = $_REQUEST['action'] ?? null;

// ✅ 获取所有专区并动态统计车辆数量（vehicle_count）
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $zones = $database->query("SELECT * FROM zones ORDER BY updated_at DESC");

    foreach ($zones as &$zone) {
        $venueIds = $database->query("SELECT id FROM venues WHERE zone_id = ?", [$zone['zone_id']]);
        $venueIdArr = array_column($venueIds, 'id');

        if (!empty($venueIdArr)) {
            $placeholders = implode(',', array_fill(0, count($venueIdArr), '?'));
            $vehicleCount = $database->query("SELECT COUNT(*) as cnt FROM vehicles WHERE bind_site IN ($placeholders)", $venueIdArr)[0]['cnt'];
        } else {
            $vehicleCount = 0;
        }

        $oldVehicleCount = intval($zone['vehicle_count'] ?? 0);
        $vehicleCount = intval($vehicleCount);
        
        // 返回给前端的车辆数，永远用实时统计的
        $zone['vehicle_count'] = $vehicleCount;
        
        // 只有车辆数量真的变化了，才更新数据库，避免每次打开页面都刷新 updated_at
        if ($oldVehicleCount !== $vehicleCount) {
            $database->query(
                "UPDATE zones SET vehicle_count = ? WHERE zone_id = ?",
                [$vehicleCount, $zone['zone_id']],
                true
            );
        }

    }

    echo json_encode(['code' => 0, 'msg' => '获取成功', 'data' => $zones]);
    exit;
}

// 添加专区
if ($action === 'add') {
    $partition_name = trim($_POST['partition_name'] ?? '');
    $partition_count = intval($_POST['partition_count'] ?? 0);
    $vehicle_count = intval($_POST['vehicle_count'] ?? 0);
    $zone_image = trim($_POST['zone_image'] ?? '');
    $sort_order = intval($_POST['sort_order'] ?? 0);
    $is_visible = intval($_POST['is_visible'] ?? 1);
    $is_claw_machine = intval($_POST['is_claw_machine'] ?? 0);
    
    $sql = "INSERT INTO zones 
            (partition_name, partition_count, vehicle_count, zone_image, sort_order, is_visible, is_claw_machine) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    $res = $database->query(
        $sql,
        [$partition_name, $partition_count, $vehicle_count, $zone_image, $sort_order, $is_visible, $is_claw_machine],
        true
    );
    echo json_encode(['code' => 0, 'msg' => '添加成功', 'rows' => $res]);
    exit;
}

// 修改专区
if ($action === 'edit') {
    $zone_id = intval($_POST['zone_id'] ?? 0);
    if ($zone_id === 0) {
        echo json_encode(['code' => 1002, 'msg' => '缺少 zone_id']);
        exit;
    }

    $partition_name = trim($_POST['partition_name'] ?? '');
    $partition_count = intval($_POST['partition_count'] ?? 0);
    $vehicle_count = intval($_POST['vehicle_count'] ?? 0);
    $zone_image = trim($_POST['zone_image'] ?? '');
    $sort_order = intval($_POST['sort_order'] ?? 0);
    $is_visible = intval($_POST['is_visible'] ?? 1);
    $is_claw_machine = intval($_POST['is_claw_machine'] ?? 0);
    
    $sql = "UPDATE zones SET 
                partition_name = ?, 
                partition_count = ?, 
                vehicle_count = ?, 
                zone_image = ?, 
                sort_order = ?, 
                is_visible = ?, 
                is_claw_machine = ? 
            WHERE zone_id = ?";
    
    $res = $database->query(
        $sql,
        [$partition_name, $partition_count, $vehicle_count, $zone_image, $sort_order, $is_visible, $is_claw_machine, $zone_id],
        true
    );
    echo json_encode(['code' => 0, 'msg' => '更新成功', 'rows' => $res]);
    exit;
}

// 删除专区
if ($action === 'delete') {
    $zone_id = intval($_POST['zone_id'] ?? 0);
    if ($zone_id === 0) {
        echo json_encode(['code' => 1003, 'msg' => '缺少 zone_id']);
        exit;
    }

    $sql = "DELETE FROM zones WHERE zone_id = ?";
    $res = $database->query($sql, [$zone_id], true);
    echo json_encode(['code' => 0, 'msg' => '删除成功', 'rows' => $res]);
    exit;
}

// 未匹配到操作
echo json_encode(['code' => 1000, 'msg' => '无效请求']);
