<?php
ob_start();

require_once '../Database.php';

date_default_timezone_set('Asia/Shanghai');

$db = new Database();

// 防止 GROUP_CONCAT 内容太长被截断
$db->query("SET SESSION group_concat_max_len = 1024000", [], true);

$sql = "
    SELECT
        TRIM(v.image_device_serial) AS image_device_serial,
        COUNT(v.id) AS vehicle_count,
        GROUP_CONCAT(v.id ORDER BY v.id SEPARATOR ', ') AS vehicle_ids,
        GROUP_CONCAT(v.serial_number ORDER BY v.id SEPARATOR ', ') AS serial_numbers,
        GROUP_CONCAT(COALESCE(v.name, '') ORDER BY v.id SEPARATOR ' | ') AS vehicle_names,
        GROUP_CONCAT(COALESCE(v.bind_site, '') ORDER BY v.id SEPARATOR ', ') AS bind_sites,
        GROUP_CONCAT(COALESCE(v.status, '') ORDER BY v.id SEPARATOR ', ') AS statuses,
        MIN(v.created_at) AS first_created_at,
        MAX(v.created_at) AS last_created_at
    FROM vehicles AS v
    WHERE v.image_device_serial IS NOT NULL
      AND TRIM(v.image_device_serial) <> ''
      AND v.serial_number IS NOT NULL
      AND TRIM(v.serial_number) <> ''

      -- 排除 ZEGO：纯数字，并且长度小于 8 位，例如 80004
      AND NOT (
          TRIM(v.image_device_serial) REGEXP '^[0-9]+$'
          AND CHAR_LENGTH(TRIM(v.image_device_serial)) < 8
      )

    GROUP BY TRIM(v.image_device_serial)
    HAVING COUNT(v.id) > 1
    ORDER BY vehicle_count DESC, image_device_serial ASC
";

$rows = $db->query($sql);

if (!is_array($rows)) {
    $rows = [];
}

if (method_exists($db, 'close')) {
    $db->close();
}

function h($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// 按文本格式输出，避免 Excel 把序列号、设备号转成科学计数法
function tdText($value)
{
    echo "<td style=\"mso-number-format:'\\@';\">" . h($value) . "</td>";
}

function thText($value)
{
    echo "<th style=\"background:#f2f2f2;\">" . h($value) . "</th>";
}

$filename = '非ZEGO重复image_device_serial_' . date('Ymd_His') . '.xls';

// 清掉前面可能产生的空格/换行，避免 header 失效
while (ob_get_level() > 0) {
    ob_end_clean();
}

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"duplicate_non_zego_image_device_serial.xls\"; filename*=UTF-8''" . rawurlencode($filename));
header("Pragma: no-cache");
header("Expires: 0");

// 防止中文乱码
echo "\xEF\xBB\xBF";

echo "<html>";
echo "<head><meta charset='UTF-8'></head>";
echo "<body>";

echo "<div style='font-weight:bold;margin-bottom:8px;'>";
echo "非 ZEGO 重复 image_device_serial 查询结果";
echo "</div>";

echo "<div style='margin-bottom:8px;'>";
echo "排除规则：image_device_serial 为纯数字，并且长度小于 8 位的记录不统计，例如 80004。";
echo "</div>";

echo "<table border='1' cellspacing='0' cellpadding='5'>";
echo "<tr>";
thText("image_device_serial");
thText("重复车辆数");
thText("vehicle_ids");
thText("serial_numbers");
thText("vehicle_names");
thText("bind_sites");
thText("statuses");
thText("最早创建时间");
thText("最后创建时间");
echo "</tr>";

if (empty($rows)) {
    echo "<tr>";
    echo "<td colspan='9'>没有查询到非 ZEGO 的重复 image_device_serial</td>";
    echo "</tr>";
} else {
    foreach ($rows as $row) {
        echo "<tr>";
        tdText($row['image_device_serial'] ?? '');
        tdText($row['vehicle_count'] ?? '');
        tdText($row['vehicle_ids'] ?? '');
        tdText($row['serial_numbers'] ?? '');
        tdText($row['vehicle_names'] ?? '');
        tdText($row['bind_sites'] ?? '');
        tdText($row['statuses'] ?? '');
        tdText($row['first_created_at'] ?? '');
        tdText($row['last_created_at'] ?? '');
        echo "</tr>";
    }
}

echo "</table>";
echo "</body>";
echo "</html>";

exit;