<?php
declare(strict_types=1);

const DEVICE_WIFI_WHITELIST_FILE = __DIR__ . '/../config/device_wifi_whitelist.txt';

function device_wifi_whitelist_save(array $venueIds): bool
{
    $ids = [];
    foreach ($venueIds as $venueId) {
        $venueId = (int)$venueId;
        if ($venueId > 0) {
            $ids[$venueId] = true;
        }
    }

    $ids = array_keys($ids);
    sort($ids, SORT_NUMERIC);
    $content = $ids ? implode(PHP_EOL, $ids) . PHP_EOL : '';
    $file = DEVICE_WIFI_WHITELIST_FILE;
    $directory = dirname($file);

    if (!is_dir($directory) && !@mkdir($directory, 0775, true)) {
        return false;
    }

    if (is_file($file) && is_writable($file)) {
        return @file_put_contents($file, $content, LOCK_EX) !== false;
    }

    $tempFile = $file . '.tmp.' . bin2hex(random_bytes(6));
    if (@file_put_contents($tempFile, $content, LOCK_EX) === false) {
        return false;
    }

    @chmod($tempFile, 0664);
    if (!@rename($tempFile, $file)) {
        @unlink($tempFile);
        return false;
    }

    return true;
}

function device_wifi_whitelist_ensure(): bool
{
    if (is_file(DEVICE_WIFI_WHITELIST_FILE)) {
        return true;
    }

    // 首次缺少配置文件时保留现有18号场地开放状态。
    return device_wifi_whitelist_save([18]);
}

function device_wifi_whitelist_ids(): array
{
    if (!device_wifi_whitelist_ensure()) {
        return [];
    }

    $content = @file_get_contents(DEVICE_WIFI_WHITELIST_FILE);
    if ($content === false || trim($content) === '') {
        return [];
    }

    $ids = [];
    foreach (preg_split('/[\s,，]+/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        if (ctype_digit($part) && (int)$part > 0) {
            $ids[(int)$part] = true;
        }
    }

    $result = array_keys($ids);
    sort($result, SORT_NUMERIC);
    return $result;
}

function device_wifi_whitelist_enabled(int $venueId): bool
{
    return $venueId > 0 && in_array($venueId, device_wifi_whitelist_ids(), true);
}
