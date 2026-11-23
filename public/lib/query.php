<?php
/* 模块: lib/query
   职责: 分页、筛选条件拼装、行映射、用量统计
   依赖: lib/db
   注意: LIKE 参数用位置占位符避免重名 */

function bg_sorted($list)
{
    usort($list, function ($a, $b) {
        $da = isset($a['date']) ? (string)$a['date'] : '';
        $db = isset($b['date']) ? (string)$b['date'] : '';
        $cmp = strcmp($db, $da);
        if ($cmp !== 0) {
            return $cmp;
        }
        $ca = isset($a['createdAt']) ? (string)$a['createdAt'] : '';
        $cb = isset($b['createdAt']) ? (string)$b['createdAt'] : '';
        return strcmp($cb, $ca);
    });
    return $list;
}

function bg_fs_usage($dir, $pattern = '')
{
    $out = array('count' => 0, 'bytes' => 0);
    if (!is_dir($dir)) {
        return $out;
    }
    if ($pattern !== '') {
        $files = glob($dir . '/' . $pattern);
        if (!is_array($files)) {
            return $out;
        }
        foreach ($files as $f) {
            if (is_file($f)) {
                $out['count']++;
                $out['bytes'] += (int)@filesize($f);
            }
        }
        return $out;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return $out;
    }
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $f = $dir . '/' . $name;
        if (is_file($f)) {
            $out['count']++;
            $out['bytes'] += (int)@filesize($f);
        }
    }
    return $out;
}

function bg_data_stats($storage)
{
    global $DB_FILE, $PART_IMG_DIR, $ATT_IMG_DIR, $BACKUP_DIR, $DATA_DIR;
    $dbBytes = 0;
    foreach (array($DB_FILE, $DB_FILE . '-wal', $DB_FILE . '-shm') as $f) {
        if (is_file($f)) {
            $dbBytes += (int)@filesize($f);
        }
    }
    $images = array('count' => 0, 'bytes' => 0);
    foreach (array($PART_IMG_DIR, $ATT_IMG_DIR) as $dir) {
        $u = bg_fs_usage($dir, '');
        $images['count'] += $u['count'];
        $images['bytes'] += $u['bytes'];
    }
    $backups = bg_fs_usage($BACKUP_DIR, $storage === 'sqlite' ? 'db-*.sqlite' : 'db-*.json');
    return array(
        'storage' => $storage,
        'dbFile' => basename($DB_FILE),
        'dbBytes' => $dbBytes,
        'dataDir' => $DATA_DIR,
        'images' => $images,
        'backups' => $backups,
        'totalBytes' => $dbBytes + $images['bytes'] + $backups['bytes'],
    );
}

/* ================================================================ 只读分页接口 */

function bg_get($key, $max = 200)
{
    $v = isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : '';
    if ($v !== '' && bg_len($v) > $max) {
        $v = function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
    }
    return $v;
}

function bg_page()
{
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $size = isset($_GET['pageSize']) ? (int)$_GET['pageSize'] : 50;
    if ($page < 1) {
        $page = 1;
    }
    if ($size < 1) {
        $size = 50;
    }
    if ($size > 200) {
        $size = 200;
    }
    return array($page, $size, ($page - 1) * $size);
}

function bg_like($q)
{
    return '%' . str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), (string)$q) . '%';
}

/* 多个字段 LIKE 关键词，参数以 position 自增命名，避免重复使用同名占位符 */
function bg_like_any(&$params, $q, $cols, $prefix)
{
    if ($q === '') {
        return '1=1';
    }
    $like = bg_like($q);
    $parts = array();
    foreach ($cols as $i => $col) {
        $k = ':' . $prefix . $i;
        $params[$k] = $like;
        $parts[] = $col . " LIKE " . $k . " ESCAPE '!'";
    }
    return '(' . implode(' OR ', $parts) . ')';
}

function bg_date_where(&$params, $alias, $from, $to, $prefix = 'd')
{
    $parts = array();
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $k = ':' . $prefix . '0';
        $params[$k] = $from;
        $parts[] = $alias . '.date >= ' . $k;
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $k = ':' . $prefix . '1';
        $params[$k] = $to;
        $parts[] = $alias . '.date <= ' . $k;
    }
    return $parts;
}

/* 报工单关键词条件：单号 / 人员 / 订单号 / 明细里的图号、名称、订单号 */
function bg_report_q_cond(&$params, $q)
{
    if ($q === '') {
        return '1=1';
    }
    $cols = array('r.no', 'r.worker', 'r.order_no');
    $parts = array();
    foreach ($cols as $i => $col) {
        $k = ':rq' . $i;
        $params[$k] = bg_like($q);
        $parts[] = $col . " LIKE " . $k . " ESCAPE '!'";
    }
    $itemParts = array();
    foreach (array('ri2.part_no', 'ri2.part_name', 'ri2.order_no') as $i => $col) {
        $k = ':riq' . $i;
        $params[$k] = bg_like($q);
        $itemParts[] = $col . " LIKE " . $k . " ESCAPE '!'";
    }
    $parts[] = 'EXISTS (SELECT 1 FROM report_items ri2 WHERE ri2.report_id = r.id AND (' . implode(' OR ', $itemParts) . '))';
    return '(' . implode(' OR ', $parts) . ')';
}

/* 统计条件：日期 + 关键词 + 订单/零件多选 */
function bg_stats_conds(&$params, $from, $to, $q, $orderIds, $partIds)
{
    $w = bg_date_where($params, 'r', $from, $to, 'sd');
    if ($q !== '') {
        $cols = array('r.no', 'r.worker', 'r.order_no', 'ri.order_no', 'ri.part_no', 'ri.part_name', 'ri.process');
        $parts = array();
        foreach ($cols as $i => $col) {
            $k = ':sq' . $i;
            $params[$k] = bg_like($q);
            $parts[] = $col . " LIKE " . $k . " ESCAPE '!'";
        }
        $w[] = '(' . implode(' OR ', $parts) . ')';
    }
    if (count($orderIds)) {
        $ph = array();
        foreach ($orderIds as $i => $id) {
            $k = ':so' . $i;
            $params[$k] = $id;
            $ph[] = $k;
        }
        $w[] = "COALESCE(NULLIF(ri.order_id, ''), r.order_id) IN (" . implode(',', $ph) . ')';
    }
    if (count($partIds)) {
        $ph = array();
        foreach ($partIds as $i => $id) {
            $k = ':sp' . $i;
            $params[$k] = $id;
            $ph[] = $k;
        }
        $w[] = 'ri.part_id IN (' . implode(',', $ph) . ')';
    }
    return count($w) ? implode(' AND ', $w) : '1=1';
}

function bg_ids_param($key)
{
    $raw = bg_get($key, 8000);
    if ($raw === '') {
        return array();
    }
    $out = array();
    foreach (explode(',', $raw) as $id) {
        $id = trim($id);
        if ($id !== '' && bg_len($id) <= 60) {
            $out[] = $id;
        }
        if (count($out) >= 200) {
            break;
        }
    }
    return $out;
}

function bg_part_row($row)
{
    $processes = json_decode(isset($row['processes']) ? $row['processes'] : '[]', true);
    if (!is_array($processes)) {
        $processes = array();
    }
    $drawings = json_decode(isset($row['drawings']) ? $row['drawings'] : '[]', true);
    if (!is_array($drawings)) {
        $drawings = array();
    }
    return array(
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

function bg_order_row($row)
{
    return array(
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

function bg_item_row($row)
{
    return array(
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

/* 一次取回多张报工单的明细，避免 JOIN 放大导致分页错位 */
function bg_items_for($pdo, $ids)
{
    $out = array();
    if (!count($ids)) {
        return $out;
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare('SELECT * FROM report_items WHERE report_id IN (' . $ph . ') ORDER BY report_id, sort, id');
    $stmt->execute($ids);
    foreach ($stmt as $row) {
        $out[$row['report_id']][] = bg_item_row($row);
    }
    return $out;
}

function bg_parts_usage($pdo, $ids)
{
    $out = array();
    if (!count($ids)) {
        return $out;
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        'SELECT part_id, COALESCE(SUM(qty), 0) AS qty, COUNT(*) AS times
         FROM report_items WHERE part_id IN (' . $ph . ') GROUP BY part_id'
    );
    $stmt->execute($ids);
    foreach ($stmt as $row) {
        $out[$row['part_id']] = array('qty' => (float)$row['qty'], 'times' => (int)$row['times']);
    }
    return $out;
}

/* 订单已报量：旧数据明细没写 order_id 时归到报工单表头订单 */
function bg_orders_usage($pdo, $ids, $excludeReportId = '')
{
    $out = array();
    if (!count($ids)) {
        return $out;
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $sql = 'SELECT oid, SUM(qty) AS qty, SUM(amount) AS amount FROM (
              SELECT COALESCE(NULLIF(ri.order_id, \'\'), r.order_id) AS oid, ri.qty AS qty, ri.amount AS amount
              FROM report_items ri JOIN reports r ON r.id = ri.report_id
              WHERE COALESCE(NULLIF(ri.order_id, \'\'), r.order_id) IN (' . $ph . ')';
    if ($excludeReportId !== '') {
        $sql .= ' AND r.id <> ?';
        $params[] = $excludeReportId;
    }
    $sql .= ') GROUP BY oid';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt as $row) {
        $out[$row['oid']] = array('qty' => (float)$row['qty'], 'amount' => (float)$row['amount']);
    }
    return $out;
}

function bg_bootstrap_counts($pdo)
{
    return array(
        'parts' => (int)$pdo->query('SELECT COUNT(*) FROM parts')->fetchColumn(),
        'orders' => (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
        'reports' => (int)$pdo->query('SELECT COUNT(*) FROM reports')->fetchColumn(),
        'items' => (int)$pdo->query('SELECT COUNT(*) FROM report_items')->fetchColumn(),
    );
}
