<?php
class TimestampGenerator {
    private static $counter = 0;

    public static function generate() {
        self::$counter++;
        $counterStr = str_pad(self::$counter, 7, "0", STR_PAD_LEFT);
        $millis = round(microtime(true) * 1000);
        return $counterStr . $millis;
    }
}

class SignatureUtil {

    public static function getEncryptStr($uuid, $appKey, $appSecret, $timeMillis, $moveCard) {
        $encryptStr = $uuid . $appKey . $appSecret . $timeMillis;
        $encryptByte = unpack("C*", $encryptStr);  // Convert string to array of bytes
        $changeByte = self::change($encryptStr, $moveCard);
        $mergeByte = self::mergeByte($encryptByte, $changeByte);
        $mergeStr = pack("C*", ...$mergeByte);  // Convert byte array back to string
        return md5($mergeStr);  // Return MD5 hash of the merged string
    }

    private static function change($encryptStr, $moveCard) {
        $encryptByte = unpack("C*", $encryptStr);
        $encryptLength = strlen($encryptStr);
        for ($i = 0; $i < $encryptLength; $i++) {
            $temp = (($i % $moveCard) > (($encryptLength - $i) % $moveCard)) ? $encryptByte[$i + 1] : $encryptByte[$encryptLength - $i];
            $encryptByte[$i + 1] = $encryptByte[$encryptLength - $i];
            $encryptByte[$encryptLength - $i] = $temp;
        }
        return $encryptByte;
    }

    private static function mergeByte($encryptByte, $changeByte) {
        $encryptLength = count($encryptByte);
        $encryptLength2 = $encryptLength * 2;
        $temp = array_fill(1, $encryptLength2, 0);
        for ($i = 1; $i <= $encryptLength; $i++) {
            $temp[$i] = $encryptByte[$i];
            $temp[$encryptLength2 - $i + 1] = $changeByte[$i];
        }
        return $temp;
    }
}

class DeviceTokenFetcher {
    private $endpoint = "https://api.jftechws.com/gwp/v3/rtc/device/token";

    public function fetchToken($sns, $accessToken, $uuid, $appKey, $appSecret, $moveCard) {
        $timeMillis = TimestampGenerator::generate();
        $signature = SignatureUtil::getEncryptStr($uuid, $appKey, $appSecret, $timeMillis, $moveCard);
        $requestId = str_replace('-', '', $this->generateUUID());

        $headers = [
            'Content-Type: application/json',
            "uuid: $uuid",
            "appKey: $appKey",
            "timeMillis: $timeMillis",
            "signature: $signature",
            "X-Request-Id: $requestId"
        ];

        $body = [
            'sns' => $sns,
            'accessToken' => $accessToken
        ];

        $ch = curl_init($this->endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            return ['code' => 500, 'msg' => 'CURL Error: ' . $error];
        }

        $responseData = json_decode($response, true);
        
        // 添加 HTTP 状态码信息
        return [
            'http_code' => $httpCode,
            'response' => $responseData
        ];
    }

    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
class DeviceLoginManager {
    private $endpoint = "https://api.jftechws.com/gwp/v3/rtc/device";

    // 设备登录
    public function loginDevice($deviceToken, $credentials, $uuid, $appKey, $appSecret, $moveCard) {
        $timeMillis = TimestampGenerator::generate();
        $signature = SignatureUtil::getEncryptStr($uuid, $appKey, $appSecret, $timeMillis, $moveCard);
        $requestId = str_replace('-', '', $this->generateUUID());

        $headers = [
            'Content-Type: application/json',
            "uuid: $uuid",
            "appKey: $appKey",
            "timeMillis: $timeMillis",
            "signature: $signature",
            "X-Request-Id: $requestId"
        ];

        $url = $this->endpoint . '/login/' . urlencode($deviceToken);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($credentials));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $this->handleResponse($response, $error, $httpCode);
    }

    // 设备登出
    public function logoutDevice($deviceToken, $uuid, $appKey, $appSecret, $moveCard) {
        $timeMillis = TimestampGenerator::generate();
        $signature = SignatureUtil::getEncryptStr($uuid, $appKey, $appSecret, $timeMillis, $moveCard);
        $requestId = str_replace('-', '', $this->generateUUID());

        $headers = [
            'Content-Type: application/json',
            "uuid: $uuid",
            "appKey: $appKey",
            "timeMillis: $timeMillis",
            "signature: $signature",
            "X-Request-Id: $requestId"
        ];

        $body = ['Name' => 'Logout'];
        $url = $this->endpoint . '/logout/' . urlencode($deviceToken);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $this->handleResponse($response, $error, $httpCode);
    }

    private function handleResponse($response, $error, $httpCode) {
        if ($error) {
            return ['code' => 500, 'msg' => 'CURL Error: ' . $error];
        }

        $responseData = json_decode($response, true);
        
        return [
            'http_code' => $httpCode,
            'response' => $responseData
        ];
    }

    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
class DeviceCapturer {
    private $endpoint = "https://api.jftechws.com/gwp/v3/rtc/device/capture";

    public function captureImage($deviceToken, $sn, $uuid, $appKey, $appSecret, $moveCard, $channel = 0, $picType = 0) {
        $timeMillis = TimestampGenerator::generate();
        $signature = SignatureUtil::getEncryptStr($uuid, $appKey, $appSecret, $timeMillis, $moveCard);
        $requestId = str_replace('-', '', $this->generateUUID());

        $headers = [
            'Content-Type: application/json',
            "uuid: $uuid",
            "appKey: $appKey",
            "timeMillis: $timeMillis",
            "signature: $signature",
            "X-Request-Id: $requestId"
        ];

        $body = [
            'Name' => 'OPSNAP',
            'OPSNAP' => [
                'Channel' => $channel,
                'PicType' => $picType
            ]
        ];

        $url = $this->endpoint . '/' . urlencode($deviceToken);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            return ['code' => 500, 'msg' => 'CURL Error: ' . $error];
        }

        $responseData = json_decode($response, true);
        
        return [
            'http_code' => $httpCode,
            'response' => $responseData
        ];
    }

    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

class DeviceBinder {
    private $endpoint = "https://api.jftechws.com/gwp/v3/rtc/device/bind";

    public function bindDevice($deviceInfo, $uuid, $appKey, $appSecret, $moveCard) {
        $timeMillis = TimestampGenerator::generate();
     
        // 使用示例
        $signature = SignatureUtil::getEncryptStr($uuid, $appKey, $appSecret, $timeMillis, $moveCard);
        $requestId = str_replace('-', '', $this->generateUUID());

        $headers = [
            'Content-Type: application/json',
            "uuid: $uuid",
            "appKey: $appKey",
            "timeMillis: $timeMillis",
            "signature: $signature",
            "X-Request-Id: $requestId"
        ];

        $ch = curl_init($this->endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($deviceInfo));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['code' => 500, 'msg' => 'CURL Error: ' . $error];
        }

        return json_decode($response, true);
    }

    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
