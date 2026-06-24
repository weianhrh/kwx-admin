<?php
require_once '../Database.php';
$database = new Database();

// -------------------- 参数 --------------------
$period = $_GET['period'] ?? 'day';    // day | week | month
$start_date = trim($_GET['start_date'] ?? '');
$end_date   = trim($_GET['end_date'] ?? '');

function isValidDateYmd($s) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }
$hasRange = isValidDateYmd($start_date) && isValidDateYmd($end_date);

// 活跃统计字段
$timeField  = 'last_active_at';
$pageTitle  = '活跃用户统计';
$trendName  = '今日活跃趋势';
$seriesName = '今日活跃';

// -------------------- 列表统计 SQL（day/week/month） --------------------
$where = " WHERE 1=1 AND {$timeField} IS NOT NULL ";

if ($hasRange) {
  $where .= " AND {$timeField} >= '{$start_date} 00:00:00'
              AND {$timeField} < DATE_ADD('{$end_date} 00:00:00', INTERVAL 1 DAY) ";
}

$limit = 30;
if ($period === 'week' || $period === 'month') $limit = 12;

if (!$hasRange && $period === 'day') {
  $where .= " AND {$timeField} >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
              AND {$timeField} < DATE_ADD(CURDATE(), INTERVAL 1 DAY) ";
}

switch ($period) {
  case 'week':
    $sql = "
      SELECT
        YEARWEEK({$timeField}, 1) AS label,
        MIN(DATE({$timeField})) AS start_date,
        MAX(DATE({$timeField})) AS end_date,
        COUNT(*) AS total
      FROM users
      {$where}
      GROUP BY label
      ORDER BY label DESC
      LIMIT {$limit}
    ";
    break;
  case 'month':
    $sql = "
      SELECT
        DATE_FORMAT({$timeField}, '%Y-%m') AS label,
        COUNT(*) AS total
      FROM users
      {$where}
      GROUP BY label
      ORDER BY label DESC
      LIMIT {$limit}
    ";
    break;
  case 'day':
  default:
    $sql = "
      SELECT
        DATE({$timeField}) AS label,
        COUNT(*) AS total
      FROM users
      {$where}
      GROUP BY label
      ORDER BY label DESC
      LIMIT {$limit}
    ";
    break;
}

$data = $database->query($sql);

// -------------------- 今日趋势（按半小时） --------------------
$startHour = 0;
$endHour = 24;

$slots = [];
$slotCounts = [];
$slotMap = [];

$idx = 0;
for ($h = $startHour; $h <= $endHour; $h++) {
  foreach ([0, 30] as $m) {
    $label = str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ":" . ($m === 0 ? "00" : "30");
    $slots[] = $label;
    $slotCounts[] = 0;
    $slotMap[$label] = $idx++;
  }
}

$trendSql = "
  SELECT
    DATE_FORMAT({$timeField}, '%H') AS hh,
    CASE WHEN MINUTE({$timeField}) < 30 THEN '00' ELSE '30' END AS mm,
    COUNT(*) AS c
  FROM users
  WHERE {$timeField} >= CURDATE()
    AND {$timeField} < CURDATE() + INTERVAL 1 DAY
    AND {$timeField} IS NOT NULL
    AND HOUR({$timeField}) BETWEEN {$startHour} AND {$endHour}
  GROUP BY hh, mm
  ORDER BY hh, mm
";
$trendRows = $database->query($trendSql);

foreach ($trendRows as $r) {
  $label = $r['hh'] . ":" . $r['mm'];
  if (isset($slotMap[$label])) $slotCounts[$slotMap[$label]] = (int)$r['c'];
}

$todayTotalSql = "
  SELECT COUNT(*) AS cnt
  FROM users
  WHERE {$timeField} >= CURDATE()
    AND {$timeField} < CURDATE() + INTERVAL 1 DAY
    AND {$timeField} IS NOT NULL
";
$todayTotal = (int)($database->query($todayTotalSql)[0]['cnt'] ?? 0);

$database->close();

$slotsJson  = json_encode($slots, JSON_UNESCAPED_UNICODE);
$countsJson = json_encode($slotCounts, JSON_UNESCAPED_UNICODE);

function activeClass($a, $b) { return $a === $b ? 'layui-btn-normal' : 'layui-btn-primary'; }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?></title>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/layui@2.9.21/dist/css/layui.css">
  <script src="https://cdn.jsdelivr.net/npm/layui@2.9.21/dist/layui.js"></script>

  <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>

  <style>
    :root{
      --primary:#5b8cff;
      --bg:#f6f8fc;
      --card:#fff;
      --text:#111827;
      --muted:#6b7280;
      --border:#e5e7eb;
      --shadow: 0 8px 28px rgba(17,24,39,.06);
      --shadow2: 0 10px 34px rgba(17,24,39,.10);
      --radius: 14px;
    }
    body{ background:var(--bg); }
    .page-wrap{ max-width: 1200px; margin: 18px auto 28px; padding: 0 14px; }

    .topbar{
      display:flex; align-items:center; justify-content:space-between;
      gap:12px; padding: 14px 16px; border-radius: var(--radius);
      background: linear-gradient(135deg, rgba(91,140,255,.16), rgba(255,255,255,1));
      border: 1px solid rgba(91,140,255,.18);
      box-shadow: var(--shadow);
    }
    .topbar .title{
      font-size: 18px; font-weight: 700; color: var(--text);
      display:flex; align-items:center; gap:10px;
    }
    .badge{
      font-size:12px; color:#fff; background: var(--primary);
      padding: 2px 8px; border-radius: 999px;
      box-shadow: 0 6px 18px rgba(91,140,255,.25);
    }

    .panel-grid{ margin-top: 14px; }
    .panel-card{
      border-radius: var(--radius);
      border: 1px solid var(--border);
      background: var(--card);
      box-shadow: var(--shadow);
      overflow: hidden;
    }
    .panel-hd{
      padding: 12px 14px;
      border-bottom: 1px solid var(--border);
      display:flex; align-items:center; justify-content:space-between; gap:10px;
    }
    .panel-hd .hd-left{
      display:flex; align-items:center; gap:10px;
      color: var(--text); font-weight: 700;
    }
    .hd-sub{ font-weight: 500; color: var(--muted); font-size: 12px; }

    .kpi{
      display:flex; gap:14px; flex-wrap:wrap;
      padding: 14px;
    }
    .kpi-item{
      flex: 1; min-width: 240px;
      border-radius: 14px;
      background: linear-gradient(135deg, rgba(91,140,255,.16), rgba(255,255,255,1));
      border: 1px solid rgba(91,140,255,.18);
      padding: 14px;
    }
    .kpi-title{ color: var(--muted); font-size: 13px; }
    .kpi-value{ font-size: 26px; font-weight: 800; color: var(--text); margin-top: 6px; }
    .kpi-foot{ margin-top: 6px; color: var(--muted); font-size: 12px; }

    .filters{
      padding: 14px;
      display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between;
    }
    .filters .left, .filters .right{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

    .cards{ padding: 14px; }
    .stat-card{
      border: 1px solid var(--border);
      border-radius: 14px;
      background: #fff;
      padding: 14px 14px;
      transition: .18s ease;
      height: 100%;
    }
    .stat-card:hover{
      transform: translateY(-2px);
      box-shadow: var(--shadow2);
      border-color: rgba(91,140,255,.28);
    }
    .stat-label{ color: var(--muted); font-size: 13px; }
    .stat-num{ margin-top: 8px; font-size: 22px; font-weight: 800; color: var(--text); }
    .empty{ padding: 34px 0 44px; text-align:center; color: var(--muted); }

    .layui-btn{ border-radius: 10px !important; }
    .layui-input{ border-radius: 10px !important; }
    .mini-note{ font-size: 12px; color: var(--muted); }
  </style>
</head>
<body>
<div class="page-wrap">

  <div class="topbar">
    <div class="title">
      <?= htmlspecialchars($pageTitle) ?>
      <span class="badge">Dashboard</span>
    </div>
    <div class="layui-btn-group">
      <button class="layui-btn layui-btn-primary" onclick="goRegister()">注册统计</button>
      <button class="layui-btn layui-btn-normal" onclick="goActive()">活跃统计</button>
    </div>
  </div>

  <div class="panel-grid">
    <div class="panel-card">
      <div class="panel-hd">
        <div class="hd-left">
          <span class="layui-badge-dot" style="background:var(--primary)"></span>
          <span><?= htmlspecialchars($trendName) ?></span>
          <span class="hd-sub">（按半小时聚合）</span>
        </div>
        <div class="mini-note">更新：<?= date('Y-m-d H:i') ?></div>
      </div>

      <div class="kpi">
        <div class="kpi-item">
          <div class="kpi-title">今日活跃用户</div>
          <div class="kpi-value"><?= $todayTotal ?> <span style="font-size:14px;font-weight:700;color:var(--muted)">人</span></div>
          <div class="kpi-foot">统计口径：users.last_active_at ∈ 今日</div>
        </div>
        <div class="kpi-item">
          <div class="kpi-title">筛选状态</div>
          <div class="kpi-value" style="font-size:18px;">
            <?= $hasRange ? (htmlspecialchars($start_date).' ~ '.htmlspecialchars($end_date)) : '（默认最近区间）' ?>
          </div>
          <div class="kpi-foot">按天默认近30天；按周/月默认近12条</div>
        </div>
      </div>

      <div style="padding:0 14px 14px;">
        <div id="trendChart" style="width:100%;height:340px;"></div>
      </div>
    </div>

    <div class="panel-card" style="margin-top:14px;">
      <div class="panel-hd">
        <div class="hd-left">
          <span class="layui-badge-dot" style="background:#16a34a"></span>
          <span>周期统计</span>
          <span class="hd-sub">（点击切换：天 / 周 / 月）</span>
        </div>
      </div>

      <div class="filters">
        <div class="left">
          <div class="layui-btn-group">
            <button class="layui-btn <?= activeClass($period,'day') ?>" onclick="changePeriod('day')">按天</button>
            <button class="layui-btn <?= activeClass($period,'week') ?>" onclick="changePeriod('week')">按周</button>
            <button class="layui-btn <?= activeClass($period,'month') ?>" onclick="changePeriod('month')">按月</button>
          </div>

          <div class="layui-form" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <input id="startDate" type="text" class="layui-input" placeholder="开始日期" style="width:140px;" value="<?= htmlspecialchars($start_date) ?>">
            <input id="endDate" type="text" class="layui-input" placeholder="结束日期" style="width:140px;" value="<?= htmlspecialchars($end_date) ?>">
            <button class="layui-btn layui-btn-normal" onclick="applyRange()">筛选</button>
            <button class="layui-btn layui-btn-primary" onclick="clearRange()">清空</button>
          </div>
        </div>

        <div class="right">
          <span class="mini-note"></span>
        </div>
      </div>

      <div class="cards">
        <?php if (empty($data)): ?>
          <div class="empty">暂无数据</div>
        <?php else: ?>
          <div class="layui-row layui-col-space12">
            <?php foreach ($data as $row):
              if ($period === 'week') {
                $label = date("m月d日", strtotime($row['start_date'])) . ' ~ ' . date("m月d日", strtotime($row['end_date']));
              } elseif ($period === 'month') {
                $label = date("Y年m月", strtotime($row['label'] . '-01'));
              } else {
                $label = date("m月d日", strtotime($row['label']));
              }
            ?>
              <div class="layui-col-xs12 layui-col-sm6 layui-col-md3">
                <div class="stat-card">
                  <div class="stat-label"><?= $label ?></div>
                  <div class="stat-num"><?= (int)$row['total'] ?> <span style="font-size:13px;color:var(--muted)">人</span></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>

</div>

<script>
  const slots = <?= $slotsJson ?>;
  const counts = <?= $countsJson ?>;
  const seriesName = <?= json_encode($seriesName, JSON_UNESCAPED_UNICODE) ?>;

  function buildUrl(base, overrides){
    const params = new URLSearchParams(window.location.search);
    for (const k in overrides){
      if (overrides[k] === null) params.delete(k);
      else params.set(k, overrides[k]);
    }
    if (!params.get('period')) params.set('period', 'day');
    return base + '?' + params.toString();
  }

  function goRegister(){ window.location.href = buildUrl('/api/index/user_register_detail.php', {}); }
  function goActive(){ window.location.href = buildUrl('/api/index/user_active_detail.php', {}); }

  function changePeriod(p){
    window.location.href = buildUrl('/api/index/user_active_detail.php', {period: p});
  }

  function applyRange(){
    const s = (document.getElementById('startDate').value || '').trim();
    const e = (document.getElementById('endDate').value || '').trim();
    window.location.href = buildUrl('/api/index/user_active_detail.php', {
      start_date: s ? s : null,
      end_date: e ? e : null
    });
  }

  function clearRange(){
    document.getElementById('startDate').value = '';
    document.getElementById('endDate').value = '';
    window.location.href = buildUrl('/api/index/user_active_detail.php', {start_date: null, end_date: null});
  }

  layui.use(['laydate'], function(){
    const laydate = layui.laydate;
    laydate.render({ elem: '#startDate', type:'date', format:'yyyy-MM-dd' });
    laydate.render({ elem: '#endDate', type:'date', format:'yyyy-MM-dd' });
  });

  const el = document.getElementById('trendChart');
  if (el && window.echarts) {
    const chart = echarts.init(el);
    chart.setOption({
      tooltip: { trigger: 'axis' },
      grid: { left: 52, right: 20, top: 18, bottom: 44 },
      xAxis: {
        type: 'category',
        boundaryGap: false,
        data: slots,
        axisLabel: { color: '#6b7280', interval: 3 }
      },
      yAxis: {
        type: 'value',
        axisLabel: { color: '#6b7280' },
        splitLine: { lineStyle: { color: 'rgba(17,24,39,.08)' } }
      },
      dataZoom: [
        { type:'inside', start: 0, end: 100 },
        { type:'slider', height: 18, bottom: 12, start: 0, end: 100 }
      ],
      series: [{
        name: seriesName,
        type: 'line',
        smooth: true,
        symbol: 'circle',
        symbolSize: 7,
        data: counts,
        lineStyle: { width: 3 },
        areaStyle: { opacity: 0.18 }
      }]
    });
    window.addEventListener('resize', () => chart.resize());
  }
</script>
</body>
</html>
