<?php
// 💡 替换成你的 AppId 和 ServerSecret
$appId        = 141962251;
$serverSecret = '5bfaa3399946c98cc6792dd19f9a08ec';
$roomId       = '120001';
$userId       = '24570'; // 要踢出的用户ID

// 生成签名函数
function GenerateSignature($appId, $signatureNonce, $serverSecret, $timeStamp) {
    return md5($appId . $signatureNonce . $serverSecret . $timeStamp);
}

// 公共参数
$signatureNonce = bin2hex(random_bytes(8));
$timeStamp      = time(); // 秒级时间戳
$signature      = GenerateSignature($appId, $signatureNonce, $serverSecret, $timeStamp);

// 请求 URL
$url = "https://rtc-api.zego.im/?Action=KickUserFromRoom"
     . "&AppId=$appId"
     . "&SignatureNonce=$signatureNonce"
     . "&Timestamp=$timeStamp"
     . "&Signature=$signature"
     . "&SignatureVersion=2.0"
     . "&RoomId=$roomId"
     . "&UserId=$userId";

// curl 请求
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
$response = curl_exec($ch);
curl_close($ch);

echo "URL: " . $url . "\n";
echo "Response: " . $response . "\n";