<?php
require_once '../Database.php';
$database = new Database();

$period = $_GET['period'] ?? 'day'; // day, week, month
if (!in_array($period, ['day', 'week', 'month'], true)) {
    $period = 'day';
}

// 平台收益：只保留驾驶订单收入，剔除礼物、金币、娃娃机和能量订单。
$driveOrderWhere = "
    FROM orders
    WHERE end_time IS NOT NULL
      AND TRIM(IFNULL(pays_type, '')) NOT IN ('能量', '金币')
      AND TRIM(IFNULL(note, '')) NOT IN ('gift', '礼物', '娃娃机抓取扣费')
";

switch ($period) {
    case 'week':
        $sql = "
            SELECT 
                YEARWEEK(end_time, 3) AS label,
                ROUND(SUM(COALESCE(payment_amount, 0)), 2) AS total
            {$driveOrderWhere}
            GROUP BY label
            ORDER BY label DESC
            LIMIT 12
        ";
        break;

    case 'month':
        $sql = "
            SELECT 
                DATE_FORMAT(end_time, '%Y-%m') AS label,
                ROUND(SUM(COALESCE(payment_amount, 0)), 2) AS total
            {$driveOrderWhere}
            GROUP BY label
            ORDER BY label DESC
            LIMIT 12
        ";
        break;

    case 'day':
    default:
        $sql = "
            SELECT 
                DATE(end_time) AS label,
                ROUND(SUM(COALESCE(payment_amount, 0)), 2) AS total
            {$driveOrderWhere}
            GROUP BY label
            ORDER BY label DESC
            LIMIT 30
        ";
        break;
}

$data = $database->query($sql) ?: [];
$database->close();
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>平台驾驶订单收益统计</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            background: #f9f9f9;
            margin: 0;
        }
        h2 {
            margin-bottom: 20px;
            font-size: 20px;
            word-break: break-word;
            text-align: center;
        }
        .desc {
            max-width: 760px;
            margin: -8px auto 18px;
            color: #666;
            font-size: 13px;
            line-height: 1.7;
            text-align: center;
        }
        .toolbar {
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: center;
        }
        .toolbar button {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            background: #0f8f8c;
            color: #fff;
            cursor: pointer;
            font-size: 14px;
        }
        .toolbar button.active {
            background: #0f8f8c;
        }
        .card-container {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: center;
        }
        .card {
            background: #fff;
            border-radius: 8px;
            padding: 15px 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            width: 180px;
            text-align: center;
        }
        .card-link {
            display: block;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
            transition: all .2s ease;
        }
        
        .card-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(0,0,0,0.16);
        }
        .card-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
        }
        .card-value {
            font-size: 18px;
            color: #333;
            font-weight: bold;
        }
        .empty {
            width: 100%;
            max-width: 520px;
            margin: 20px auto;
            padding: 22px;
            color: #777;
            background: #fff;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }
    </style>
</head>
<body>
    <h2>平台驾驶订单收益统计</h2>
    <div class="desc">当前只统计驾驶订单收入，已剔除礼物、金币、娃娃机和能量订单。点击日期卡片可查看对应周期的场地业绩排行。</div>

    <div class="toolbar">
        <button onclick="changePeriod('day')" class="<?= $period == 'day' ? 'active' : '' ?>">按天</button>
        <button onclick="changePeriod('week')" class="<?= $period == 'week' ? 'active' : '' ?>">按周</button>
        <button onclick="changePeriod('month')" class="<?= $period == 'month' ? 'active' : '' ?>">按月</button>
    </div>

    <div class="card-container">
<?php if (empty($data)): ?>
        <div class="empty">暂无驾驶订单收益数据</div>
<?php endif; ?>
<?php foreach ($data as $row): ?>
<?php
    $topPageUrl = '/res/top.html';

    $label = '';
    $startDate = '';
    $endDate = '';

    if ($period === 'week') {
        // label 形如 202624：2026年第24周
        $weekLabel = (string)$row['label'];
        $year = (int)substr($weekLabel, 0, 4);
        $week = (int)substr($weekLabel, 4, 2);

        $start = new DateTime();
        $start->setISODate($year, $week); // 周一

        $end = clone $start;
        $end->modify('+7 days'); // 下周一，作为 SQL 开区间

        $showEnd = clone $end;
        $showEnd->modify('-1 day'); // 周日，仅用于显示

        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');

        $label = $start->format('m月d日') . ' ~ ' . $showEnd->format('m月d日');

    } elseif ($period === 'month') {
        $start = DateTime::createFromFormat('Y-m-d', $row['label'] . '-01');

        $end = clone $start;
        $end->modify('+1 month');

        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');

        $label = $start->format('Y年m月');

    } elseif ($period === 'day') {
        $start = DateTime::createFromFormat('Y-m-d', $row['label']);

        $end = clone $start;
        $end->modify('+1 day');

        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');

        $label = $start->format('m月d日');

    } else {
        $label = htmlspecialchars((string)$row['label'], ENT_QUOTES, 'UTF-8');
    }

    $cardHref = '';
    if ($startDate && $endDate) {
        $cardHref = $topPageUrl . '?' . http_build_query([
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rank_type' => 'total'
        ]);
    }
?>
        
        <?php if ($cardHref): ?>
            <a class="card card-link" href="<?= htmlspecialchars($cardHref, ENT_QUOTES, 'UTF-8') ?>" title="点击查看该周期业绩排行">
        <?php else: ?>
            <div class="card">
        <?php endif; ?>
        
            <div class="card-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="card-value"><?= number_format((float)$row['total'], 2, '.', '') ?> 元</div>
        
        <?php if ($cardHref): ?>
            </a>
        <?php else: ?>
            </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <script>
        function changePeriod(period) {
            window.location.href = `recharge_detail.php?period=${period}`;
        }
    </script>
</body>
</html>
