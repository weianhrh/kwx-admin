<?php

class CameraUpgradeService
{
    private string $authId = '57';
    private string $authCode_status = 'AbX+0PaZ6hz5aw5tp8jk080/TfI=';
    private string $authCode_upgrade = 'AbX+0PaZ6hZ5aw5tp8ik080/TfI=';
    private string $logFile = __DIR__ . '/log/camera_upgrade.log';

    // 获取状态
    public function getVersion(array $devIds): array
    {
        $list = array_map(fn($id) => ['DevId' => $id], $devIds);

        $payload = json_encode([
            'AuthId' => $this->authId,
            'AuthCode' => $this->authCode_status,
            'list' => $list
        ]);

        $response = $this->sendRequest('http://api.minirtc.com:8000/Iot/Dev/GetDevStatus', $payload);
        $this->log('GetVersion', $devIds, $response);

        return json_decode($response, true);
    }

    // 执行升级
    public function upgrade(array $devIds): array
    {
        $results = [];

        foreach ($devIds as $devId) {
            $payload = json_encode([
                'AuthId' => $this->authId,
                'AuthCode' => $this->authCode_upgrade,
                'DevId' => $devId,
                'method' => 'AppUpdate',
                'data' => ['force' => 1]
            ]);

            $response = $this->sendRequest('http://api.minirtc.com:8000/Iot/Dev/OnDevComSet', $payload);
            $this->log('Upgrade', $devId, $response);
            $results[$devId] = json_decode($response, true);
        }

        return $results;
    }

    // 封装 CURL 请求
    private function sendRequest(string $url, string $payload): string
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Apifox/1.0.0 (https://apifox.com)',
                'Content-Type: text/plain',
                'Accept: */*',
                'Host: api.minirtc.com:8000',
                'Connection: keep-alive'
            ]
        ]);

        $response = curl_exec($curl);
        if ($response === false) {
            $this->log('CURL ERROR', $url, curl_error($curl));
        }

        curl_close($curl);
        return $response;
    }

    // 日志记录
    private function log(string $action, $target, $result)
    {
        $time = date('Y-m-d H:i:s');
        $line = "$time [$action] " . json_encode($target) . " => $result" . PHP_EOL;
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }
}
