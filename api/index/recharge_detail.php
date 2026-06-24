<?php
require_once '../Database.php';
$database = new Database();

$period = $_GET['period'] ?? 'day'; // day, week, month

switch ($period) {
case 'week':
    $sql = "
        SELECT 
            YEARWEEK(created_at, 3) AS label,
            ROUND(SUM(payer_total), 2) AS total
        FROM RechargeOrders
        WHERE status = '支付成功'
        GROUP BY label
        ORDER BY label DESC
        LIMIT 12
    ";
    break;

    case 'month':
        $sql = "
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') AS label,
                ROUND(SUM(payer_total), 2) AS total
            FROM RechargeOrders
            WHERE status = '支付成功'
            GROUP BY label
            ORDER BY label DESC
            LIMIT 12
        ";
        break;

    case 'day':
    default:
        $sql = "
            SELECT 
                DATE(created_at) AS label,
                ROUND(SUM(payer_total), 2) AS total
            FROM RechargeOrders
            WHERE status = '支付成功'
            GROUP BY label
            ORDER BY label DESC
            LIMIT 30
        ";
        break;
}

$data = $database->query($sql);
$database->close();
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>平台流水统计</title>
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
            background: #5b8cff;
            color: #fff;
            cursor: pointer;
            font-size: 14px;
        }
        .toolbar button.active {
            background: #2a66e0;
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
            width: 160px;
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
    </style>
</head>
<body>
    <h2>平台流水统计</h2>

    <div class="toolbar">
        <button onclick="changePeriod('day')" class="<?= $period == 'day' ? 'active' : '' ?>">按天</button>
        <button onclick="changePeriod('week')" class="<?= $period == 'week' ? 'active' : '' ?>">按周</button>
        <button onclick="changePeriod('month')" class="<?= $period == 'month' ? 'active' : '' ?>">按月</button>
    </div>

    <div class="card-container">
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
            <a class="card card-link" href="<?= htmlspecialchars($cardHref, ENT_QUOTES, 'UTF-8') ?>">
        <?php else: ?>
            <div class="card">
        <?php endif; ?>
        
            <div class="card-label"><?= $label ?></div>
            <div class="card-value"><?= $row['total'] ?> 元</div>
        
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
