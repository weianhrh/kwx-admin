<?php

// require_once __DIR__ . '/vendor/autoload.php';

// use Lcobucci\JWT\Configuration;
// use Lcobucci\JWT\Signer\EdDSA;
// use Lcobucci\JWT\Signer\Key\InMemory;

// // ====== 替换你自己的信息 ======
// $kid = 'T4WMJVYBCJ';
// $projectId = '3AE3T6KKMC';
// $privateKeyPem = <<<KEY
// -----BEGIN PRIVATE KEY-----
// MC4CAQAwBQYDK2VwBCIEIOQfsVSiq2/2tPFncymDWyg8m45a+LNkTDY9FitJ6E5c
// -----END PRIVATE KEY-----
// KEY;

// // ====== 生成配置对象 ======
// $config = Configuration::forAsymmetricSigner(
//     new EdDSA(),
//     InMemory::plainText($privateKeyPem),
//     InMemory::empty()
// );

// // ====== 构建 token ======
// $now = new DateTimeImmutable();
// $token = $config->builder()
//     ->issuedAt($now->modify('-30 seconds'))
//     ->expiresAt($now->modify('+1 day'))
//     ->relatedTo($projectId)  // sub
//     ->withHeader('kid', $kid)
//     ->getToken($config->signer(), $config->signingKey());

// // ====== 获取字符串形式的 token ======
// echo $token->toString();
$token = "eyJ0eXAiOiJKV1QiLCJhbGciOiJFZERTQSIsImtpZCI6IlQ0V01KVllCQ0oifQ.eyJzdWIiOiIzQUUzVDZLS01DIiwiaWF0IjoxNzQ2NzA5MTgzLCJleHAiOjE3NDY3MDk4MTN9.i2WzXJtHBG8uzGIbmQln3NcNnf4SNQfvOL4a06TisO7QVWmYOQQTUET63qNn-kqNTDrNGnQA0fG12veIINqeAg";
$curl = curl_init();

curl_setopt_array($curl, array(
    CURLOPT_URL => 'https://nn5vxkr22v.re.qweatherapi.com/v7/weather/now?location=101010100',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'GET',
    CURLOPT_HTTPHEADER => array(
        'Authorization: Bearer ' . $token,
        'User-Agent: Apifox/1.0.0 (https://apifox.com)',
        'Accept: */*',
        'Host: nn5vxkr22v.re.qweatherapi.com',
        'Connection: keep-alive'
    ),
));

$response = curl_exec($curl);
curl_close($curl);

echo $response;
