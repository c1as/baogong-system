<?php
/* 模块: actions/reports
   Action: reports.list reports.get report.create report.update report.delete
   职责: 报工单列表/详情/增删改，明细联动订单
   依赖: lib/repo lib/validate lib/image lib/query
   注意: 明细缺订单号时自动建单，表头订单号取第一条明细 */

function act_reports_list()
{
    $pdo = bg_pdo();
    $q = bg_get('q', 100);
    $from = bg_get('from', 10);
    $to = bg_get('to', 10);
    list($page, $size, $offset) = bg_page();
    $params = array();
    $w = bg_date_where($params, 'r', $from, $to, 'rd');
    $w[] = bg_report_q_cond($params, $q);
    $where = implode(' AND ', $w);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM reports r WHERE ' . $where);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare(
        'SELECT r.* FROM reports r WHERE ' . $where .
        ' ORDER BY COALESCE(r.date, \'\') DESC, COALESCE(r.created_at, \'\') DESC, r.no DESC LIMIT ' . $size . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $reports = array();
    $ids = array();
    foreach ($stmt as $row) {
        $reports[] = array(
            'id' => $row['id'],
            'no' => $row['no'],
            'date' => $row['date'],
            'worker' => $row['worker'],
            'workshop' => isset($row['workshop']) ? $row['workshop'] : '',
            'note' => isset($row['note']) ? $row['note'] : '',
            'orderId' => $row['order_id'],
            'orderNo' => $row['order_no'],
            'images' => json_decode(isset($row['images']) ? $row['images'] : '[]', true),
            'createdAt' => isset($row['created_at']) ? $row['created_at'] : '',
            'updatedAt' => isset($row['updated_at']) ? $row['updated_at'] : '',
            'items' => array(),
        );
        $ids[] = $row['id'];
    }
    $itemsMap = bg_items_for($pdo, $ids);
    foreach ($reports as $i => $r) {
        $reports[$i]['items'] = isset($itemsMap[$r['id']]) ? $itemsMap[$r['id']] : array();
        if (!is_array($reports[$i]['images'])) {
            $reports[$i]['images'] = array();
        }
    }
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(ri.qty), 0) AS qty, COALESCE(SUM(ri.amount), 0) AS amount
         FROM reports r LEFT JOIN report_items ri ON ri.report_id = r.id WHERE ' . $where
    );
    $stmt->execute($params);
    $sum = $stmt->fetch(PDO::FETCH_ASSOC);
    bg_json(200, array(
        'ok' => true,
        'items' => $reports,
        'total' => $total,
        'page' => $page,
        'pageSize' => $size,
        'hasMore' => $offset + count($reports) < $total,
        'summary' => array('count' => $total, 'qty' => (float)$sum['qty'], 'amount' => (float)$sum['amount']),
    ));
    return;
}

function act_reports_get()
{
    $pdo = bg_pdo();
    $id = bg_get('id', 60);
    if ($id === '') {
        bg_fail(400, '缺少报工单 id');
    }
    $stmt = $pdo->prepare('SELECT * FROM reports WHERE id = ?');
    $stmt->execute(array($id));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        bg_fail(404, '报工单不存在');
    }
    $itemsMap = bg_items_for($pdo, array($id));
    $images = json_decode(isset($row['images']) ? $row['images'] : '[]', true);
    bg_json(200, array(
        'report' => array(
            'id' => $row['id'],
            'no' => $row['no'],
            'date' => $row['date'],
            'worker' => $row['worker'],
            'workshop' => isset($row['workshop']) ? $row['workshop'] : '',
            'note' => isset($row['note']) ? $row['note'] : '',
            'orderId' => $row['order_id'],
            'orderNo' => $row['order_no'],
            'images' => is_array($images) ? $images : array(),
            'items' => isset($itemsMap[$id]) ? $itemsMap[$id] : array(),
            'createdAt' => isset($row['created_at']) ? $row['created_at'] : '',
            'updatedAt' => isset($row['updated_at']) ? $row['updated_at'] : '',
        ),
    ));
    return;
}

function act_report_create()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $body = bg_body();
    $in = isset($body['report']) && is_array($body['report']) ? $body['report'] : $body;
    $pdo = bg_pdo();
    $date = bg_date(isset($in['date']) ? $in['date'] : '');
    list($partIds, $orderIds) = bg_report_item_refs($in);
    $parts = bg_parts_by_ids($pdo, $partIds);
    $orders = bg_orders_by_ids($pdo, $orderIds);
    $in['no'] = isset($in['no']) && is_string($in['no']) ? trim($in['no']) : '';

    $pdo->beginTransaction();
    try {
        if ($in['no'] === '') {
            $in['no'] = bg_seq_next_no($pdo, 'reports', 'BG' . str_replace('-', '', $date) . '-');
        }
        $conflict = bg_report_row_by_no($pdo, $in['no']);
        $db = array(
            'parts' => $parts,
            'orders' => $orders,
            'reports' => $conflict ? array($conflict) : array(),
        );
        $report = bg_normalize_report($in, null, $db);
        foreach ($report['items'] as $ii => $item) {
            if ($item['orderId'] === '') {
                $no = bg_seq_next_no($pdo, 'orders', 'PO' . str_replace('-', '', $report['date']) . '-');
                $order = bg_auto_order_for_item($report, $item, array(), $no);
                bg_order_insert($pdo, $order);
                $report['items'][$ii]['orderId'] = $order['id'];
                $report['items'][$ii]['orderNo'] = $order['no'];
            }
        }
        $report['orderId'] = $report['items'][0]['orderId'];
        $report['orderNo'] = $report['items'][0]['orderNo'];

        $images = array();
        $newImages = isset($body['newImages']) && is_array($body['newImages']) ? array_slice($body['newImages'], 0, 6) : array();
        foreach ($newImages as $dataUrl) {
            if (is_string($dataUrl) && strpos($dataUrl, 'data:') === 0) {
                $images[] = bg_image_save('attachments', bg_attach_base(), $dataUrl);
            }
        }
        $report['images'] = $images;
        bg_report_insert($pdo, $report);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (bg_unique_error($e)) {
            bg_fail(400, '报工单号「' . $in['no'] . '」已存在');
        }
        throw $e;
    }
    bg_json(200, array('report' => $report));
    return;
}

function act_report_update()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
    $body = bg_body();
    $in = isset($body['report']) && is_array($body['report']) ? $body['report'] : $body;
    $pdo = bg_pdo();
    $existing = bg_report_by_id($pdo, $id);
    if (!$existing) {
        bg_fail(404, '报工单不存在');
    }
    if (isset($in['updatedAt']) && is_string($in['updatedAt']) && $in['updatedAt'] !== ''
        && $in['updatedAt'] !== $existing['updatedAt']) {
        bg_fail(409, '该报工单已被他人修改，请刷新后重试');
    }
    $date = bg_date(isset($in['date']) ? $in['date'] : '');
    list($partIds, $orderIds) = bg_report_item_refs($in);
    $parts = bg_parts_by_ids($pdo, $partIds);
    $orders = bg_orders_by_ids($pdo, $orderIds);
    $in['no'] = isset($in['no']) && is_string($in['no']) ? trim($in['no']) : '';

    $pdo->beginTransaction();
    try {
        if ($in['no'] === '') {
            $in['no'] = bg_seq_next_no($pdo, 'reports', 'BG' . str_replace('-', '', $date) . '-');
        }
        $conflict = bg_report_row_by_no($pdo, $in['no']);
        $db = array(
            'parts' => $parts,
            'orders' => $orders,
            'reports' => $conflict ? array($conflict) : array(),
        );
        $report = bg_normalize_report($in, $existing, $db);
        foreach ($report['items'] as $ii => $item) {
            if ($item['orderId'] === '') {
                $no = bg_seq_next_no($pdo, 'orders', 'PO' . str_replace('-', '', $report['date']) . '-');
                $order = bg_auto_order_for_item($report, $item, array(), $no);
                bg_order_insert($pdo, $order);
                $report['items'][$ii]['orderId'] = $order['id'];
                $report['items'][$ii]['orderNo'] = $order['no'];
            }
        }
        $report['orderId'] = $report['items'][0]['orderId'];
        $report['orderNo'] = $report['items'][0]['orderNo'];

        $allowed = isset($existing['images']) && is_array($existing['images']) ? $existing['images'] : array();
        $kept = $allowed;
        if (isset($body['images']) && is_array($body['images'])) {
            $kept = array();
            foreach ($allowed as $f) {
                if (in_array($f, $body['images'], true)) {
                    $kept[] = $f;
                }
            }
        }
        /* 待删附件等写库成功后再删，避免并发冲突回滚时误删文件 */
        $toRemove = array();
        foreach ($allowed as $f) {
            if (!in_array($f, $kept, true)) {
                $toRemove[] = $f;
            }
        }
        $newImages = isset($body['newImages']) && is_array($body['newImages']) ? array_slice($body['newImages'], 0, 6) : array();
        foreach ($newImages as $dataUrl) {
            if (is_string($dataUrl) && strpos($dataUrl, 'data:') === 0) {
                $kept[] = bg_image_save('attachments', bg_attach_base(), $dataUrl);
            }
        }
        $report['images'] = $kept;
        if (bg_report_update($pdo, $report, $existing['updatedAt']) < 1) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            bg_fail(409, '该报工单已被他人修改，请刷新后重试');
        }
        foreach ($toRemove as $f) {
            bg_image_remove($f);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (bg_unique_error($e)) {
            bg_fail(400, '报工单号「' . $in['no'] . '」已存在');
        }
        throw $e;
    }
    bg_json(200, array('report' => $report));
    return;
}

function act_report_delete()
{
    $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
    $pdo = bg_pdo();
    $existing = bg_report_by_id($pdo, $id);
    if (!$existing) {
        bg_fail(404, '报工单不存在');
    }
    $imgs = isset($existing['images']) && is_array($existing['images']) ? $existing['images'] : array();
    foreach ($imgs as $f) {
        bg_image_remove($f);
    }
    bg_report_delete($pdo, $id);
    bg_json(200, array('ok' => true));
    return;
}
