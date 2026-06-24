<?php
// 引入数据库连接类
require_once '../Database.php';
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

// 创建数据库连接实例
$database = new Database();

// 简单处理请求
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if ($action === 'get_venues_images') {
    // 查询所有场地信息，包括备份图片URL[1](@ref)
    $sql = "SELECT id, venue_name, backup_image_url FROM venues";
    $result = $database->query($sql);
    
    if ($result !== false) {
        // 返回JSON格式数据[7,8](@ref)
        echo json_encode([
            'code' => 0, 
            'msg' => '成功', 
            'data' => $result
        ]);
    } else {
        echo json_encode([
            'code' => 1001, 
            'msg' => '获取场地信息失败', 
            'data' => []
        ]);
    }
} else {
    echo json_encode([
        'code' => 1002, 
        'msg' => '未知操作', 
        'data' => []
    ]);
}
?>

