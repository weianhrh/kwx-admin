<?php
$appId        = 141962251;
$serverSecret = '5bfaa3399946c98cc6792dd19f9a08ec';
$roomId       = '120001';

// 生成签名函数
function GenerateSignature($appId, $signatureNonce, $serverSecret, $timeStamp) {
    return md5($appId . $signatureNonce . $serverSecret . $timeStamp);
}

$signatureNonce = bin2hex(random_bytes(8));
$timeStamp      = time();
$sig            = GenerateSignature($appId, $signatureNonce, $serverSecret, $timeStamp);

$url = "https://rtc-api.zego.im/?Action=DescribeSimpleStreamList"
     . "&RoomId=$roomId"
     . "&AppId=$appId"
     . "&SignatureNonce=$signatureNonce"
     . "&Timestamp=$timeStamp"
     . "&Signature=$sig"
     . "&SignatureVersion=2.0"
     . "&IsTest=false";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
$response = curl_exec($ch);
curl_close($ch);

echo "Url: " . $url . "\n";
echo "Response: " . $response . "\n";