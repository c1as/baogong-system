<?php
/*
 * 识别日志插件 · 自检脚本
 *
 * 校验日志目录可写、主日志存在，并关联展示 fusion-trace 裁决链路文件，
 * 按 invokeId 串联「主日志 ↔ 融合 trace」，便于排查每一字段判定依据。
 */

if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

$ctx = $GLOBALS['BG_PLUGIN_CTX'];
$cfg = isset($ctx['config']) && is_array($ctx['config']) ? $ctx['config'] : array();

/**
 * 数据目录（与 ocf/handwriting-lexicon 保持同一解析顺序）。
 *
 * @return string
 */
function ocrlog_data_dir()
{
    if (isset($GLOBALS['DATA_DIR']) && is_string($GLOBALS['DATA_DIR']) && $GLOBALS['DATA_DIR'] !== '') {
        return rtrim(str_replace('\\', '/', $GLOBALS['DATA_DIR']), '/');
    }
    return dirname(__DIR__, 3) . '/data';
}

$details = array();
$allOk = true;
$dataDir = ocrlog_data_dir();
$logDir = $dataDir . '/ocr-logs';

if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$writable = is_dir($logDir) && is_writable($logDir);
$details[] = array(
    'label' => '日志目录',
    'ok' => $writable,
    'detail' => $logDir . ($writable ? '（可写）' : '（不可写，请检查权限）'),
);
if (!$writable) {
    $allOk = false;
}

/* 主日志（当天） */
$mainLog = $logDir . '/report.ocr.log';
$mainOk = is_file($mainLog);
$details[] = array(
    'label' => '主日志（report.ocr.log）',
    'ok' => true,
    'detail' => $mainOk ? '存在（' . @date('Y-m-d H:i', @filemtime($mainLog)) . ' 更新）' : '尚未生成（首次识别后创建）',
);

/* 融合 trace（当天 JSONL） */
$traceFile = $logDir . '/fusion-trace-' . date('Y-m-d') . '.jsonl';
$traceExists = is_file($traceFile);
$traceCount = 0;
$traceSample = '';
if ($traceExists) {
    $lines = file($traceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $traceCount = is_array($lines) ? count($lines) : 0;
    if ($traceCount > 0) {
        $last = json_decode($lines[$traceCount - 1], true);
        if (is_array($last)) {
            $traceSample = '最近 invokeId：' . (isset($last['invokeId']) ? $last['invokeId'] : '-')
                . ' · 字段数 ' . count(isset($last['fields']) && is_array($last['fields']) ? $last['fields'] : array());
        }
    }
}
$details[] = array(
    'label' => '融合裁决链路（fusion-trace）',
    'ok' => true,
    'detail' => $traceExists
        ? ('今日 ' . $traceCount . ' 条记录；' . ($traceSample !== '' ? $traceSample : '可解析'))
        : '今日暂无 trace（融合引擎开启 traceLevel 后按 invokeId 写入）',
);

/* keepDays 配置校验 */
$keepDays = isset($cfg['keepDays']) && is_numeric($cfg['keepDays']) ? (int)$cfg['keepDays'] : 14;
$keepOk = $keepDays >= 1 && $keepDays <= 180;
$details[] = array(
    'label' => '日志保留天数',
    'ok' => $keepOk,
    'detail' => $keepOk ? ($keepDays . ' 天（主日志与 fusion-trace 同生命周期）') : '必须是 1~180 之间的整数',
);
if (!$keepOk) {
    $allOk = false;
}

return array(
    'ok' => $allOk,
    'message' => $allOk ? '日志与裁决链路自检通过' : '配置或权限有误，请查看下方明细',
    'details' => $details,
);
