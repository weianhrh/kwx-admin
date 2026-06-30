<?php
require_once '../Database.php';
$database = new Database();

// -------------------- 参数 --------------------
$period = $_GET['period'] ?? 'day';    // day | week | month
if (!in_array($period, ['day', 'week', 'month'], true)) {
  $period = 'day';
}
$start_date = trim($_GET['start_date'] ?? '');
$end_date   = trim($_GET['end_date'] ?? '');

function isValidDateYmd($s) { return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function activeTab($current, $target) { return $current === $target ? ' is-active' : ''; }
function periodText($period) {
  if ($period === 'week') return '按周统计';
  if ($period === 'month') return '按月统计';
  return '按天统计';
}

$hasRange = isValidDateYmd($start_date) && isValidDateYmd($end_date);

// 统计字段
$timeField  = 'created_at';
$pageTitle  = '注册用户统计';
$trendName  = '今日注册趋势';
$seriesName = '今日注册';
$selfUrl    = 'user_register_detail.php';
$registerUrl = 'user_register_detail.php';
$activeUrl   = 'user_active_detail.php';

// -------------------- 列表统计 SQL（day/week/month） --------------------
$where = " WHERE 1=1 ";

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
if (!is_array($data)) $data = [];

// -------------------- 今日趋势（按半小时） --------------------
$startHour = 0;
$endHour = 24;

$slots = [];
$slotCounts = [];
$slotMap = [];

$idx = 0;
for ($h = $startHour; $h < $endHour; $h++) {
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
    AND HOUR({$timeField}) BETWEEN {$startHour} AND 23
  GROUP BY hh, mm
  ORDER BY hh, mm
";
$trendRows = $database->query($trendSql);
if (!is_array($trendRows)) $trendRows = [];

foreach ($trendRows as $r) {
  $label = $r['hh'] . ":" . $r['mm'];
  if (isset($slotMap[$label])) $slotCounts[$slotMap[$label]] = (int)$r['c'];
}

$todayTotalSql = "
  SELECT COUNT(*) AS cnt
  FROM users
  WHERE {$timeField} >= CURDATE()
    AND {$timeField} < CURDATE() + INTERVAL 1 DAY
";
$todayTotal = (int)($database->query($todayTotalSql)[0]['cnt'] ?? 0);

$database->close();

$periodTotal = 0;
foreach ($data as $row) {
  $periodTotal += (int)($row['total'] ?? 0);
}

$maxSlotCount = 0;
$maxSlotLabel = '-';
foreach ($slotCounts as $i => $count) {
  if ((int)$count > $maxSlotCount) {
    $maxSlotCount = (int)$count;
    $maxSlotLabel = $slots[$i] ?? '-';
  }
}

$slotsJson  = json_encode($slots, JSON_UNESCAPED_UNICODE);
$countsJson = json_encode($slotCounts, JSON_UNESCAPED_UNICODE);
$rangeText = $hasRange ? ($start_date . ' ~ ' . $end_date) : ($period === 'day' ? '最近30天' : '最近12条周期');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= h($pageTitle) ?></title>
  <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
  <style>
    :root {
      --page-bg: #f4f7f8;
      --card-bg: #ffffff;
      --card-soft: #f8fbfc;
      --border: #dfe8eb;
      --border-light: #edf3f5;
      --text: #172126;
      --muted: #6b7d84;
      --muted-2: #8aa0a8;
      --primary: #0f8f8c;
      --primary-dark: #08706e;
      --primary-soft: #e6f6f5;
      --success: #20a876;
      --warning: #d58a16;
      --warning-soft: #fff6e4;
      --danger: #df5b57;
      --shadow: 0 14px 38px rgba(30, 55, 66, .08);
      --shadow-sm: 0 8px 22px rgba(30, 55, 66, .06);
      --radius: 10px;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "HarmonyOS Sans SC", "Microsoft YaHei", Arial, sans-serif;
      -webkit-tap-highlight-color: transparent;
    }

    html,
    body {
      width: 100%;
      min-height: 100%;
    }

    body {
      min-width: 320px;
      min-height: 100vh;
      padding: 22px 24px 32px;
      color: var(--text);
      background:
        linear-gradient(90deg, rgba(15, 143, 140, .045), rgba(47, 125, 246, .028) 58%, rgba(213, 138, 22, .018)),
        var(--page-bg);
      overflow-x: hidden;
    }

    button,
    input {
      font: inherit;
    }

    button {
      user-select: none;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    .page {
      width: 100%;
      max-width: 1280px;
      margin: 0 auto;
    }

    .page-title-row {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 14px;
      margin-bottom: 16px;
    }

    .page-title {
      display: flex;
      align-items: center;
      gap: 10px;
      min-height: 44px;
      color: var(--text);
      font-size: 22px;
      line-height: 1.2;
      font-weight: 900;
    }

    .page-title::before {
      content: "";
      width: 8px;
      height: 22px;
      border-radius: 999px;
      background: var(--primary);
      box-shadow: 0 6px 14px rgba(15, 143, 140, .20);
      flex: 0 0 auto;
    }

    .page-desc {
      margin-top: 4px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.6;
    }

    .top-actions {
      display: flex;
      flex-wrap: wrap;
      justify-content: flex-end;
      gap: 8px;
      padding-top: 3px;
    }

    .btn {
      min-height: 38px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      border: 1px solid transparent;
      border-radius: 8px;
      padding: 0 14px;
      color: #fff;
      background: var(--primary);
      font-size: 14px;
      font-weight: 800;
      line-height: 1;
      cursor: pointer;
      transition: background .16s ease, border-color .16s ease, color .16s ease, box-shadow .16s ease, transform .16s ease;
      white-space: nowrap;
    }

    .btn:hover {
      background: var(--primary-dark);
      box-shadow: 0 10px 22px rgba(15, 143, 140, .18);
    }

    .btn:active {
      transform: translateY(1px);
    }

    .btn-outline {
      color: var(--primary-dark);
      background: #fff;
      border-color: rgba(15, 143, 140, .35);
      box-shadow: none;
    }

    .btn-outline:hover {
      color: var(--primary-dark);
      background: var(--primary-soft);
      border-color: var(--primary);
      box-shadow: none;
    }

    .btn-muted {
      color: #486169;
      background: #fff;
      border-color: var(--border);
      box-shadow: none;
    }

    .btn-muted:hover {
      color: var(--primary-dark);
      background: #fbfefe;
      border-color: rgba(15, 143, 140, .30);
      box-shadow: none;
    }

    .tabs {
      display: inline-flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .tab-btn {
      min-height: 34px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0 12px;
      border: 1px solid var(--border);
      border-radius: 999px;
      color: #486169;
      background: #fff;
      font-size: 13px;
      font-weight: 800;
      cursor: pointer;
      transition: background .16s ease, border-color .16s ease, color .16s ease;
    }

    .tab-btn:hover,
    .tab-btn.is-active {
      color: var(--primary-dark);
      border-color: rgba(15, 143, 140, .38);
      background: var(--primary-soft);
    }

    .section-card {
      margin-bottom: 14px;
      overflow: hidden;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      background: rgba(255, 255, 255, .98);
      box-shadow: var(--shadow);
    }

    .section-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 14px;
      padding: 18px 20px 14px;
      border-bottom: 1px solid var(--border-light);
    }

    .section-title {
      display: flex;
      align-items: center;
      gap: 9px;
      color: var(--text);
      font-size: 17px;
      line-height: 1.25;
      font-weight: 900;
    }

    .section-title::before {
      content: "";
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--primary);
      box-shadow: 0 0 0 5px var(--primary-soft);
      flex: 0 0 auto;
    }

    .section-desc {
      margin-top: 8px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.6;
    }

    .muted {
      color: var(--muted);
    }

    .mini-note {
      color: var(--muted);
      font-size: 12px;
      line-height: 1.6;
      white-space: nowrap;
    }

    .summary-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      padding: 0 20px 20px;
    }

    .summary-item {
      min-height: 86px;
      display: grid;
      align-content: center;
      gap: 7px;
      padding: 14px;
      border: 1px solid var(--border);
      border-radius: 10px;
      background: #fff;
      box-shadow: var(--shadow-sm);
      transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
    }

    .summary-item:hover {
      transform: translateY(-1px);
      border-color: rgba(15, 143, 140, .28);
      box-shadow: 0 12px 28px rgba(30, 55, 66, .10);
    }

    .summary-label {
      color: var(--muted);
      font-size: 13px;
      line-height: 1.3;
    }

    .summary-value {
      color: var(--text);
      font-size: 25px;
      line-height: 1.1;
      font-weight: 900;
      word-break: break-all;
    }

    .summary-unit {
      margin-left: 4px;
      color: var(--muted);
      font-size: 13px;
      font-weight: 800;
    }

    .chart-wrap {
      padding: 0 20px 20px;
    }

    #trendChart {
      width: 100%;
      height: 330px;
      border: 1px solid var(--border-light);
      border-radius: 10px;
      background: #fff;
    }

    .toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 14px 20px;
      border-bottom: 1px solid var(--border-light);
      background: #fbfefe;
    }

    .toolbar-left,
    .toolbar-right {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
    }

    .field {
      min-height: 38px;
      width: 148px;
      padding: 0 11px;
      border: 1px solid var(--border);
      border-radius: 8px;
      color: var(--text);
      background: #fff;
      font-size: 14px;
      outline: none;
      transition: border-color .16s ease, box-shadow .16s ease;
    }

    .field:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(15, 143, 140, .12);
    }

    .data-table-wrap {
      width: 100%;
      overflow-x: auto;
    }

    .data-table {
      width: 100%;
      min-width: 680px;
      border-collapse: collapse;
      font-size: 14px;
    }

    .data-table th,
    .data-table td {
      padding: 13px 18px;
      border-bottom: 1px solid var(--border-light);
      color: #34464e;
      text-align: left;
      white-space: nowrap;
    }

    .data-table th {
      color: var(--muted);
      background: #fbfefe;
      font-size: 12px;
      font-weight: 900;
    }

    .data-table tbody tr:hover td {
      background: #f8fbfc;
    }

    .num-text {
      color: var(--text);
      font-weight: 900;
    }

    .tag {
      min-height: 28px;
      display: inline-flex;
      align-items: center;
      padding: 0 10px;
      border-radius: 999px;
      color: var(--primary-dark);
      background: var(--primary-soft);
      font-size: 12px;
      font-weight: 900;
    }

    .empty {
      padding: 36px 20px 42px;
      color: var(--muted);
      text-align: center;
      font-size: 14px;
    }

    .mobile-cards {
      display: none;
      padding: 14px;
      gap: 10px;
    }

    .mini-card {
      display: grid;
      gap: 8px;
      padding: 13px;
      border: 1px solid var(--border);
      border-radius: 10px;
      background: #fff;
      box-shadow: 0 6px 16px rgba(30, 55, 66, .05);
    }

    .mini-card-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      color: var(--muted);
      font-size: 13px;
    }

    .mini-card-num {
      color: var(--text);
      font-size: 22px;
      line-height: 1.1;
      font-weight: 900;
    }

    @media (max-width: 1080px) {
      .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 760px) {
      body {
        padding: 14px;
      }

      .page-title-row,
      .section-head,
      .toolbar {
        flex-direction: column;
        align-items: stretch;
      }

      .top-actions {
        justify-content: flex-start;
      }

      .page-title {
        font-size: 20px;
      }

      .summary-grid {
        grid-template-columns: 1fr;
        padding: 0 14px 14px;
      }

      .section-head,
      .toolbar {
        padding: 15px 14px;
      }

      .toolbar-left,
      .toolbar-right,
      .tabs {
        width: 100%;
      }

      .tab-btn,
      .btn,
      .field {
        flex: 1 1 auto;
      }

      #trendChart {
        height: 290px;
      }

      .chart-wrap {
        padding: 0 14px 14px;
      }

      .data-table-wrap {
        display: none;
      }

      .mobile-cards {
        display: grid;
      }
    }
  </style>
</head>
<body>
  <main class="page">
    <div class="page-title-row">
      <div>
        <h1 class="page-title"><?= h($pageTitle) ?></h1>
        <p class="page-desc">沿用旧项目统计口径，只调整成新后台统一视觉；当前字段：<?= h('用户创建时间') ?>。</p>
      </div>
      <div class="top-actions">
        <button class="btn is-active" type="button" onclick="goRegister()">注册统计</button>
        <button class="btn btn-outline" type="button" onclick="goActive()">活跃统计</button>
        <button class="btn btn-muted" type="button" onclick="backDashboard()">返回工作台</button>
      </div>
    </div>

    <section class="section-card">
      <div class="section-head">
        <div>
          <div class="section-title"><?= h($trendName) ?></div>
          <p class="section-desc">按半小时聚合，展示今日 00:00 到当前日期结束的趋势变化。</p>
        </div>
        <div class="mini-note">更新：<?= date('Y-m-d H:i') ?></div>
      </div>

      <div class="summary-grid">
        <div class="summary-item">
          <div class="summary-label">今日注册用户</div>
          <div class="summary-value"><?= (int)$todayTotal ?><span class="summary-unit">人</span></div>
        </div>
        <div class="summary-item">
          <div class="summary-label">峰值半小时</div>
          <div class="summary-value"><?= h($maxSlotLabel) ?><span class="summary-unit"><?= (int)$maxSlotCount ?> 人</span></div>
        </div>
        <div class="summary-item">
          <div class="summary-label">当前筛选</div>
          <div class="summary-value" style="font-size:18px;"><?= h($rangeText) ?></div>
        </div>
        <div class="summary-item">
          <div class="summary-label"><?= h(periodText($period)) ?>合计</div>
          <div class="summary-value"><?= (int)$periodTotal ?><span class="summary-unit">人</span></div>
        </div>
      </div>

      <div class="chart-wrap">
        <div id="trendChart"></div>
      </div>
    </section>

    <section class="section-card">
      <div class="section-head">
        <div>
          <div class="section-title">周期统计</div>
          <p class="section-desc">支持按天、按周、按月切换，也可以按日期范围筛选。</p>
        </div>
        <div class="mini-note"><?= h(periodText($period)) ?></div>
      </div>

      <div class="toolbar">
        <div class="toolbar-left">
          <div class="tabs" aria-label="周期切换">
            <button class="tab-btn<?= activeTab($period, 'day') ?>" type="button" onclick="changePeriod('day')">按天</button>
            <button class="tab-btn<?= activeTab($period, 'week') ?>" type="button" onclick="changePeriod('week')">按周</button>
            <button class="tab-btn<?= activeTab($period, 'month') ?>" type="button" onclick="changePeriod('month')">按月</button>
          </div>
        </div>
        <div class="toolbar-right">
          <input id="startDate" class="field" type="date" value="<?= h($start_date) ?>" aria-label="开始日期">
          <input id="endDate" class="field" type="date" value="<?= h($end_date) ?>" aria-label="结束日期">
          <button class="btn" type="button" onclick="applyRange()">筛选</button>
          <button class="btn btn-muted" type="button" onclick="clearRange()">清空</button>
        </div>
      </div>

      <?php if (empty($data)): ?>
        <div class="empty">暂无数据</div>
      <?php else: ?>
        <div class="data-table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>周期</th>
                <th>统计方式</th>
                <th>注册人数</th>
                <th>占本页合计</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data as $row):
                if ($period === 'week') {
                  $label = date("Y-m-d", strtotime($row['start_date'])) . ' ~ ' . date("Y-m-d", strtotime($row['end_date']));
                } elseif ($period === 'month') {
                  $label = date("Y年m月", strtotime($row['label'] . '-01'));
                } else {
                  $label = date("Y-m-d", strtotime($row['label']));
                }
                $rowTotal = (int)($row['total'] ?? 0);
                $percent = $periodTotal > 0 ? round($rowTotal * 100 / $periodTotal, 1) : 0;
              ?>
                <tr>
                  <td><span class="tag"><?= h($label) ?></span></td>
                  <td><?= h(periodText($period)) ?></td>
                  <td><span class="num-text"><?= $rowTotal ?></span> 人</td>
                  <td><?= h($percent) ?>%</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="mobile-cards">
          <?php foreach ($data as $row):
            if ($period === 'week') {
              $label = date("Y-m-d", strtotime($row['start_date'])) . ' ~ ' . date("Y-m-d", strtotime($row['end_date']));
            } elseif ($period === 'month') {
              $label = date("Y年m月", strtotime($row['label'] . '-01'));
            } else {
              $label = date("Y-m-d", strtotime($row['label']));
            }
            $rowTotal = (int)($row['total'] ?? 0);
            $percent = $periodTotal > 0 ? round($rowTotal * 100 / $periodTotal, 1) : 0;
          ?>
            <article class="mini-card">
              <div class="mini-card-top"><span><?= h($label) ?></span><span class="tag"><?= h(periodText($period)) ?></span></div>
              <div class="mini-card-num"><?= $rowTotal ?> 人</div>
              <div class="muted">占本页合计：<?= h($percent) ?>%</div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>

  <script>
    const slots = <?= $slotsJson ?>;
    const counts = <?= $countsJson ?>;
    const seriesName = <?= json_encode($seriesName, JSON_UNESCAPED_UNICODE) ?>;
    const selfUrl = <?= json_encode($selfUrl, JSON_UNESCAPED_UNICODE) ?>;
    const registerUrl = <?= json_encode($registerUrl, JSON_UNESCAPED_UNICODE) ?>;
    const activeUrl = <?= json_encode($activeUrl, JSON_UNESCAPED_UNICODE) ?>;

    function buildUrl(base, overrides) {
      const params = new URLSearchParams(window.location.search);
      Object.keys(overrides || {}).forEach(function(key) {
        if (overrides[key] === null || overrides[key] === undefined || overrides[key] === '') {
          params.delete(key);
        } else {
          params.set(key, overrides[key]);
        }
      });
      if (!params.get('period')) params.set('period', 'day');
      const qs = params.toString();
      return base + (qs ? '?' + qs : '');
    }

    function goRegister() { window.location.href = buildUrl(registerUrl, {}); }
    function goActive() { window.location.href = buildUrl(activeUrl, {}); }

    function changePeriod(period) {
      window.location.href = buildUrl(selfUrl, { period: period });
    }

    function applyRange() {
      const start = (document.getElementById('startDate').value || '').trim();
      const end = (document.getElementById('endDate').value || '').trim();
      if ((start && !end) || (!start && end)) {
        alert('开始日期和结束日期需要一起填写');
        return;
      }
      window.location.href = buildUrl(selfUrl, { start_date: start || null, end_date: end || null });
    }

    function clearRange() {
      document.getElementById('startDate').value = '';
      document.getElementById('endDate').value = '';
      window.location.href = buildUrl(selfUrl, { start_date: null, end_date: null });
    }

    function backDashboard() {
      if (window.parent && window.parent !== window) {
        window.parent.location.hash = '';
      } else {
        window.location.href = '../../dashboard.html';
      }
    }

    const chartEl = document.getElementById('trendChart');
    if (chartEl && window.echarts) {
      const chart = echarts.init(chartEl);
      chart.setOption({
        color: ['#0f8f8c'],
        tooltip: {
          trigger: 'axis',
          backgroundColor: 'rgba(23, 33, 38, .92)',
          borderWidth: 0,
          textStyle: { color: '#fff' }
        },
        grid: { left: 48, right: 24, top: 26, bottom: 54 },
        xAxis: {
          type: 'category',
          boundaryGap: false,
          data: slots,
          axisTick: { show: false },
          axisLine: { lineStyle: { color: '#dfe8eb' } },
          axisLabel: { color: '#6b7d84', interval: 3 }
        },
        yAxis: {
          type: 'value',
          minInterval: 1,
          axisLabel: { color: '#6b7d84' },
          splitLine: { lineStyle: { color: 'rgba(223, 232, 235, .95)' } }
        },
        dataZoom: [
          { type: 'inside', start: 0, end: 100 },
          { type: 'slider', height: 18, bottom: 18, start: 0, end: 100, borderColor: '#dfe8eb' }
        ],
        series: [{
          name: seriesName,
          type: 'line',
          smooth: true,
          showSymbol: false,
          symbolSize: 7,
          data: counts,
          lineStyle: { width: 3 },
          areaStyle: {
            opacity: .16,
            color: {
              type: 'linear',
              x: 0, y: 0, x2: 0, y2: 1,
              colorStops: [
                { offset: 0, color: 'rgba(15, 143, 140, .26)' },
                { offset: 1, color: 'rgba(15, 143, 140, 0)' }
              ]
            }
          }
        }]
      });
      window.addEventListener('resize', function() { chart.resize(); });
    } else if (chartEl) {
      chartEl.innerHTML = '<div class="empty">趋势图组件加载失败，周期统计仍可正常查看</div>';
    }
  </script>
</body>
</html>
