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

/* ================================================================ 输出工具 */

function bg_json($status, $data)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bg_fail($status, $message)
{
    global $BG_THROW;
    if ($BG_THROW) {
        throw new RuntimeException($message);
    }
    bg_json($status, array('error' => $message));
}

function bg_len($s)
{
    if (function_exists('mb_strlen')) {
        return (int)mb_strlen($s, 'UTF-8');
    }
    $n = @preg_match_all('/./us', $s);
    return ($n === false) ? strlen($s) : (int)$n;
}

function bg_str($v, $max, $field)
{
    if ($v === null) {
        $v = '';
    }
    if (is_array($v) || is_object($v)) {
        $v = '';
    }
    if (!is_string($v)) {
        $v = (string)$v;
    }
    $v = trim($v);
    if (bg_len($v) > $max) {
        bg_fail(400, $field . '最多 ' . $max . ' 个字符');
    }
    return $v;
}

function bg_num($v, $field, $min = 0, $max = 1000000000)
{
    if ($v === null || $v === '' || is_bool($v) || is_array($v)) {
        bg_fail(400, $field . '必须是数字');
    }
    if (!is_numeric($v)) {
        bg_fail(400, $field . '必须是数字');
    }
    $n = (float)$v;
    if (!is_finite($n)) {
        bg_fail(400, $field . '必须是数字');
    }
    if ($n < $min) {
        bg_fail(400, $field . '不能小于 ' . $min);
    }
    if ($n > $max) {
        bg_fail(400, $field . '不能大于 ' . $max);
    }
    return $n;
}

function bg_round($n)
{
    return round($n, 2);
}

function bg_date($v)
{
    $s = bg_str($v, 10, '日期');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        bg_fail(400, '日期格式应为 YYYY-MM-DD');
    }
    $parts = explode('-', $s);
    if (count($parts) !== 3) {
        bg_fail(400, '日期格式应为 YYYY-MM-DD');
    }
    if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        bg_fail(400, '日期不存在');
    }
    return $s;
}

function bg_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return array();
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        bg_fail(400, '数据格式错误');
    }
    return $data;
}

function bg_uuid()
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/* ================================================================ 配置 */

function bg_load_env($file)
{
    $cfg = array();
    if (!is_file($file)) {
        return $cfg;
    }
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $cfg;
    }
    foreach ($lines as $line) {
        $line = trim(str_replace("\r", '', $line));
        if ($line === '' || substr($line, 0, 1) === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        $len = strlen($v);
        if ($len >= 2) {
            $a = substr($v, 0, 1);
            $b = substr($v, -1);
            if (($a === '"' && $b === '"') || ($a === "'" && $b === "'")) {
                $v = substr($v, 1, $len - 2);
            }
        }
        if ($k !== '') {
            $cfg[$k] = $v;
        }
    }
    return $cfg;
}

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

/* ================================================================ 数据层 */

function bg_image_name($v)
{
    if (!is_string($v) || $v === '') {
        return '';
    }
    if (strpos($v, 'data:') === 0) {
        return '';
    }
    $p = strpos($v, '?');
    if ($p !== false) {
        $v = substr($v, 0, $p);
    }
    $p = strpos($v, '#');
    if ($p !== false) {
        $v = substr($v, 0, $p);
    }
    $v = ltrim(str_replace('\\', '/', $v), '/');
    if (preg_match('#^(parts|attachments|drawings)/([^/]+)$#', $v, $m)) {
        return $m[1] . '/' . $m[2];
    }
    $slash = strrpos($v, '/');
    if ($slash !== false) {
        $v = substr($v, $slash + 1);
    }
    return $v;
}

/* 按名字在各图片目录中定位文件：parts/ → attachments/ → 旧版 images 根目录 */
function bg_image_path($name)
{
    global $IMG_ROOT, $PART_IMG_DIR, $ATT_IMG_DIR, $DRAW_DIR;
    $name = bg_image_name($name);
    if ($name === '') {
        return '';
    }
    $segs = explode('/', $name);
    $file = end($segs);
    if (!preg_match('/^[0-9A-Za-z_.\-\x{4e00}-\x{9fa5}]+\.(jpg|jpeg|png|webp|pdf)$/u', $file)) {
        return '';
    }
    $dirs = array();
    if (count($segs) === 2) {
        if ($segs[0] === 'parts') {
            $dirs[] = $PART_IMG_DIR;
        } elseif ($segs[0] === 'attachments') {
            $dirs[] = $ATT_IMG_DIR;
        } elseif ($segs[0] === 'drawings') {
            $dirs[] = $DRAW_DIR;
        }
    }
    $dirs[] = $PART_IMG_DIR;
    $dirs[] = $ATT_IMG_DIR;
    $dirs[] = $DRAW_DIR;
    $dirs[] = $IMG_ROOT;
    foreach ($dirs as $d) {
        if (is_file($d . '/' . $file)) {
            return $d . '/' . $file;
        }
    }
    return '';
}

/* 零件图片文件名：优先零件名称，其次图号；去掉文件系统不允许的字符 */
function bg_part_image_base($part)
{
    $label = isset($part['name']) ? trim((string)$part['name']) : '';
    if ($label === '') {
        $label = isset($part['no']) ? trim((string)$part['no']) : '';
    }
    $label = preg_replace('/[\\\\\/:*?"<>|\s\x{00a0}]+/u', '-', $label);
    $label = trim($label, '.-_');
    $label = function_exists('mb_substr') ? mb_substr($label, 0, 40, 'UTF-8') : substr($label, 0, 40);
    return $label !== '' ? $label : '零件';
}

/* 当天附件序号最大值 */
function bg_attach_max_seq($day)
{
    global $ATT_IMG_DIR;
    $max = 0;
    $items = @scandir($ATT_IMG_DIR);
    if (!is_array($items)) {
        return $max;
    }
    foreach ($items as $f) {
        if (preg_match('/^' . preg_quote($day, '/') . '-(\d{3,4})\.(jpg|jpeg|png|webp)$/i', $f, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $max;
}

/* 附件文件名：上传日期 + 当天序号，如 20261120-001 */
function bg_attach_base()
{
    $day = date('Ymd');
    $next = bg_attach_max_seq($day) + 1;
    return $day . '-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/* 零件改名后同步图片文件名 */
function bg_part_image_rename($part, $old)
{
    global $PART_IMG_DIR;
    $src = bg_image_path($old);
    if ($src === '' || dirname(str_replace('\\', '/', $src)) !== str_replace('\\', '/', $PART_IMG_DIR)) {
        return bg_image_name($old);
    }
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $base = bg_part_image_base($part);
    $name = $base;
    $i = 2;
    while (is_file($PART_IMG_DIR . '/' . $name . '.' . $ext) && $PART_IMG_DIR . '/' . $name . '.' . $ext !== $src) {
        $name = $base . '-' . $i;
        $i++;
    }
    $dst = $PART_IMG_DIR . '/' . $name . '.' . $ext;
    if ($dst !== $src && !@rename($src, $dst)) {
        return bg_image_name($old);
    }
    return 'parts/' . $name . '.' . $ext;
}

/* 旧版扁平结构一次性迁移到 data/{db,snapshots,images/parts,images/attachments} */
function bg_storage_migrate()
{
    global $DATA_DIR, $DB_FILE, $SNAP_DIR, $IMG_ROOT, $PART_IMG_DIR, $ATT_IMG_DIR;

    foreach (array('', '-wal', '-shm') as $suffix) {
        $old = $DATA_DIR . '/baogong.sqlite' . $suffix;
        $new = $DB_FILE . $suffix;
        if (is_file($old) && !is_file($new)) {
            @rename($old, $new);
        }
    }

    $legacySnap = $DATA_DIR . '/backups';
    if (is_dir($legacySnap)) {
        $items = @scandir($legacySnap);
        if (is_array($items)) {
            foreach ($items as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $src = $legacySnap . '/' . $name;
                $dst = $SNAP_DIR . '/' . $name;
                if (is_file($src) && !is_file($dst)) {
                    @rename($src, $dst);
                }
            }
        }
        @rmdir($legacySnap);
    }

    $items = @scandir($IMG_ROOT);
    if (is_array($items)) {
        foreach ($items as $name) {
            if ($name === '.' || $name === '..' || is_dir($IMG_ROOT . '/' . $name)) {
                continue;
            }
            if (!preg_match('/\.(jpg|jpeg|png|webp)$/i', $name)) {
                continue;
            }
            $dir = preg_match('/-r\d+-\d+\.(jpg|jpeg|png|webp)$/i', $name) ? $ATT_IMG_DIR : $PART_IMG_DIR;
            if (!is_file($dir . '/' . $name)) {
                @rename($IMG_ROOT . '/' . $name, $dir . '/' . $name);
            }
        }
    }

    $marker = $DATA_DIR . '/.storage-v2';
    if (is_file($marker) || !is_file($DB_FILE)) {
        return;
    }
    try {
        $pdo = new PDO('sqlite:' . $DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout=5000');

        $updPart = $pdo->prepare('UPDATE parts SET image = ? WHERE id = ?');
        foreach ($pdo->query('SELECT id, no, name, image FROM parts')->fetchAll() as $p) {
            $old = isset($p['image']) ? (string)$p['image'] : '';
            if ($old === '') {
                continue;
            }
            $src = bg_image_path($old);
            if ($src === '') {
                continue;
            }
            $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
            $base = bg_part_image_base($p);
            $name = $base;
            $i = 2;
            while (is_file($PART_IMG_DIR . '/' . $name . '.' . $ext) && $PART_IMG_DIR . '/' . $name . '.' . $ext !== $src) {
                $name = $base . '-' . $i;
                $i++;
            }
            $dst = $PART_IMG_DIR . '/' . $name . '.' . $ext;
            if ($dst !== $src && !@rename($src, $dst)) {
                continue;
            }
            $updPart->execute(array('parts/' . $name . '.' . $ext, $p['id']));
        }

        $updReport = $pdo->prepare('UPDATE reports SET images = ? WHERE id = ?');
        $seq = array();
        foreach ($pdo->query('SELECT id, images FROM reports')->fetchAll() as $r) {
            $imgs = json_decode(isset($r['images']) ? (string)$r['images'] : '[]', true);
            if (!is_array($imgs) || !count($imgs)) {
                continue;
            }
            $out = array();
            $changed = false;
            foreach ($imgs as $img) {
                $img = (string)$img;
                if ($img === '') {
                    continue;
                }
                $src = bg_image_path($img);
                if ($src === '') {
                    $out[] = $img;
                    continue;
                }
                $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
                $file = basename($src);
                if (preg_match('/^\d{8}-\d{3,4}\.(jpg|jpeg|png|webp)$/i', $file)) {
                    $out[] = 'attachments/' . $file;
                    if ($img !== 'attachments/' . $file) {
                        $changed = true;
                    }
                    continue;
                }
                $day = date('Ymd', (int)@filemtime($src));
                if (!isset($seq[$day])) {
                    $seq[$day] = bg_attach_max_seq($day);
                }
                $seq[$day]++;
                $newname = $day . '-' . str_pad((string)$seq[$day], 3, '0', STR_PAD_LEFT);
                $dst = $ATT_IMG_DIR . '/' . $newname . '.' . $ext;
                if ($src !== $dst && @rename($src, $dst)) {
                    $out[] = 'attachments/' . $newname . '.' . $ext;
                    $changed = true;
                } else {
                    $out[] = $img;
                }
            }
            if ($changed) {
                $updReport->execute(array(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $r['id']));
            }
        }
        @file_put_contents($marker, date('c'));
    } catch (Throwable $e) {
        @error_log('[baogong] storage migrate: ' . $e->getMessage());
    }
}

function bg_migrate_part($p)
{
    $processes = array();
    if (isset($p['processes']) && is_array($p['processes'])) {
        foreach ($p['processes'] as $x) {
            if (!is_array($x)) {
                continue;
            }
            $name = isset($x['name']) ? (string)$x['name'] : '';
            $desc = isset($x['desc']) ? (string)$x['desc'] : '';
            $price = bg_round(isset($x['price']) ? (float)$x['price'] : 0);
            if ($name === '' && $price == 0) {
                continue;
            }
            $processes[] = array('name' => $name, 'desc' => $desc, 'price' => $price);
        }
    }
    if (count($processes) === 0) {
        $name = isset($p['process']) ? (string)$p['process'] : '';
        $price = bg_round(isset($p['price']) ? (float)$p['price'] : 0);
        if ($name !== '' || $price != 0) {
            $processes[] = array('name' => $name !== '' ? $name : '默认工序', 'price' => $price);
        }
    }
    $p['image'] = bg_image_name(isset($p['image']) ? $p['image'] : '');
    $p['stock'] = isset($p['stock']) && is_numeric($p['stock']) ? (float)$p['stock'] : 0;
    $p['processes'] = $processes;
    $p['drawings'] = bg_normalize_drawings($p, null);
    $p['price'] = count($processes) ? $processes[0]['price'] : 0;
    unset($p['process']);
    return $p;
}

function bg_migrate_order($o)
{
    $o['process'] = isset($o['process']) ? (string)$o['process'] : '';
    return $o;
}

function bg_migrate_report($r)
{
    $r['orderId'] = isset($r['orderId']) ? (string)$r['orderId'] : '';
    $r['orderNo'] = isset($r['orderNo']) ? (string)$r['orderNo'] : '';
    $imgs = array();
    if (isset($r['images']) && is_array($r['images'])) {
        foreach ($r['images'] as $f) {
            $n = bg_image_name($f);
            if ($n !== '') {
                $imgs[] = $n;
            }
        }
    }
    $r['images'] = $imgs;
    $items = array();
    if (isset($r['items']) && is_array($r['items'])) {
        foreach ($r['items'] as $it) {
            if (!is_array($it)) {
                continue;
            }
            if (!isset($it['orderId']) || !is_string($it['orderId'])) {
                $it['orderId'] = $r['orderId'];
            }
            if (!isset($it['orderNo']) || !is_string($it['orderNo'])) {
                $it['orderNo'] = $r['orderNo'];
            }
            $items[] = $it;
        }
    }
    $r['items'] = $items;
    return $r;
}

function bg_pdo()
{
    global $DB_FILE, $BG_PDO;
    if (isset($BG_PDO) && $BG_PDO instanceof PDO) {
        return $BG_PDO;
    }
    try {
        $pdo = new PDO('sqlite:' . $DB_FILE);
    } catch (Throwable $e) {
        bg_fail(500, 'PHP 未启用 SQLite 扩展（pdo_sqlite）。请在 Web Station 的 PHP 配置 → 扩展里勾选 pdo_sqlite / sqlite3，保存后重试。');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('PRAGMA busy_timeout=5000');
    bg_sqlite_schema($pdo);
    bg_sqlite_import_json($pdo);
    $BG_PDO = $pdo;
    return $pdo;
}

function bg_sqlite_schema($pdo)
{
    $sql = array(
        "CREATE TABLE IF NOT EXISTS parts (
            id TEXT PRIMARY KEY,
            no TEXT NOT NULL UNIQUE,
            name TEXT DEFAULT '',
            material TEXT DEFAULT '',
            note TEXT DEFAULT '',
            stock REAL DEFAULT 0,
            image TEXT DEFAULT '',
            processes TEXT DEFAULT '[]',
            drawings TEXT DEFAULT '[]',
            created_at TEXT,
            updated_at TEXT
        )",
        "CREATE TABLE IF NOT EXISTS orders (
            id TEXT PRIMARY KEY,
            no TEXT NOT NULL UNIQUE,
            date TEXT,
            part_id TEXT,
            part_no TEXT,
            part_name TEXT,
            process TEXT DEFAULT '',
            qty REAL DEFAULT 0,
            note TEXT DEFAULT '',
            created_at TEXT,
            updated_at TEXT
        )",
        "CREATE TABLE IF NOT EXISTS reports (
            id TEXT PRIMARY KEY,
            no TEXT NOT NULL UNIQUE,
            date TEXT,
            worker TEXT,
            workshop TEXT,
            note TEXT,
            order_id TEXT,
            order_no TEXT,
            images TEXT DEFAULT '[]',
            created_at TEXT,
            updated_at TEXT
        )",
        "CREATE TABLE IF NOT EXISTS report_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id TEXT NOT NULL,
            part_id TEXT,
            part_no TEXT,
            part_name TEXT,
            process TEXT,
            qty REAL,
            price REAL,
            amount REAL,
            order_id TEXT,
            order_no TEXT,
            sort INTEGER DEFAULT 0
        )",
        'CREATE INDEX IF NOT EXISTS idx_reports_date ON reports(date)',
        'CREATE INDEX IF NOT EXISTS idx_items_report ON report_items(report_id)',
        'CREATE INDEX IF NOT EXISTS idx_items_order ON report_items(order_id)',
        'CREATE INDEX IF NOT EXISTS idx_items_part ON report_items(part_id)',
        'CREATE INDEX IF NOT EXISTS idx_orders_date ON orders(date)',
        'CREATE INDEX IF NOT EXISTS idx_orders_part ON orders(part_id, date)',
        'CREATE INDEX IF NOT EXISTS idx_parts_updated ON parts(updated_at DESC)',
        'CREATE INDEX IF NOT EXISTS idx_reports_created ON reports(date DESC, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS idx_items_part_order ON report_items(part_id, order_id)'
    );
    foreach ($sql as $one) {
        $pdo->exec($one);
    }
    /* v2.3：旧库补 parts.drawings 列，只做一次，不影响已有数据 */
    $cols = array();
    foreach ($pdo->query('PRAGMA table_info(parts)') as $c) {
        $cols[$c['name']] = true;
    }
    if (!isset($cols['drawings'])) {
        try {
            $pdo->exec("ALTER TABLE parts ADD COLUMN drawings TEXT DEFAULT '[]'");
        } catch (Throwable $e) {
            /* 并发初始化时可能已被另一个请求加上，忽略 */
        }
    }
}

function bg_sqlite_import_json($pdo)
{
    global $DATA_DIR, $BG_SNAP;
    $jsonFile = $DATA_DIR . '/db.json';
    if (!is_file($jsonFile)) {
        return;
    }
    $count = (int)$pdo->query('SELECT COUNT(*) FROM parts')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $raw = @file_get_contents($jsonFile);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data)) {
        return;
    }

    $parts = array();
    if (isset($data['parts']) && is_array($data['parts'])) {
        foreach ($data['parts'] as $p) {
            if (is_array($p) && isset($p['id']) && isset($p['no'])) {
                $parts[] = bg_migrate_part($p);
            }
        }
    }
    $orders = array();
    if (isset($data['orders']) && is_array($data['orders'])) {
        foreach ($data['orders'] as $o) {
            if (is_array($o) && isset($o['id']) && isset($o['no'])) {
                $orders[] = bg_migrate_order($o);
            }
        }
    }
    $reports = array();
    if (isset($data['reports']) && is_array($data['reports'])) {
        foreach ($data['reports'] as $r) {
            if (is_array($r) && isset($r['id'])) {
                if (!isset($r['items']) || !is_array($r['items'])) {
                    $r['items'] = array();
                }
                $reports[] = bg_migrate_report($r);
            }
        }
    }
    if (count($parts) === 0 && count($reports) === 0) {
        return;
    }

    $BG_SNAP = array('parts' => array(), 'orders' => array(), 'reports' => array());
    bg_db_save(array('parts' => $parts, 'reports' => $reports, 'orders' => $orders));
    @rename($jsonFile, $jsonFile . '.imported');
    @file_put_contents($DATA_DIR . '/.migrated', date('c'));
    @error_log('[baogong] 已从 db.json 导入 SQLite：' . count($parts) . ' 零件 / ' . count($orders) . ' 订单 / ' . count($reports) . ' 报工单');
}

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
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $BG_SNAP = bg_index_state($db);
}

/* ---------------- 快照：把当前数据库整份存到 data/snapshots，并记录元数据 ---------------- */

function bg_snapshot_meta_file()
{
    global $BACKUP_DIR;
    return $BACKUP_DIR . '/snapshots.json';
}

function bg_snapshot_meta_load()
{
    $file = bg_snapshot_meta_file();
    $out = array();
    if (!is_file($file)) {
        return $out;
    }
    $raw = @file_get_contents($file);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data)) {
        return $out;
    }
    foreach ($data as $name => $item) {
        if (is_string($name) && is_array($item)) {
            $out[$name] = $item;
        }
    }
    return $out;
}

function bg_snapshot_meta_save($meta)
{
    @file_put_contents(
        bg_snapshot_meta_file(),
        json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/* 校验快照文件名，返回可读路径，不存在返回空串 */
function bg_snapshot_path($name)
{
    global $BACKUP_DIR;
    $name = basename((string)$name);
    if (!preg_match('/^db-\d{8}-\d{6}-[\d.]{4}\.sqlite$/', $name)) {
        return '';
    }
    $path = $BACKUP_DIR . '/' . $name;
    return is_file($path) ? $path : '';
}

function bg_snapshot_counts()
{
    $pdo = bg_pdo();
    $out = array('parts' => 0, 'orders' => 0, 'reports' => 0);
    foreach (array_keys($out) as $k) {
        try {
            $out[$k] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $k)->fetchColumn();
        } catch (Throwable $e) {
            $out[$k] = 0;
        }
    }
    return $out;
}

function bg_snapshot_create($reason = 'manual', $note = '')
{
    global $BACKUP_DIR, $DB_FILE;
    if (!is_file($DB_FILE)) {
        return null;
    }
    $name = 'db-' . date('Ymd-His') . '-' . sprintf('%04d', random_int(0, 9999)) . '.sqlite';
    $target = $BACKUP_DIR . '/' . $name;
    $pdo = bg_pdo();
    try {
        $pdo->exec('VACUUM INTO ' . $pdo->quote($target));
    } catch (Throwable $e) {
        if (!@copy($DB_FILE, $target)) {
            throw new RuntimeException('创建快照失败：' . $e->getMessage());
        }
    }
    $counts = bg_snapshot_counts();
    $meta = bg_snapshot_meta_load();
    $meta[$name] = array(
        'file' => $name,
        'reason' => (string)$reason,
        'note' => (string)$note,
        'createdAt' => date('Y-m-d H:i:s'),
        'bytes' => (int)@filesize($target),
        'parts' => $counts['parts'],
        'orders' => $counts['orders'],
        'reports' => $counts['reports'],
    );
    bg_snapshot_meta_save($meta);
    bg_snapshot_prune();
    return $meta[$name];
}

/* 自动快照保留 20 份，手动快照保留 60 份；顺手补齐/清理元数据 */
function bg_snapshot_prune()
{
    global $BACKUP_DIR;
    $meta = bg_snapshot_meta_load();
    $files = glob($BACKUP_DIR . '/db-*.sqlite');
    if (!is_array($files)) {
        $files = array();
    }
    $changed = false;
    $onDisk = array();
    foreach ($files as $f) {
        $name = basename($f);
        $onDisk[$name] = true;
        if (!isset($meta[$name])) {
            $meta[$name] = array(
                'file' => $name,
                'reason' => 'auto',
                'note' => '',
                'createdAt' => date('Y-m-d H:i:s', (int)@filemtime($f)),
                'bytes' => (int)@filesize($f),
                'parts' => 0,
                'orders' => 0,
                'reports' => 0,
            );
            $changed = true;
        }
    }
    foreach (array_keys($meta) as $name) {
        if (!isset($onDisk[$name])) {
            unset($meta[$name]);
            $changed = true;
        }
    }
    $auto = array();
    $manual = array();
    foreach ($meta as $name => $m) {
        if (isset($m['reason']) && $m['reason'] === 'auto') {
            $auto[] = $name;
        } else {
            $manual[] = $name;
        }
    }
    sort($auto);
    sort($manual);
    while (count($auto) > 20) {
        $name = array_shift($auto);
        @unlink($BACKUP_DIR . '/' . $name);
        unset($meta[$name]);
        $changed = true;
    }
    while (count($manual) > 60) {
        $name = array_shift($manual);
        @unlink($BACKUP_DIR . '/' . $name);
        unset($meta[$name]);
        $changed = true;
    }
    if ($changed) {
        bg_snapshot_meta_save($meta);
    }
    return $meta;
}

/* 用快照覆盖当前数据库；覆盖前先自动存一份“恢复前”快照 */
function bg_snapshot_restore($name)
{
    global $BACKUP_DIR, $DB_FILE, $BG_PDO, $BG_SNAP;
    $src = bg_snapshot_path($name);
    if ($src === '') {
        throw new RuntimeException('快照不存在，可能已被清理');
    }
    bg_snapshot_create('pre-restore', '恢复《' . basename($src) . '》前自动备份');
    $pdo = bg_pdo();
    try {
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (Throwable $e) {
        /* 校验点失败不影响后续覆盖 */
    }
    $BG_PDO = null;
    $BG_SNAP = null;
    @unlink($DB_FILE . '-wal');
    @unlink($DB_FILE . '-shm');
    if (!@copy($src, $DB_FILE)) {
        throw new RuntimeException('写入数据库失败，请检查 data 目录权限');
    }
    return true;
}

function bg_backup_once()
{
    global $DB_FILE, $BACKUP_DIR;
    if (!is_file($DB_FILE)) {
        return;
    }
    $stamp = $BACKUP_DIR . '/.last';
    $last = 0;
    if (is_file($stamp)) {
        $last = (int)@file_get_contents($stamp);
    }
    if (time() - $last < 43200) {
        return;
    }
    @file_put_contents($stamp, (string)time());
    bg_snapshot_create('auto', '');
}

function bg_backup_force($reason = 'manual', $note = '')
{
    return bg_snapshot_create($reason, $note);
}

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

/* ================================================================ 登录 */

function bg_b64url($s)
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function bg_b64url_decode($s)
{
    $s = strtr($s, '-_', '+/');
    $n = strlen($s) % 4;
    if ($n !== 0) {
        $s .= str_repeat('=', 4 - $n);
    }
    $r = base64_decode($s, true);
    return $r === false ? '' : $r;
}

function bg_secret()
{
    global $SECRET_FILE;
    if (is_file($SECRET_FILE)) {
        $s = @file_get_contents($SECRET_FILE);
        if ($s !== false && strlen($s) >= 16) {
            return $s;
        }
    }
    $s = random_bytes(32);
    @file_put_contents($SECRET_FILE, $s, LOCK_EX);
    @chmod($SECRET_FILE, 0600);
    return $s;
}

function bg_token()
{
    $payload = bg_b64url(json_encode(array('exp' => time() + 2592000)));
    $sig = bg_b64url(hash_hmac('sha256', $payload, bg_secret(), true));
    return $payload . '.' . $sig;
}

function bg_verify_token($t)
{
    $pos = strrpos($t, '.');
    if ($pos === false) {
        return false;
    }
    $payload = substr($t, 0, $pos);
    $sig = substr($t, $pos + 1);
    $expect = bg_b64url(hash_hmac('sha256', $payload, bg_secret(), true));
    if (!hash_equals($expect, $sig)) {
        return false;
    }
    $json = bg_b64url_decode($payload);
    if ($json === '') {
        return false;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['exp'])) {
        return false;
    }
    return (int)$data['exp'] > time();
}

function bg_authed()
{
    global $APP_PASSWORD;
    if ($APP_PASSWORD === '') {
        return true;
    }
    if (!isset($_COOKIE['bg_session']) || !is_string($_COOKIE['bg_session'])) {
        return false;
    }
    return bg_verify_token($_COOKIE['bg_session']);
}

function bg_throttle_load()
{
    global $DATA_DIR;
    $f = $DATA_DIR . '/login-fails.json';
    if (!is_file($f)) {
        return array();
    }
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : array();
}

function bg_throttle_save($d)
{
    global $DATA_DIR;
    @file_put_contents($DATA_DIR . '/login-fails.json', json_encode($d), LOCK_EX);
}

/* ================================================================ 图片 */

function bg_image_remove($name)
{
    $path = bg_image_path($name);
    if ($path !== '') {
        @unlink($path);
    }
}

function bg_image_save($sub, $base, $dataUrl, $dedupe = '')
{
    global $PART_IMG_DIR, $ATT_IMG_DIR;
    if (!is_string($dataUrl) || !preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $dataUrl, $m)) {
        bg_fail(400, '图片格式不支持（请用 JPG / PNG / WebP）');
    }
    $bin = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
    if ($bin === false || $bin === '') {
        bg_fail(400, '图片内容为空');
    }
    if (strlen($bin) > 8388608) {
        bg_fail(400, '图片太大，请压缩后再上传');
    }
    $ext = '.jpg';
    if ($m[1] === 'png') {
        $ext = '.png';
    }
    if ($m[1] === 'webp') {
        $ext = '.webp';
    }
    if (!preg_match('/^[0-9A-Za-z_.\-\x{4e00}-\x{9fa5}]+$/u', (string)$base)) {
        bg_fail(400, '图片文件名不合法');
    }
    $isAtt = $sub === 'attachments';
    $dir = $isAtt ? $ATT_IMG_DIR : $PART_IMG_DIR;
    $prefix = $isAtt ? 'attachments' : 'parts';
    $name = $base;
    if (is_file($dir . '/' . $name . $ext)) {
        $suffix = $dedupe !== '' ? substr(md5($dedupe), 0, 4) : '2';
        $name = $base . '-' . $suffix;
        $i = 2;
        while (is_file($dir . '/' . $name . $ext) && $i < 100) {
            $name = $base . '-' . $suffix . '-' . $i;
            $i++;
        }
    }
    if (@file_put_contents($dir . '/' . $name . $ext, $bin) === false) {
        bg_fail(500, '图片保存失败，请检查 ' . $prefix . ' 目录权限');
    }
    return $prefix . '/' . $name . $ext;
}

/* 图纸列表规范化：只保留 drawings/ 下的图片或 PDF */
function bg_normalize_drawings($in, $existing)
{
    $src = null;
    if (isset($in['drawings']) && is_array($in['drawings'])) {
        $src = $in['drawings'];
    } elseif ($existing && isset($existing['drawings']) && is_array($existing['drawings'])) {
        $src = $existing['drawings'];
    }
    $out = array();
    if (is_array($src)) {
        foreach ($src as $d) {
            if (!is_array($d)) {
                continue;
            }
            $path = bg_image_name(isset($d['path']) ? $d['path'] : '');
            if ($path === '' || strpos($path, 'drawings/') !== 0) {
                continue;
            }
            $name = isset($d['name']) && (string)$d['name'] !== '' ? basename((string)$d['name']) : basename($path);
            $type = isset($d['type']) && $d['type'] === 'pdf' ? 'pdf' : 'image';
            $out[] = array('path' => $path, 'name' => $name, 'type' => $type);
            if (count($out) >= 20) {
                break;
            }
        }
    }
    return $out;
}

/* 保存一张图纸：图片（JPG/PNG/WebP）或 PDF，原样存盘不压缩 */
function bg_drawing_save($part, $dataUrl)
{
    global $DRAW_DIR;
    if (!is_string($dataUrl) || $dataUrl === '') {
        bg_fail(400, '图纸内容为空');
    }
    $ext = '';
    $bin = false;
    if (preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $dataUrl, $m)) {
        $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        $bin = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
        if ($bin !== false && strlen($bin) > 8388608) {
            bg_fail(400, '图片图纸太大（限 8MB）');
        }
    } elseif (preg_match('#^data:application/pdf;base64,(.+)$#s', $dataUrl, $m)) {
        $ext = 'pdf';
        $bin = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        if ($bin !== false && strlen($bin) > 20971520) {
            bg_fail(400, 'PDF 图纸太大（限 20MB）');
        }
    } else {
        bg_fail(400, '图纸格式不支持（请用 JPG / PNG / WebP / PDF）');
    }
    if ($bin === false || $bin === '') {
        bg_fail(400, '图纸内容为空');
    }
    $base = bg_part_image_base($part);
    $i = 1;
    while (is_file($DRAW_DIR . '/' . $base . '-' . $i . '.' . $ext)) {
        $i++;
    }
    $fname = $base . '-' . $i . '.' . $ext;
    if (@file_put_contents($DRAW_DIR . '/' . $fname, $bin) === false) {
        bg_fail(500, '图纸保存失败，请检查 drawings 目录权限');
    }
    return array('path' => 'drawings/' . $fname, 'name' => $fname, 'type' => $ext === 'pdf' ? 'pdf' : 'image');
}

/* 零件改名后同步图纸文件名 */
function bg_drawings_rename($drawings, $part)
{
    global $DRAW_DIR;
    if (!is_array($drawings) || !count($drawings)) {
        return array();
    }
    $base = bg_part_image_base($part);
    $out = array();
    $i = 0;
    foreach ($drawings as $d) {
        $i++;
        $src = bg_image_path(isset($d['path']) ? $d['path'] : '');
        $type = isset($d['type']) && $d['type'] === 'pdf' ? 'pdf' : 'image';
        if ($src === '' || str_replace('\\', '/', dirname($src)) !== str_replace('\\', '/', $DRAW_DIR)) {
            $out[] = array(
                'path' => isset($d['path']) ? $d['path'] : '',
                'name' => isset($d['name']) ? $d['name'] : '',
                'type' => $type,
            );
            continue;
        }
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $name = $base . '-' . $i;
        $try = $name;
        $n = 2;
        while (is_file($DRAW_DIR . '/' . $try . '.' . $ext) && $DRAW_DIR . '/' . $try . '.' . $ext !== $src) {
            $try = $name . '-' . $n;
            $n++;
        }
        $dst = $DRAW_DIR . '/' . $try . '.' . $ext;
        if ($dst !== $src && !@rename($src, $dst)) {
            $out[] = array(
                'path' => isset($d['path']) ? $d['path'] : '',
                'name' => isset($d['name']) ? $d['name'] : '',
                'type' => $type,
            );
            continue;
        }
        $out[] = array('path' => 'drawings/' . $try . '.' . $ext, 'name' => $try . '.' . $ext, 'type' => $type);
    }
    return $out;
}

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
    $stockValue = isset($in['stock']) ? $in['stock'] : 0;
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

/* ================================================================ 插件（图像识别） */

/**
 * 插件约定
 *   public/plugins/<id>/plugin.json  清单：id / name / version / description / capabilities / entry / settings[]
 *   public/plugins/<id>/handler.php  处理器：读取 $BG_PLUGIN_CTX（或定义 bg_plugin_run($ctx)），return 数组结果
 *   配置保存在 data/plugins.json
 */

function bg_plugins_dir()
{
    return __DIR__ . '/plugins';
}

function bg_plugin_state_file()
{
    global $DATA_DIR;
    return $DATA_DIR . '/plugins.json';
}

function bg_plugin_state_load()
{
    $file = bg_plugin_state_file();
    if (!is_file($file)) {
        return array();
    }
    $raw = @file_get_contents($file);
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? $data : array();
}

function bg_plugin_state_save($state)
{
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (@file_put_contents(bg_plugin_state_file(), $json, LOCK_EX) === false) {
        bg_fail(500, '插件配置保存失败，请检查 data 目录的写权限');
    }
}

function bg_plugin_cut($v, $max)
{
    $s = is_string($v) ? trim($v) : '';
    if ($s === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
    return substr($s, 0, $max);
}

function bg_plugin_field($f)
{
    if (!is_array($f)) {
        return null;
    }
    $key = isset($f['key']) && is_string($f['key']) ? $f['key'] : '';
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/', $key)) {
        return null;
    }
    $types = array('text', 'password', 'number', 'select', 'checkbox', 'textarea');
    $type = isset($f['type']) && in_array($f['type'], $types, true) ? $f['type'] : 'text';
    $out = array(
        'key' => $key,
        'label' => bg_plugin_cut(isset($f['label']) ? $f['label'] : $key, 40),
        'type' => $type,
        'placeholder' => bg_plugin_cut(isset($f['placeholder']) ? $f['placeholder'] : '', 120),
        'hint' => bg_plugin_cut(isset($f['hint']) ? $f['hint'] : '', 120),
        'default' => isset($f['default']) && !is_array($f['default']) ? (string)$f['default'] : '',
    );
    if ($type === 'textarea') {
        $rows = isset($f['rows']) ? (int)$f['rows'] : 4;
        $out['rows'] = $rows < 2 ? 2 : ($rows > 12 ? 12 : $rows);
    }
    if ($type === 'select') {
        $opts = array();
        if (isset($f['options']) && is_array($f['options'])) {
            foreach ($f['options'] as $o) {
                if (!is_array($o)) {
                    continue;
                }
                $val = isset($o['value']) && !is_array($o['value']) ? (string)$o['value'] : '';
                if ($val === '') {
                    continue;
                }
                $opts[] = array('value' => $val, 'label' => bg_plugin_cut(isset($o['label']) ? $o['label'] : $val, 40));
            }
        }
        $out['options'] = $opts;
    }
    return $out;
}

function bg_plugin_manifest($file)
{
    $raw = @file_get_contents($file);
    $m = $raw === false ? null : json_decode($raw, true);
    if (!is_array($m)) {
        return null;
    }
    $id = isset($m['id']) && is_string($m['id']) ? $m['id'] : '';
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id) || basename(dirname($file)) !== $id) {
        return null;
    }
    $entry = isset($m['entry']) && is_string($m['entry']) ? $m['entry'] : 'handler.php';
    if (!preg_match('/^[A-Za-z0-9_.-]+\.php$/', $entry) || !is_file(dirname($file) . '/' . $entry)) {
        return null;
    }
    $check = isset($m['check']) && is_string($m['check']) ? $m['check'] : '';
    if ($check !== '' && (!preg_match('/^[A-Za-z0-9_.-]+\.php$/', $check) || !is_file(dirname($file) . '/' . $check))) {
        $check = '';
    }
    $caps = array();
    if (isset($m['capabilities']) && is_array($m['capabilities'])) {
        foreach ($m['capabilities'] as $c) {
            if (is_string($c) && preg_match('/^[a-z][a-z0-9.-]{0,31}$/', $c)) {
                $caps[] = $c;
            }
        }
    }
    $fields = array();
    if (isset($m['settings']) && is_array($m['settings'])) {
        foreach ($m['settings'] as $f) {
            $def = bg_plugin_field($f);
            if ($def) {
                $fields[] = $def;
            }
        }
    }
    return array(
        'id' => $id,
        'name' => bg_plugin_cut(isset($m['name']) ? $m['name'] : $id, 40),
        'version' => bg_plugin_cut(isset($m['version']) ? $m['version'] : '0.0.0', 20),
        'description' => bg_plugin_cut(isset($m['description']) ? $m['description'] : '', 200),
        'author' => bg_plugin_cut(isset($m['author']) ? $m['author'] : '', 40),
        'capabilities' => $caps,
        'settings' => $fields,
        'entry' => $entry,
        'check' => $check,
        'dir' => dirname($file),
    );
}

function bg_plugin_all()
{
    $list = array();
    $dir = bg_plugins_dir();
    if (!is_dir($dir)) {
        return $list;
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
        return $list;
    }
    sort($items);
    foreach ($items as $name) {
        if ($name === '.' || $name === '..' || $name === '' || $name[0] === '.') {
            continue;
        }
        $file = $dir . '/' . $name . '/plugin.json';
        if (!is_file($file)) {
            continue;
        }
        $m = bg_plugin_manifest($file);
        if ($m) {
            $list[$m['id']] = $m;
        }
    }
    return $list;
}

function bg_plugin_one($id)
{
    if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id)) {
        bg_fail(400, '插件标识不合法');
    }
    $all = bg_plugin_all();
    if (!isset($all[$id])) {
        bg_fail(404, '插件不存在或未安装');
    }
    return $all[$id];
}

function bg_plugin_is_enabled($id)
{
    $state = bg_plugin_state_load();
    if (!isset($state[$id]) || !is_array($state[$id])) {
        return true;
    }
    return !isset($state[$id]['enabled']) || (bool)$state[$id]['enabled'];
}

function bg_plugin_config($m)
{
    $state = bg_plugin_state_load();
    $saved = isset($state[$m['id']]['settings']) && is_array($state[$m['id']]['settings'])
        ? $state[$m['id']]['settings'] : array();
    $cfg = array();
    foreach ($m['settings'] as $f) {
        $v = array_key_exists($f['key'], $saved) ? $saved[$f['key']] : $f['default'];
        $cfg[$f['key']] = is_scalar($v) ? (string)$v : '';
    }
    return $cfg;
}

function bg_plugin_public($m)
{
    $cfg = bg_plugin_config($m);
    $fields = array();
    foreach ($m['settings'] as $f) {
        $f['value'] = $f['type'] === 'password' && $cfg[$f['key']] !== '' ? '********' : $cfg[$f['key']];
        unset($f['default']);
        $fields[] = $f;
    }
    return array(
        'id' => $m['id'],
        'name' => $m['name'],
        'version' => $m['version'],
        'description' => $m['description'],
        'author' => $m['author'],
        'capabilities' => $m['capabilities'],
        'enabled' => bg_plugin_is_enabled($m['id']),
        'hasCheck' => $m['check'] !== '',
        'settings' => $fields,
    );
}

function bg_plugin_normalize_result($out)
{
    if (!is_array($out)) {
        bg_fail(502, '识别插件返回的数据格式不正确');
    }
    $f = isset($out['fields']) && is_array($out['fields']) ? $out['fields'] : array();

    $date = isset($f['date']) ? trim((string)$f['date']) : '';
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = '';
    }
    if ($date !== '') {
        $d = explode('-', $date);
        if (count($d) !== 3 || !checkdate((int)$d[1], (int)$d[2], (int)$d[0])) {
            $date = '';
        }
    }

    $items = array();
    $srcItems = isset($f['items']) && is_array($f['items']) ? $f['items'] : array();
    foreach ($srcItems as $it) {
        if (!is_array($it) || count($items) >= 20) {
            continue;
        }
        $qty = isset($it['qty']) && is_numeric($it['qty']) ? (float)$it['qty'] : 0;
        if ($qty < 0 || $qty > 1000000) {
            $qty = 0;
        }
        $price = isset($it['price']) && is_numeric($it['price']) ? bg_round((float)$it['price']) : null;
        $items[] = array(
            'partNo' => bg_plugin_cut(isset($it['partNo']) ? $it['partNo'] : '', 40),
            'partName' => bg_plugin_cut(isset($it['partName']) ? $it['partName'] : '', 40),
            'process' => bg_plugin_cut(isset($it['process']) ? $it['process'] : '', 40),
            'qty' => bg_round($qty),
            'price' => $price,
        );
    }

    $warnings = array();
    if (isset($out['warnings']) && is_array($out['warnings'])) {
        foreach ($out['warnings'] as $w) {
            if (is_string($w) && trim($w) !== '' && count($warnings) < 5) {
                $warnings[] = bg_plugin_cut($w, 80);
            }
        }
    }

    $confidence = isset($out['confidence']) && is_numeric($out['confidence']) ? (float)$out['confidence'] : 0;
    if ($confidence < 0) {
        $confidence = 0;
    }
    if ($confidence > 1) {
        $confidence = 1;
    }

    return array(
        'engine' => bg_plugin_cut(isset($out['engine']) ? $out['engine'] : '', 40),
        'confidence' => round($confidence, 3),
        'fields' => array(
            'date' => $date,
            'worker' => bg_plugin_cut(isset($f['worker']) ? $f['worker'] : '', 40),
            'workshop' => bg_plugin_cut(isset($f['workshop']) ? $f['workshop'] : '', 40),
            'note' => bg_plugin_cut(isset($f['note']) ? $f['note'] : '', 100),
            'items' => $items,
        ),
        'warnings' => $warnings,
        'raw' => bg_plugin_cut(isset($out['raw']) ? $out['raw'] : '', 4000),
    );
}

function bg_plugin_invoke($id, $dataUrl)
{
    global $BG_PLUGIN_CTX;
    $m = bg_plugin_one($id);

    if (!bg_plugin_is_enabled($id)) {
        bg_fail(400, '该识别插件已停用，请先在设置页启用');
    }
    if (!is_string($dataUrl) || !preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $dataUrl, $mt)) {
        bg_fail(400, '请上传 JPG / PNG / WebP 格式的报工单照片');
    }
    $binLen = strlen($mt[2]) * 3 / 4;
    if ($binLen > 8388608) {
        bg_fail(400, '图片太大，请压缩后再识别');
    }

    $db = bg_db_load();
    $catalog = array();
    foreach ($db['parts'] as $p) {
        if (count($catalog) >= 300) {
            break;
        }
        $names = array();
        foreach (bg_part_processes($p) as $x) {
            $names[] = isset($x['name']) ? (string)$x['name'] : '';
        }
        $catalog[] = array(
            'no' => isset($p['no']) ? (string)$p['no'] : '',
            'name' => isset($p['name']) ? (string)$p['name'] : '',
            'processes' => $names,
        );
    }

    $BG_PLUGIN_CTX = array(
        'id' => $m['id'],
        'image' => $dataUrl,
        'catalog' => $catalog,
        'config' => bg_plugin_config($m),
        'locale' => 'zh-CN',
        'today' => date('Y-m-d'),
    );

    $out = null;
    try {
        $ret = include $m['dir'] . '/' . $m['entry'];
        if (is_array($ret)) {
            $out = $ret;
        } elseif (function_exists('bg_plugin_run')) {
            $out = bg_plugin_run($BG_PLUGIN_CTX);
        }
    } catch (Throwable $e) {
        @error_log('[baogong] 插件 ' . $m['id'] . ' 执行失败：' . $e->getMessage());
        bg_fail(502, '识别失败：' . $e->getMessage());
    }
    if (!is_array($out)) {
        bg_fail(502, '识别插件没有返回结果');
    }

    $result = bg_plugin_normalize_result($out);
    $result['plugin'] = array('id' => $m['id'], 'name' => $m['name'], 'version' => $m['version']);
    return $result;
}

function bg_part_processes($p)
{
    return (isset($p['processes']) && is_array($p['processes'])) ? $p['processes'] : array();
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

/* ---------------- 图号模糊匹配（与 v1.7 前端算法一致） ---------------- */

function bg_norm_key($v)
{
    $s = str_replace(array('－', '—', '–', '‑', '　'), '-', (string)$v);
    $s = preg_replace('/\s+/u', '', $s);
    return strtoupper($s);
}

function bg_compact_key($v)
{
    return preg_replace('/[^0-9A-Z\x{4e00}-\x{9fa5}]/u', '', bg_norm_key($v));
}

function bg_slen($s)
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function bg_ssub($s, $start, $len)
{
    return function_exists('mb_substr') ? mb_substr($s, $start, $len, 'UTF-8') : substr($s, $start, $len);
}

function bg_strip_date($s)
{
    $s = preg_replace('/20\d{2}\s*[-\/年.]\s*\d{1,2}\s*[-\/月.]\s*\d{1,2}\s*日?/u', ' ', (string)$s);
    return preg_replace('/(?<!\d)\d{1,2}\s*[-\/.]\s*\d{1,2}(?!\d)\s*日?/u', ' ', $s);
}

function bg_sim_key($a, $b)
{
    $A = preg_split('//u', (string)$a, -1, PREG_SPLIT_NO_EMPTY);
    $B = preg_split('//u', (string)$b, -1, PREG_SPLIT_NO_EMPTY);
    $m = count($A);
    $n = count($B);
    if (!$m || !$n) {
        return 0;
    }
    $prev = range(0, $n);
    for ($i = 1; $i <= $m; $i++) {
        $cur = array($i);
        for ($j = 1; $j <= $n; $j++) {
            $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + ($A[$i - 1] === $B[$j - 1] ? 0 : 1));
        }
        $prev = $cur;
    }
    return 1 - $prev[$n] / max($m, $n);
}

/* 返回 [{part, score}]，按分数降序 */
function bg_fuzzy_candidates($parts, $text, $limit = 5)
{
    $bare = bg_strip_date($text);
    $raw = bg_norm_key($bare);
    $flat = bg_compact_key($bare);
    if ($raw === '') {
        return array();
    }
    preg_match_all('/[0-9A-Z]{4,}/', $flat, $mm);
    $runs = $mm[0];
    $maxRun = 0;
    foreach ($runs as $r) {
        $maxRun = max($maxRun, strlen($r));
    }
    $longRuns = array();
    foreach ($runs as $r) {
        if (strlen($r) === $maxRun) {
            $longRuns[] = $r;
        }
    }

    $scored = array();
    foreach ($parts as $p) {
        $no = bg_norm_key(isset($p['no']) ? $p['no'] : '');
        $noC = bg_compact_key(isset($p['no']) ? $p['no'] : '');
        $nm = bg_norm_key(isset($p['name']) ? $p['name'] : '');
        $score = 0;
        if ($no !== '' && strpos($raw, $no) !== false) {
            $score = 1;
        } elseif (bg_slen($noC) >= 4 && strpos($flat, $noC) !== false) {
            $score = 0.96;
        } else {
            foreach ($longRuns as $r) {
                $rl = strlen($r);
                if ($rl < 4) {
                    continue;
                }
                $noLen = bg_slen($noC);
                $lim = min($rl, $noLen);
                for ($l = $lim; $l >= 4; $l--) {
                    $pre = substr($r, 0, $l);
                    if ($noLen >= $l && strpos($noC, $pre) !== false) {
                        $score = max($score, 0.95);
                        break;
                    }
                    if (strpos($pre, $noC) !== false) {
                        $score = max($score, 0.93);
                        break;
                    }
                }
                if ($score < 0.9) {
                    $sim = bg_sim_key($r, $noC);
                    if ($sim >= 0.72) {
                        $score = max($score, 0.8 + ($sim - 0.72) * 0.5);
                    }
                }
            }
            if (!$score && bg_slen($noC) >= 5) {
                $len = bg_slen($noC);
                $seg = 0;
                $flatLen = bg_slen($flat);
                for ($i = 0; $i + $len <= $flatLen; $i++) {
                    $seg = max($seg, bg_sim_key(bg_ssub($flat, $i, $len), $noC));
                }
                if ($seg >= 0.75) {
                    $score = 0.72 + ($seg - 0.75) * 0.5;
                }
            }
        }
        if (!$score && bg_slen($nm) >= 2 && strpos($raw, $nm) !== false) {
            $score = 0.9;
        }
        if ($score > 0) {
            $scored[] = array('part' => $p, 'score' => $score);
        }
    }
    usort($scored, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    return array_slice($scored, 0, $limit);
}

/* 先精确（归一化 / 去分隔符 / 名称），再模糊 */
function bg_match_part($parts, $no, $name)
{
    $n = bg_norm_key($no);
    $nm = bg_norm_key($name);
    $hit = null;
    foreach ($parts as $p) {
        if ($n !== '' && bg_norm_key(isset($p['no']) ? $p['no'] : '') === $n) {
            $hit = $p;
            break;
        }
    }
    if (!$hit && $n !== '') {
        $nc = bg_compact_key($n);
        foreach ($parts as $p) {
            if ($nc !== '' && bg_compact_key(isset($p['no']) ? $p['no'] : '') === $nc) {
                $hit = $p;
                break;
            }
        }
    }
    if (!$hit && $nm !== '') {
        foreach ($parts as $p) {
            if (bg_norm_key(isset($p['name']) ? $p['name'] : '') === $nm) {
                $hit = $p;
                break;
            }
        }
    }
    if (!$hit && $n !== '') {
        foreach ($parts as $p) {
            if (isset($p['name']) && $p['name'] !== '' && bg_norm_key($p['name']) === $n) {
                $hit = $p;
                break;
            }
        }
    }
    if ($hit) {
        return array('part' => $hit, 'score' => 1);
    }
    $fz = bg_fuzzy_candidates($parts, trim($no . ' ' . $name), 1);
    return count($fz) ? $fz[0] : null;
}

function bg_all_parts_for_match($pdo)
{
    $out = array();
    foreach ($pdo->query('SELECT * FROM parts ORDER BY updated_at DESC, id DESC') as $row) {
        $out[] = bg_part_row($row);
    }
    return $out;
}

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
            'version' => '2.3',
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
                    $matches[] = bg_match_part($parts, $no, $name);
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
        $head = array('零件图号', '零件名称', '材料', '库存数量', '备注');
        for ($i = 1; $i <= 9; $i++) {
            $head[] = '工序' . $i;
            $head[] = '工序说明' . $i;
            $head[] = '单价' . $i;
        }
        $lines = array(implode(',', array_map('bg_csv_cell', $head)));
        foreach ($stmt as $row) {
            $p = bg_part_row($row);
            $ps = isset($p['processes']) && is_array($p['processes']) ? $p['processes'] : array();
            $line = array($p['no'], $p['name'], $p['material'], $p['stock'], $p['note']);
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
                    $next['settings'][$f['key']] = (string)(float)$v;
                }
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
        header('Content-Type: ' . $types[$ext]);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($path);
        exit;

    default:
        bg_fail(404, '接口不存在');
}
