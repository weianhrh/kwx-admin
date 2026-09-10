<?php
// api/operat/svga_proxy.php
// 后台同源读取 app.kwxapp.cn 的 SVGA 文件，避免浏览器因静态文件缺少 CORS 响应头而拦截。
// PHP 7.3 compatible.

require_once __DIR__ . '/../Database.php';

function svgaProxyError($message, $httpStatus)
{
    http_response_code($httpStatus);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    svgaProxyError('仅支持 GET 请求', 405);
}

$sessionToken = isset($_COOKIE['session_token']) ? (string)$_COOKIE['session_token'] : '';
if ($sessionToken === '') {
    svgaProxyError('未登录或会话已过期', 401);
}

$database = new Database();
$user = $database->getUserBySessionToken($sessionToken);
$database->close();

if (!$user) {
    svgaProxyError('未登录或会话已过期', 401);
}

$roleId = isset($user['role_id']) ? (int)$user['role_id'] : 0;
if (!in_array($roleId, array(1, 2, 3, 4), true)) {
    svgaProxyError('无权预览驾驶礼物', 403);
}

$sourceUrl = isset($_GET['url']) ? trim((string)$_GET['url']) : '';
if ($sourceUrl === '' || !filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
    svgaProxyError('SVGA 链接不正确', 400);
}

$parts = parse_url($sourceUrl);
$scheme = isset($parts['scheme']) ? strtolower((string)$parts['scheme']) : '';
$host = isset($parts['host']) ? strtolower((string)$parts['host']) : '';
$path = isset($parts['path']) ? (string)$parts['path'] : '';
$port = isset($parts['port']) ? (int)$parts['port'] : 443;

// 严格限制域名和目录，不能把此接口当作任意 URL 代理使用。
if (
    $scheme !== 'https' ||
    $host !== 'app.kwxapp.cn' ||
    $port !== 443 ||
    !preg_match('#^/app/imgv2/img/[A-Za-z0-9._-]+\.svga$#i', $path)
) {
    svgaProxyError('只允许预览驾驶礼物 SVGA 文件', 400);
}

if (!function_exists('curl_init')) {
    svgaProxyError('服务器未启用 cURL', 500);
}

// 忽略原链接的查询参数，使用校验后的固定域名与路径，避免跳转和 SSRF。
$remoteUrl = 'https://app.kwxapp.cn' . $path;
$curl = curl_init($remoteUrl);
curl_setopt_array($curl, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => 'KwxAdminSvgaPreview/1.0',
    CURLOPT_HTTPHEADER => array('Accept: application/octet-stream,*/*;q=0.8'),
));

$binary = curl_exec($curl);
$curlError = curl_error($curl);
$httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
$contentType = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
curl_close($curl);

if ($binary === false || $httpCode !== 200) {
    error_log('svga_proxy.php fetch failed: HTTP ' . $httpCode . ' ' . $curlError);
    svgaProxyError('读取 SVGA 文件失败', 502);
}

$size = strlen($binary);
if ($size <= 0) {
    svgaProxyError('SVGA 文件内容为空', 502);
}
if ($size > 20 * 1024 * 1024) {
    svgaProxyError('SVGA 文件超过 20MB 限制', 413);
}

header('Content-Type: application/octet-stream');
header('Content-Length: ' . $size);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('X-SVGA-Source-Type: ' . ($contentType !== '' ? $contentType : 'unknown'));
echo $binary;
exit;

