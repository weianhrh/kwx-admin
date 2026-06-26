<?php
require_once '../Database.php';

$db = new Database();

/* ========== 登录鉴权，获取当前用户 ========== */
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) { http_response_code(401); echo '未登录'; exit; }

$user = $db->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) { http_response_code(403); echo '无权访问'; exit; }

$my_uid   = (string)$user['uid'];
$role_id  = (int)$user['role_id'];
$isAdmin  = in_array($role_id, [1,2], true); // 按需调整管理员角色ID

/* ========== 参数处理 & 默认最近7天 ========== */
function norm_dt($v, $fallback) {
    if (!$v || !trim($v)) return $fallback;
    $v = str_replace('T', ' ', trim($v));            // 兼容 datetime-local
    if (!preg_match('/:\d{2}:\d{2}$/', $v)) $v .= ':00'; // 补秒
    return $v;
}
$default_start = date('Y-m-d 00:00:00');
$default_end   = date('Y-m-d 23:59:59');

// --- 新：处理 uid 筛选，管理员支持 “ALL=全部”，默认 ALL ---
$rawUid = $_GET['uid'] ?? null;
if ($isAdmin) {
    if ($rawUid === null || $rawUid === '') {
        $selected_uid = 'ALL'; // 默认全部
    } else {
        $selected_uid = trim($rawUid);
    }
} else {
    // 非管理员强制只能看自己
    $selected_uid = $my_uid;
}

$start        = norm_dt($_GET['start'] ?? '', $default_start);
$end          = norm_dt($_GET['end']   ?? '', $default_end);

// --- 新：阈值改为默认 3，后面用 total_rows ≥ threshold 判断在岗 ---
$threshold    = isset($_GET['threshold']) ? max(1, (int)$_GET['threshold']) : 3;

$page      = max(1, (int)($_GET['page'] ?? 1));
$page_size = max(1, min(100, (int)($_GET['page_size'] ?? 24)));
$exclude_current_hour_from = date('Y-m-d H:00:00'); // 排除本小时，等下个整点结算

// 用于 <input type="datetime-local"> 的值
$start_input = date('Y-m-d\TH:i', strtotime($start));
$end_input   = date('Y-m-d\TH:i', strtotime($end));

/* ========== UID 下拉数据源 ========== */
if ($isAdmin) {
    $uidOptions = $db->query("SELECT DISTINCT uid FROM checkin_log ORDER BY uid ASC") ?: [];
} else {
    $uidOptions = [['uid' => $my_uid]];
}

/* ========== SQL 片段：根据是否 ALL 决定是否按 uid 过滤、分组 ========== */
$groupExpr = "DATE_FORMAT(checkin_time, '%Y-%m-%d %H:00:00')";

if ($isAdmin && $selected_uid === 'ALL') {
    // 管理员：全部用户模式
    $where_uid  = '';                // 不加 uid 条件
    $paramsBase = [$start, $end, $exclude_current_hour_from];
    $select_uid = "'' AS uid";       // 为了 SELECT 列结构统一，这里给个空 uid
    $group_by   = $groupExpr;        // 只按小时分组
    $showUidCol = false;             // 表格不显示 UID 列
    $filter_desc = '全部用户';
} else {
    // 指定某个 uid（管理员或普通用户）
    $where_uid  = "uid = ? AND ";
    $paramsBase = [$selected_uid, $start, $end, $exclude_current_hour_from];
    $select_uid = "uid";
    $group_by   = "uid, $groupExpr"; // 按 uid+小时分组
    $showUidCol = true;              // 表格显示 UID 列
    $filter_desc = "UID：" . htmlspecialchars($selected_uid);
}

/* ========== 统计分组后的总行数（用于分页） ========== */
$count_sql = "
    SELECT COUNT(*) AS cnt FROM (
        SELECT $groupExpr AS period_start
        FROM checkin_log
        WHERE {$where_uid}checkin_time >= ?
          AND checkin_time <= ?
          AND checkin_time < ?
        GROUP BY $group_by
    ) t
";
$count_row  = $db->query($count_sql, $paramsBase);
$total_rows = (int)($count_row[0]['cnt'] ?? 0);

$total_pages = max(1, (int)ceil($total_rows / $page_size));
$page   = min($page, $total_pages);
$offset = ($page - 1) * $page_size;

/* ========== 分页数据查询（每行=一个小时段） ========== */
$data_sql = "
    SELECT 
        $select_uid,
        $groupExpr AS period_start,
        COUNT(DISTINCT DATE_FORMAT(checkin_time, '%i')) AS distinct_minutes,
LEAST(COUNT(*), 4) AS total_rows,
        MIN(checkin_time) AS first_checkin,
        MAX(checkin_time) AS last_checkin
    FROM checkin_log
    WHERE {$where_uid}checkin_time >= ?
      AND checkin_time <= ?
      AND checkin_time < ?
    GROUP BY $group_by
    ORDER BY period_start DESC
    LIMIT $page_size OFFSET $offset
";
$rows = $db->query($data_sql, $paramsBase) ?: [];

/* 标记状态 + 本页汇总；现在按 total_rows ≥ threshold 判断在岗 */
$summary = ['on'=>0, 'not'=>0, 'all'=>0];
foreach ($rows as &$r) {
    $ok = ((int)$r['total_rows'] >= $threshold);  // ★ 关键改动：按总记录数判断
    $r['ok'] = $ok ? 1 : 0;
    $summary['all']++;
    $ok ? $summary['on']++ : $summary['not']++;
}
unset($r);

/* 生成分页链接 query string */
function qs(array $params) {
    $base = $_GET;
    foreach ($params as $k=>$v) $base[$k] = $v;
    return htmlspecialchars('?' . http_build_query($base));
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>在岗统计（分页 + UID 自动筛选 + 最近7天）</title>

<style>
:root{
  --brand:#0f918d;
  --brand-dark:#08706d;
  --brand-soft:#e7f7f6;
  --ink:#15242c;
  --muted:#687c86;
  --line:#dbe7eb;
  --panel:#ffffff;
  --panel-soft:#f8fbfb;
  --page:#f3f7f8;
  --success:#20a876;
  --warning:#d98b16;
  --shadow:0 12px 34px rgba(30,55,66,.08);
  --secondary:#687c86;
}

*{
  box-sizing:border-box;
  font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","HarmonyOS Sans SC","Microsoft YaHei",Arial,sans-serif;
}

html,
body{
  width:100%;
  min-height:100%;
  margin:0;
}

body{
  color:var(--ink);
  background:var(--page);
  overflow-x:hidden;
}

.container{
  width:100%;
  max-width:none;
  min-height:100vh;
  margin:0;
  padding:0;
}

.card{
  width:100%;
  min-height:calc(100vh - 2px);
  margin:0;
  padding:0;
  overflow:hidden;
  border:1px solid var(--line);
  border-radius:8px;
  background:var(--panel);
  box-shadow:var(--shadow);
}

.card-title{
  position:relative;
  display:flex;
  align-items:center;
  min-height:72px;
  margin:0;
  padding:20px 24px 18px 42px;
  border-bottom:1px solid var(--line);
  color:var(--ink);
  font-size:24px;
  line-height:1.25;
  font-weight:800;
}

.card-title::before{
  position:absolute;
  left:24px;
  top:24px;
  width:6px;
  height:24px;
  border-radius:999px;
  background:var(--brand);
  content:"";
}

.meta{
  margin:0;
  padding:14px 24px;
  border-bottom:1px solid var(--line);
  color:var(--muted);
  background:linear-gradient(180deg,#fff 0%,#f8fbfb 100%);
  font-size:13px;
  line-height:1.7;
}

.meta b{
  color:var(--ink);
  font-weight:800;
}

form.filter{
  display:flex;
  flex-wrap:wrap;
  gap:12px;
  align-items:center;
  margin:0;
  padding:16px 24px;
  border-bottom:1px solid var(--line);
  background:#fff;
}

form.filter > *{
  flex:0 0 auto;
}

form.filter input,
form.filter select,
form.filter button{
  min-height:40px;
  border:1px solid var(--line);
  border-radius:8px;
  padding:0 12px;
  color:var(--ink);
  background:#fff;
  outline:0;
  font-size:14px;
  transition:border-color .18s ease,box-shadow .18s ease,background .18s ease;
}

form.filter input:focus,
form.filter select:focus{
  border-color:rgba(15,145,141,.5);
  box-shadow:0 0 0 4px rgba(15,145,141,.10);
}

form.filter button{
  min-width:86px;
  border-color:rgba(15,145,141,.22);
  color:var(--brand-dark);
  background:#eefaf8;
  cursor:pointer;
  font-weight:700;
}

form.filter button[type="submit"],
#submitBtn{
  min-width:108px;
  border-color:var(--brand);
  color:#fff;
  background:var(--brand);
}

form.filter button:hover{
  filter:brightness(.98);
}

form.filter button.quick{
  color:#35545c;
  border-color:#d6e4e8;
  background:#f7fbfb;
}

#uidSelect{
  width:150px;
}

#startInput,
#endInput{
  width:230px;
}

input[name="page_size"]{
  width:112px !important;
}

.kpis{
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:14px;
  margin:0;
  padding:18px 24px 0;
}

.kpi{
  min-height:70px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  border:1px solid var(--line);
  border-radius:10px;
  padding:14px 16px;
  color:var(--muted);
  background:var(--panel-soft);
  font-size:14px;
}

.kpi b{
  color:var(--brand-dark);
  font-size:24px;
  line-height:1;
  font-weight:900;
}

.table-wrap{
  width:calc(100% - 48px);
  margin:18px 24px 0;
  overflow:auto;
  border:1px solid var(--line);
  border-radius:10px;
  background:#fff;
}

.table{
  width:100%;
  border-collapse:collapse;
  table-layout:auto;
}

.table th,
.table td{
  padding:13px 14px;
  border-bottom:1px solid var(--line);
  color:var(--ink);
  font-size:14px;
  text-align:left;
  white-space:nowrap;
}

.table th{
  position:sticky;
  top:0;
  z-index:1;
  color:#49606a;
  background:#f6fbfb;
  font-weight:800;
}

.table tbody tr:last-child td{
  border-bottom:0;
}

.table tr:hover td{
  background:#fbfdfd;
}

.tag{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:62px;
  min-height:26px;
  border-radius:999px;
  padding:3px 10px;
  font-size:12px;
  font-weight:800;
}

.tag.ok{
  color:#0b7e5e;
  background:#e9f8f2;
}

.tag.warn{
  color:#a8660f;
  background:#fff5e6;
}

.pagination{
  display:flex;
  justify-content:flex-end;
  gap:8px;
  align-items:center;
  flex-wrap:wrap;
  margin:0;
  padding:18px 24px 24px;
}

.pagination a,
.pagination span{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:36px;
  min-width:42px;
  border:1px solid var(--line);
  border-radius:8px;
  padding:0 12px;
  text-decoration:none;
  color:#49606a;
  background:#fff;
  font-size:13px;
  font-weight:700;
}

.pagination a:hover{
  color:var(--brand-dark);
  background:var(--brand-soft);
}

.pagination .active{
  border-color:var(--brand);
  color:#fff;
  background:var(--brand);
}

@media (max-width: 1200px){
  form.filter{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
  }
  form.filter > *,
  #uidSelect,
  #startInput,
  #endInput,
  input[name="page_size"]{
    width:100% !important;
  }
  span[name="zhi_text"]{
    display:none;
  }
  #submitBtn{
    grid-column:auto / span 2;
  }
}

@media (max-width: 768px){
  .card{
    border-radius:0;
  }
  .card-title{
    min-height:60px;
    padding:18px 18px 16px 34px;
    font-size:20px;
  }
  .card-title::before{
    left:18px;
    top:21px;
    height:21px;
  }
  .meta,
  form.filter,
  .kpis,
  .pagination{
    padding-left:16px;
    padding-right:16px;
  }
  form.filter{
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:10px;
  }
  .kpis{
    grid-template-columns:1fr;
  }
  .table-wrap{
    width:calc(100% - 32px);
    margin-left:16px;
    margin-right:16px;
  }
  .table{
    min-width:720px;
  }
  .table th,
  .table td{
    padding:10px 12px;
    font-size:13px;
  }
}

@media (max-width: 480px){
  form.filter{
    grid-template-columns:1fr;
  }
  #submitBtn{
    grid-column:auto;
  }
  .pagination{
    justify-content:flex-start;
  }
}
</style>
</head>
<body class="kwx-checkin-report">
<div class="container">

  <div class="card">
    <div class="card-title">在岗统计（每小时分组）</div>
    <div class="meta">
      当前登录 UID：<b><?php echo htmlspecialchars($my_uid); ?></b> ｜ 
      当前筛选：<b><?php echo $filter_desc; ?></b> ｜ 
      查询区间：<?php echo htmlspecialchars($start); ?> ～ <?php echo htmlspecialchars($end); ?> ｜ 
      阈值：<b>总记录次数 ≥ <?php echo (int)$threshold; ?></b> 记为在岗 ｜ 当前时段（<?php echo date('Y-m-d H:00:00'); ?>）未完成 已排除
    </div>

    <form class="filter" method="get" id="filterForm">
      <!-- UID 下拉：管理员可切换，非管理员禁用并提交隐藏域 -->
      <select name="uid" id="uidSelect" <?php echo $isAdmin ? '' : 'disabled'; ?>>
        <?php if ($isAdmin): ?>
          <option value="ALL" <?php echo ($selected_uid === 'ALL') ? 'selected' : ''; ?>>全部</option>
        <?php endif; ?>
        <?php foreach ($uidOptions as $opt): $v = (string)$opt['uid']; ?>
          <option value="<?php echo htmlspecialchars($v); ?>" <?php echo $v===$selected_uid ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($v); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if(!$isAdmin): ?>
        <input type="hidden" name="uid" value="<?php echo htmlspecialchars($selected_uid); ?>">
      <?php endif; ?>

      <!-- 时间范围（datetime-local） -->
      <input type="datetime-local" name="start" id="startInput" value="<?php echo $start_input; ?>" />
      <span name="zhi_text" style="align-self:center;color:var(--secondary)">至</span>
      <input type="datetime-local" name="end" id="endInput" value="<?php echo $end_input; ?>" />

      <!-- 快捷按钮 -->
      <button type="button" class="quick" id="btnToday">今天</button>
      <button type="button" class="quick" id="btn7">最近7天</button>
      <button type="button" class="quick" id="btnMonth">本月</button>

      <!-- 阈值：现在控制 total_rows ≥ threshold，在小屏被隐藏 -->
      <input type="number" name="threshold" min="1" value="<?php echo (int)$threshold; ?>" style="display:none" title="在岗阈值">
      <input type="number" name="page_size" min="1" max="100" value="<?php echo (int)$page_size; ?>" style="width:110px" title="每页条数">

      <button type="submit" id="submitBtn">筛选</button>
    </form>

    <div class="kpis">
      <div class="kpi">本页在岗小时：<b><?php echo $summary['on']; ?></b></div>
      <div class="kpi">本页不足阈值：<b><?php echo $summary['not']; ?></b></div>
      <div class="kpi">本页合计小时：<b><?php echo $summary['all']; ?></b></div>
    </div>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <?php if ($showUidCol): ?>
              <th>UID</th>
            <?php endif; ?>
            <th>年月日时（起点）</th>
            <th>不同分钟数</th>
            <th>总记录数</th>
            <th>首条签到时间</th>
            <th>末条签到时间</th>
            <th>状态</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="<?php echo $showUidCol ? 7 : 6; ?>" style="color:#999">无记录</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
          <tr>
            <?php if ($showUidCol): ?>
              <td><?php echo htmlspecialchars($r['uid']); ?></td>
            <?php endif; ?>
            <td><?php echo htmlspecialchars($r['period_start']); ?></td>
            <td><?php echo min((int)$r['distinct_minutes'], 4); ?></td>
            <td><?php echo (int)$r['total_rows']; ?></td>
            <td><?php echo htmlspecialchars($r['first_checkin']); ?></td>
            <td><?php echo htmlspecialchars($r['last_checkin']); ?></td>
            <td>
              <?php if ((int)$r['total_rows'] >= $threshold): ?>
                <span class="tag ok">在岗</span>
              <?php else: ?>
                <span class="tag warn">不足阈值</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- 分页 -->
    <div class="pagination">
      <?php if ($page > 1): ?>
        <a href="<?php echo qs(['page'=>1]); ?>">« 首页</a>
        <a href="<?php echo qs(['page'=>$page-1]); ?>">‹ 上一页</a>
      <?php else: ?>
        <span>« 首页</span><span>‹ 上一页</span>
      <?php endif; ?>

      <span class="active"><?php echo $page; ?> / <?php echo $total_pages; ?></span>

      <?php if ($page < $total_pages): ?>
        <a href="<?php echo qs(['page'=>$page+1]); ?>">下一页 ›</a>
        <a href="<?php echo qs(['page'=>$total_pages]); ?>">末页 »</a>
      <?php else: ?>
        <span>下一页 ›</span><span>末页 »</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function(){
  const form = document.getElementById('filterForm');
  const uidSelect = document.getElementById('uidSelect');
  const startInput = document.getElementById('startInput');
  const endInput = document.getElementById('endInput');

  // 选择 UID 自动提交，并回到第1页
  if (uidSelect) {
    uidSelect.addEventListener('change', () => {
      addOrSetParam('page', '1');
      form.submit();
    });
  }

  // 快捷时间按钮
  document.getElementById('btnToday').addEventListener('click', () => {
    const now = new Date();
    setRange(
      new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0),
      new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59)
    );
    addOrSetParam('page', '1');
    form.submit();
  });

  document.getElementById('btn7').addEventListener('click', () => {
    const now = new Date();
    const start = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 6, 0, 0); // 最近7天
    const end   = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59);
    setRange(start, end);
    addOrSetParam('page', '1');
    form.submit();
  });

  document.getElementById('btnMonth').addEventListener('click', () => {
    const now = new Date();
    const start = new Date(now.getFullYear(), now.getMonth(), 1, 0, 0);
    const end   = new Date(now.getFullYear(), now.getMonth()+1, 0, 23, 59); // 本月最后一天
    setRange(start, end);
    addOrSetParam('page', '1');
    form.submit();
  });

  function pad(n){ return (n<10?'0':'') + n; }
  function toDTLocal(v){
    return v.getFullYear() + '-' + pad(v.getMonth()+1) + '-' + pad(v.getDate())
         + 'T' + pad(v.getHours()) + ':' + pad(v.getMinutes());
  }
  function setRange(s, e){
    startInput.value = toDTLocal(s);
    endInput.value   = toDTLocal(e);
  }
  function addOrSetParam(key, val){
    let url = new URL(window.location.href);
    url.searchParams.set(key, val);
    history.replaceState(null, '', url.toString());
  }

  // 首次无 start/end 参数时，保证控件显示为今天（你如需真·最近7天可以改这里）
  <?php if (!isset($_GET['start']) && !isset($_GET['end'])): ?>
    (function defaultToday(){
      const now = new Date();
      const start = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0);
      const end   = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 23, 59);
      setRange(start, end);
    })();
  <?php endif; ?>
})();
</script>
</body>
</html>
