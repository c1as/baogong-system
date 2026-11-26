<?php
/* 模块: lib/repo
   职责: 全量读库、分表同步、写库入口
   依赖: lib/db
   注意: bg_db_save 负责把内存数组写回各表 */

function bg_index_state($db)
{
    $out = array('parts' => array(), 'orders' => array(), 'reports' => array());
    foreach ($db['parts'] as $p) {
        $out['parts'][$p['id']] = md5(json_encode($p));
    }
    foreach ($db['orders'] as $o) {
        $out['orders'][$o['id']] = md5(json_encode($o));
    }
    foreach ($db['reports'] as $r) {
        $out['reports'][$r['id']] = md5(json_encode($r));
    }
    return $out;
}

function bg_db_load()
{
    global $BG_SNAP;
    $pdo = bg_pdo();
    $db = array('parts' => array(), 'reports' => array(), 'orders' => array(), 'updatedAt' => null);

    foreach ($pdo->query('SELECT * FROM parts') as $row) {
        $processes = json_decode(isset($row['processes']) ? $row['processes'] : '[]', true);
        if (!is_array($processes)) {
            $processes = array();
        }
        $drawings = json_decode(isset($row['drawings']) ? $row['drawings'] : '[]', true);
        if (!is_array($drawings)) {
            $drawings = array();
        }
        $db['parts'][] = array(
            'id' => $row['id'],
            'no' => $row['no'],
            'name' => isset($row['name']) ? $row['name'] : '',
            'material' => isset($row['material']) ? $row['material'] : '',
            'note' => isset($row['note']) ? $row['note'] : '',
            'stock' => (float)$row['stock'],
            'image' => isset($row['image']) ? $row['image'] : '',
            'processes' => $processes,
            'drawings' => $drawings,
            'price' => count($processes) ? (float)$processes[0]['price'] : 0,
            'createdAt' => isset($row['created_at']) ? $row['created_at'] : '',
            'updatedAt' => isset($row['updated_at']) ? $row['updated_at'] : '',
        );
    }

    foreach ($pdo->query('SELECT * FROM orders') as $row) {
        $db['orders'][] = array(
            'id' => $row['id'],
            'no' => $row['no'],
            'date' => $row['date'],
            'partId' => $row['part_id'],
            'partNo' => $row['part_no'],
            'partName' => isset($row['part_name']) ? $row['part_name'] : '',
            'process' => isset($row['process']) ? $row['process'] : '',
            'qty' => (float)$row['qty'],
            'note' => isset($row['note']) ? $row['note'] : '',
            'createdAt' => isset($row['created_at']) ? $row['created_at'] : '',
            'updatedAt' => isset($row['updated_at']) ? $row['updated_at'] : '',
        );
    }

    $itemsBy = array();
    foreach ($pdo->query('SELECT * FROM report_items ORDER BY report_id, sort, id') as $row) {
        $itemsBy[$row['report_id']][] = array(
            'partId' => $row['part_id'],
            'partNo' => $row['part_no'],
            'partName' => $row['part_name'],
            'process' => $row['process'],
            'qty' => (float)$row['qty'],
            'price' => (float)$row['price'],
            'amount' => (float)$row['amount'],
            'orderId' => $row['order_id'],
            'orderNo' => $row['order_no'],
        );
    }

    foreach ($pdo->query('SELECT * FROM reports') as $row) {
        $images = json_decode(isset($row['images']) ? $row['images'] : '[]', true);
        if (!is_array($images)) {
            $images = array();
        }
        $db['reports'][] = array(
            'id' => $row['id'],
            'no' => $row['no'],
            'date' => $row['date'],
            'worker' => $row['worker'],
            'workshop' => $row['workshop'],
            'note' => $row['note'],
            'orderId' => $row['order_id'],
            'orderNo' => $row['order_no'],
            'items' => isset($itemsBy[$row['id']]) ? $itemsBy[$row['id']] : array(),
            'images' => $images,
            'createdAt' => isset($row['created_at']) ? $row['created_at'] : '',
            'updatedAt' => isset($row['updated_at']) ? $row['updated_at'] : '',
        );
    }

    $BG_SNAP = bg_index_state($db);
    return $db;
}

function bg_sync_parts($pdo, $parts)
{
    global $BG_SNAP;
    $stmt = $pdo->prepare(
        'INSERT INTO parts (id, no, name, material, note, stock, image, processes, drawings, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET
           no = excluded.no, name = excluded.name, material = excluded.material, note = excluded.note,
           stock = excluded.stock, image = excluded.image, processes = excluded.processes,
           drawings = excluded.drawings, updated_at = excluded.updated_at'
    );
    $seen = array();
    foreach ($parts as $p) {
        $seen[$p['id']] = true;
        $hash = md5(json_encode($p));
        if (isset($BG_SNAP['parts'][$p['id']]) && $BG_SNAP['parts'][$p['id']] === $hash) {
            continue;
        }
        $stmt->execute(array(
            $p['id'],
            $p['no'],
            isset($p['name']) ? $p['name'] : '',
            isset($p['material']) ? $p['material'] : '',
            isset($p['note']) ? $p['note'] : '',
            isset($p['stock']) ? (float)$p['stock'] : 0,
            isset($p['image']) ? $p['image'] : '',
            json_encode(isset($p['processes']) ? $p['processes'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode(isset($p['drawings']) ? $p['drawings'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            isset($p['createdAt']) ? $p['createdAt'] : gmdate('Y-m-d\TH:i:s\Z'),
            isset($p['updatedAt']) ? $p['updatedAt'] : gmdate('Y-m-d\TH:i:s\Z'),
        ));
    }
    $del = $pdo->prepare('DELETE FROM parts WHERE id = ?');
    foreach (array_keys($BG_SNAP['parts']) as $id) {
        if (!isset($seen[$id])) {
            $del->execute(array($id));
        }
    }
}

function bg_sync_orders($pdo, $orders)
{
    global $BG_SNAP;
    $stmt = $pdo->prepare(
        'INSERT INTO orders (id, no, date, part_id, part_no, part_name, process, qty, note, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET
           no = excluded.no, date = excluded.date, part_id = excluded.part_id, part_no = excluded.part_no,
           part_name = excluded.part_name, process = excluded.process, qty = excluded.qty, note = excluded.note,
           updated_at = excluded.updated_at'
    );
    $seen = array();
    foreach ($orders as $o) {
        $seen[$o['id']] = true;
        $hash = md5(json_encode($o));
        if (isset($BG_SNAP['orders'][$o['id']]) && $BG_SNAP['orders'][$o['id']] === $hash) {
            continue;
        }
        $stmt->execute(array(
            $o['id'],
            $o['no'],
            isset($o['date']) ? $o['date'] : '',
            isset($o['partId']) ? $o['partId'] : '',
            isset($o['partNo']) ? $o['partNo'] : '',
            isset($o['partName']) ? $o['partName'] : '',
            isset($o['process']) ? $o['process'] : '',
            isset($o['qty']) ? (float)$o['qty'] : 0,
            isset($o['note']) ? $o['note'] : '',
            isset($o['createdAt']) ? $o['createdAt'] : gmdate('Y-m-d\TH:i:s\Z'),
            isset($o['updatedAt']) ? $o['updatedAt'] : gmdate('Y-m-d\TH:i:s\Z'),
        ));
    }
    $del = $pdo->prepare('DELETE FROM orders WHERE id = ?');
    foreach (array_keys($BG_SNAP['orders']) as $id) {
        if (!isset($seen[$id])) {
            $del->execute(array($id));
        }
    }
}

function bg_sync_reports($pdo, $reports)
{
    global $BG_SNAP;
    $stmt = $pdo->prepare(
        'INSERT INTO reports (id, no, date, worker, workshop, note, order_id, order_no, images, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(id) DO UPDATE SET
           no = excluded.no, date = excluded.date, worker = excluded.worker, workshop = excluded.workshop,
           note = excluded.note, order_id = excluded.order_id, order_no = excluded.order_no,
           images = excluded.images, updated_at = excluded.updated_at'
    );
    $delItems = $pdo->prepare('DELETE FROM report_items WHERE report_id = ?');
    $insItem = $pdo->prepare(
        'INSERT INTO report_items (report_id, part_id, part_no, part_name, process, qty, price, amount, order_id, order_no, sort)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $seen = array();
    foreach ($reports as $r) {
        $seen[$r['id']] = true;
        $hash = md5(json_encode($r));
        if (isset($BG_SNAP['reports'][$r['id']]) && $BG_SNAP['reports'][$r['id']] === $hash) {
            continue;
        }
        $stmt->execute(array(
            $r['id'],
            $r['no'],
            isset($r['date']) ? $r['date'] : '',
            isset($r['worker']) ? $r['worker'] : '',
            isset($r['workshop']) ? $r['workshop'] : '',
            isset($r['note']) ? $r['note'] : '',
            isset($r['orderId']) ? $r['orderId'] : '',
            isset($r['orderNo']) ? $r['orderNo'] : '',
            json_encode(isset($r['images']) ? $r['images'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            isset($r['createdAt']) ? $r['createdAt'] : gmdate('Y-m-d\TH:i:s\Z'),
            isset($r['updatedAt']) ? $r['updatedAt'] : gmdate('Y-m-d\TH:i:s\Z'),
        ));
        $delItems->execute(array($r['id']));
        $items = isset($r['items']) && is_array($r['items']) ? $r['items'] : array();
        $sort = 0;
        foreach ($items as $it) {
            $insItem->execute(array(
                $r['id'],
                isset($it['partId']) ? $it['partId'] : '',
                isset($it['partNo']) ? $it['partNo'] : '',
                isset($it['partName']) ? $it['partName'] : '',
                isset($it['process']) ? $it['process'] : '',
                isset($it['qty']) ? (float)$it['qty'] : 0,
                isset($it['price']) ? (float)$it['price'] : 0,
                isset($it['amount']) ? (float)$it['amount'] : 0,
                isset($it['orderId']) ? $it['orderId'] : '',
                isset($it['orderNo']) ? $it['orderNo'] : '',
                $sort,
            ));
            $sort++;
        }
    }
    $delReport = $pdo->prepare('DELETE FROM reports WHERE id = ?');
    foreach (array_keys($BG_SNAP['reports']) as $id) {
        if (!isset($seen[$id])) {
            $delItems->execute(array($id));
            $delReport->execute(array($id));
        }
    }
}

function bg_db_save($db)
{
    global $BG_SNAP;
    $pdo = bg_pdo();
    if ($BG_SNAP === null) {
        bg_db_load();
    }
    $pdo->beginTransaction();
    try {
        bg_sync_parts($pdo, isset($db['parts']) ? $db['parts'] : array());
        bg_sync_orders($pdo, isset($db['orders']) ? $db['orders'] : array());
        bg_sync_reports($pdo, isset($db['reports']) ? $db['reports'] : array());
        /* 整库替换后序号失效，清空后由下次分配按实际最大单号重新回填 */
        $pdo->exec('DELETE FROM seq');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $BG_SNAP = bg_index_state($db);
}

/* ================================================================ 单行写入
   写接口只动目标行，不再用「全量快照 diff」写回：
   - 并发保存不同记录时不会互相覆盖；
   - 删除后不会被另一请求的旧快照 UPSERT 复活；
   - 更新可带 updated_at 乐观锁，冲突返回 409。
   bg_db_save 仅保留给恢复/导入这类整库替换场景。 */

function bg_json_list($v)
{
    return json_encode(is_array($v) ? $v : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* 是否为唯一键冲突（SQLite 报 UNIQUE constraint failed） */
function bg_unique_error($e)
{
    if (!($e instanceof PDOException)) {
        return false;
    }
    if (stripos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
        return true;
    }
    return (string)$e->getCode() === '23000';
}

/* ---------------- 零件 ---------------- */

function bg_part_insert($pdo, $part)
{
    $stmt = $pdo->prepare(
        'INSERT INTO parts (id, no, name, material, note, stock, image, processes, drawings, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute(array(
        (string)$part['id'],
        (string)$part['no'],
        isset($part['name']) ? (string)$part['name'] : '',
        isset($part['material']) ? (string)$part['material'] : '',
        isset($part['note']) ? (string)$part['note'] : '',
        isset($part['stock']) ? (float)$part['stock'] : 0,
        isset($part['image']) ? (string)$part['image'] : '',
        bg_json_list(isset($part['processes']) ? $part['processes'] : array()),
        bg_json_list(isset($part['drawings']) ? $part['drawings'] : array()),
        isset($part['createdAt']) ? (string)$part['createdAt'] : gmdate('Y-m-d\TH:i:s\Z'),
        isset($part['updatedAt']) ? (string)$part['updatedAt'] : gmdate('Y-m-d\TH:i:s\Z'),
    ));
}

/* 返回受影响行数；带 $expectUpdatedAt 时做乐观锁，0 表示记录已被他人修改 */
function bg_part_update($pdo, $part, $expectUpdatedAt = null)
{
    $params = array(
        (string)$part['no'],
        isset($part['name']) ? (string)$part['name'] : '',
        isset($part['material']) ? (string)$part['material'] : '',
        isset($part['note']) ? (string)$part['note'] : '',
        isset($part['stock']) ? (float)$part['stock'] : 0,
        isset($part['image']) ? (string)$part['image'] : '',
        bg_json_list(isset($part['processes']) ? $part['processes'] : array()),
        bg_json_list(isset($part['drawings']) ? $part['drawings'] : array()),
        isset($part['createdAt']) ? (string)$part['createdAt'] : gmdate('Y-m-d\TH:i:s\Z'),
        isset($part['updatedAt']) ? (string)$part['updatedAt'] : gmdate('Y-m-d\TH:i:s\Z'),
        (string)$part['id'],
    );
    $sql = 'UPDATE parts SET no = ?, name = ?, material = ?, note = ?, stock = ?, image = ?,'
        . ' processes = ?, drawings = ?, created_at = ?, updated_at = ? WHERE id = ?';
    if ($expectUpdatedAt !== null) {
        $sql .= ' AND updated_at = ?';
        $params[] = (string)$expectUpdatedAt;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function bg_part_delete($pdo, $id)
{
    $stmt = $pdo->prepare('DELETE FROM parts WHERE id = ?');
    $stmt->execute(array((string)$id));
    return $stmt->rowCount();
}

/* ---------------- 订单 ---------------- */

function bg_order_insert($pdo, $order)
{
    $stmt = $pdo->prepare(
        'INSERT INTO orders (id, no, date, part_id, part_no, part_name, process, qty, note, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute(array(
        (string)$order['id'],
        (string)$order['no'],
        isset($order['date']) ? (string)$order['date'] : '',
        isset($order['partId']) ? (string)$order['partId'] : '',
        isset($order['partNo']) ? (string)$order['partNo'] : '',
        isset($order['partName']) ? (string)$order['partName'] : '',
        isset($order['process']) ? (string)$order['process'] : '',
        isset($order['qty']) ? (float)$order['qty'] : 0,
        isset($order['note']) ? (string)$order['note'] : '',
        isset($order['createdAt']) ? (string)$order['createdAt'] : gmdate('Y-m-d\TH:i:s\Z'),
        isset($order['updatedAt']) ? (string)$order['updatedAt'] : gmdate('Y-m-d\TH:i:s\Z'),
    ));
}

function bg_order_update($pdo, $order, $expectUpdatedAt = null)
{
    $params = array(
        (string)$order['no'],
        isset($order['date']) ? (string)$order['date'] : '',
        isset($order['partId']) ? (string)$order['partId'] : '',
        isset($order['partNo']) ? (string)$order['partNo'] : '',
        isset($order['partName']) ? (string)$order['partName'] : '',
        isset($order['process']) ? (string)$order['process'] : '',
        isset($order['qty']) ? (float)$order['qty'] : 0,
        isset($order['note']) ? (string)$order['note'] : '',
        isset($order['createdAt']) ? (string)$order['createdAt'] : gmdate('Y-m-d\TH:i:s\Z'),
        isset($order['updatedAt']) ? (string)$order['updatedAt'] : gmdate('Y-m-d\TH:i:s\Z'),
        (string)$order['id'],
    );
    $sql = 'UPDATE orders SET no = ?, date = ?, part_id = ?, part_no = ?, part_name = ?,'
        . ' process = ?, qty = ?, note = ?, created_at = ?, updated_at = ? WHERE id = ?';
    if ($expectUpdatedAt !== null) {
        $sql .= ' AND updated_at = ?';
        $params[] = (string)$expectUpdatedAt;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function bg_order_delete($pdo, $id)
{
    $stmt = $pdo->prepare('DELETE FROM orders WHERE id = ?');
    $stmt->execute(array((string)$id));
    return $stmt->rowCount();
}

/* ---------------- 报工单（含明细） ---------------- */

function bg_report_items_replace($pdo, $reportId, $items)
{
    $del = $pdo->prepare('DELETE FROM report_items WHERE report_id = ?');
    $del->execute(array((string)$reportId));
    $ins = $pdo->prepare(
        'INSERT INTO report_items (report_id, part_id, part_no, part_name, process, qty, price, amount, order_id, order_no, sort)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $sort = 0;
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $ins->execute(array(
            (string)$reportId,
            isset($it['partId']) ? (string)$it['partId'] : '',
            isset($it['partNo']) ? (string)$it['partNo'] : '',
            isset($it['partName']) ? (string)$it['partName'] : '',
            isset($it['process']) ? (string)$it['process'] : '',
            isset($it['qty']) ? (float)$it['qty'] : 0,
            isset($it['price']) ? (float)$it['price'] : 0,
            isset($it['amount']) ? (float)$it['amount'] : 0,
            isset($it['orderId']) ? (string)$it['orderId'] : '',
            isset($it['orderNo']) ? (string)$it['orderNo'] : '',
            $sort,
        ));
        $sort++;
    }
}

function bg_report_insert($pdo, $report)
{
    $stmt = $pdo->prepare(
        'INSERT INTO reports (id, no, date, worker, workshop, note, order_id, order_no, images, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute(array(
        (string)$report['id'],
        (string)$report['no'],
        isset($report['date']) ? (string)$report['date'] : '',
        isset($report['worker']) ? (string)$report['worker'] : '',
        isset($report['workshop']) ? (string)$report['workshop'] : '',
        isset($report['note']) ? (string)$report['note'] : '',
        isset($report['orderId']) ? (string)$report['orderId'] : '',
        isset($report['orderNo']) ? (string)$report['orderNo'] : '',
        bg_json_list(isset($report['images']) ? $report['images'] : array()),
        isset($report['createdAt']) ? (string)$report['createdAt'] : gmdate('Y-m-d\TH:i:s\Z'),
        isset($report['updatedAt']) ? (string)$report['updatedAt'] : gmdate('Y-m-d\TH:i:s\Z'),
    ));
    bg_report_items_replace($pdo, $report['id'], isset($report['items']) ? $report['items'] : array());
}

/* 返回受影响行数；0 表示记录已被他人修改或删除 */
function bg_report_update($pdo, $report, $expectUpdatedAt = null)
{
    $params = array(
        (string)$report['no'],
        isset($report['date']) ? (string)$report['date'] : '',
        isset($report['worker']) ? (string)$report['worker'] : '',
        isset($report['workshop']) ? (string)$report['workshop'] : '',
        isset($report['note']) ? (string)$report['note'] : '',
        isset($report['orderId']) ? (string)$report['orderId'] : '',
        isset($report['orderNo']) ? (string)$report['orderNo'] : '',
        bg_json_list(isset($report['images']) ? $report['images'] : array()),
        isset($report['createdAt']) ? (string)$report['createdAt'] : gmdate('Y-m-d\TH:i:s\Z'),
        isset($report['updatedAt']) ? (string)$report['updatedAt'] : gmdate('Y-m-d\TH:i:s\Z'),
        (string)$report['id'],
    );
    $sql = 'UPDATE reports SET no = ?, date = ?, worker = ?, workshop = ?, note = ?,'
        . ' order_id = ?, order_no = ?, images = ?, created_at = ?, updated_at = ? WHERE id = ?';
    if ($expectUpdatedAt !== null) {
        $sql .= ' AND updated_at = ?';
        $params[] = (string)$expectUpdatedAt;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->rowCount() < 1) {
        return 0;
    }
    bg_report_items_replace($pdo, $report['id'], isset($report['items']) ? $report['items'] : array());
    return 1;
}

function bg_report_delete($pdo, $id)
{
    $delItems = $pdo->prepare('DELETE FROM report_items WHERE report_id = ?');
    $delItems->execute(array((string)$id));
    $stmt = $pdo->prepare('DELETE FROM reports WHERE id = ?');
    $stmt->execute(array((string)$id));
    return $stmt->rowCount();
}

/* ---------------- 单号序列 ---------------- */

/* 在事务内按「前缀」原子分配单号：首次从已有单号回填，之后自增。
   调用前必须已 beginTransaction()，否则读到的是别的请求刚写入的值。 */
function bg_seq_next_no($pdo, $table, $prefix)
{
    if ($table !== 'orders' && $table !== 'reports') {
        throw new RuntimeException('单号序列表名不合法');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO seq (key, value)'
        . ' SELECT ?, COALESCE(MAX(CAST(substr(no, ' . (strlen($prefix) + 1) . ') AS INTEGER)), 0) + 1'
        . ' FROM ' . $table . ' WHERE no LIKE ?'
        . ' ON CONFLICT(key) DO UPDATE SET value = value + 1'
    );
    $stmt->execute(array($prefix, $prefix . '%'));
    $q = $pdo->prepare('SELECT value FROM seq WHERE key = ?');
    $q->execute(array($prefix));
    $n = (int)$q->fetchColumn();
    return $prefix . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
}
