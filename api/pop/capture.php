<?php
header('Content-Type: application/json');
require_once 'DeviceBinder.php';
require_once '../RedisHelper.php';
// error_reporting(0); // 屏蔽 PHP warning 和 notice

class DeviceOperator {
    private $uuid = "65d1a8037816ab11a812db04";
    private $appKey = "00c41718600d1b07313a70f22e1731cc";
    private $appSecret = "41ce348d9daa430ba25e5030933896f5";
    private $moveCard = 2;
    private $credentials = ['UserName' => 'admin', 'PassWord' => ''];

       
    public function processCapture($deviceSn) {
        try {
            // 验证输入参数
            if(empty($deviceSn)) {
                throw new Exception("缺少设备序列号参数", 400);
            }
            file_put_contents(__DIR__ . "/log/sn_log.txt", $deviceSn . PHP_EOL, FILE_APPEND);

            $result = [
                'code' => 200,
                'message' => 'Success',
                'image_url' => null
            ];

            /**************** 设备绑定 ****************/
            // 检查 Redis 15 是否已有绑定标志
            $this->redis = new RedisHelper();
            $this->redis->connect();
            $this->redis->selectDb(15); // ✅ 当前页面设置使用 Redis 第 15 库
            $cached = $this->redis->get($deviceSn);
            if (!$cached) {
                // 若 Redis 中无记录，则绑定设备
                $binder = new DeviceBinder();
                $bindResult = $binder->bindDevice(
                    ["sn" => $deviceSn],
                    $this->uuid,
                    $this->appKey,
                    $this->appSecret,
                    $this->moveCard
                );
                if (!empty($bindResult['code']) && ($bindResult['code'] == 2000 || $bindResult['code'] == 29001)) {
                    $this->redis->setWithExpiration($deviceSn, 'bound', 31536000); // 缓存绑定状态
                } else {
                    throw new Exception("设备绑定失败: ".($bindResult['msg'] ?? '未知错误'), 401);
                }
            } else {
                // 已绑定的设备，直接跳过
                file_put_contents(__DIR__ . "/log/bind_skip_log.txt", $deviceSn . PHP_EOL, FILE_APPEND);
            }
           

            /**************** 获取Token ****************/
            $tokenFetcher = new DeviceTokenFetcher();
            $tokenResult = $tokenFetcher->fetchToken(
                [$deviceSn],
                "",
                $this->uuid,
                $this->appKey,
                $this->appSecret,
                $this->moveCard
            );

            if($tokenResult['http_code'] != 200 || 
               ($tokenResult['response']['code'] ?? 0) != 2000) {
                throw new Exception("获取Token失败: ".($tokenResult['response']['msg'] ?? '未知错误'), 402);
            }

            $deviceToken = $tokenResult['response']['data'][0]['token'] ?? null;
            if(empty($deviceToken)) {
                throw new Exception("无效的设备Token", 403);
            }

            /**************** 设备登录 ****************/
            $loginManager = new DeviceLoginManager();
            $loginResult = $loginManager->loginDevice(
                $deviceToken,
                $this->credentials,
                $this->uuid,
                $this->appKey,
                $this->appSecret,
                $this->moveCard
            );

            if(empty($loginResult['response']['data']['Ret']) || $loginResult['response']['data']['Ret'] != 100) {
                throw new Exception("设备登录失败: ".$this->getErrorMsg($loginResult['response']['data']['Ret'] ?? 0), 404);
            }

            /**************** 执行抓图 ****************/
            $capturer = new DeviceCapturer();
            $captureResult = $capturer->captureImage(
                $deviceToken,
                $deviceSn,
                $this->uuid,
                $this->appKey,
                $this->appSecret,
                $this->moveCard
            );

            if(empty($captureResult['response']['data']['image']) || 
               ($captureResult['response']['data']['Ret'] ?? 0) != 100) {
                throw new Exception("抓图失败: ".$this->getErrorMsg($captureResult['response']['data']['Ret'] ?? 0), 405);
            }

            /**************** 返回结果 ****************/
            $result['image_url'] = $captureResult['response']['data']['image'];

            /**************** 设备登出 ****************/
            $loginManager->logoutDevice(
                $deviceToken,
                $this->uuid,
                $this->appKey,
                $this->appSecret,
                $this->moveCard
            );

        } catch (Exception $e) {
            $result['code'] = $e->getCode();
            $result['message'] = $e->getMessage();
        }

        return $result;
    }

    private function getErrorMsg($code) {
        $errorMap = [
            100 => '成功',
            101 => '设备离线',
            102 => '认证失败',
            103 => '请求超时',
            104 => '通道不存在',
            // 添加更多错误码映射...
        ];
        return $errorMap[$code] ?? '未知错误';
    }
}

/**************** 执行入口 ****************/
try {
    $deviceSn = $_GET['sn'] ?? null;
    $operator = new DeviceOperator();
    $response = $operator->processCapture($deviceSn);
    
    http_response_code($response['code'] == 200 ? 200 : 400);
    echo json_encode($response, JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'code' => 500,
        'message' => '系统错误: '.$e->getMessage(),
        'image_url' => null
    ]);
}