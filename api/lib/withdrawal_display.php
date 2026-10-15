<?php
// 提现记录和审批列表共用申请时的金额快照，不按当前费率重算历史申请。
function kwxWithdrawalRemarkAmount($remarks, $labels) {
    foreach ($labels as $label) {
        $pattern = '/' . preg_quote($label, '/') . '\s*[:：=]\s*([0-9]+(?:\.[0-9]+)?)/u';
        if (preg_match($pattern, (string)$remarks, $matches)) {
            return round((float)$matches[1], 2);
        }
    }
    return 0.00;
}

function kwxWithdrawalDisplayAmounts(array $row) {
    $remarks = $row['remarks'] ?? '';
    $imageFee = kwxWithdrawalRemarkAmount($remarks, ['图传费用扣减', '未结算图传费用', '图传费用']);
    $settlement = kwxWithdrawalRemarkAmount($remarks, ['参与提现结算金额', '结算金额']);
    $display = kwxWithdrawalRemarkAmount($remarks, ['提现金额', '展示提现金额']);
    $refund = kwxWithdrawalRemarkAmount($remarks, ['退款扣减']);
    $debit = kwxWithdrawalRemarkAmount($remarks, ['账户余额扣减', '总扣款']);

    // 没有金额快照的旧申请按原字段展示，与原提现记录接口保持一致。
    if ($settlement <= 0) {
        $settlement = (float)($row['withdrawal_amount'] ?? 0);
    }
    if ($display <= 0) {
        $display = $imageFee > 0
            ? round($settlement + $imageFee, 2)
            : (float)($row['withdrawal_amount'] ?? 0);
    }
    if ($debit <= 0) {
        $debit = round($display + $refund, 2);
    }

    $row['image_fee_amount'] = round($imageFee, 2);
    $row['settlement_amount'] = round($settlement, 2);
    $row['display_withdrawal_amount'] = round($display, 2);
    $row['refund_deduct_amount'] = round($refund, 2);
    $row['total_debit_amount'] = round($debit, 2);
    return $row;
}
