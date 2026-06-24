<?php
// InitVersion.php

ob_start(); // 开启输出缓冲，截获整个页面输出

function autoAppendVersion($html) {
    return preg_replace_callback(
        '#<(script|link)([^>]+)(src|href)="([^"]+?)"#i',
        function ($matches) {
            $tag = $matches[1];
            $attrs = $matches[2];
            $attrType = $matches[3];
            $src = $matches[4];

            // 只处理本地资源，跳过 https://、// 等远程资源
            if (strpos($src, '//') !== false) return $matches[0];

            $path = parse_url($src, PHP_URL_PATH);
            $fullPath = $_SERVER['DOCUMENT_ROOT'] . $path;

            if (file_exists($fullPath)) {
                $ver = filemtime($fullPath);
                return "<$tag$attrs$attrType=\"$src?v=$ver\"";
            }

            return $matches[0];
        },
        $html
    );
}

register_shutdown_function(function () {
    $content = ob_get_clean();
    echo autoAppendVersion($content);
});
