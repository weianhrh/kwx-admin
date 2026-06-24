<?php
/**
 * 获取房间 room1 内用户数目的最简示例代码
 */

//请将 appId 修改为你的 AppId
//举例：1234567890
$appId = 141962251;
//请将 serverSecret 修改为你的 serverSecret，serverSecret 为 字符串
//举例："1234567890bbc111111da999ef05f0ee"
$serverSecret = '5bfaa3399946c98cc6792dd19f9a08ec';

//生成签名
//Signature=md5(AppId + SignatureNonce + ServerSecret + Timestamp)
function GenerateSignature($appId, $signatureNonce, $serverSecret, $timeStamp){
    $str = $appId . $signatureNonce . $serverSecret . $timeStamp;
    //使用PHP中标准的MD5算法，默认返回32字符16进制数
    return md5($str);
}

//取随机字符串并转换为十六进制值
$signatureNonce = bin2hex(random_bytes(8));

//取毫秒级时间戳
list($msec, $sec) = explode(' ', microtime());
$msecTime = (float)sprintf('%.0f', (floatval($msec) + floatval($sec)) * 1000);
//对$msecTime四舍五入取到秒级时间戳
$timeStamp = round($msecTime/1000);
// 生成签名
// 生成签名时用到的 Timestamp 要和url参数中的 Timestamp一致，生成签名时用到的 SignatureNonce 也要和url参数中的 SignatureNonce 一致
$sig = GenerateSignature($appId, $signatureNonce, $serverSecret, $timeStamp);

//以下示例可调用 "DescribeUserNum" API 获取 RoomID 为 room1 的房间的用户数量
$url = "https://rtc-api.zego.im/?Action=DescribeUserNum&RoomId[]=120001&AppId=$appId&SignatureNonce=$signatureNonce&Timestamp=$timeStamp&Signature=$sig&SignatureVersion=2.0&IsTest=false";

//使用curl库
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
//设置请求连接超时时间,单位：秒
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
$response = curl_exec($ch);
curl_close($ch);
//请求的url
echo "Url: " . $url ."\r\n";
//返回的结果
echo "response:" . $response;