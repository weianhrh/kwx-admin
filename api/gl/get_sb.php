<?php
require_once '../RedisHelper.php';  // 假设RedisHelper类在这个文件中
require_once '../Database.php';     // 假设Database类在这个文件中

class DeviceVenueFetcher {
    private $redis;
    private $db;

    public function __construct() {
        $this->redis = new RedisHelper();
        $this->db = new Database();

        // 连接Redis库2
        $this->redis->connect('127.0.0.1', 6379);
        $this->redis->selectDb(2);
    }

    public function fetchAllDevices() {
        $result = [];

        // 获取所有设备ID
        $allKeys = $this->redis->getAllKeys();  // 假设这个方法返回所有键

        foreach ($allKeys as $deviceId) {
            $deviceInfo = $this->fetchVenueByDeviceId($deviceId);
            if ($deviceInfo !== null) {
                $result[] = $deviceInfo;
            }
        }

        // 返回JSON
        header('Content-Type: application/json');
        echo json_encode([
            'code' => 0,
            'msg' => 'success',
            'data' => $result
        ]);
    }

    public function fetchVenueByDeviceId($deviceId) {
        // 从Redis获取设备信息（可以根据你的Redis结构改）


        // 查询设备信息
        $sql = "SELECT bind_site, image_device_serial, name FROM vehicles WHERE serial_number = ?";
        $vehicleInfo = $this->db->query($sql, [$deviceId]);

        if (empty($vehicleInfo)) {
            return null;
        }

        $bindSite = $vehicleInfo[0]['bind_site'];

        // 查询场地名称
        $venueSql = "SELECT venue_name FROM venues WHERE id = ?";
        $venueInfo = $this->db->query($venueSql, [$bindSite]);

        if (empty($venueInfo)) {
            return null;
        }

        $venueName = $venueInfo[0]['venue_name'];

        return [
            'bind_site' => $bindSite,
            'device_id' => $deviceId,
            'device_name' => $vehicleInfo[0]['name'],
            'image_device_serial' => $vehicleInfo[0]['image_device_serial'],
            'venue_name' => $venueName
        ];
    }

    public function __destruct() {
        // 关闭数据库和Redis连接
        $this->db->close();
        $this->redis->close();
    }
}

// 使用
$fetcher = new DeviceVenueFetcher();
$fetcher->fetchAllDevices();
?>
