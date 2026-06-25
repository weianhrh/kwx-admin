<?php
// /api/venue/uploadImg.php
require_once '../Database.php'; // 引入数据库连接类
require_once '../lib/venue_scope.php';
require_once './_venue_locks.php';
$locks = new VenueLocks();
$database = new Database();

// 1. 用户身份认证
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '无有效认证信息，请登录', 'data' => []]);
    exit;
}

$sql = "SELECT id, uid, role_id, venue_id FROM admin_users WHERE session_token = ?";
$user = $database->query($sql, [$session_token]);

if (!$user) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或不存在', 'data' => []]);
    $database->close();
    exit;
}

$role_id = $user[0]['role_id'];
$venue_id = $user[0]['venue_id'];
$operator_uid = $user[0]['uid']; // 可按需赋值
if (isset($_POST['id']) && is_numeric($_POST['id']) && (int)$_POST['id'] > 0) {
    $venue_id = intval($_POST['id']);
}

if (!venue_scope_can_access($database, $user[0], (int)$venue_id)) {
    echo json_encode(['code' => 1003, 'msg' => '权限不足，无法上传该场地图片', 'data' => []]);
    exit;
}

// 2. 图片上传处理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    $file = $_FILES['image'];

    // ✅ 检查图片大小（最大2MB）
    $maxSize = 2 * 1024 * 1024; // 2MB
    if ($file['size'] > $maxSize) {
        echo json_encode(['code' => 1004, 'msg' => '图片大小不能超过2MB']);
        exit;
    }

    // ✅ 检查 MIME 类型（限制为 JPG/PNG/WEBP）
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? null;
    
    if (!$mime || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
        echo json_encode(['code' => 1006, 'msg' => '仅支持 JPG/PNG/WEBP 格式图片']);
        exit;
    }

    // ✅ 检查是否是真实图片（防止恶意脚本伪装图片）
    if (!@getimagesize($file['tmp_name'])) {
        echo json_encode(['code' => 1007, 'msg' => '上传文件不是有效的图片']);
        exit;
    }

    // ✅ 系统级错误码校验
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['code' => 1003, 'msg' => '图片上传失败']);
        exit;
    }

    // ✅ 检查场地 ID 合法性
    if (!$venue_id || !is_numeric($venue_id)) {
        echo json_encode(['code' => 1002, 'msg' => '缺少场地ID']);
        exit;
    }
    
    // 上传前检查 10 天冷却锁
    if (!in_array($role_id, [1, 2], true) && $locks->isLocked('image', $venue_id)) {
        $info = $locks->get('image', $venue_id);
        echo json_encode([
            'code' => 1021,
            'msg'  => '该场地图片上传处于10天冷却期，暂不可再次上传。解锁时间：' . ($info['until_iso'] ?? ''),
            'data' => ['lock' => $info]
        ]);
        exit;
    }
    // 3. 保存文件
    $upload_dir = __DIR__ . '/pending_images/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'venue_' . $venue_id . '_' . date('YmdHis') . '.' . $ext;
    $target_path = $upload_dir . $filename;
    $public_path = 'pending_images/' . $filename;
    $uploaded_at = date('Y-m-d H:i:s'); 

    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        // ✅ 成功响应
        // echo json_encode(['code' => 0, 'msg' => $venue_id, 'image_url' => $public_path]); <== 原来的
        // $locks->set('image', $venue_id, '上传场地图片发起审核', $operator_uid ?? 0);

        // echo json_encode(['code'=>0,'msg'=>'图片上传成功，等待审核','data'=>[
        //     'locks' => [
        //         'name'  => $locks->get('name',  $venue_id),
        //         'image' => $locks->get('image', $venue_id)
        //     ]
        // ]]);
        echo json_encode(['code'=>0,'msg'=>'图片上传成功，等待审核']);
        // 记录图片审核状态
        $insert_sql = "INSERT INTO venue_image_reviews (venue_id, image_url, status, uploaded_at) 
                       VALUES (?, ?, 'pending', ?)";
        $database->query($insert_sql, [$venue_id, $public_path, $uploaded_at], true);
        
    } else {
        echo json_encode(['code' => 1005, 'msg' => '文件保存失败']);
    }

    $database->close();
    exit;
}
?>
