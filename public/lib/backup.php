<?php
/* 模块: lib/backup
   职责: 快照创建/清理/恢复、自动备份、清空业务数据
   依赖: lib/config lib/db
   注意: 恢复前会先存一份“恢复前”快照 */

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

/* 递归删除目录内文件与子目录（保留目录本身），返回删除的文件数 */
function bg_clear_dir_files($dir)
{
    $items = @scandir($dir);
    if (!is_array($items)) {
        return 0;
    }
    $n = 0;
    foreach ($items as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $path = $dir . '/' . $f;
        if (is_dir($path)) {
            $n += bg_clear_dir_files($path);
            @rmdir($path);
        } elseif (@unlink($path)) {
            $n++;
        }
    }
    return $n;
}

/* 清空全部业务数据：零件 / 订单 / 报工单、全部图片、识别日志与审查记录；快照保留，便于误清除后恢复 */
function bg_data_clear()
{
    global $PART_IMG_DIR, $ATT_IMG_DIR, $DRAW_DIR, $DATA_DIR, $BG_SNAP;
    $counts = bg_snapshot_counts();
    $pdo = bg_pdo();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM report_items');
        $pdo->exec('DELETE FROM reports');
        $pdo->exec('DELETE FROM orders');
        $pdo->exec('DELETE FROM parts');
        /* 清空业务数据的同时重置单号序列，下次从 01 开始 */
        $pdo->exec('DELETE FROM seq');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $images = 0;
    foreach (array($PART_IMG_DIR, $ATT_IMG_DIR, $DRAW_DIR) as $dir) {
        $items = @scandir($dir);
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            if (is_file($dir . '/' . $f) && @unlink($dir . '/' . $f)) {
                $images++;
            }
        }
    }
    /* 识别与审查数据：审查工作台列表来自 OCR 日志，逐条判定存在审查记录与归档。
       handwriting_lexicon.json 是人工积累的词库资产，不随业务数据清除。 */
    $logs = bg_clear_dir_files($DATA_DIR . '/ocr-logs');
    $reviews = 0;
    $reviewFile = $DATA_DIR . '/handwriting_review.json';
    if (is_file($reviewFile) && @unlink($reviewFile)) {
        $reviews = 1;
    }
    bg_clear_dir_files($DATA_DIR . '/review-archive');
    $BG_SNAP = null;
    return array(
        'cleared' => $counts,
        'images' => $images,
        'logs' => $logs,
        'reviews' => $reviews,
    );
}
