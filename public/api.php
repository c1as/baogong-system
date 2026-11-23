<?php
/**
 * 报工管理系统 · PHP 后端（群晖 Web Station · SQLite 分支）
 *
 * 数据保存在 data/db/baogong.sqlite，图片在 data/images/{parts,attachments}，快照在 data/snapshots，配置在 app.env。
 * 接口：api.php?action=xxx
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$BG_ROOT = dirname(__DIR__);
require_once __DIR__ . '/lib/net.php';
$BG_THROW = false;

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/repo.php';
require_once __DIR__ . '/lib/backup.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/image.php';
require_once __DIR__ . '/lib/validate.php';
require_once __DIR__ . '/lib/csv.php';
require_once __DIR__ . '/lib/plugin.php';
require_once __DIR__ . '/lib/log.php';
require_once __DIR__ . '/lib/query.php';
require_once __DIR__ . '/lib/match.php';

$BG_CFG = bg_load_env($BG_ROOT . '/app.env');
$APP_PASSWORD = isset($BG_CFG['APP_PASSWORD']) ? $BG_CFG['APP_PASSWORD'] : '';
$DATA_DIR = isset($BG_CFG['DATA_DIR']) && $BG_CFG['DATA_DIR'] !== '' ? $BG_CFG['DATA_DIR'] : $BG_ROOT . '/data';
$DB_DIR = $DATA_DIR . '/db';
$SNAP_DIR = $DATA_DIR . '/snapshots';
$IMG_ROOT = $DATA_DIR . '/images';
$PART_IMG_DIR = $IMG_ROOT . '/parts';
$ATT_IMG_DIR = $IMG_ROOT . '/attachments';
$DRAW_DIR = $IMG_ROOT . '/drawings';
$IMG_DIR = $IMG_ROOT;
$BACKUP_DIR = $SNAP_DIR;
$DB_FILE = $DB_DIR . '/baogong.sqlite';
$SECRET_FILE = $DATA_DIR . '/.secret';
$BG_SNAP = null;
$BG_PDO = null;

foreach (array($DATA_DIR, $DB_DIR, $SNAP_DIR, $PART_IMG_DIR, $ATT_IMG_DIR, $DRAW_DIR) as $bgDir) {
    if (!is_dir($bgDir)) {
        @mkdir($bgDir, 0775, true);
    }
}
$BG_WRITABLE = false;
if (is_dir($DATA_DIR)) {
    $probe = $DATA_DIR . '/.write-test';
    if (@file_put_contents($probe, '1') !== false) {
        $BG_WRITABLE = true;
        @unlink($probe);
    }
}
if (!$BG_WRITABLE) {
    bg_fail(500, '数据目录不可写：' . $DATA_DIR . '。请给 Web Station 的 http 用户读写权限，并确认 PHP 的 open_basedir 未限制该目录。');
}

date_default_timezone_set('Asia/Shanghai');

/* 把旧版扁平目录（db 文件 / backups / images 根目录）迁移到新结构，只执行一次 */
bg_storage_migrate();

set_exception_handler(function ($e) {
    @error_log('[baogong] ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => '服务器内部错误'), JSON_UNESCAPED_UNICODE);
    }
});

/* ================================================================ 路由 */

$action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';

bg_backup_once();

if ($action === 'health') {
    $db = bg_db_load();
    bg_json(200, array(
        'ok' => true,
        'time' => gmdate('c'),
        'parts' => count($db['parts']),
        'reports' => count($db['reports']),
        'orders' => count($db['orders']),
    ));
}

if ($action === 'login') {
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $body = bg_body();
    $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'unknown';

    $fails = bg_throttle_load();
    $rec = isset($fails[$ip]) && is_array($fails[$ip]) ? $fails[$ip] : array('n' => 0, 'until' => 0);
    if (isset($rec['until']) && (int)$rec['until'] > time()) {
        bg_fail(429, '尝试次数过多，请 1 分钟后再试');
    }

    $ok = false;
    if ($APP_PASSWORD !== '' && strlen($password) === strlen($APP_PASSWORD)) {
        $ok = hash_equals($APP_PASSWORD, $password);
    }
    if (!$ok) {
        $rec['n'] = (isset($rec['n']) ? (int)$rec['n'] : 0) + 1;
        if ($rec['n'] >= 5) {
            $rec['until'] = time() + 60;
            $rec['n'] = 0;
        }
        $fails[$ip] = $rec;
        bg_throttle_save($fails);
        bg_fail(401, '密码不正确');
    }
    unset($fails[$ip]);
    bg_throttle_save($fails);
    setcookie('bg_session', bg_token(), array(
        'expires' => time() + 2592000,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    bg_json(200, array('ok' => true));
}

if (!bg_authed()) {
    bg_fail(401, '未登录');
}

switch ($action) {
    case 'data.stats':
        bg_json(200, bg_data_stats('sqlite'));
        break;

    case 'backup.list':
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
        break;

    case 'backup.create':
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
        break;

    case 'backup.restore':
        if ($method !== 'POST') {
            bg_fail(405, '请求方法不支持');
        }
        $body = bg_body();
        bg_snapshot_restore(isset($body['id']) ? (string)$body['id'] : '');
        bg_json(200, array('ok' => true));
        break;

    case 'backup.delete':
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
        break;

    case 'data.clear':
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
        bg_json(200, array('ok' => true, 'cleared' => $res['cleared'], 'images' => $res['images']));
        break;

    case 'backup.download':
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

    case 'state':
        $db = bg_db_load();
        bg_json(200, array(
            'parts' => $db['parts'],
            'reports' => bg_sorted($db['reports']),
            'orders' => bg_sorted($db['orders']),
            'auth' => $APP_PASSWORD !== '',
            'imageBase' => 'api.php?action=image&f=',
        ));
        break;

    case 'bootstrap':
        $pdo = bg_pdo();
        $first = (string)$pdo->query('SELECT COALESCE(MIN(date), \'\') FROM reports')->fetchColumn();
        bg_json(200, array(
            'auth' => $APP_PASSWORD !== '',
            'imageBase' => 'api.php?action=image&f=',
            'counts' => bg_bootstrap_counts($pdo),
            'firstDate' => $first,
            'version' => '2.7',
        ));
        break;

    case 'parts.list':
        $pdo = bg_pdo();
        $q = bg_get('q', 100);
        list($page, $size, $offset) = bg_page();
        $params = array();
        $where = bg_like_any($params, $q, array('p.no', 'p.name', 'p.material'), 'pl');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM parts p WHERE ' . $where);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT p.* FROM parts p WHERE ' . $where . ' ORDER BY COALESCE(p.updated_at, \'\') DESC, p.id DESC LIMIT ' . $size . ' OFFSET ' . $offset);
        $stmt->execute($params);
        $items = array();
        $ids = array();
        foreach ($stmt as $row) {
            $items[] = bg_part_row($row);
            $ids[] = $row['id'];
        }
        $usage = bg_parts_usage($pdo, $ids);
        foreach ($items as $i => $it) {
            $items[$i]['usage'] = isset($usage[$it['id']]) ? $usage[$it['id']] : array('qty' => 0, 'times' => 0);
        }
        bg_json(200, array(
            'ok' => true,
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $size,
            'hasMore' => $offset + count($items) < $total,
        ));
        break;

    case 'parts.search':
        $pdo = bg_pdo();
        $q = bg_get('q', 100);
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 50) {
            $limit = 50;
        }
        $params = array();
        $where = bg_like_any($params, $q, array('p.no', 'p.name', 'p.material'), 'ps');
        $stmt = $pdo->prepare('SELECT p.* FROM parts p WHERE ' . $where . ' ORDER BY COALESCE(p.updated_at, \'\') DESC, p.id DESC LIMIT ' . $limit);
        $stmt->execute($params);
        $items = array();
        foreach ($stmt as $row) {
            $items[] = bg_part_row($row);
        }
        bg_json(200, array('ok' => true, 'items' => $items));
        break;

    case 'parts.get':
        $pdo = bg_pdo();
        $ids = bg_ids_param('ids');
        $id = bg_get('id', 60);
        if (!count($ids) && $id !== '') {
            $ids = array($id);
        }
        if (!count($ids)) {
            bg_fail(400, '缺少零件 id');
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare('SELECT * FROM parts WHERE id IN (' . $ph . ')');
        $stmt->execute($ids);
        $items = array();
        $got = array();
        foreach ($stmt as $row) {
            $items[] = bg_part_row($row);
            $got[] = $row['id'];
        }
        $usage = bg_parts_usage($pdo, $got);
        foreach ($items as $i => $it) {
            $items[$i]['usage'] = isset($usage[$it['id']]) ? $usage[$it['id']] : array('qty' => 0, 'times' => 0);
        }
        if (isset($_GET['ids'])) {
            bg_json(200, array('items' => $items));
        }
        if (!count($items)) {
            bg_fail(404, '零件不存在');
        }
        bg_json(200, array('part' => $items[0]));
        break;

    case 'parts.match':
        $pdo = bg_pdo();
        $parts = bg_all_parts_for_match($pdo);
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
        if ($limit < 1) {
            $limit = 5;
        }
        if ($limit > 20) {
            $limit = 20;
        }
        if ($method === 'POST') {
            $body = bg_body();
            $matches = array();
            if (isset($body['queries']) && is_array($body['queries'])) {
                foreach ($body['queries'] as $qq) {
                    $no = is_array($qq) && isset($qq['no']) && is_scalar($qq['no']) ? trim((string)$qq['no']) : '';
                    $name = is_array($qq) && isset($qq['name']) && is_scalar($qq['name']) ? trim((string)$qq['name']) : '';
                    $hit = bg_match_part($parts, $no, $name);
                    if (bg_log_match_on()) {
                        bg_log_match($no, $name, $hit, bg_fuzzy_candidates($parts, trim($no . ' ' . $name), 3));
                    }
                    $matches[] = $hit;
                }
            } elseif (isset($body['texts']) && is_array($body['texts'])) {
                foreach ($body['texts'] as $t) {
                    $t = is_scalar($t) ? trim((string)$t) : '';
                    $fz = bg_fuzzy_candidates($parts, $t, 1);
                    $matches[] = count($fz) ? $fz[0] : null;
                }
            }
            bg_json(200, array('ok' => true, 'matches' => $matches));
        }
        $no = bg_get('no', 100);
        $name = bg_get('name', 100);
        $text = bg_get('text', 300);
        if ($no !== '' || $name !== '') {
            bg_json(200, array(
                'ok' => true,
                'match' => bg_match_part($parts, $no, $name),
                'candidates' => bg_fuzzy_candidates($parts, trim($no . ' ' . $name), $limit),
            ));
        }
        bg_json(200, array(
            'ok' => true,
            'match' => null,
            'candidates' => bg_fuzzy_candidates($parts, $text, $limit),
        ));
        break;

    case 'orders.list':
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
        break;

    case 'orders.search':
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
        break;

    case 'orders.get':
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
        break;

    case 'reports.list':
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
        break;

    case 'reports.get':
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
        break;

    case 'stats.list':
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
        break;

    case 'stats.overview':
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
        break;

    case 'export.parts':
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

    case 'export.stats':
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

    case 'plugins.list':
        $list = array();
        foreach (bg_plugin_all() as $m) {
            $list[] = bg_plugin_public($m);
        }
        bg_json(200, array('plugins' => $list, 'dir' => 'public/plugins/'));
        break;

    case 'plugin.save':
        if ($method !== 'POST') {
            bg_fail(405, '请求方法不支持');
        }
        $m = bg_plugin_one(isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '');
        $body = bg_body();
        $state = bg_plugin_state_load();
        $prev = isset($state[$m['id']]) && is_array($state[$m['id']]) ? $state[$m['id']] : array();
        $next = array(
            'enabled' => isset($body['enabled']) ? (bool)$body['enabled'] : bg_plugin_is_enabled($m['id']),
            'settings' => isset($prev['settings']) && is_array($prev['settings']) ? $prev['settings'] : array(),
        );
        $input = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : array();
        foreach ($m['settings'] as $f) {
            if (!array_key_exists($f['key'], $input)) {
                continue;
            }
            $v = $input[$f['key']];
            if ($f['type'] === 'password') {
                if (!is_string($v)) {
                    continue;
                }
                $v = trim($v);
                if ($v === '********') {
                    continue;
                }
                if (strlen($v) > 500) {
                    bg_fail(400, $f['label'] . '最多 500 个字符');
                }
                $next['settings'][$f['key']] = $v;
            } elseif ($f['type'] === 'checkbox') {
                $next['settings'][$f['key']] = $v ? '1' : '';
            } elseif ($f['type'] === 'number') {
                if ($v === '' || $v === null) {
                    $next['settings'][$f['key']] = '';
                } elseif (!is_numeric($v)) {
                    bg_fail(400, $f['label'] . '必须是数字');
                } else {
                    $num = (float)$v;
                    if (isset($f['min']) && $num < (float)$f['min']) {
                        bg_fail(400, $f['label'] . '不能小于 ' . $f['min']);
                    }
                    if (isset($f['max']) && $num > (float)$f['max']) {
                        bg_fail(400, $f['label'] . '不能大于 ' . $f['max']);
                    }
                    $next['settings'][$f['key']] = (string)$num;
                }
            } elseif ($f['type'] === 'multiselect') {
                $arr = is_array($v) ? $v : preg_split('/[\s,，、;；]+/u', is_scalar($v) ? (string)$v : '');
                $allowed = array();
                foreach (bg_plugin_all() as $p) {
                    if ($p['id'] === $m['id'] || !in_array('report.ocr', $p['capabilities'], true)) {
                        continue;
                    }
                    $allowed[$p['id']] = true;
                }
                $picked = array();
                foreach ($arr as $x) {
                    if (is_string($x) && isset($allowed[$x]) && !in_array($x, $picked, true)) {
                        $picked[] = $x;
                    }
                }
                $next['settings'][$f['key']] = implode(',', $picked);
            } else {
                $s = is_scalar($v) ? trim((string)$v) : '';
                $limit = $f['type'] === 'textarea' ? 2000 : 500;
                if (bg_len($s) > $limit) {
                    bg_fail(400, $f['label'] . '最多 ' . $limit . ' 个字符');
                }
                $next['settings'][$f['key']] = $s;
            }
        }
        $state[$m['id']] = $next;
        bg_plugin_state_save($state);
        bg_json(200, array('plugin' => bg_plugin_public(bg_plugin_one($m['id']))));
        break;

    case 'plugin.invoke':
        if ($method !== 'POST') {
            bg_fail(405, '请求方法不支持');
        }
        $body = bg_body();
        $image = isset($body['image']) && is_string($body['image']) ? $body['image'] : '';
        $res = bg_plugin_invoke(isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '', $image);
        bg_json(200, $res);
        break;

    case 'plugin.check':
        $m = bg_plugin_one(isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '');
        $res = bg_plugin_check($m['id']);
        $res['plugin'] = array('id' => $m['id'], 'name' => $m['name'], 'version' => $m['version']);
        bg_json(200, $res);
        break;

    case 'net.check':
        bg_json(200, bg_net_check());
        break;

    case 'logout':
        setcookie('bg_session', '', array(
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ));
        bg_json(200, array('ok' => true));
        break;

    case 'part.create':
        if ($method !== 'POST') {
            bg_fail(405, '请求方法不支持');
        }
        $body = bg_body();
        $in = isset($body['part']) && is_array($body['part']) ? $body['part'] : $body;
        $db = bg_db_load();
        $part = bg_normalize_part($in, null);
        foreach ($db['parts'] as $p) {
            if (isset($p['no']) && $p['no'] === $part['no']) {
                bg_fail(400, '零件图号「' . $part['no'] . '」已存在');
            }
        }
        if (isset($in['image']) && is_string($in['image']) && strpos($in['image'], 'data:') === 0) {
            $part['image'] = bg_image_save('parts', bg_part_image_base($part), $in['image'], $part['id']);
        }
        $newDrawings = isset($body['newDrawings']) && is_array($body['newDrawings']) ? array_slice($body['newDrawings'], 0, 20) : array();
        foreach ($newDrawings as $nd) {
            $part['drawings'][] = bg_drawing_save($part, isset($nd['data']) ? $nd['data'] : '');
        }
        $db['parts'][] = $part;
        bg_db_save($db);
        bg_json(200, array('part' => $part));
        break;

    case 'part.update':
        if ($method !== 'POST') {
            bg_fail(405, '请求方法不支持');
        }
        $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
        $body = bg_body();
        $in = isset($body['part']) && is_array($body['part']) ? $body['part'] : $body;
        $db = bg_db_load();
        $target = -1;
        foreach ($db['parts'] as $i => $p) {
            if (isset($p['id']) && $p['id'] === $id) {
                $target = $i;
                break;
            }
        }
        if ($target < 0) {
            bg_fail(404, '零件不存在');
        }
        $existing = $db['parts'][$target];
        $part = bg_normalize_part($in, $existing);
        foreach ($db['parts'] as $p) {
            if (isset($p['no']) && $p['no'] === $part['no'] && $p['id'] !== $existing['id']) {
                bg_fail(400, '零件图号「' . $part['no'] . '」已存在');
            }
        }
        $part['image'] = isset($existing['image']) ? bg_image_name($existing['image']) : '';
        if (isset($in['image']) && is_string($in['image']) && strpos($in['image'], 'data:') === 0) {
            bg_image_remove(isset($existing['image']) ? $existing['image'] : '');
            $part['image'] = bg_image_save('parts', bg_part_image_base($part), $in['image'], $part['id']);
        } elseif (!empty($in['removeImage'])) {
            bg_image_remove(isset($existing['image']) ? $existing['image'] : '');
            $part['image'] = '';
        } elseif ($part['image'] !== '') {
            $part['image'] = bg_part_image_rename($part, $part['image']);
        }
        /* 图纸：删除被移除的物理文件，保存新增图纸，零件改名时同步文件名 */
        $oldDrawings = isset($existing['drawings']) && is_array($existing['drawings']) ? $existing['drawings'] : array();
        $keptPaths = array();
        foreach ($part['drawings'] as $d) {
            $keptPaths[] = $d['path'];
        }
        foreach ($oldDrawings as $od) {
            $oldPath = bg_image_name(isset($od['path']) ? $od['path'] : '');
            if ($oldPath !== '' && !in_array($oldPath, $keptPaths, true)) {
                bg_image_remove($oldPath);
            }
        }
        $newDrawings = isset($body['newDrawings']) && is_array($body['newDrawings']) ? array_slice($body['newDrawings'], 0, 20) : array();
        foreach ($newDrawings as $nd) {
            $part['drawings'][] = bg_drawing_save($part, isset($nd['data']) ? $nd['data'] : '');
        }
        $oldName = isset($existing['name']) ? (string)$existing['name'] : '';
        if ($oldName !== (string)$part['name'] || (isset($existing['no']) ? $existing['no'] : '') !== $part['no']) {
            $part['drawings'] = bg_drawings_rename($part['drawings'], $part);
        }
        $db['parts'][$target] = $part;
        bg_db_save($db);
        bg_json(200, array('part' => $part));
        break;

    case 'part.delete':
        $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
        $db = bg_db_load();
        $target = -1;
        foreach ($db['parts'] as $i => $p) {
            if (isset($p['id']) && $p['id'] === $id) {
                $target = $i;
                break;
            }
        }
        if ($target < 0) {
            bg_fail(404, '零件不存在');
        }
        bg_image_remove(isset($db['parts'][$target]['image']) ? $db['parts'][$target]['image'] : '');
        $drawings = isset($db['parts'][$target]['drawings']) && is_array($db['parts'][$target]['drawings']) ? $db['parts'][$target]['drawings'] : array();
        foreach ($drawings as $d) {
            bg_image_remove(isset($d['path']) ? $d['path'] : '');
        }
        array_splice($db['parts'], $target, 1);
        bg_db_save($db);
        bg_json(200, array('ok' => true));
        break;

    case 'part.drawing.remove':
        if ($method !== 'POST') {
            bg_fail(405, '请求方法不支持');
        }
        $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
        $body = bg_body();
        $path = bg_image_name(isset($body['path']) ? $body['path'] : '');
        if ($path === '' || strpos($path, 'drawings/') !== 0) {
            bg_fail(400, '图纸路径不正确');
        }
        $db = bg_db_load();
        $target = -1;
        foreach ($db['parts'] as $i => $p) {
            if (isset($p['id']) && $p['id'] === $id) {
                $target = $i;
                break;
            }
        }
        if ($target < 0) {
            bg_fail(404, '零件不存在');
        }
        $left = array();
        $found = false;
        foreach ($db['parts'][$target]['drawings'] as $d) {
            if (isset($d['path']) && $d['path'] === $path) {
                $found = true;
                continue;
            }
            $left[] = $d;
        }
        if (!$found) {
            bg_fail(404, '图纸不存在');
        }
        bg_image_remove($path);
        $db['parts'][$target]['drawings'] = $left;
        $db['parts'][$target]['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        bg_db_save($db);
        bg_json(200, array('part' => $db['parts'][$target]));
        break;

    case 'part.stock':
        if ($method !== 'POST') {
            bg_fail(405, '请求方法不支持');
        }
        $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
        $body = bg_body();
        $db = bg_db_load();
        $target = -1;
        foreach ($db['parts'] as $i => $p) {
            if (isset($p['id']) && $p['id'] === $id) {
                $target = $i;
                break;
            }
        }
        if ($target < 0) {
            bg_fail(404, '零件不存在');
        }
        $stock = bg_round(bg_num(isset($body['stock']) ? $body['stock'] : 0, '库存数量', 0, 1000000000));
        $db['parts'][$target]['stock'] = $stock;
        $db['parts'][$target]['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        bg_db_save($db);
        bg_json(200, array('part' => $db['parts'][$target]));
        break;

    case 'part.import':
        if ($method !== 'POST') {
            bg_fail(405, '请求方法不支持');
        }
        $body = bg_body();
        $rows = isset($body['parts']) && is_array($body['parts']) ? $body['parts'] : array();
        if (count($rows) === 0) {
            bg_fail(400, '没有可导入的数据');
        }
        if (count($rows) > 500) {
            bg_fail(400, '一次最多导入 500 个零件');
        }
        $db = bg_db_load();
        $added = 0;
        $updated = 0;
        $errors = array();
        $BG_THROW = true;
        try {
            $i = 0;
            foreach ($rows as $row) {
                $i++;
                try {
                    $row = is_array($row) ? $row : array();
                    $no = isset($row['no']) ? trim((string)$row['no']) : '';
                    $existingIndex = -1;
                    foreach ($db['parts'] as $pi => $p) {
                        if (isset($p['no']) && $p['no'] === $no) {
                            $existingIndex = $pi;
                            break;
                        }
                    }
                    $existing = $existingIndex >= 0 ? $db['parts'][$existingIndex] : null;
                    $part = bg_normalize_part($row, $existing);
                    if ($existingIndex >= 0) {
                        $db['parts'][$existingIndex] = $part;
                        $updated++;
                    } else {
                        $db['parts'][] = $part;
                        $added++;
                    }
                } catch (RuntimeException $e) {
                    if (count($errors) < 20) {
                        $errors[] = '第 ' . $i . ' 行：' . $e->getMessage();
                    }
                }
            }
            bg_db_save($db);
        } finally {
            $BG_THROW = false;
        }
        bg_json(200, array('added' => $added, 'updated' => $updated, 'errors' => $errors));
        break;

    case 'order.create':
        if ($method !== 'POST') {
            bg_fail(405, '请求方法不支持');
        }
        $body = bg_body();
        $in = isset($body['order']) && is_array($body['order']) ? $body['order'] : $body;
        $db = bg_db_load();
        $order = bg_normalize_order($in, null, $db['parts'], $db['orders']);
        $db['orders'][] = $order;
        bg_db_save($db);
        bg_json(200, array('order' => $order));
        break;

    case 'order.update':
        if ($method !== 'POST') {
            bg_fail(405, '请求方法不支持');
        }
        $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
        $body = bg_body();
        $in = isset($body['order']) && is_array($body['order']) ? $body['order'] : $body;
        $db = bg_db_load();
        $target = -1;
        foreach ($db['orders'] as $i => $o) {
            if (isset($o['id']) && $o['id'] === $id) {
                $target = $i;
                break;
            }
        }
        if ($target < 0) {
            bg_fail(404, '订单不存在');
        }
        $order = bg_normalize_order($in, $db['orders'][$target], $db['parts'], $db['orders']);
        $db['orders'][$target] = $order;
        bg_db_save($db);
        bg_json(200, array('order' => $order));
        break;

    case 'order.delete':
        $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
        $db = bg_db_load();
        $target = -1;
        foreach ($db['orders'] as $i => $o) {
            if (isset($o['id']) && $o['id'] === $id) {
                $target = $i;
                break;
            }
        }
        if ($target < 0) {
            bg_fail(404, '订单不存在');
        }
        array_splice($db['orders'], $target, 1);
        bg_db_save($db);
        bg_json(200, array('ok' => true));
        break;

    case 'report.create':
        if ($method !== 'POST') {
            bg_fail(405, '请求方法不支持');
        }
        $body = bg_body();
        $in = isset($body['report']) && is_array($body['report']) ? $body['report'] : $body;
        $db = bg_db_load();
        $report = bg_normalize_report($in, null, $db);
        foreach ($report['items'] as $ii => $item) {
            if ($item['orderId'] === '') {
                $order = bg_auto_order_for_item($report, $item, $db['orders']);
                $db['orders'][] = $order;
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
        $db['reports'][] = $report;
        bg_db_save($db);
        bg_json(200, array('report' => $report));
        break;

    case 'report.update':
        if ($method !== 'POST') {
            bg_fail(405, '请求方法不支持');
        }
        $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
        $body = bg_body();
        $in = isset($body['report']) && is_array($body['report']) ? $body['report'] : $body;
        $db = bg_db_load();
        $target = -1;
        foreach ($db['reports'] as $i => $r) {
            if (isset($r['id']) && $r['id'] === $id) {
                $target = $i;
                break;
            }
        }
        if ($target < 0) {
            bg_fail(404, '报工单不存在');
        }
        $existing = $db['reports'][$target];
        $report = bg_normalize_report($in, $existing, $db);
        foreach ($report['items'] as $ii => $item) {
            if ($item['orderId'] === '') {
                $order = bg_auto_order_for_item($report, $item, $db['orders']);
                $db['orders'][] = $order;
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
        foreach ($allowed as $f) {
            if (!in_array($f, $kept, true)) {
                bg_image_remove($f);
            }
        }
        $newImages = isset($body['newImages']) && is_array($body['newImages']) ? array_slice($body['newImages'], 0, 6) : array();
        foreach ($newImages as $dataUrl) {
            if (is_string($dataUrl) && strpos($dataUrl, 'data:') === 0) {
                $kept[] = bg_image_save('attachments', bg_attach_base(), $dataUrl);
            }
        }
        $report['images'] = $kept;
        $db['reports'][$target] = $report;
        bg_db_save($db);
        bg_json(200, array('report' => $report));
        break;

    case 'report.delete':
        $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
        $db = bg_db_load();
        $target = -1;
        foreach ($db['reports'] as $i => $r) {
            if (isset($r['id']) && $r['id'] === $id) {
                $target = $i;
                break;
            }
        }
        if ($target < 0) {
            bg_fail(404, '报工单不存在');
        }
        $imgs = isset($db['reports'][$target]['images']) && is_array($db['reports'][$target]['images']) ? $db['reports'][$target]['images'] : array();
        foreach ($imgs as $f) {
            bg_image_remove($f);
        }
        array_splice($db['reports'], $target, 1);
        bg_db_save($db);
        bg_json(200, array('ok' => true));
        break;

    case 'export.csv':
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

    case 'export.json':
        $db = bg_db_load();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="baogong-backup-' . date('Y-m-d') . '.json"');
        header('Cache-Control: no-store');
        echo json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;

    case 'restore':
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
        break;

    case 'image':
        $f = isset($_GET['f']) && is_string($_GET['f']) ? $_GET['f'] : '';
        $path = bg_image_path($f);
        if ($path === '') {
            bg_fail(404, '图片不存在');
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $types = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf');
        $ctype = isset($types[$ext]) ? $types[$ext] : 'application/octet-stream';
        $size = filesize($path);
        header('Content-Type: ' . $ctype);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=31536000, immutable');
        if ($ext === 'pdf') {
            header('Content-Disposition: inline; filename="' . rawurlencode(basename($path)) . '"');
            $range = isset($_SERVER['HTTP_RANGE']) ? (string)$_SERVER['HTTP_RANGE'] : '';
            if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
                $start = 0;
                $end = $size - 1;
                if ($m[1] === '' && $m[2] !== '') {
                    $start = max(0, $size - (int)$m[2]);
                } else {
                    $start = (int)$m[1];
                    if ($m[2] !== '') {
                        $end = min((int)$m[2], $size - 1);
                    }
                }
                if ($start > $end || $start >= $size) {
                    header('HTTP/1.1 416 Range Not Satisfiable');
                    header('Content-Range: bytes */' . $size);
                    exit;
                }
                header('HTTP/1.1 206 Partial Content');
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
                header('Content-Length: ' . ($end - $start + 1));
                $fp = fopen($path, 'rb');
                if ($fp) {
                    fseek($fp, $start);
                    $left = $end - $start + 1;
                    while ($left > 0 && !feof($fp)) {
                        $chunk = fread($fp, min(65536, $left));
                        if ($chunk === false) {
                            break;
                        }
                        echo $chunk;
                        $left -= strlen($chunk);
                    }
                    fclose($fp);
                }
                exit;
            }
        }
        header('Content-Length: ' . $size);
        readfile($path);
        exit;

    default:
        bg_fail(404, '接口不存在');
}
