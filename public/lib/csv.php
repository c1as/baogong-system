<?php
/* 模块: lib/csv
   职责: 报工记录 CSV 导出
   依赖: 无
   注意: 含 BOM，Excel 直接打开不乱码 */

/* ================================================================ CSV */

function bg_csv_cell($v)
{
    $s = (string)$v;
    if ($s === '') {
        return '';
    }
    if (strpbrk($s, ",\"\r\n") !== false) {
        return '"' . str_replace('"', '""', $s) . '"';
    }
    return $s;
}

function bg_csv($reports)
{
    $lines = array();
    $head = array('报工单号', '日期', '报工人', '车间', '订单号', '零件图号', '零件名称', '工序', '数量', '单价(元)', '金额(元)', '备注');
    $lines[] = implode(',', array_map('bg_csv_cell', $head));

    $qtyTotal = 0;
    $amountTotal = 0;
    foreach ($reports as $r) {
        $items = isset($r['items']) && is_array($r['items']) ? $r['items'] : array();
        foreach ($items as $it) {
            $qtyTotal += isset($it['qty']) ? (float)$it['qty'] : 0;
            $amountTotal += isset($it['amount']) ? (float)$it['amount'] : 0;
            $row = array(
                isset($r['no']) ? $r['no'] : '',
                isset($r['date']) ? $r['date'] : '',
                isset($r['worker']) ? $r['worker'] : '',
                isset($r['workshop']) ? $r['workshop'] : '',
                isset($it['orderNo']) && $it['orderNo'] !== '' ? $it['orderNo'] : (isset($r['orderNo']) ? $r['orderNo'] : ''),
                isset($it['partNo']) ? $it['partNo'] : '',
                isset($it['partName']) ? $it['partName'] : '',
                isset($it['process']) ? $it['process'] : '',
                isset($it['qty']) ? $it['qty'] : '',
                isset($it['price']) ? $it['price'] : '',
                isset($it['amount']) ? $it['amount'] : '',
                isset($r['note']) ? $r['note'] : '',
            );
            $lines[] = implode(',', array_map('bg_csv_cell', $row));
        }
    }
    $lines[] = implode(',', array_map('bg_csv_cell', array('合计', '', '', '', '', '', '', '', bg_round($qtyTotal), '', bg_round($amountTotal), '')));
    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
}
