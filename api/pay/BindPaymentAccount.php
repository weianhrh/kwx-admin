<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/venue_scope.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function bind_payment_reply($code, $msg, $data = null, array $context = []) {
    echo json_encode(array_merge([
        'code' => $code,
        'msg' => $msg,
        'data' => $data,
    ], $context), JSON_UNESCAPED_UNICODE);
    exit;
}

function bind_payment_text($field, $label, $default = '', $rejectWhitespace = false) {
    $value = $_POST[$field] ?? $default;
    if (!is_scalar($value)) {
        bind_payment_reply(1004, $label . '格式不正确');
    }
    $value = (string)$value;
    if ($rejectWhitespace && preg_match('/\s/u', $value)) {
        bind_payment_reply(1004, $label . '不能包含空格');
    }
    return trim($value);
}

$database = null;
$transactionStarted = false;
$venue_id = 0;

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET', 'POST'], true)) {
        bind_payment_reply(1004, '仅支持 GET / POST 请求');
    }

    $session_token = $_COOKIE['session_token'] ?? null;
    if (!$session_token) {
        bind_payment_reply(1001, '用户未登录或会话已过期');
    }
    $database = new Database();
    $user = $database->getUserBySessionToken($session_token);
    if (!$user || empty($user['role_id'])) {
        bind_payment_reply(1001, '用户未登录或无权访问');
    }

    // 显式传入的场地必须合法；不能把非法/冲突参数当成“未传”而回退到主场地。
    $requestedIds = [];
    foreach ([$_GET, $_POST] as $source) {
        foreach (['venue_id', 'venueId'] as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $value = $source[$key];
            if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value <= 0) {
                bind_payment_reply(1004, '场地参数不正确，请从提现场地总览重新进入');
            }
            $requestedIds[] = (int)$value;
        }
    }
    if (count(array_unique($requestedIds)) > 1) {
        bind_payment_reply(1004, '场地参数不一致，请刷新后重试');
    }

    // 与提现接口共用多场地权限：1/2 按平台权限，其他角色只能访问其关联场地。
    // 只有完全未传场地参数的旧入口，才兼容使用该账号的默认场地。
    $venue_id = venue_scope_resolve_single_id($database, $user, (int)($requestedIds[0] ?? 0));
    if ($venue_id <= 0) {
        bind_payment_reply(1003, '未绑定或无权访问该场地');
    }

    if ($method === 'POST') {
        $account_name = bind_payment_text('account_name', '账户姓名');
        $withdrawal_account = bind_payment_text('withdrawal_account', '提现卡号', '', true);
        $remarks = bind_payment_text('remarks', '备注');
        $account_type = bind_payment_text('account_type', '账号类型', '银行卡');
        $bank_name = bind_payment_text('bank_name', '银行名称');
        $bank_number = bind_payment_text('bank_number', '银行行号', '', true);
        if ($account_name === '' || $withdrawal_account === '' || $bank_name === '' || $account_type === '') {
            bind_payment_reply(1004, '账户姓名、提现卡号、银行名称和账号类型不能为空');
        }
        $database->beginTransaction();
        $transactionStarted = true;
    }

    // 先锁定场地行，即使该场地还没有资金行，也能串行处理本接口的重复绑定请求。
    $venueRows = $database->query(
        'SELECT id, venue_name FROM venues WHERE id = ? LIMIT 1' . ($method === 'POST' ? ' FOR UPDATE' : ''),
        [$venue_id]
    );
    if ($venueRows === false) {
        throw new RuntimeException('读取场地失败');
    }
    if (!$venueRows) {
        if ($transactionStarted) {
            $database->rollBack();
            $transactionStarted = false;
        }
        bind_payment_reply(1003, '场地不存在或已删除');
    }

    $fundRows = $database->query(
        'SELECT id, venue_id, account_name, withdrawal_account, remarks, account_type, bank_name, bank_number
         FROM venue_funds WHERE venue_id = ? LIMIT 2' . ($method === 'POST' ? ' FOR UPDATE' : ''),
        [$venue_id]
    );
    if ($fundRows === false) {
        throw new RuntimeException('读取收款账号失败');
    }
    if (count($fundRows) > 1) {
        if ($transactionStarted) {
            $database->rollBack();
            $transactionStarted = false;
        }
        bind_payment_reply(1003, '本场地存在重复资金账户，请联系平台核对，勿重复绑定');
    }

    $fund = $fundRows[0] ?? null;
    $isBound = $fund !== null && trim((string)($fund['withdrawal_account'] ?? '')) !== '';
    $context = [
        'venue_id' => $venue_id,
        'venue_name' => (string)($venueRows[0]['venue_name'] ?? ''),
        'is_bound' => $isBound,
    ];

    if ($method === 'GET') {
        bind_payment_reply(0, $isBound ? '本场地已绑定' : '本场地未绑定', $fund, $context);
    }

    if ($isBound) {
        $database->rollBack();
        $transactionStarted = false;
        bind_payment_reply(1002, '本场地已绑定收款账号，不能重复绑定；如需更换请联系平台管理员', null, $context);
    }

    if ($fund !== null) {
        // 已有资金行但没有收款账号：只补收款资料，绝不重置余额或重建资金行。
        $affected = $database->query(
            "UPDATE venue_funds
             SET account_name = ?, withdrawal_account = ?, remarks = ?, account_type = ?, bank_name = ?, bank_number = ?, updated_at = NOW()
             WHERE id = ? AND venue_id = ? AND (withdrawal_account IS NULL OR TRIM(withdrawal_account) = '')",
            [$account_name, $withdrawal_account, $remarks, $account_type, $bank_name, $bank_number, (int)$fund['id'], $venue_id],
            true
        );
    } else {
        $affected = $database->query(
            'INSERT INTO venue_funds (venue_id, account_name, withdrawal_account, remarks, account_type, bank_name, bank_number)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$venue_id, $account_name, $withdrawal_account, $remarks, $account_type, $bank_name, $bank_number],
            true
        );
    }
    if ($affected !== 1) {
        throw new RuntimeException('写入收款账号失败或状态已变化');
    }

    $database->commit();
    $transactionStarted = false;
    $context['is_bound'] = true;
    bind_payment_reply(0, '本场地收款账号绑定成功', null, $context);
} catch (Throwable $e) {
    if ($transactionStarted && $database) {
        try {
            $database->rollBack();
        } catch (Throwable $ignore) {
        }
    }
    // 不把银行卡号、姓名或数据库异常原文写入响应/日志。
    error_log('BindPaymentAccount failed; venue_id=' . $venue_id . '; type=' . get_class($e) . '; code=' . $e->getCode());
    bind_payment_reply(1003, '收款账号操作失败，请稍后重试或联系平台核对');
}
