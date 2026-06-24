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
                YEARWEEK(time_value, 1) AS label,
                MIN(DATE(time_value)) AS start_date,
                MAX(DATE(time_value)) AS end_date,
                SUM(amount) AS total
            FROM (
                SELECT
                    COALESCE(end_time, start_time) AS time_value,
                    payment_amount AS amount
                FROM orders
                WHERE reservation_id = ?
                  AND TRIM(IFNULL(pays_type, '')) <> '能量'
                  AND (end_time IS NOT NULL OR start_time IS NOT NULL)

                UNION ALL

                SELECT
                    send_time AS time_value,
                    payment_amount / 10 * 0.6 AS amount
                FROM gift_orders
                WHERE reservation_id = ?
                  AND status = '已完成'
                  AND send_time IS NOT NULL
            ) t
            GROUP BY label
            ORDER BY label DESC
        ";
        break;

    case 'month':
        $sql = "
            SELECT
                DATE_FORMAT(time_value, '%Y-%m') AS label,
                SUM(amount) AS total
            FROM (
                SELECT
                    COALESCE(end_time, start_time) AS time_value,
                    payment_amount AS amount
                FROM orders
                WHERE reservation_id = ?
                  AND TRIM(IFNULL(pays_type, '')) <> '能量'
                  AND (end_time IS NOT NULL OR start_time IS NOT NULL)

                UNION ALL

                SELECT
                    send_time AS time_value,
                    payment_amount / 10 * 0.6 AS amount
                FROM gift_orders
                WHERE reservation_id = ?
                  AND status = '已完成'
                  AND send_time IS NOT NULL
            ) t
            GROUP BY label
            ORDER BY label DESC
        ";
        break;

    case 'day':
    default:
        $sql = "
            SELECT
                DATE(time_value) AS label,
                SUM(amount) AS total
            FROM (
                SELECT
                    COALESCE(end_time, start_time) AS time_value,
                    payment_amount AS amount
                FROM orders
                WHERE reservation_id = ?
                  AND TRIM(IFNULL(pays_type, '')) <> '能量'
                  AND (end_time IS NOT NULL OR start_time IS NOT NULL)

                UNION ALL

                SELECT
                    send_time AS time_value,
                    payment_amount / 10 * 0.6 AS amount
                FROM gift_orders
                WHERE reservation_id = ?
                  AND status = '已完成'
                  AND send_time IS NOT NULL
            ) t
            GROUP BY label
            ORDER BY label DESC
        ";
        break;
}


// 这行留着执行查询
$data = $database->query($sql, [$venue_id, $venue_id]);



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
      --primary:#5b8cff;
      --primary-dark:#2a66e0;
      --bg1:#f8f9ff;
      --bg2:#f0f3ff;
      --text:#111827;
      --muted:#6b7280;
      --card:#ffffff;
      --shadow: 0 8px 24px rgba(17,24,39,.08);
      --radius: 14px;
    }

    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,"PingFang SC","Microsoft YaHei",sans-serif;
      background: linear-gradient(180deg,var(--bg1),var(--bg2));
      color: var(--text);
      padding: 18px 14px 28px;
    }

    .wrap{
      max-width: 420px; /* 手机看着像App页面 */
      margin: 0 auto;
    }

    h2{
      margin: 10px 0 14px;
      font-size: 20px;
      font-weight: 800;
      text-align:center;
      letter-spacing: .5px;
    }

    /* 胶囊按钮组 */
    .toolbar{
      display:flex;
      gap:10px;
      justify-content:center;
      padding: 10px;
      background: rgba(255,255,255,.7);
      border: 1px solid rgba(0,0,0,.06);
      border-radius: 999px;
      box-shadow: 0 6px 18px rgba(17,24,39,.06);
      position: sticky;
      top: 10px;
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      z-index: 10;
      margin-bottom: 14px;
    }

    .toolbar button{
      flex:1;
      min-width: 0;
      border:none;
      border-radius: 999px;
      padding: 10px 0;
      font-size: 14px;
      font-weight: 700;
      background: rgba(91,140,255,.12);
      color: var(--primary-dark);
      cursor:pointer;
      transition: .2s;
    }
    .toolbar button.active{
      background: var(--primary);
      color:#fff;
      box-shadow: 0 10px 20px rgba(91,140,255,.25);
    }
    .toolbar button:active{
      transform: scale(.98);
    }

    .card-container{
      display:flex;
      flex-direction: column;
      gap: 12px;
    }

    .card{
      background: var(--card);
      border-radius: var(--radius);
      padding: 14px 16px;
      box-shadow: var(--shadow);
      border: 1px solid rgba(0,0,0,.05);
    }

    .card-label{
      font-size: 13px;
      color: var(--muted);
      margin-bottom: 8px;
      text-align:center;
    }

    .card-value{
      font-size: 22px;
      font-weight: 900;
      text-align:center;
      color: #0f172a;
    }

    /* 大一点屏幕（比如横屏/平板）再变两列 */
    @media (min-width: 520px){
      .wrap{ max-width: 760px; }
      .card-container{
        display:grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 14px;
      }
    }
  </style>
</head>

<body>
  <div class="wrap">
    <h2><?= htmlspecialchars($venue_name) ?> 收益趋势</h2>

    <div class="toolbar">
      <button onclick="changePeriod('day')" class="<?= $period == 'day' ? 'active' : '' ?>">按天</button>
      <button onclick="changePeriod('week')" class="<?= $period == 'week' ? 'active' : '' ?>">按周</button>
      <button onclick="changePeriod('month')" class="<?= $period == 'month' ? 'active' : '' ?>">按月</button>
    </div>

    <div class="card-container">
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
