<?php
/* 模块: lib/db
   职责: 存储目录迁移、PDO 初始化、SQLite schema、旧 JSON 导入
   依赖: lib/config
   注意: 迁移只在启动时执行一次 */

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
