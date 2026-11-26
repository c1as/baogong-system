<?php
/* 模块: actions/orders
   Action: orders.list orders.search orders.get order.create order.update order.delete
   职责: 订单查询与增删改，附带已报量与剩余量
   依赖: lib/repo lib/validate lib/query
   注意: 已报量来自报工明细，勿重复累计 */

function act_orders_list()
{
    $pdo = bg_pdo();
    $q = bg_get('q', 100);
    $from = bg_get('from', 10);
    $to = bg_get('to', 10);
    list($page, $size, $offset) = bg_page();
    $params = array();
    $w = bg_date_where($params, 'o', $from, $to, 'od');
    $w[] = bg_like_any($params, $q, array('o.no', 'o.part_no', 'o.part_name', 'o.process', 'o.note'), 'oq');
    $where = implode(' AND ', $w);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders o WHERE ' . $where);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare(
        'SELECT o.* FROM orders o WHERE ' . $where .
        ' ORDER BY COALESCE(o.date, \'\') DESC, COALESCE(o.created_at, \'\') DESC, o.id DESC LIMIT ' . $size . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $items = array();
    $ids = array();
    foreach ($stmt as $row) {
        $items[] = bg_order_row($row);
        $ids[] = $row['id'];
    }
    $usage = bg_orders_usage($pdo, $ids);
    foreach ($items as $i => $it) {
        $u = isset($usage[$it['id']]) ? $usage[$it['id']] : array('qty' => 0, 'amount' => 0);
        $items[$i]['reportedQty'] = $u['qty'];
        $items[$i]['reportedAmount'] = $u['amount'];
        $items[$i]['remaining'] = $it['qty'] - $u['qty'];
    }
    bg_json(200, array(
        'ok' => true,
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'pageSize' => $size,
        'hasMore' => $offset + count($items) < $total,
    ));
    return;
}

function act_orders_search()
{
    $pdo = bg_pdo();
    $partId = bg_get('partId', 60);
    $process = bg_get('process', 100);
    $q = bg_get('q', 100);
    $exclude = bg_get('excludeReportId', 60);
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    if ($limit < 1) {
        $limit = 50;
    }
    if ($limit > 100) {
        $limit = 100;
    }
    $params = array();
    $w = array();
    if ($partId !== '') {
        $w[] = 'o.part_id = :pid';
        $params[':pid'] = $partId;
    }
    if ($process !== '') {
        $w[] = "(o.process = '' OR o.process = :proc)";
        $params[':proc'] = $process;
    }
    $w[] = bg_like_any($params, $q, array('o.no', 'o.part_no', 'o.part_name', 'o.process', 'o.note'), 'os');
    $where = implode(' AND ', $w);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders o WHERE ' . $where);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare(
        'SELECT o.* FROM orders o WHERE ' . $where .
        ' ORDER BY COALESCE(o.date, \'\') DESC, COALESCE(o.created_at, \'\') DESC, o.id DESC LIMIT ' . $limit
    );
    $stmt->execute($params);
    $items = array();
    $ids = array();
    foreach ($stmt as $row) {
        $items[] = bg_order_row($row);
        $ids[] = $row['id'];
    }
    $usage = bg_orders_usage($pdo, $ids, $exclude);
    foreach ($items as $i => $it) {
        $u = isset($usage[$it['id']]) ? $usage[$it['id']] : array('qty' => 0, 'amount' => 0);
        $items[$i]['reportedQty'] = $u['qty'];
        $items[$i]['reportedAmount'] = $u['amount'];
        $items[$i]['remaining'] = $it['qty'] - $u['qty'];
    }
    bg_json(200, array('ok' => true, 'items' => $items, 'total' => $total));
    return;
}

function act_orders_get()
{
    $pdo = bg_pdo();
    $id = bg_get('id', 60);
    if ($id === '') {
        bg_fail(400, '缺少订单 id');
    }
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute(array($id));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        bg_fail(404, '订单不存在');
    }
    $order = bg_order_row($row);
    $usage = bg_orders_usage($pdo, array($id));
    $u = isset($usage[$id]) ? $usage[$id] : array('qty' => 0, 'amount' => 0);
    $order['reportedQty'] = $u['qty'];
    $order['reportedAmount'] = $u['amount'];
    $order['remaining'] = $order['qty'] - $u['qty'];
    bg_json(200, array('order' => $order));
    return;
}

function act_order_create()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $body = bg_body();
    $in = isset($body['order']) && is_array($body['order']) ? $body['order'] : $body;
    $pdo = bg_pdo();
    $date = bg_date(isset($in['date']) ? $in['date'] : '');
    $partId = isset($in['partId']) && is_string($in['partId']) ? $in['partId'] : '';
    $part = $partId !== '' ? bg_part_by_id($pdo, $partId) : null;
    $in['no'] = isset($in['no']) && is_string($in['no']) ? trim($in['no']) : '';

    $pdo->beginTransaction();
    try {
        if ($in['no'] === '') {
            $in['no'] = bg_seq_next_no($pdo, 'orders', 'PO' . str_replace('-', '', $date) . '-');
        }
        $conflict = bg_order_row_by_no($pdo, $in['no']);
        $order = bg_normalize_order($in, null, $part ? array($part) : array(), $conflict ? array($conflict) : array());
        bg_order_insert($pdo, $order);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (bg_unique_error($e)) {
            bg_fail(400, '订单号「' . $in['no'] . '」已存在');
        }
        throw $e;
    }
    bg_json(200, array('order' => $order));
    return;
}

function act_order_update()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
    $body = bg_body();
    $in = isset($body['order']) && is_array($body['order']) ? $body['order'] : $body;
    $pdo = bg_pdo();
    $existing = bg_order_by_id($pdo, $id);
    if (!$existing) {
        bg_fail(404, '订单不存在');
    }
    if (isset($in['updatedAt']) && is_string($in['updatedAt']) && $in['updatedAt'] !== ''
        && $in['updatedAt'] !== $existing['updatedAt']) {
        bg_fail(409, '该订单已被他人修改，请刷新后重试');
    }
    $date = bg_date(isset($in['date']) ? $in['date'] : '');
    $partId = isset($in['partId']) && is_string($in['partId']) ? $in['partId'] : '';
    $part = $partId !== '' ? bg_part_by_id($pdo, $partId) : null;
    $in['no'] = isset($in['no']) && is_string($in['no']) ? trim($in['no']) : '';

    $pdo->beginTransaction();
    try {
        if ($in['no'] === '') {
            $in['no'] = bg_seq_next_no($pdo, 'orders', 'PO' . str_replace('-', '', $date) . '-');
        }
        $conflict = bg_order_row_by_no($pdo, $in['no']);
        $order = bg_normalize_order($in, $existing, $part ? array($part) : array(), $conflict ? array($conflict) : array());
        if (bg_order_update($pdo, $order, $existing['updatedAt']) < 1) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            bg_fail(409, '该订单已被他人修改，请刷新后重试');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (bg_unique_error($e)) {
            bg_fail(400, '订单号「' . $in['no'] . '」已存在');
        }
        throw $e;
    }
    bg_json(200, array('order' => $order));
    return;
}

function act_order_delete()
{
    $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
    $pdo = bg_pdo();
    if (bg_order_delete($pdo, $id) < 1) {
        bg_fail(404, '订单不存在');
    }
    bg_json(200, array('ok' => true));
    return;
}
