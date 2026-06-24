
<?php
require_once '../RedisHelper.php';


$redis = new RedisHelper();
$redis->connect('127.0.0.1', 6379);
$redis->selectDb(9);

$pattern = 'A_*';
$cursor = 0;
$reservationCounts = [];

do {
    $result = $redis->scan($cursor, $pattern, 100);
    $cursor = $result[0];
    $keys = $result[1];

    if (is_array($keys)) {
        foreach ($keys as $key) {
            $json = $redis->get($key);
            $data = json_decode($json, true);

            if ($data && isset($data['reservation_id'])) {
                $reservationId = $data['reservation_id'];
                if (!isset($reservationCounts[$reservationId])) {
                    $reservationCounts[$reservationId] = 0;
                }
                $reservationCounts[$reservationId]++;
            }
        }
    }
} while ($cursor != 0);

echo "Reservation Counts:\n";
print_r($reservationCounts);

$redis->close();
?>
