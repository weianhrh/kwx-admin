<?php
// KWX 图文审核原图判断，首页与审核列表共用。2026-11-11

/**
 * 共享文件位于 api/venue：无论从首页还是审核页调用，都检查同一个目录。
 * 只检查本地路径；本文件不初始化数据库、不输出响应、不修改文件。
 */
function kwxPendingImageDirectoryInfo() {
    $directory = __DIR__ . '/pending_images';
    clearstatcache(true, $directory);
    $realDirectory = @realpath($directory);
    if ($realDirectory === false || !@is_dir($directory)
        || !@is_readable($directory)
        || (DIRECTORY_SEPARATOR === '/' && !@is_executable($directory))) {
        $realDirectory = false;
    }
    return ['directory' => $directory, 'real_directory' => $realDirectory];
}

/**
 * 只认当前 KWX 后台 pending_images 中的原图。
 * 支持相对路径、站内路径、KWX/聚合代理完整 URL；不对外发起 HTTP 请求。
 * OSS 正式图等无法映射为本地待审原图的地址，不返回为待审核卡片。
 */
function checkLocalPendingImage($url, $pendingDirectory, $realPendingDirectory) {
    $result = ['exists' => false, 'reason' => 'invalid_pending_path', 'filename' => ''];
    if (!is_string($url) || trim($url) === '') {
        return $result;
    }

    $parts = @parse_url(trim($url));
    if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
        return $result;
    }
    if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return $result;
    }
    if (isset($parts['host']) && !in_array(strtolower($parts['host']), ['open.kwxapp.cn', 'audit-kwx.rcwulian.cn'], true)) {
        $result['reason'] = 'not_local_pending_url';
        return $result;
    }
    if (isset($parts['scheme']) && !isset($parts['host'])) {
        return $result;
    }

    $path = $parts['path'] ?? '';
    $prefixes = isset($parts['host'])
        ? ['/api/venue/pending_images/']
        : ['pending_images/', './pending_images/', '/api/venue/pending_images/'];
    $encodedName = null;
    foreach ($prefixes as $prefix) {
        if (strpos($path, $prefix) === 0) {
            $encodedName = substr($path, strlen($prefix));
            break;
        }
    }
    if ($encodedName === null || $encodedName === '') {
        return $result;
    }

    // parse_url 已去掉 ?query/#fragment；rawurldecode 保留文件名中的 +。
    $filename = rawurldecode($encodedName);
    if ($filename === '.' || $filename === '..' || strlen($filename) > 255
        || strpos($filename, '/') !== false || strpos($filename, '\\') !== false
        || preg_match('/[\x00-\x1F\x7F]/', $filename)) {
        return $result;
    }
    $result['filename'] = $filename;
    if ($realPendingDirectory === false) {
        $result['reason'] = 'pending_directory_unavailable';
        return $result;
    }

    $filepath = $pendingDirectory . '/' . $filename;
    clearstatcache(true, $filepath);
    if (!@is_file($filepath)) {
        $result['reason'] = 'file_missing_or_not_regular';
        return $result;
    }
    if (!@is_readable($filepath)) {
        $result['reason'] = 'file_unreadable';
        return $result;
    }
    $realFile = @realpath($filepath);
    if ($realFile === false || dirname($realFile) !== $realPendingDirectory) {
        $result['reason'] = 'file_outside_pending_directory';
        return $result;
    }

    $result['exists'] = true;
    $result['reason'] = 'file_exists';
    return $result;
}

