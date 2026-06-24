

<?php

// require_once '../Database.php';
// $database = new Database();

// // $ids = [15, 18, 19, 21, 22, 23, 26, 30, 31, 35, 36, 38, 39, 40, 41, 42, 44, 45, 46, 47, 48, 51];
// $ids = [9,15,18,21,22,23,26,30,32,34,35,36,38,39,40,41,42,44,45,46,47,48,50,51,52,56,57,58,59,61,63,64];
// $idList = implode(',', $ids);

// // 查询旧记录
// $sql = "SELECT id, image_url FROM venues WHERE id IN ($idList)";
// $result = $database->query($sql);

// // 更新记录并备份原 image_url
// foreach ($result as $row) {
//     $id = $row['id'];

//     // 同时更新 image_url 和 备份字段
//     $updateSql = "UPDATE venues SET image_url = backup_image_url WHERE id = ?";
//     $database->query($updateSql, [$id], true);
// }




// require_once '../Database.php';
// $database = new Database();

// // 需要更新的 ID 列表
// $ids = [9,15,17,18,21,22,23,26,30,32,34,35,36,38,39,40,41,42,45,46,47,48,50,51,52,55,56,57,58,59,61,62,63,64,65];
// // $ids = [41,62,63];
// $idList = implode(',', $ids);

// // 查询旧记录：获取 image_url（要备份） 和 id
// $sql = "SELECT id, image_url FROM venues WHERE id IN ($idList)";
// $result = $database->query($sql);

// // 更新记录：把 image_url 备份到 backup_image_url，同时设置新的 image_url
// foreach ($result as $row) {
//     $id = $row['id'];
//     $oldImageUrl = $row['image_url'];
//     $newImageUrl = "https://rcwulian.cn/app/imgv2/img/wj.jpg";

//     $updateSql = "UPDATE venues SET backup_image_url = ?, image_url = ? WHERE id = ?";
//     $database->query($updateSql, [$newImageUrl, $id], true);
// }

// echo "更新完成，共处理 " . count($result) . " 条记录。";


// require_once '../Database.php';
// $database = new Database();

// // 生成 1 到 66 的 ID 列表
// $ids = range(1, 66);
// $idList = implode(',', $ids);

// // 固定的新描述
// $newDesc = "欢迎体验遥控小车";

// // 查询旧记录：获取 venue_description 和 id
// $sql = "SELECT id, venue_description FROM venues WHERE id IN ($idList)";
// $result = $database->query($sql);

// // 遍历并更新
// foreach ($result as $row) {
//     $id = $row['id'];
//     $oldDescription = $row['venue_description'];

//     // 更新 venue_description，并备份旧值到 backup_description
//     $updateSql = "UPDATE venues SET backup_description = ?, venue_description = ? WHERE id = ?";
//     $database->query($updateSql, [$oldDescription, $newDesc, $id], true);
// }

// echo "更新完成，共处理 " . count($result) . " 条记录。";


// 还原
require_once '../Database.php';
$database = new Database();

// 需要更新的 ID 列表
$ids = [9,15,17,18,21,22,23,26,30,32,34,35,36,38,39,40,41,42,45,46,47,48,50,51,52,55,56,57,58,59,61,62,63,64,65];
$idList = implode(',', $ids);

// 查询旧记录：获取 image_url（要备份） 和 id
$sql = "SELECT id, backup_image_url,image_url FROM venues WHERE id IN ($idList)";
$result = $database->query($sql);

// 更新记录：把 image_url 备份到 backup_image_url，同时设置新的 image_url
foreach ($result as $row) {
    $id = $row['id'];
    $oldImageUrl = $row['backup_image_url'];
    // $newImageUrl = "https://open.rcwulian.cn/api/1/venue_{$id}.jpg";

    $updateSql = "UPDATE venues SET image_url = ? WHERE id = ?";
    $database->query($updateSql, [$oldImageUrl, $id], true);
}

echo "更新完成，共处理 " . count($result) . " 条记录。";

// 还原

require_once '../Database.php';
$database = new Database();

// 生成 1 到 66 的 ID 列表
$ids = range(1, 66);
$idList = implode(',', $ids);

// 固定的新描述
// $newDesc = "欢迎体验遥控小车";

// 查询旧记录：获取 venue_description 和 id
$sql = "SELECT id, venue_description FROM venues WHERE id IN ($idList)";
$result = $database->query($sql);

// 遍历并更新
foreach ($result as $row) {
    $id = $row['id'];
    // $oldDescription = $row['venue_description'];

    // 更新 venue_description，并备份旧值到 backup_description
    $updateSql = "UPDATE venues SET venue_description = backup_description WHERE id = ?";
    $database->query($updateSql, [$id], true);
}

echo "更新完成，共处理 " . count($result) . " 条记录。";
?>
