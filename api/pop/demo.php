<?php
require_once 'DeviceBinder.php';

$uuid = "65d1a8037816ab11a812db04";
$appKey = "00c41718600d1b07313a70f22e1731cc";
$appSecret = "41ce348d9daa430ba25e5030933896f5";
$moveCard = 2;
$deviceInfo = [
    "sn" => "02f56491881d9757"
    
];

$binder = new DeviceBinder();
$result = $binder->bindDevice($deviceInfo, $uuid, $appKey, $appSecret,2);

echo "绑定结果：\n";
print_r($result);


// 设备序列号数组（确保设备已绑定）
$deviceSns = [
    "02f56491881d9757"
];

$tokenFetcher = new DeviceTokenFetcher();
$result = $tokenFetcher->fetchToken(
    $deviceSns,
    "", // 如果不使用杰峰AMS系统则传空字符串
    $uuid,
    $appKey,
    $appSecret,
    $moveCard
);
// 在现有代码基础上增加以下处理逻辑
if ($result['http_code'] == 200) {
    if (isset($result['response']['code']) && $result['response']['code'] == 2000) {
        if (!empty($result['response']['data'])) {
            // 创建存储 token 的数组
            $deviceTokens = [];
            
            foreach ($result['response']['data'] as $device) {
                if (isset($device['token'])) {
                    $deviceTokens[$device['sn']] = $device['token'];
                    echo "设备 {$device['sn']} 的 token 为：\n{$device['token']}\n\n";
                }
            }
            
            // 后续使用示例（存储到变量供其他接口使用）
            if (!empty($deviceTokens)) {
                // 示例：获取第一个设备的 token
                $firstSn = array_key_first($deviceTokens);
                $firstToken = $deviceTokens[$firstSn];
                
                // 示例调用其他设备接口
                // $deviceStatus = getDeviceStatus($firstSn, $firstToken);
            }
        } else {
            echo "警告：接口返回成功但未获取到有效 token（可能设备未绑定）";
        }
    } else {
        echo "接口返回错误1：".$result['response']['msg'] ?? '未知错误';
    }
} else {
    echo "HTTP 请求异常，状态码：".$result['http_code'];
}



// 在获取 token 之后添加以下流程
if (!empty($deviceTokens)) {
    $loginManager = new DeviceLoginManager();
    $capturer = new DeviceCapturer();
    
    // 设备登录凭证（根据实际情况修改）
    $deviceCredentials = [
        'UserName' => 'admin',
        'PassWord' => '' // 留空表示使用默认密码
    ];

    foreach ($deviceTokens as $sn => $token) {
        /**************** 登录设备 ****************/
        $loginResult = $loginManager->loginDevice(
            $token,
            $deviceCredentials,
            $uuid,
            $appKey,
            $appSecret,
            $moveCard
        );

        echo "\n登录结果（设备 {$sn}）：\n";
        if ($loginResult['http_code'] != 200 || 
           ($loginResult['response']['code'] ?? 0) != 2000 ||
           ($loginResult['response']['data']['Ret'] ?? 0) != 100) 
        {
            echo "设备登录失败，跳过抓图\n";
            continue;
        }
        echo "登录成功！\n";

        /**************** 执行抓图 ****************/
        $captureResult = $capturer->captureImage(
            $token,
            $sn,
            $uuid,
            $appKey,
            $appSecret,
            $moveCard
        );

        // 处理抓图结果（原有逻辑）
        // ...
        // 在获取 token 的代码之后添加以下内容
        if (!empty($deviceTokens)) {
            $capturer = new DeviceCapturer();
            
            // 遍历所有设备进行抓图
            foreach ($deviceTokens as $sn => $token) {
                $captureResult = $capturer->captureImage(
                    $token,    // 使用获取到的设备token
                    $sn,       // 设备序列号
                    $uuid,
                    $appKey,
                    $appSecret,
                    $moveCard,
                    0,         // 通道号
                    0          // 图片类型
                );
        
                echo "\n抓图结果（设备 {$sn}）：\n";
                echo "HTTP 状态码: " . $captureResult['http_code'] . "\n";
                
                if ($captureResult['http_code'] == 200) {
                    if (isset($captureResult['response']['code']) && $captureResult['response']['code'] == 2000) {
                        $imageUrl = $captureResult['response']['data']['image'] ?? '';
                        $retCode = $captureResult['response']['data']['Ret'] ?? '';
                        
                        if ($retCode == 100 && !empty($imageUrl)) {
                            echo "抓图成功！图片地址：{$imageUrl}\n";
                            // 这里可以添加图片下载逻辑
                        } else {
                            echo "设备返回错误，状态码：{$retCode}\n";
                        }
                    } else {
                        echo "接口返回错误2：".($captureResult['response']['msg'] ?? '未知错误')."\n";
                    }
                } else {
                    echo "HTTP 请求异常\n";
                }
            }
        }
        /**************** 登出设备 ****************/
        $logoutResult = $loginManager->logoutDevice(
            $token,
            $uuid,
            $appKey,
            $appSecret,
            $moveCard
        );

        //echo "\n登出结果：";
        if ($logoutResult['http_code'] == 200 && 
           ($logoutResult['response']['code'] ?? 0) == 2000 &&
           ($logoutResult['response']['data']['Ret'] ?? 0) == 100) 
        {
            //echo "成功\n";
        } else {
            //echo "失败\n";
        }
    }
}