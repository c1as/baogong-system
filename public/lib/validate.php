<?php
/* 模块: lib/validate
   职责: 零件/订单/报工单的字段归一化与编号生成
   依赖: lib/http
   注意: 报工单归一化会联动订单冗余字段 */

/* ================================================================ 零件 */

function bg_normalize_processes($in, $existing)
{
    $src = null;
    if (isset($in['processes']) && is_array($in['processes'])) {
        $src = $in['processes'];
    } elseif ($existing && isset($existing['processes']) && is_array($existing['processes'])) {
        $src = $existing['processes'];
    }

    $out = array();
    if (is_array($src)) {
        $n = 0;
        foreach ($src as $raw) {
            $n++;
            if ($n > 20) {
                break;
            }
            if (!is_array($raw)) {
                continue;
            }
            $name = bg_str(isset($raw['name']) ? $raw['name'] : '', 60, '工序名称');
            if ($name === '') {
                continue;
            }
            $desc = bg_str(isset($raw['desc']) ? $raw['desc'] : '', 200, '工序说明');
            $price = 0;
            if (isset($raw['price']) && $raw['price'] !== '' && $raw['price'] !== null) {
                $price = bg_round(bg_num($raw['price'], '工序单价', 0, 1000000));
            }
            $out[] = array('name' => $name, 'desc' => $desc, 'price' => $price);
        }
    }
    return $out;
}

function bg_normalize_part($in, $existing)
{
    $no = bg_str(isset($in['no']) ? $in['no'] : '', 60, '零件图号');
    if ($no === '') {
        bg_fail(400, '请填写零件图号');
    }
    $name = bg_str(isset($in['name']) ? $in['name'] : '', 60, '零件名称');
    $material = bg_str(isset($in['material']) ? $in['material'] : '', 60, '材料');
    $note = bg_str(isset($in['note']) ? $in['note'] : '', 500, '备注');
    $stockValue = isset($in['stock'])
        ? $in['stock']
        : ($existing && isset($existing['stock']) ? $existing['stock'] : 0);
    if ($stockValue === null || $stockValue === '') {
        $stockValue = 0;
    }
    $stock = bg_round(bg_num($stockValue, '库存数量', 0, 1000000000));
    $processes = bg_normalize_processes($in, $existing);
    $now = gmdate('Y-m-d\TH:i:s\Z');

    return array(
        'id' => $existing ? $existing['id'] : bg_uuid(),
        'no' => $no,
        'name' => $name,
        'material' => $material,
        'note' => $note,
        'stock' => $stock,
        'image' => $existing && isset($existing['image']) ? $existing['image'] : '',
        'processes' => $processes,
        'drawings' => bg_normalize_drawings($in, $existing),
        'price' => count($processes) ? $processes[0]['price'] : 0,
        'createdAt' => $existing && isset($existing['createdAt']) ? $existing['createdAt'] : $now,
        'updatedAt' => $now,
    );
}

/* ================================================================ 订单 */

function bg_next_order_no($orders, $date)
{
    $prefix = 'PO' . str_replace('-', '', $date);
    $n = 1;
    while (true) {
        $no = $prefix . '-' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
        $exists = false;
        foreach ($orders as $o) {
            if (isset($o['no']) && $o['no'] === $no) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            return $no;
        }
        $n++;
    }
}

function bg_normalize_order($in, $existing, $parts, $orders)
{
    $date = bg_date(isset($in['date']) ? $in['date'] : '');
    $partId = bg_str(isset($in['partId']) ? $in['partId'] : '', 60, '零件');
    $part = null;
    foreach ($parts as $p) {
        if (isset($p['id']) && $p['id'] === $partId) {
            $part = $p;
            break;
        }
    }
    if (!$part) {
        bg_fail(400, '请选择订单零件');
    }
    $process = bg_str(isset($in['process']) ? $in['process'] : '', 60, '工序');
    $qty = bg_round(bg_num(isset($in['qty']) ? $in['qty'] : 0, '订单数量', 0, 1000000000));
    if ($qty <= 0) {
        bg_fail(400, '订单数量必须大于 0');
    }
    $note = bg_str(isset($in['note']) ? $in['note'] : '', 500, '备注');

    $no = bg_str(isset($in['no']) ? $in['no'] : '', 40, '订单号');
    if ($no === '') {
        $no = bg_next_order_no($orders, $date);
    }
    foreach ($orders as $o) {
        if (isset($o['no']) && $o['no'] === $no && (!$existing || $o['id'] !== $existing['id'])) {
            bg_fail(400, '订单号「' . $no . '」已存在');
        }
    }

    $now = gmdate('Y-m-d\TH:i:s\Z');
    return array(
        'id' => $existing ? $existing['id'] : bg_uuid(),
        'no' => $no,
        'date' => $date,
        'partId' => $part['id'],
        'partNo' => $part['no'],
        'partName' => isset($part['name']) ? $part['name'] : '',
        'process' => $process,
        'qty' => $qty,
        'note' => $note,
        'createdAt' => $existing && isset($existing['createdAt']) ? $existing['createdAt'] : $now,
        'updatedAt' => $now,
    );
}

function bg_auto_order_for_item($report, $item, $orders)
{
    $now = gmdate('Y-m-d\TH:i:s\Z');
    return array(
        'id' => bg_uuid(),
        'no' => bg_next_order_no($orders, $report['date']),
        'date' => $report['date'],
        'partId' => $item['partId'],
        'partNo' => $item['partNo'],
        'partName' => $item['partName'],
        'process' => isset($item['process']) ? $item['process'] : '',
        'qty' => bg_round($item['qty']),
        'note' => '报工自动创建',
        'createdAt' => $now,
        'updatedAt' => $now,
    );
}

/* ================================================================ 报工 */

function bg_next_report_no($reports, $date)
{
    $prefix = 'BG' . str_replace('-', '', $date);
    $n = 1;
    while (true) {
        $no = $prefix . '-' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
        $exists = false;
        foreach ($reports as $r) {
            if (isset($r['no']) && $r['no'] === $no) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            return $no;
        }
        $n++;
    }
}

function bg_normalize_report($in, $existing, $db)
{
    $date = bg_date(isset($in['date']) ? $in['date'] : '');
    $worker = bg_str(isset($in['worker']) ? $in['worker'] : '', 40, '报工人');
    if ($worker === '') {
        bg_fail(400, '请填写报工人');
    }
    $workshop = bg_str(isset($in['workshop']) ? $in['workshop'] : '', 60, '车间');
    $note = bg_str(isset($in['note']) ? $in['note'] : '', 500, '备注');

    $rawItems = isset($in['items']) && is_array($in['items']) ? $in['items'] : array();
    if (count($rawItems) === 0) {
        bg_fail(400, '请至少添加一条报工明细');
    }
    if (count($rawItems) > 100) {
        bg_fail(400, '单张报工单最多 100 条明细');
    }

    $partMap = array();
    foreach ($db['parts'] as $p) {
        if (isset($p['id'])) {
            $partMap[$p['id']] = $p;
        }
    }

    $items = array();
    $idx = 0;
    foreach ($rawItems as $it) {
        $idx++;
        if (!is_array($it)) {
            bg_fail(400, '第 ' . $idx . ' 条明细格式不正确');
        }
        $partId = isset($it['partId']) && is_string($it['partId']) ? $it['partId'] : '';
        if (!isset($partMap[$partId])) {
            bg_fail(400, '第 ' . $idx . ' 条明细的零件不存在，请重新选择');
        }
        $part = $partMap[$partId];
        $qty = bg_num(isset($it['qty']) ? $it['qty'] : 0, '第 ' . $idx . ' 条数量', 0, 1000000);
        if ($qty <= 0) {
            bg_fail(400, '第 ' . $idx . ' 条数量必须大于 0');
        }
        $price = bg_round(bg_num(isset($it['price']) ? $it['price'] : 0, '第 ' . $idx . ' 条单价', 0, 1000000));
        $process = bg_str(isset($it['process']) ? $it['process'] : '', 60, '第 ' . $idx . ' 条工序');
        if ($process === '' && isset($part['processes'][0]['name'])) {
            $process = (string)$part['processes'][0]['name'];
        }

        $orderId = bg_str(isset($it['orderId']) ? $it['orderId'] : '', 60, '第 ' . $idx . ' 条订单');
        $orderNo = '';
        if ($orderId !== '') {
            $found = null;
            foreach ($db['orders'] as $o) {
                if (isset($o['id']) && $o['id'] === $orderId) {
                    $found = $o;
                    break;
                }
            }
            if (!$found) {
                bg_fail(400, '第 ' . $idx . ' 条明细的订单不存在，请重新选择');
            }
            if ($found['partId'] !== $part['id']) {
                bg_fail(400, '第 ' . $idx . ' 条明细的订单与零件不匹配');
            }
            if ($found['process'] !== '' && $process !== '' && $found['process'] !== $process) {
                bg_fail(400, '第 ' . $idx . ' 条明细的订单与工序不匹配');
            }
            $orderNo = $found['no'];
        }

        $items[] = array(
            'partId' => $part['id'],
            'partNo' => isset($part['no']) ? (string)$part['no'] : '',
            'partName' => isset($part['name']) ? (string)$part['name'] : '',
            'process' => $process,
            'qty' => $qty,
            'price' => $price,
            'amount' => bg_round($qty * $price),
            'orderId' => $orderId,
            'orderNo' => $orderNo,
        );
    }

    $no = bg_str(isset($in['no']) ? $in['no'] : '', 40, '报工单号');
    if ($no === '') {
        $no = bg_next_report_no($db['reports'], $date);
    }
    foreach ($db['reports'] as $r) {
        if (!isset($r['no']) || $r['no'] !== $no) {
            continue;
        }
        if ($existing && isset($existing['id']) && $r['id'] === $existing['id']) {
            continue;
        }
        bg_fail(400, '报工单号「' . $no . '」已存在');
    }

    $now = gmdate('Y-m-d\TH:i:s\Z');
    return array(
        'id' => $existing ? $existing['id'] : bg_uuid(),
        'no' => $no,
        'date' => $date,
        'worker' => $worker,
        'workshop' => $workshop,
        'note' => $note,
        'orderId' => $existing && isset($existing['orderId']) ? $existing['orderId'] : '',
        'orderNo' => $existing && isset($existing['orderNo']) ? $existing['orderNo'] : '',
        'items' => $items,
        'images' => $existing && isset($existing['images']) && is_array($existing['images']) ? $existing['images'] : array(),
        'createdAt' => $existing && isset($existing['createdAt']) ? $existing['createdAt'] : $now,
        'updatedAt' => $now,
    );
}
