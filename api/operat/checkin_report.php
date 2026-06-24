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
  --primary:#5b8cff;--secondary:#7f8fa4;
  --background-gradient-start:#f8f9ff;--background-gradient-end:#f0f3ff;
  --card-bg:#ffffff;--border-color:#ddd;--text-color:#333;
  --shadow:0 4px 24px rgba(91,140,255,.08);--transition:all .3s ease;--hover-bg:#f9f9f9;
}

*{margin:0;padding:0;box-sizing:border-box;font-family:"PingFang SC",system-ui}
body{
  background:linear-gradient(135deg,var(--background-gradient-start) 0%,var(--background-gradient-end) 100%);
  min-height:100vh;padding:16px;display:flex;justify-content:center;align-items:flex-start
}
.container{max-width:980px;width:100%}
.card{background:var(--card-bg);border-radius:14px;padding:16px;box-shadow:var(--shadow);margin-bottom:16px;transition:var(--transition)}
.card-title{font-size:20px;color:var(--text-color);margin-bottom:12px;font-weight:600;border-bottom:2px solid var(--primary);padding-bottom:8px;text-align:left}
.meta{color:var(--secondary);font-size:12px;margin:-4px 0 12px;line-height:1.4}

/* ====== 表单：响应式网格/PC flex ====== */
form.filter{
  display:flex;              /* PC: 一排+自动换行 */
  flex-wrap:wrap;
  gap:12px;
  align-items:center;
  margin-bottom:12px;
}
form.filter > *{width:auto}
form.filter input,
form.filter select,
form.filter button{
  min-height:38px;
  padding:8px 12px;
  border:1px solid var(--border-color);
  border-radius:10px;
  font-size:14px;
  background:#fff;
}
form.filter button{background:var(--primary);color:#fff;border-color:var(--primary);cursor:pointer}
form.filter button.quick{background:#fff;color:var(--text-color);border-color:var(--border-color)}
form.filter button:hover{opacity:.95}

/* PC：两个 datetime 输入给个固定宽度更稳 */
#startInput,#endInput{width:210px}
#uidSelect{min-width:120px}
#submitBtn{min-width:120px}

/* KPI */
.kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:8px 0 12px}
.kpi{background:#fff;border:1px solid var(--border-color);border-radius:10px;padding:10px 12px}
.kpi b{font-size:18px;color:var(--text-color)}

/* 表格容器：小屏可横向滚动 */
.table-wrap{width:100%;overflow:auto;border:1px solid var(--border-color);border-radius:12px;background:#fff}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:10px 12px;border-bottom:1px solid var(--border-color);font-size:14px;color:var(--text-color);text-align:left;white-space:nowrap}
.table th{background:#f6f8ff;font-weight:600;position:sticky;top:0;z-index:1}
.table tr:hover td{background:#fafcff}
.tag{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px}
.tag.ok{background:#eaf9f4;color:#0a8f66}
.tag.warn{background:#fff5e6;color:#b06b00}

/* 分页 */
.pagination{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px}
.pagination a,.pagination span{
  padding:8px 12px;border:1px solid var(--border-color);border-radius:10px;text-decoration:none;color:var(--text-color);background:#fff
}
.pagination a:hover{background:var(--hover-bg)}
.pagination .active{background:var(--primary);color:#fff;border-color:var(--primary)}

/* ====== 768px 以下（平板/常见手机横屏） ====== */
@media (max-width: 768px){
  .container{max-width:100%}
  .card{padding:14px;border-radius:12px}
  .card-title{font-size:18px}
  .meta{font-size:12px}

  /* 表单：2列布局 */
  form.filter{
    display:grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap:10px;
  }
  #submitBtn{grid-column:auto / span 2}

  .kpis{grid-template-columns:1fr 1fr}
  .table th,.table td{padding:8px 10px;font-size:13px}
}

/* ====== 480px 以下（小屏/手机竖屏） ====== */
@media (max-width: 480px){
  body{padding:12px}
  .card{padding:12px}
  .card-title{font-size:16px}
  .meta{font-size:11px}

  /* 表单：单列 */
  form.filter{grid-template-columns:1fr}
  #submitBtn{grid-column:auto / span 1}

  #startInput,#endInput{width:100%}

  .kpis{grid-template-columns:1fr}
  .kpi b{font-size:16px}

  .table{min-width:640px}
  .table th,.table td{padding:8px 8px;font-size:12px}
}

/* <=992px 再切网格，隐藏一些输入 */
@media (max-width: 992px){
  form.filter{
    display:grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap:10px;
  }
  #submitBtn{grid-column:auto / span 2}
  input[name="threshold"],
  input[name="page_size"],
  span[name="zhi_text"]{
    display: none;
  }
}
</style>
</head>
<body>
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
