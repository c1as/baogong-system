<?php
/* 模块: actions/stats
   Action: stats.list stats.overview
   职责: 报工明细统计与年度概览
   依赖: lib/query lib/db
   注意: 统计按明细行展开，分页以明细为单位 */

function act_stats_list()
{
    $pdo = bg_pdo();
    $from = bg_get('from', 10);
    $to = bg_get('to', 10);
    $q = bg_get('q', 100);
    $orderIds = bg_ids_param('orderIds');
    $partIds = bg_ids_param('partIds');
    list($page, $size, $offset) = bg_page();
    $params = array();
    $where = bg_stats_conds($params, $from, $to, $q, $orderIds, $partIds);
    $base = ' FROM reports r JOIN report_items ri ON ri.report_id = r.id'
        . ' LEFT JOIN orders o ON o.id = COALESCE(NULLIF(ri.order_id, \'\'), r.order_id)'
        . ' WHERE ' . $where;
    $stmt = $pdo->prepare('SELECT COUNT(*) AS rows_count, COALESCE(SUM(ri.qty), 0) AS qty, COALESCE(SUM(ri.amount), 0) AS amount' . $base);
    $stmt->execute($params);
    $sum = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare(
        'SELECT r.id AS report_id, r.no AS report_no, r.date, r.worker,'
        . ' COALESCE(NULLIF(ri.order_no, \'\'), r.order_no) AS order_no, o.qty AS order_qty,'
        . ' ri.part_no, ri.part_name, ri.process, ri.qty, ri.price, ri.amount, ri.sort, ri.id AS item_id'
        . $base . ' ORDER BY COALESCE(r.date, \'\') DESC, r.no DESC, ri.sort, ri.id'
        . ' LIMIT ' . $size . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $items = array();
    foreach ($stmt as $row) {
        $items[] = array(
            'reportId' => $row['report_id'],
            'date' => $row['date'],
            'reportNo' => $row['report_no'],
            'worker' => $row['worker'],
            'orderNo' => $row['order_no'],
            'orderQty' => $row['order_qty'] === null ? '' : (float)$row['order_qty'],
            'partNo' => $row['part_no'],
            'partName' => $row['part_name'],
            'process' => $row['process'],
            'qty' => (float)$row['qty'],
            'price' => (float)$row['price'],
            'amount' => (float)$row['amount'],
        );
    }
    $rows = (int)$sum['rows_count'];
    bg_json(200, array(
        'ok' => true,
        'items' => $items,
        'total' => $rows,
        'page' => $page,
        'pageSize' => $size,
        'hasMore' => $offset + count($items) < $rows,
        'summary' => array('rows' => $rows, 'qty' => (float)$sum['qty'], 'amount' => (float)$sum['amount']),
    ));
    return;
}

function act_stats_overview()
{
    $pdo = bg_pdo();
    $first = (string)$pdo->query('SELECT COALESCE(MIN(date), \'\') FROM reports')->fetchColumn();
    $years = array();
    $stmt = $pdo->query(
        'SELECT substr(r.date, 1, 4) AS year, COUNT(DISTINCT r.id) AS reports, COUNT(ri.id) AS items,'
        . ' COALESCE(SUM(ri.qty), 0) AS qty, COALESCE(SUM(ri.amount), 0) AS amount'
        . ' FROM reports r LEFT JOIN report_items ri ON ri.report_id = r.id'
        . ' WHERE r.date <> \'\' GROUP BY year ORDER BY year DESC'
    );
    foreach ($stmt as $row) {
        $years[] = array(
            'year' => $row['year'],
            'reports' => (int)$row['reports'],
            'items' => (int)$row['items'],
            'qty' => (float)$row['qty'],
            'amount' => (float)$row['amount'],
        );
    }
    bg_json(200, array(
        'counts' => bg_bootstrap_counts($pdo),
        'firstDate' => $first,
        'years' => $years,
    ));
    return;
}
