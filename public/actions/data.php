<?php
/* 模块: actions/data
   Action: data.stats data.clear backup.list backup.create backup.restore backup.delete backup.download export.parts export.stats export.csv export.json restore
   职责: 数据量统计、快照备份/恢复/下载、CSV/JSON 导出与导入
   依赖: lib/backup lib/query lib/csv lib/db lib/repo
   注意: 导出走 header+exit，勿改为 bg_json */

function act_data_stats()
{
    bg_json(200, bg_data_stats('sqlite'));
    return;
}

function act_data_clear()
{
    global $APP_PASSWORD, $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $body = bg_body();
    /* 二次验证：已设置密码须重新输入密码，否则须输入固定确认语句 */
    if ($APP_PASSWORD !== '') {
        $pwd = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
        if ($pwd === '' || strlen($pwd) !== strlen($APP_PASSWORD) || !hash_equals($APP_PASSWORD, $pwd)) {
            bg_fail(403, '密码不正确，未清除任何数据');
        }
    } else {
        $phrase = isset($body['phrase']) && is_string($body['phrase']) ? trim($body['phrase']) : '';
        if ($phrase !== '清除全部数据') {
            bg_fail(400, '确认语句不正确，未清除任何数据');
        }
    }
    $counts = bg_snapshot_counts();
    if ($counts['parts'] > 0 || $counts['orders'] > 0 || $counts['reports'] > 0) {
        bg_snapshot_create('pre-clear', '清除全部数据前自动备份');
    }
    $res = bg_data_clear();
    bg_json(200, array(
        'ok' => true,
        'cleared' => $res['cleared'],
        'images' => $res['images'],
        'logs' => isset($res['logs']) ? $res['logs'] : 0,
        'reviews' => isset($res['reviews']) ? $res['reviews'] : 0,
    ));
    return;
}

function act_backup_list()
{
    global $BACKUP_DIR;
    bg_snapshot_prune();
    $meta = bg_snapshot_meta_load();
    $items = array();
    foreach ($meta as $name => $m) {
        $path = $BACKUP_DIR . '/' . $name;
        if (!is_file($path)) {
            continue;
        }
        $m['file'] = $name;
        $m['bytes'] = (int)@filesize($path);
        $items[] = $m;
    }
    usort($items, function ($a, $b) {
        return strcmp((string)$b['createdAt'], (string)$a['createdAt']);
    });
    bg_json(200, array(
        'snapshots' => $items,
        'autoKeep' => 20,
        'manualKeep' => 60,
        'totalBytes' => array_sum(array_map(function ($x) {
            return (int)$x['bytes'];
        }, $items)),
    ));
    return;
}

function act_backup_create()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $body = bg_body();
    $note = isset($body['note']) && is_string($body['note']) ? trim($body['note']) : '';
    $note = function_exists('mb_substr') ? mb_substr($note, 0, 100, 'UTF-8') : substr($note, 0, 100);
    $item = bg_snapshot_create('manual', $note);
    if (!$item) {
        bg_fail(400, '还没有数据库文件，暂时无法创建快照');
    }
    bg_json(200, array('snapshot' => $item));
    return;
}

function act_backup_restore()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $body = bg_body();
    bg_snapshot_restore(isset($body['id']) ? (string)$body['id'] : '');
    bg_json(200, array('ok' => true));
    return;
}

function act_backup_delete()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $body = bg_body();
    $name = isset($body['id']) ? (string)$body['id'] : '';
    $path = bg_snapshot_path($name);
    if ($path === '') {
        bg_fail(404, '快照不存在');
    }
    if (!@unlink($path)) {
        bg_fail(500, '删除失败，请检查 data/snapshots 目录权限');
    }
    $meta = bg_snapshot_meta_load();
    if (isset($meta[$name])) {
        unset($meta[$name]);
        bg_snapshot_meta_save($meta);
    }
    bg_json(200, array('ok' => true));
    return;
}

function act_backup_download()
{
    $name = isset($_GET['id']) ? (string)$_GET['id'] : '';
    $path = bg_snapshot_path($name);
    if ($path === '') {
        bg_fail(404, '快照不存在');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

function act_export_parts()
{
    $pdo = bg_pdo();
    $q = bg_get('q', 100);
    $params = array();
    $where = bg_like_any($params, $q, array('p.no', 'p.name', 'p.material'), 'ep');
    $stmt = $pdo->prepare('SELECT p.* FROM parts p WHERE ' . $where . ' ORDER BY COALESCE(p.updated_at, \'\') DESC, p.id DESC');
    $stmt->execute($params);
    $head = array('零件图号', '零件名称', '材料', '备注');
    for ($i = 1; $i <= 9; $i++) {
        $head[] = '工序' . $i;
        $head[] = '工序说明' . $i;
        $head[] = '单价' . $i;
    }
    $lines = array(implode(',', array_map('bg_csv_cell', $head)));
    foreach ($stmt as $row) {
        $p = bg_part_row($row);
        $ps = isset($p['processes']) && is_array($p['processes']) ? $p['processes'] : array();
        $line = array($p['no'], $p['name'], $p['material'], $p['note']);
        for ($i = 0; $i < 9; $i++) {
            $x = isset($ps[$i]) ? $ps[$i] : null;
            $line[] = $x ? (isset($x['name']) ? $x['name'] : '') : '';
            $line[] = $x ? (isset($x['desc']) ? $x['desc'] : '') : '';
            $line[] = $x ? (isset($x['price']) ? $x['price'] : '') : '';
        }
        $lines[] = implode(',', array_map('bg_csv_cell', $line));
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="parts.csv"; filename*=UTF-8\'\'' . rawurlencode('零件列表_' . date('Y-m-d') . '.csv'));
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    exit;
}

function act_export_stats()
{
    $pdo = bg_pdo();
    $from = bg_get('from', 10);
    $to = bg_get('to', 10);
    $q = bg_get('q', 100);
    $orderIds = bg_ids_param('orderIds');
    $partIds = bg_ids_param('partIds');
    $params = array();
    $where = bg_stats_conds($params, $from, $to, $q, $orderIds, $partIds);
    $stmt = $pdo->prepare(
        'SELECT r.no AS report_no, r.date, COALESCE(NULLIF(ri.order_no, \'\'), r.order_no) AS order_no,'
        . ' o.qty AS order_qty, r.worker, ri.part_no, ri.part_name, ri.process, ri.qty, ri.price, ri.amount'
        . ' FROM reports r JOIN report_items ri ON ri.report_id = r.id'
        . ' LEFT JOIN orders o ON o.id = COALESCE(NULLIF(ri.order_id, \'\'), r.order_id)'
        . ' WHERE ' . $where . ' ORDER BY COALESCE(r.date, \'\') DESC, r.no DESC, ri.sort, ri.id'
    );
    $stmt->execute($params);
    $lines = array('日期,报工单号,订单号,订单数量,报工人,零件图号,零件名称,工序,数量,单价(元),金额(元)');
    foreach ($stmt as $row) {
        $lines[] = implode(',', array_map('bg_csv_cell', array(
            $row['date'],
            $row['report_no'],
            $row['order_no'],
            $row['order_qty'] === null ? '' : $row['order_qty'],
            $row['worker'],
            $row['part_no'],
            $row['part_name'],
            $row['process'],
            $row['qty'],
            $row['price'],
            $row['amount'],
        )));
    }
    $label = ($from !== '' ? $from : '全部') . '_' . ($to !== '' ? $to : '全部');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stats.csv"; filename*=UTF-8\'\'' . rawurlencode('报工统计_' . $label . '.csv'));
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    exit;
}

function act_export_csv()
{
    $pdo = bg_pdo();
    $month = bg_get('month', 7);
    $from = bg_get('from', 10);
    $to = bg_get('to', 10);
    $q = bg_get('q', 100);
    $params = array();
    $w = bg_date_where($params, 'r', $from, $to, 'ed');
    if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $w[] = 'r.date LIKE :emonth';
        $params[':emonth'] = $month . '%';
    }
    $w[] = bg_report_q_cond($params, $q);
    $where = implode(' AND ', $w);
    $stmt = $pdo->prepare(
        'SELECT r.* FROM reports r WHERE ' . $where .
        ' ORDER BY COALESCE(r.date, \'\') DESC, COALESCE(r.created_at, \'\') DESC, r.no DESC'
    );
    $stmt->execute($params);
    $filtered = array();
    $ids = array();
    foreach ($stmt as $row) {
        $images = json_decode(isset($row['images']) ? $row['images'] : '[]', true);
        $filtered[$row['id']] = array(
            'id' => $row['id'],
            'no' => $row['no'],
            'date' => $row['date'],
            'worker' => $row['worker'],
            'workshop' => isset($row['workshop']) ? $row['workshop'] : '',
            'note' => isset($row['note']) ? $row['note'] : '',
            'orderId' => $row['order_id'],
            'orderNo' => $row['order_no'],
            'items' => array(),
        );
        $ids[] = $row['id'];
    }
    $itemsMap = bg_items_for($pdo, $ids);
    $reports = array();
    foreach ($filtered as $id => $r) {
        $r['items'] = isset($itemsMap[$id]) ? $itemsMap[$id] : array();
        $reports[] = $r;
    }
    $label = $month !== '' ? $month : '全部';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="baogong.csv"; filename*=UTF-8\'\'' . rawurlencode('报工明细_' . $label . '.csv'));
    header('Cache-Control: no-store');
    echo bg_csv($reports);
    exit;
}

function act_export_json()
{
    $db = bg_db_load();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="baogong-backup-' . date('Y-m-d') . '.json"');
    header('Cache-Control: no-store');
    echo json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function act_restore()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $body = bg_body();
    $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;
    if (!isset($data['parts']) || !is_array($data['parts']) || !isset($data['reports']) || !is_array($data['reports'])) {
        bg_fail(400, '备份文件格式不正确');
    }
    bg_backup_force('pre-restore', '从 JSON 备份恢复前自动备份');
    $parts = array();
    foreach ($data['parts'] as $p) {
        if (!is_array($p) || !isset($p['id']) || !isset($p['no'])) {
            continue;
        }
        $parts[] = bg_migrate_part($p);
    }
    $reports = array();
    foreach ($data['reports'] as $r) {
        if (!is_array($r) || !isset($r['id'])) {
            continue;
        }
        if (!isset($r['items']) || !is_array($r['items'])) {
            $r['items'] = array();
        }
        $reports[] = bg_migrate_report($r);
    }
    $orders = array();
    if (isset($data['orders']) && is_array($data['orders'])) {
        foreach ($data['orders'] as $o) {
            if (!is_array($o) || !isset($o['id']) || !isset($o['no'])) {
                continue;
            }
            $orders[] = bg_migrate_order($o);
        }
    }
    bg_db_save(array('parts' => $parts, 'reports' => $reports, 'orders' => $orders));
    bg_json(200, array('ok' => true, 'parts' => count($parts), 'reports' => count($reports), 'orders' => count($orders)));
    return;
}
