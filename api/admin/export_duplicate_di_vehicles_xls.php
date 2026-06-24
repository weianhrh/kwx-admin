<?php
require_once '../Database.php';

date_default_timezone_set('Asia/Shanghai');

$db = new Database();

$db->query("SET SESSION group_concat_max_len = 1024000", [], true);

$sql = "
    SELECT
        di.id AS device_id,
        di.playing_stream_id,
        COUNT(v.id) AS vehicle_count,
        GROUP_CONCAT(v.id ORDER BY v.id) AS vehicle_ids,
        GROUP_CONCAT(v.serial_number ORDER BY v.id) AS serial_numbers
    FROM device_information AS di
    INNER JOIN vehicles AS v
        ON v.image_device_serial = di.id
    WHERE di.playing_stream_id IS NOT NULL
      AND TRIM(di.playing_stream_id) <> ''
      AND v.serial_number IS NOT NULL
      AND TRIM(v.serial_number) <> ''
    GROUP BY di.id, di.playing_stream_id
    HAVING COUNT(v.id) > 1
    ORDER BY vehicle_count DESC
";

$rows = $db->query($sql);

$filename = '重复绑定设备_' . date('Ymd_His') . '.xls';

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=" . $filename);
header("Pragma: no-cache");
header("Expires: 0");

// 防止中文乱码
echo "\xEF\xBB\xBF";

echo "<table border='1'>";
echo "<tr>";
echo "<th>device_id</th>";
echo "<th>playing_stream_id</th>";
echo "<th>vehicle_count</th>";
echo "<th>vehicle_ids</th>";
echo "<th>serial_numbers</th>";
echo "</tr>";

foreach ($rows as $row) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['device_id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['playing_stream_id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['vehicle_count']) . "</td>";
    echo "<td>" . htmlspecialchars($row['vehicle_ids']) . "</td>";
    echo "<td>" . htmlspecialchars($row['serial_numbers']) . "</td>";
    echo "</tr>";
}

echo "</table>";

$db->close();
exit;