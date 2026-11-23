<?php
/* 模块: lib/log
   职责: 识别日志与匹配日志的落盘
   依赖: lib/config
   注意: 由 ocr-log 插件开关控制 */

/* ---------------- 识别日志（由 ocr-log 插件开关控制） ---------------- */

function bg_log_cfg()
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $all = bg_plugin_all();
    if (!isset($all['ocr-log']) || !bg_plugin_is_enabled('ocr-log')) {
        $cfg = false;
        return $cfg;
    }
    $cfg = bg_plugin_config($all['ocr-log']);
    return $cfg;
}

function bg_log_dir()
{
    global $DATA_DIR;
    return $DATA_DIR . '/ocr-logs';
}

function bg_log_write($entry)
{
    $cfg = bg_log_cfg();
    if ($cfg === false || !is_array($entry)) {
        return;
    }
    $dir = bg_log_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    $entry = array('ts' => date('Y-m-d H:i:s')) + $entry;
    $line = @json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }
    @file_put_contents($dir . '/' . date('Y-m-d') . '.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);

    $keep = isset($cfg['keepDays']) && is_numeric($cfg['keepDays']) ? (int)$cfg['keepDays'] : 14;
    if ($keep < 1) {
        $keep = 1;
    }
    $cut = time() - $keep * 86400;
    foreach ((array)@glob($dir . '/*.jsonl') as $file) {
        if (is_string($file) && @filemtime($file) < $cut) {
            @unlink($file);
        }
    }
}

function bg_log_image($dataUrl)
{
    static $info = null;
    if ($info !== null) {
        return $info;
    }
    $info = array('len' => is_string($dataUrl) ? strlen($dataUrl) : 0, 'id' => '');
    if (is_string($dataUrl) && $dataUrl !== '') {
        $info['id'] = substr(sha1($dataUrl), 0, 10);
    }
    return $info;
}

function bg_log_ocr($result, $ms, $kind, $image = '')
{
    $cfg = bg_log_cfg();
    if ($cfg === false || !is_array($result)) {
        return;
    }
    $f = isset($result['fields']) && is_array($result['fields']) ? $result['fields'] : array();
    $items = array();
    $src = isset($f['items']) && is_array($f['items']) ? $f['items'] : array();
    foreach (array_slice($src, 0, 20) as $it) {
        if (!is_array($it)) {
            continue;
        }
        $items[] = array(
            'no' => (string)(isset($it['partNo']) ? $it['partNo'] : ''),
            'name' => (string)(isset($it['partName']) ? $it['partName'] : ''),
            'proc' => (string)(isset($it['process']) ? $it['process'] : ''),
            'qty' => isset($it['qty']) ? $it['qty'] : 0,
            'price' => isset($it['price']) ? $it['price'] : null,
        );
    }
    $entry = array(
        'kind' => $kind,
        'plugin' => isset($result['plugin']['id']) ? $result['plugin']['id'] : '',
        'engine' => isset($result['engine']) ? $result['engine'] : '',
        'ms' => (int)$ms,
        'conf' => isset($result['confidence']) ? $result['confidence'] : 0,
        'date' => isset($f['date']) ? $f['date'] : '',
        'worker' => isset($f['worker']) ? $f['worker'] : '',
        'workshop' => isset($f['workshop']) ? $f['workshop'] : '',
        'items' => $items,
        'warnings' => isset($result['warnings']) && is_array($result['warnings'])
            ? array_slice($result['warnings'], 0, 6) : array(),
    );
    if ($image !== '') {
        $entry['image'] = bg_log_image($image);
    }
    if (!isset($cfg['level']) || (string)$cfg['level'] !== 'basic') {
        $entry['raw'] = bg_plugin_cut(isset($result['raw']) ? $result['raw'] : '', 1500);
    }
    bg_log_write($entry);
}

function bg_log_match_on()
{
    $cfg = bg_log_cfg();
    return $cfg !== false && (!isset($cfg['logMatch']) || (string)$cfg['logMatch'] !== '');
}

function bg_log_match($no, $name, $hit, $cands)
{
    if (!bg_log_match_on()) {
        return;
    }
    $arr = array();
    foreach (array_slice((array)$cands, 0, 3) as $c) {
        if (!is_array($c) || !isset($c['part']) || !is_array($c['part'])) {
            continue;
        }
        $arr[] = (isset($c['part']['no']) ? (string)$c['part']['no'] : '')
            . ':' . (isset($c['score']) ? round((float)$c['score'], 3) : 0);
    }
    bg_log_write(array(
        'kind' => 'match',
        'q' => bg_plugin_cut((string)$no, 40),
        'name' => bg_plugin_cut((string)$name, 40),
        'hit' => is_array($hit) && isset($hit['part']['no']) ? (string)$hit['part']['no'] : '',
        'score' => is_array($hit) && isset($hit['score']) ? round((float)$hit['score'], 3) : 0,
        'cands' => $arr,
    ));
}
