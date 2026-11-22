<?php
/*
 * 识别日志插件 · 自检
 *
 * 检查日志目录是否可写，并列出今日条数与最近几条记录。
 * 由 api.php?action=plugin.check&id=ocr-log 引入。
 */

if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

global $DATA_DIR;
$dir = ($DATA_DIR !== '' ? $DATA_DIR : dirname(dirname(dirname(__DIR__))) . '/data') . '/ocr-logs';
$file = $dir . '/' . date('Y-m-d') . '.jsonl';

$writable = true;
if (!is_dir($dir)) {
    $writable = @mkdir($dir, 0775, true) || is_dir($dir);
} else {
    $writable = is_writable($dir);
}

$details = array();
$details[] = array(
    'label' => '日志目录',
    'ok' => $writable,
    'detail' => $dir . ($writable ? '（可写）' : '（不可写，请检查 data 目录权限）'),
);

$lines = array();
if (is_file($file)) {
    $raw = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($raw)) {
        $lines = $raw;
    }
}
$cnt = count($lines);
$details[] = array(
    'label' => '今日记录',
    'ok' => true,
    'detail' => $writable
        ? ($cnt . ' 条 · ' . ($cnt ? '最近写入 ' . date('H:i:s', (int)@filemtime($file)) : '暂无记录，识别一次后自动生成'))
        : '目录不可写，无法记录',
);

foreach (array_slice($lines, -6) as $ln) {
    $e = json_decode($ln, true);
    if (!is_array($e)) {
        continue;
    }
    $time = isset($e['ts']) ? substr((string)$e['ts'], 11) : '';
    if (isset($e['kind']) && $e['kind'] === 'match') {
        $hit = isset($e['hit']) && $e['hit'] !== '' ? (string)$e['hit'] : '未匹配';
        $cands = isset($e['cands']) && is_array($e['cands']) ? implode(' / ', array_slice($e['cands'], 0, 3)) : '';
        $details[] = array(
            'label' => $time . ' 零件匹配',
            'ok' => isset($e['hit']) && $e['hit'] !== '',
            'detail' => '「' . (isset($e['q']) ? $e['q'] : '') . '」→ ' . $hit
                . (isset($e['score']) && $e['score'] > 0 ? '（' . $e['score'] . '）' : '')
                . ($cands !== '' ? ' 候选 ' . $cands : ''),
        );
        continue;
    }
    $items = isset($e['items']) && is_array($e['items']) ? count($e['items']) : 0;
    $warn = isset($e['warnings']) && is_array($e['warnings']) && $e['warnings']
        ? ' ｜ ' . $e['warnings'][0] : '';
    $details[] = array(
        'label' => $time . (isset($e['kind']) && $e['kind'] === 'engine' ? ' 子引擎' : ' 识别'),
        'ok' => true,
        'detail' => (isset($e['plugin']) ? $e['plugin'] : '')
            . ' · ' . (isset($e['engine']) ? $e['engine'] : '')
            . ' · ' . $items . ' 行'
            . ' · ' . round(((float)(isset($e['conf']) ? $e['conf'] : 0)) * 100) . '%'
            . ' · ' . (isset($e['ms']) ? (int)$e['ms'] : 0) . 'ms'
            . $warn,
    );
}

return array(
    'ok' => $writable,
    'message' => $writable ? '日志正常，今日 ' . $cnt . ' 条记录' : '日志目录不可写：' . $dir,
    'details' => $details,
);
