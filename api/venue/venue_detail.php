<?php
require_once '../Database.php';
$database = new Database();

$venue_id = $_GET['venue_id'] ?? 0;
$period = $_GET['period'] ?? 'day'; // day, week, month
if (!$venue_id) {
    exit("缺少参数");
}

// 获取场地名称
$venueInfo = $database->query("SELECT venue_name FROM venues WHERE id = ?", [$venue_id]);
$venue_name = $venueInfo[0]['venue_name'] ?? '未知场地';

// 保留这个 switch
switch ($period) {
    case 'week':
        $sql = "
            SELECT
                YEARWEEK(COALESCE(end_time, start_time), 1) AS label,
                MIN(DATE(COALESCE(end_time, start_time))) AS start_date,
                MAX(DATE(COALESCE(end_time, start_time))) AS end_date,
                SUM(payment_amount) AS total
            FROM orders
            WHERE reservation_id = ?
              AND TRIM(IFNULL(pays_type, '')) <> '能量'
              AND (end_time IS NOT NULL OR start_time IS NOT NULL)
            GROUP BY label
            ORDER BY label DESC
        ";
        break;

    case 'month':
        $sql = "
            SELECT
                DATE_FORMAT(COALESCE(end_time, start_time), '%Y-%m') AS label,
                SUM(payment_amount) AS total
            FROM orders
            WHERE reservation_id = ?
              AND TRIM(IFNULL(pays_type, '')) <> '能量'
              AND (end_time IS NOT NULL OR start_time IS NOT NULL)
            GROUP BY label
            ORDER BY label DESC
        ";
        break;

    case 'day':
    default:
        $sql = "
            SELECT
                DATE(COALESCE(end_time, start_time)) AS label,
                SUM(payment_amount) AS total
            FROM orders
            WHERE reservation_id = ?
              AND TRIM(IFNULL(pays_type, '')) <> '能量'
              AND (end_time IS NOT NULL OR start_time IS NOT NULL)
            GROUP BY label
            ORDER BY label DESC
        ";
        break;
}


// 这行留着执行查询
$data = $database->query($sql, [$venue_id]) ?: [];



$database->close();
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <title>场地收益详情</title>
  <style>
    :root{
      --primary:#0f8f8c;
      --primary-dark:#08706e;
      --primary-soft:#e6f6f5;
      --page:#f4f7f8;
      --line:#dfe8eb;
      --text:#172126;
      --muted:#667985;
      --card:#ffffff;
      --shadow:0 12px 34px rgba(30,55,66,.08);
      --radius:8px;
    }

    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,"PingFang SC","Microsoft YaHei",sans-serif;
      background: var(--page);
      color: var(--text);
      padding: 22px;
    }

    .wrap{
      width: 100%;
      max-width: none;
      margin: 0 auto;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: var(--card);
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    h2{
      position: relative;
      margin: 0;
      padding: 20px 22px 8px 38px;
      font-size: 22px;
      font-weight: 800;
      line-height: 1.3;
      text-align:left;
      letter-spacing: 0;
    }

    h2::before{
      position:absolute;
      left:22px;
      top:23px;
      width:6px;
      height:22px;
      border-radius:999px;
      background:var(--primary);
      content:"";
    }

    .sub-title{
      margin:0;
      padding:0 22px 18px 38px;
      color:var(--muted);
      font-size:14px;
    }

    .toolbar{
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      justify-content:flex-start;
      padding: 16px 22px;
      border-top:1px solid var(--line);
      border-bottom:1px solid var(--line);
      background:#fbfdfd;
    }

    .toolbar button{
      min-width: 90px;
      border:1px solid rgba(15,143,140,.24);
      border-radius: var(--radius);
      padding: 9px 16px;
      font-size: 14px;
      font-weight: 700;
      background: var(--primary-soft);
      color: var(--primary-dark);
      cursor:pointer;
    }
    .toolbar button.active{
      background: var(--primary);
      color:#fff;
      border-color:var(--primary);
    }

    .card-container{
      display:grid;
      grid-template-columns: repeat(4, minmax(160px, 1fr));
      gap: 12px;
      padding: 18px 22px 22px;
    }

    .card{
      background: var(--card);
      border-radius: var(--radius);
      padding: 14px 16px;
      border: 1px solid var(--line);
      box-shadow: none;
    }

    .card-label{
      font-size: 13px;
      color: var(--muted);
      margin-bottom: 8px;
      text-align:left;
      font-weight:700;
    }

    .card-value{
      font-size: 24px;
      font-weight: 900;
      text-align:left;
      color: var(--text);
    }

    .empty{
      grid-column: 1 / -1;
      padding: 36px 16px;
      color: var(--muted);
      text-align:center;
    }

    @media (max-width: 1100px){
      .card-container{
        grid-template-columns: repeat(2, 1fr);
      }
    }

    @media (max-width: 640px){
      body{ padding: 12px; }
      h2{ padding: 18px 16px 8px 32px; font-size: 20px; }
      h2::before{ left:16px; top:21px; }
      .sub-title{ padding:0 16px 16px 32px; }
      .toolbar{ padding:14px 16px; }
      .toolbar button{ flex:1; }
      .card-container{ grid-template-columns: 1fr; padding:14px 16px 16px; }
    }
  </style>
</head>

<body>
  <div class="wrap">
    <h2><?= htmlspecialchars($venue_name) ?> 业绩趋势</h2>
    <p class="sub-title">仅统计驾驶订单收入，不包含礼物收入。</p>

    <div class="toolbar">
      <button onclick="changePeriod('day')" class="<?= $period == 'day' ? 'active' : '' ?>">按天</button>
      <button onclick="changePeriod('week')" class="<?= $period == 'week' ? 'active' : '' ?>">按周</button>
      <button onclick="changePeriod('month')" class="<?= $period == 'month' ? 'active' : '' ?>">按月</button>
    </div>

    <div class="card-container">
     <?php if (empty($data)): ?>
        <div class="empty">暂无业绩数据</div>
     <?php endif; ?>
     <?php foreach ($data as $row):
    if ($period === 'week') {
        $label = date("m月d日", strtotime($row['start_date'])) . ' ~ ' . date("m月d日", strtotime($row['end_date']));
    } elseif ($period === 'month') {
        $label = date("Y年m月", strtotime($row['label'] . '-01'));
    } elseif ($period === 'day') {
        $label = date("m月d日", strtotime($row['label']));
    } else {
        $label = htmlspecialchars($row['label']);
    }
?>
    <div class="card">
        <div class="card-label"><?= $label ?></div>
        <div class="card-value"><?= round($row['total'], 2) ?> 元</div>
    </div>
<?php endforeach; ?>




    </div>

    <script>
        function changePeriod(period) {
            window.location.href = `venue_detail.php?venue_id=<?= $venue_id ?>&period=${period}`;
        }
    </script>
    </div>
</body>
</html>
