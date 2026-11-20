<?php
/**
 * 报工管理系统 · PHP 后端（群晖 Web Station）
 *
 * 与 Node 版共用 public/ 前端、data/db.json 数据、app.env 配置。
 * 接口：api.php?action=xxx
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$BG_ROOT = dirname(__DIR__);
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
$IMG_DIR = $DATA_DIR . '/images';
$BACKUP_DIR = $DATA_DIR . '/backups';
$DB_FILE = $DATA_DIR . '/db.json';
$SECRET_FILE = $DATA_DIR . '/.secret';

if (!is_dir($DATA_DIR)) {
    @mkdir($DATA_DIR, 0775, true);
}
if (!is_dir($IMG_DIR)) {
    @mkdir($IMG_DIR, 0775, true);
}
if (!is_dir($BACKUP_DIR)) {
    @mkdir($BACKUP_DIR, 0775, true);
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
    $slash = strrpos($v, '/');
    if ($slash !== false) {
        $v = substr($v, $slash + 1);
    }
    return $v;
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
            $price = bg_round(isset($x['price']) ? (float)$x['price'] : 0);
            if ($name === '' && $price == 0) {
                continue;
            }
            $processes[] = array('name' => $name, 'price' => $price);
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

function bg_db_load()
{
    global $DB_FILE, $BACKUP_DIR;
    $empty = array('parts' => array(), 'reports' => array(), 'orders' => array(), 'updatedAt' => null);
    if (!is_file($DB_FILE)) {
        return $empty;
    }
    $raw = @file_get_contents($DB_FILE);
    if ($raw === false || trim($raw) === '') {
        return $empty;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $files = glob($BACKUP_DIR . '/db-*.json');
        if (is_array($files) && count($files) > 0) {
            sort($files);
            $last = $files[count($files) - 1];
            $raw2 = @file_get_contents($last);
            if ($raw2 !== false) {
                $data = json_decode($raw2, true);
            }
            if (is_array($data)) {
                @copy($DB_FILE, $DB_FILE . '.corrupt-' . time());
            }
        }
    }
    if (!is_array($data)) {
        return $empty;
    }

    $parts = array();
    if (isset($data['parts']) && is_array($data['parts'])) {
        foreach ($data['parts'] as $p) {
            if (!is_array($p) || !isset($p['id']) || !isset($p['no'])) {
                continue;
            }
            $parts[] = bg_migrate_part($p);
        }
    }
    $reports = array();
    if (isset($data['reports']) && is_array($data['reports'])) {
        foreach ($data['reports'] as $r) {
            if (!is_array($r) || !isset($r['id'])) {
                continue;
            }
            if (!isset($r['items']) || !is_array($r['items'])) {
                $r['items'] = array();
            }
            $reports[] = bg_migrate_report($r);
        }
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
    return array('parts' => $parts, 'reports' => $reports, 'orders' => $orders, 'updatedAt' => isset($data['updatedAt']) ? $data['updatedAt'] : null);
}

function bg_db_save($db)
{
    global $DB_FILE;
    $db['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
    $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        bg_fail(500, '数据编码失败');
    }
    $fp = @fopen($DB_FILE . '.lock', 'c');
    if ($fp) {
        @flock($fp, LOCK_EX);
    }
    $tmp = $DB_FILE . '.tmp';
    $ok = @file_put_contents($tmp, $json, LOCK_EX);
    if ($ok === false) {
        if ($fp) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
        bg_fail(500, '数据保存失败，请检查 data 目录权限');
    }
    @rename($tmp, $DB_FILE);
    if ($fp) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
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
    @copy($DB_FILE, $BACKUP_DIR . '/db-' . date('Ymd-His') . '.json');
    $files = glob($BACKUP_DIR . '/db-*.json');
    if (is_array($files)) {
        sort($files);
        while (count($files) > 30) {
            @unlink(array_shift($files));
        }
    }
}

function bg_backup_force()
{
    global $DB_FILE, $BACKUP_DIR;
    if (!is_file($DB_FILE)) {
        return;
    }
    @copy($DB_FILE, $BACKUP_DIR . '/db-' . date('Ymd-His') . '-restore.json');
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
    global $DB_FILE, $IMG_DIR, $BACKUP_DIR, $DATA_DIR;
    $dbBytes = 0;
    foreach (array($DB_FILE, $DB_FILE . '-wal', $DB_FILE . '-shm') as $f) {
        if (is_file($f)) {
            $dbBytes += (int)@filesize($f);
        }
    }
    $images = bg_fs_usage($IMG_DIR, '');
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

function bg_image_remove_prefix($prefix)
{
    global $IMG_DIR;
    if (!preg_match('/^[A-Za-z0-9-]+$/', $prefix)) {
        return;
    }
    $files = glob($IMG_DIR . '/' . $prefix . '-*');
    if (is_array($files)) {
        foreach ($files as $f) {
            @unlink($f);
        }
    }
}

function bg_image_remove($name)
{
    global $IMG_DIR;
    $name = bg_image_name($name);
    if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
        return;
    }
    @unlink($IMG_DIR . '/' . $name);
}

function bg_image_save($fileBase, $dataUrl)
{
    global $IMG_DIR;
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
    if (!preg_match('/^[A-Za-z0-9-]+$/', $fileBase)) {
        bg_fail(400, '图片标识不合法');
    }
    $name = $fileBase . $ext;
    if (@file_put_contents($IMG_DIR . '/' . $name, $bin) === false) {
        bg_fail(500, '图片保存失败，请检查 data/images 目录权限');
    }
    return $name;
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
            $price = 0;
            if (isset($raw['price']) && $raw['price'] !== '' && $raw['price'] !== null) {
                $price = bg_round(bg_num($raw['price'], '工序单价', 0, 1000000));
            }
            $out[] = array('name' => $name, 'price' => $price);
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
        bg_json(200, bg_data_stats('json'));
        break;

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
            $part['image'] = bg_image_save($part['id'] . '-' . time(), $in['image']);
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
        if (isset($in['image']) && is_string($in['image']) && strpos($in['image'], 'data:') === 0) {
            bg_image_remove_prefix($part['id']);
            $part['image'] = bg_image_save($part['id'] . '-' . time(), $in['image']);
        } elseif (!empty($in['removeImage'])) {
            bg_image_remove(isset($existing['image']) ? $existing['image'] : '');
            $part['image'] = '';
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
        bg_image_remove_prefix($id);
        array_splice($db['parts'], $target, 1);
        bg_db_save($db);
        bg_json(200, array('ok' => true));
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
        $n = 0;
        foreach ($newImages as $dataUrl) {
            if (is_string($dataUrl) && strpos($dataUrl, 'data:') === 0) {
                $images[] = bg_image_save($report['id'] . '-r' . time() . '-' . $n, $dataUrl);
                $n++;
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
        $n = 0;
        foreach ($newImages as $dataUrl) {
            if (is_string($dataUrl) && strpos($dataUrl, 'data:') === 0) {
                $kept[] = bg_image_save($report['id'] . '-r' . time() . '-' . $n, $dataUrl);
                $n++;
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
        bg_image_remove_prefix($id);
        array_splice($db['reports'], $target, 1);
        bg_db_save($db);
        bg_json(200, array('ok' => true));
        break;

    case 'export.csv':
        $db = bg_db_load();
        $month = isset($_GET['month']) && is_string($_GET['month']) ? $_GET['month'] : '';
        $q = isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : '';
        $list = bg_sorted($db['reports']);
        $filtered = array();
        foreach ($list as $r) {
            $date = isset($r['date']) ? (string)$r['date'] : '';
            if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month) && strpos($date, $month) !== 0) {
                continue;
            }
            if ($q !== '') {
                $hay = (isset($r['no']) ? $r['no'] : '') . ' ' . (isset($r['worker']) ? $r['worker'] : '') . ' ' . (isset($r['orderNo']) ? $r['orderNo'] : '');
                $items = isset($r['items']) && is_array($r['items']) ? $r['items'] : array();
                foreach ($items as $it) {
                    $hay .= ' ' . (isset($it['partNo']) ? $it['partNo'] : '') . ' ' . (isset($it['partName']) ? $it['partName'] : '') . ' ' . (isset($it['orderNo']) ? $it['orderNo'] : '');
                }
                if (stripos($hay, $q) === false) {
                    continue;
                }
            }
            $filtered[] = $r;
        }
        $label = $month !== '' ? $month : '全部';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="baogong.csv"; filename*=UTF-8\'\'' . rawurlencode('报工明细_' . $label . '.csv'));
        header('Cache-Control: no-store');
        echo bg_csv($filtered);
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
        bg_backup_force();
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
        if (!preg_match('/^[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)$/', $f)) {
            bg_fail(404, '图片不存在');
        }
        $path = $IMG_DIR . '/' . $f;
        if (!is_file($path)) {
            bg_fail(404, '图片不存在');
        }
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $types = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
        header('Content-Type: ' . $types[$ext]);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($path);
        exit;

    default:
        bg_fail(404, '接口不存在');
}
