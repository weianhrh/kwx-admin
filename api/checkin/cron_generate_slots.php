<?php
// api/checkin/cron_generate_slots.php
require_once '../Database.php';
require_once '../RedisHelper.php';

date_default_timezone_set('Asia/Taipei');

/**
 * 在 [startTs, endTs] 内随机选 4 个时间点，彼此至少间隔 minGapSec 秒
 */
function pick4TimesInHour(int $startTs, int $endTs, int $minGapSec = 600): array {
    $slots = [];
    $tries = 0;
    while (count($slots) < 4 && $tries < 2000) {
        $tries++;
        $r = random_int($startTs, $endTs);
        $ok = true;
        foreach ($slots as $t) {
            if (abs($t - $r) < $minGapSec) {
                $ok = false;
                break;
            }
        }
        if ($ok) $slots[] = $r;
    }
    sort($slots);
    return array_map(fn($t) => date('Y-m-d H:i:s', $t), $slots);
}

try {
    $rh = new RedisHelper();
    $rh->connect('127.0.0.1', 6379, 2);
    $rh->selectDb(10);

    $now         = new DateTime('now');
    $period      = $now->format('YmdH');                      // 2025111713
    $periodStart = strtotime($now->format('Y-m-d H:00:00'));  // 13:00:00
    $slotEndTs   = $periodStart + (57 * 60) + 59;             // 13:57:59
    $hourEndTs   = $periodStart + (59 * 60) + 59;             // 13:59:59

    $slotsKey         = "checkin:slots:$period";
    $resignCandidateKey = "checkin:resign_candidate:$period";

    // 1）生成 4 个随机节点，TTL 到 57:59
    if (!$rh->exists($slotsKey)) {
        $times = pick4TimesInHour($periodStart, $slotEndTs, 600);
        $ttl   = max(1, $slotEndTs - time() + 5);
        $json  = json_encode($times, JSON_UNESCAPED_UNICODE);

        if (method_exists($rh, 'setWithExpiration')) {
            $rh->setWithExpiration($slotsKey, $json, $ttl);
        } else {
            $rh->set($slotsKey, $json);
            if (method_exists($rh, 'expireAt')) {
                $rh->expireAt($slotsKey, time() + $ttl);
            }
        }

        echo "✅ Generated slots for $period (ttl={$ttl}s): " . implode(', ', $times) . "\n";
    } else {
        echo "⏩ Slots for $period already exist.\n";
    }

    // 2）额外生成一个“补签候选 key”，TTL 到 59:59
    if (!$rh->exists($resignCandidateKey)) {
        $payload = [
            'period'     => $period,
            'hour_start' => date('Y-m-d H:00:00', $periodStart),
            'hour_end'   => date('Y-m-d H:59:59', $periodStart),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ttl  = max(30, $hourEndTs - time() + 5);  // 保证到 59:59 左右过期

        if (method_exists($rh, 'setWithExpiration')) {
            $rh->setWithExpiration($resignCandidateKey, $json, $ttl);
        } else {
            $rh->set($resignCandidateKey, $json);
            if (method_exists($rh, 'expireAt')) {
                $rh->expireAt($resignCandidateKey, time() + $ttl);
            }
        }

        echo "✅ Created resign_candidate for $period (ttl={$ttl}s)\n";
    } else {
        echo "⏩ resign_candidate for $period already exists.\n";
    }

} catch (Throwable $e) {
    echo "ERR: " . $e->getMessage() . "\n";
}
