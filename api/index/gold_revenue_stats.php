<?php
require_once '../Database.php';

header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

function jsonOut($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    $resp = ['code' => $code, 'msg' => $msg];
    if ($data !== null) {
        $resp['data'] = $data;
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

$database = new Database();

// 会话校验
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) {
    if (isset($_GET['ajax'])) {
        jsonOut(1001, '用户未登录或会话已过期', []);
    }
    header("Location: login.html");
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || empty($user['role_id'])) {
    if (isset($_GET['ajax'])) {
        jsonOut(1001, '用户未登录或无权访问', []);
    }
    echo '用户未登录或无权访问';
    exit;
}

// ===== AJAX 数据接口 =====
if (isset($_GET['ajax'])) {
    $mode = $_GET['mode'] ?? 'day';
    $mode = in_array($mode, ['day', 'week', 'month'], true) ? $mode : 'day';

    // 苹果金币订单时间
    $appleTimeExpr = "COALESCE(a.purchase_date, a.created_at)";

    if ($mode === 'day') {
        $sql = "
            SELECT
                label,
                sort_key,
                ROUND(COALESCE(SUM(total_amount), 0), 2) AS total_amount,
                COALESCE(SUM(order_count), 0) AS order_count
            FROM (
                -- 苹果金币充值
                SELECT
                    DATE_FORMAT(DATE($appleTimeExpr), '%m月%d日') AS label,
                    DATE($appleTimeExpr) AS sort_key,
                    COALESCE(SUM(p.price), 0) AS total_amount,
                    COUNT(*) AS order_count
                FROM apple_iap_orders a
                INNER JOIN iap_gold_products p
                    ON a.product_id = p.product_id
                WHERE a.order_status = 'success'
                  AND a.verify_status = 1
                GROUP BY DATE($appleTimeExpr)

                UNION ALL

                -- 安卓金币充值：RechargeOrders，订单号包含 GO
                SELECT
                    DATE_FORMAT(DATE(created_at), '%m月%d日') AS label,
                    DATE(created_at) AS sort_key,
                    COALESCE(SUM(COALESCE(CAST(NULLIF(payer_total, '') AS DECIMAL(10,2)), 0)), 0) AS total_amount,
                    COUNT(*) AS order_count
                FROM RechargeOrders
                WHERE order_number LIKE '%GO%'
                  AND status = '支付成功'
                GROUP BY DATE(created_at)
            ) t
            GROUP BY sort_key, label
            ORDER BY sort_key DESC
            LIMIT 30
        ";
    } elseif ($mode === 'week') {
        $sql = "
            SELECT
                CONCAT(
                    DATE_FORMAT(sort_key, '%m月%d日'),
                    ' - ',
                    DATE_FORMAT(DATE_ADD(sort_key, INTERVAL 6 DAY), '%m月%d日')
                ) AS label,
                sort_key,
                ROUND(COALESCE(SUM(total_amount), 0), 2) AS total_amount,
                COALESCE(SUM(order_count), 0) AS order_count
            FROM (
                -- 苹果金币充值
                SELECT
                    DATE_SUB(DATE($appleTimeExpr), INTERVAL WEEKDAY($appleTimeExpr) DAY) AS sort_key,
                    COALESCE(SUM(p.price), 0) AS total_amount,
                    COUNT(*) AS order_count
                FROM apple_iap_orders a
                INNER JOIN iap_gold_products p
                    ON a.product_id = p.product_id
                WHERE a.order_status = 'success'
                  AND a.verify_status = 1
                GROUP BY DATE_SUB(DATE($appleTimeExpr), INTERVAL WEEKDAY($appleTimeExpr) DAY)

                UNION ALL

                -- 安卓金币充值
                SELECT
                    DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY) AS sort_key,
                    COALESCE(SUM(COALESCE(CAST(NULLIF(payer_total, '') AS DECIMAL(10,2)), 0)), 0) AS total_amount,
                    COUNT(*) AS order_count
                FROM RechargeOrders
                WHERE order_number LIKE '%GO%'
                  AND status = '支付成功'
                GROUP BY DATE_SUB(DATE(created_at), INTERVAL WEEKDAY(created_at) DAY)
            ) t
            GROUP BY sort_key
            ORDER BY sort_key DESC
            LIMIT 20
        ";
    } else {
        $sql = "
            SELECT
                DATE_FORMAT(sort_key, '%Y年%m月') AS label,
                sort_key,
                ROUND(COALESCE(SUM(total_amount), 0), 2) AS total_amount,
                COALESCE(SUM(order_count), 0) AS order_count
            FROM (
                -- 苹果金币充值
                SELECT
                    DATE_FORMAT($appleTimeExpr, '%Y-%m-01') AS sort_key,
                    COALESCE(SUM(p.price), 0) AS total_amount,
                    COUNT(*) AS order_count
                FROM apple_iap_orders a
                INNER JOIN iap_gold_products p
                    ON a.product_id = p.product_id
                WHERE a.order_status = 'success'
                  AND a.verify_status = 1
                GROUP BY DATE_FORMAT($appleTimeExpr, '%Y-%m')

                UNION ALL

                -- 安卓金币充值
                SELECT
                    DATE_FORMAT(created_at, '%Y-%m-01') AS sort_key,
                    COALESCE(SUM(COALESCE(CAST(NULLIF(payer_total, '') AS DECIMAL(10,2)), 0)), 0) AS total_amount,
                    COUNT(*) AS order_count
                FROM RechargeOrders
                WHERE order_number LIKE '%GO%'
                  AND status = '支付成功'
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ) t
            GROUP BY sort_key
            ORDER BY sort_key DESC
            LIMIT 12
        ";
    }

    $rows = $database->query($sql) ?: [];

    jsonOut(0, 'ok', [
        'mode' => $mode,
        'list' => $rows
    ]);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>金币收益统计</title>
    <style>
        :root {
            --primary: #3f6de0;
            --primary-light: #6f95f5;
            --text-main: #222;
            --text-sub: #666;
            --card-bg: #ffffff;
            --page-bg: #f3f3f3;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            --radius: 12px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: "PingFang SC", "Microsoft YaHei", system-ui, sans-serif;
        }

        body {
            background: var(--page-bg);
            min-height: 100vh;
            padding: 28px 24px 40px;
            color: var(--text-main);
        }

        .wrap {
            max-width: 1100px;
            margin: 0 auto;
        }

        .title {
            text-align: center;
            font-size: 34px;
            font-weight: 700;
            margin: 10px 0 24px;
            color: #111;
        }

        .toolbar {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        .tab-btn {
            border: none;
            background: var(--primary-light);
            color: #fff;
            padding: 10px 18px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            transition: transform .15s ease, opacity .15s ease, background .15s ease;
        }

        .tab-btn:hover {
            transform: translateY(-1px);
        }

        .tab-btn.active {
            background: var(--primary);
            box-shadow: 0 4px 12px rgba(63, 109, 224, 0.28);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 16px;
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            min-height: 110px;
            padding: 18px 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .card-date {
            font-size: 16px;
            color: #555;
            margin-bottom: 14px;
        }

        .card-amount {
            font-size: 20px;
            font-weight: 700;
            color: #10233f;
        }

        .card-sub {
            margin-top: 8px;
            font-size: 12px;
            color: #888;
        }

        .empty {
            text-align: center;
            color: #888;
            font-size: 16px;
            padding: 48px 0;
        }

        .loading {
            text-align: center;
            color: #888;
            font-size: 16px;
            padding: 48px 0;
        }

        @media (max-width: 1024px) {
            .grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 18px 14px 28px;
            }

            .title {
                font-size: 28px;
                margin-bottom: 18px;
            }

            .grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .card {
                min-height: 96px;
            }

            .card-date {
                font-size: 14px;
                margin-bottom: 10px;
            }

            .card-amount {
                font-size: 18px;
            }
        }

        @media (max-width: 420px) {
            .grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="title">金币收益统计</div>

    <div class="toolbar">
        <button class="tab-btn active" data-mode="day">按天</button>
        <button class="tab-btn" data-mode="week">按周</button>
        <button class="tab-btn" data-mode="month">按月</button>
    </div>

    <div id="content" class="loading">加载中...</div>
</div>

<script>
const contentEl = document.getElementById('content');
const tabBtns = document.querySelectorAll('.tab-btn');

async function loadStats(mode = 'day') {
    contentEl.className = 'loading';
    contentEl.textContent = '加载中...';

    try {
        const res = await fetch(`gold_revenue_stats.php?ajax=1&mode=${mode}&_=${Date.now()}`, {
            credentials: 'include',
            cache: 'no-store'
        });
        const ret = await res.json();

        if (ret.code !== 0) {
            contentEl.className = 'empty';
            contentEl.textContent = ret.msg || '加载失败';
            return;
        }

        const list = Array.isArray(ret.data?.list) ? ret.data.list : [];

        if (!list.length) {
            contentEl.className = 'empty';
            contentEl.textContent = '暂无数据';
            return;
        }

        const html = `
            <div class="grid">
                ${list.map(item => `
                    <div class="card">
                        <div class="card-date">${item.label || ''}</div>
                        <div class="card-amount">${Number(item.total_amount || 0).toFixed(2)} 元</div>
                        <div class="card-sub">订单数：${item.order_count || 0}</div>
                    </div>
                `).join('')}
            </div>
        `;

        contentEl.className = '';
        contentEl.innerHTML = html;

    } catch (e) {
        contentEl.className = 'empty';
        contentEl.textContent = '加载失败，请刷新重试';
        console.error(e);
    }
}

tabBtns.forEach(btn => {
    btn.addEventListener('click', function () {
        tabBtns.forEach(x => x.classList.remove('active'));
        this.classList.add('active');
        loadStats(this.dataset.mode);
    });
});

loadStats('day');
</script>
</body>
</html>