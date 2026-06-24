<?php
require_once '../RedisHelper.php';
require_once '../Database.php'; // 确保路径正确

// 创建 Redis 实例
$redis = new RedisHelper();
$db = new Database(); // 创建数据库实例

try {
    // 尝试连接到 Redis
    $redis->connect();
    $redis->selectDb(14);

    // 获取所有违规数据的键
    $pattern = 'device_violation:*';
    $keys = $redis->getAllKeys($pattern);
    $violations = [];
    $venueViolationCounts = []; // 用于存储场地违规统计

    foreach ($keys as $key) {
        $violationData = $redis->get($key);
        if ($violationData) {
            $decoded = json_decode($violationData, true);
            if (is_array($decoded)) {
                // 去除 device_violation: 前缀
                $shortKey = str_replace('device_violation:', '', $key);
                $decoded['key'] = $shortKey;

                // 从数据库获取 bind_site 和 venue_name
                $imageDeviceSerial = $decoded['key'] ?? null;
                $bindSite = null;
                $venueName = null;
                $is_banned = 0;

                if ($imageDeviceSerial) {
                    // 查询 vehicles 表获取 bind_site 和 is_banned
                    $sqlVehicles = "SELECT bind_site, is_banned FROM vehicles WHERE image_device_serial = ?";
                    $resultVehicles = $db->query($sqlVehicles, [$imageDeviceSerial]);

                    if ($resultVehicles !== false && !empty($resultVehicles)) {
                        $bindSite = $resultVehicles[0]['bind_site'];
                        $is_banned = $resultVehicles[0]['is_banned'];
                        
                        // 查询 venues 表获取 venue_name
                        $sqlVenues = "SELECT venue_name FROM venues WHERE id = ?";
                        $resultVenues = $db->query($sqlVenues, [$bindSite]);

                        if ($resultVenues !== false && !empty($resultVenues)) {
                            $venueName = $resultVenues[0]['venue_name'];
                        }

                        // 统计场地违规数（如果尚未统计过）
                        if ($bindSite && !isset($venueViolationCounts[$bindSite])) {
                            // 获取今天早上6点到次日6点的时间范围
                            $currentTime = time();
                            $today6am = strtotime('today 6:00');
                            $tomorrow6am = strtotime('tomorrow 6:00');
                            
                            // 如果当前时间在早上6点之前，则统计前一天6点到当天6点
                            if ($currentTime < $today6am) {
                                $startTime = date('Y-m-d H:i:s', strtotime('yesterday 6:00'));
                                $endTime = date('Y-m-d H:i:s', $today6am);
                            } else {
                                $startTime = date('Y-m-d H:i:s', $today6am);
                                $endTime = date('Y-m-d H:i:s', $tomorrow6am);
                            }

                            // 查询 device_bans 表中该场地的违规数
                            $sqlViolationCount = "SELECT COUNT(*) as violation_count FROM device_bans 
                                                 WHERE venue_id = ? AND created_at >= ? AND created_at < ?";
                            $resultCount = $db->query($sqlViolationCount, [$bindSite, $startTime, $endTime]);

                            if ($resultCount !== false && !empty($resultCount)) {
                                $venueViolationCounts[$bindSite] = $resultCount[0]['violation_count'];
                            } else {
                                $venueViolationCounts[$bindSite] = 0;
                            }
                        }
                    }
                }
                if (isset($decoded['remark']) && !empty($decoded['remark'])) {
                    $decoded['remark'] = $decoded['remark']; // 添加备注字段
                }

                // 添加字段到数据
                $decoded['bind_site'] = $bindSite;
                $decoded['venue_name'] = $venueName;
                $decoded['is_banned'] = $is_banned;
                $decoded['violation_count'] = $bindSite ? ($venueViolationCounts[$bindSite] ?? 0) : 0;
                $violations[] = $decoded;
            }
        }
    }

    // 关闭数据库连接
    $db->close();

    // 输出所有违规数据
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'violations' => $violations
    ]);

} catch (Exception $e) {
    // 如果操作失败，记录错误并返回错误信息
    error_log($e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch violations: ' . $e->getMessage()
    ]);
}
?>