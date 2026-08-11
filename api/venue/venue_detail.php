<?php
require_once '../Database.php';
require_once '../lib/venue_scope.php';

$database = new Database();

$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    http_response_code(401);
    exit('用户未登录或会话已过期');
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || empty($user['role_id'])) {
    http_response_code(401);
    $database->close();
    exit('用户未登录或无权访问');
}

$role_id = (int)$user['role_id'];
if (!in_array($role_id, [1, 2, 3], true)) {
    http_response_code(403);
    $database->close();
    exit('无权查看场地业绩');
}

$is_platform_admin = venue_scope_is_platform_admin($user);
$visible_venues = venue_scope_visible_venues($database, $user);
$venue_id = venue_scope_requested_id($_GET);
$period = $_GET['period'] ?? 'day'; // day, week, month
if (!in_array($period, ['day', 'week', 'month'], true)) {
    $period = 'day';
}

if (!$is_platform_admin) {
    $has_primary_venue = false;
    foreach ($visible_venues as &$visible_venue) {
        $visible_venue['is_primary'] = (int)($visible_venue['is_primary'] ?? 0) === 1 ? 1 : 0;
        if ($visible_venue['is_primary'] === 1) {
            $has_primary_venue = true;
        }
    }
    unset($visible_venue);

    // 兼容旧账号：关系表没有主场地标记时，使用 admin_users.venue_id。
    if (!$has_primary_venue) {
        $legacy_primary_id = (int)($user['venue_id'] ?? 0);
        foreach ($visible_venues as &$visible_venue) {
            if ((int)($visible_venue['id'] ?? 0) === $legacy_primary_id) {
                $visible_venue['is_primary'] = 1;
                $has_primary_venue = true;
                break;
            }
        }
        unset($visible_venue);
    }

    // 极旧数据没有任何主场地信息时，将权限范围内第一个场地作为默认主场地。
    if (!$has_primary_venue && $visible_venues) {
        $visible_venues[0]['is_primary'] = 1;
    }

    usort($visible_venues, static function (array $a, array $b): int {
        $primary_compare = (int)($b['is_primary'] ?? 0) <=> (int)($a['is_primary'] ?? 0);
        return $primary_compare !== 0
            ? $primary_compare
            : ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
    });

    if ($venue_id > 0 && !venue_scope_can_access($database, $user, $venue_id)) {
        http_response_code(403);
        $database->close();
        exit('无权查看该场地业绩');
    }

    // 加盟商首次进入时优先展示 admin_user_venues 中标记的主场地。
    if ($venue_id <= 0) {
        foreach ($visible_venues as $venue) {
            if ((int)($venue['is_primary'] ?? 0) === 1) {
                $venue_id = (int)$venue['id'];
                break;
            }
        }
        if ($venue_id <= 0 && $visible_venues) {
            $venue_id = (int)$visible_venues[0]['id'];
        }
    }
}

if ($venue_id <= 0) {
    $database->close();
    exit('当前账号未绑定可查看的场地');
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
              AND TRIM(IFNULL(pays_type, '')) NOT IN ('能量', '金币')
              AND TRIM(IFNULL(note, '')) NOT IN ('gift', '礼物', '娃娃机抓取扣费')
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
              AND TRIM(IFNULL(pays_type, '')) NOT IN ('能量', '金币')
              AND TRIM(IFNULL(note, '')) NOT IN ('gift', '礼物', '娃娃机抓取扣费')
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
              AND TRIM(IFNULL(pays_type, '')) NOT IN ('能量', '金币')
              AND TRIM(IFNULL(note, '')) NOT IN ('gift', '礼物', '娃娃机抓取扣费')
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

    .venue-filter{
      min-width:260px;
      height:40px;
      padding:0 12px;
      border:1px solid var(--line);
      border-radius:var(--radius);
      color:var(--text);
      background:#fff;
      font-size:14px;
      font-weight:700;
      outline:none;
    }

    .venue-filter:focus{
      border-color:rgba(15,143,140,.55);
      box-shadow:0 0 0 3px rgba(15,143,140,.10);
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
      .venue-filter{ width:100%; min-width:0; }
      .toolbar button{ flex:1; }
      .card-container{ grid-template-columns: 1fr; padding:14px 16px 16px; }
    }
  </style>
</head>

<body>
  <div class="wrap">
    <h2><?= htmlspecialchars($venue_name) ?> 业绩趋势</h2>
   

    <div class="toolbar">
      <?php if (!$is_platform_admin): ?>
      <select id="venueFilter" class="venue-filter" aria-label="选择场地">
        <?php foreach ($visible_venues as $venue): ?>
          <?php
            $option_id = (int)($venue['id'] ?? 0);
            $is_primary = (int)($venue['is_primary'] ?? 0) === 1;
            $option_label = ($is_primary ? '主场地' : '子场地') . '｜' . (string)($venue['venue_name'] ?? '') . '（ID：' . $option_id . '）';
          ?>
          <option value="<?= $option_id ?>" <?= $option_id === $venue_id ? 'selected' : '' ?>><?= htmlspecialchars($option_label, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
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
        const venueFilter = document.getElementById('venueFilter');
        if (venueFilter) {
            venueFilter.addEventListener('change', function () {
                const venueId = String(this.value || '');
                if (!/^\d+$/.test(venueId)) return;
                window.location.href = `venue_detail.php?venue_id=${encodeURIComponent(venueId)}&period=<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>`;
            });
        }

        function changePeriod(period) {
            const allowed = ['day', 'week', 'month'];
            if (!allowed.includes(period)) return;
            window.location.href = `venue_detail.php?venue_id=<?= (int)$venue_id ?>&period=${encodeURIComponent(period)}`;
        }
    </script>
    </div>
</body>
</html>
