<?php
/**
 * OCF 横切 · 裁决链路记录（旁路 JSONL）
 *
 * trace 不进返回值（返回字段受 items/warnings/raw 上限约束且会被主程序归一化丢弃），
 * 采用旁路文件 data/ocr-logs/fusion-trace-YYYY-MM-DD.jsonl，用 invokeId 与主日志关联。
 * 零改动 api.php；满足 R-09「任一字段可还原判定链路」。
 */

if (defined('OCF_TRACE_LOADED')) {
    return;
}
define('OCF_TRACE_LOADED', 1);

/**
 * 请求级 trace 状态。
 *
 * @param bool $reset 是否重置
 * @return array 引用
 */
function &ocf_trace_state($reset = false)
{
    static $s = array('active' => false, 'invokeId' => '', 'ts' => '', 'fields' => array(), 'meta' => array());
    if ($reset) {
        $s = array('active' => false, 'invokeId' => '', 'ts' => '', 'fields' => array(), 'meta' => array());
    }
    return $s;
}

/**
 * trace 日志目录。
 *
 * @return string
 */
function ocf_trace_dir()
{
    $dir = '';
    if (isset($GLOBALS['DATA_DIR']) && is_string($GLOBALS['DATA_DIR'])) {
        $dir = $GLOBALS['DATA_DIR'];
    }
    if ($dir === '') {
        $env = getenv('OCF_DATA_DIR');
        if ($env !== false && trim((string)$env) !== '') {
            $dir = (string)$env;
        }
    }
    if ($dir === '') {
        $dir = dirname(__DIR__, 4) . '/data';
    }
    return rtrim(str_replace('\\', '/', $dir), '/') . '/ocr-logs';
}

/**
 * 生成 invokeId（与主日志关联）。
 *
 * @return string
 */
function ocf_trace_invoke_id()
{
    return date('YmdHis') . '-ocrfusion-' . substr(md5(uniqid('', true)), 0, 4);
}

/**
 * 开始一次 trace（架构 §5.4 横切 begin）。
 *
 * @param array $ctx 上下文
 * @param array $cfg 配置
 * @return void
 */
function ocf_trace_begin($ctx, $cfg)
{
    $s = &ocf_trace_state(true);
    if (empty($cfg['enableTrace']) || (isset($cfg['traceLevel']) && $cfg['traceLevel'] === 'off')) {
        return; /* 关闭 trace */
    }
    $s['active'] = true;
    $s['invokeId'] = isset($ctx['invokeId']) ? (string)$ctx['invokeId'] : ocf_trace_invoke_id();
    $s['ts'] = date('c');
    $s['meta'] = array(
        'engineCount' => isset($ctx['engineCount']) ? (int)$ctx['engineCount'] : 0,
        'today' => isset($ctx['today']) ? (string)$ctx['today'] : date('Y-m-d'),
        'traceLevel' => isset($cfg['traceLevel']) ? (string)$cfg['traceLevel'] : 'full',
    );
}

/**
 * 记录某字段某层的一个节点（架构 §5.4 ocf_trace_field）。
 *
 * @param string $field   字段名
 * @param string $stage   层标识 L0~L6
 * @param mixed  $payload 载荷
 * @return void
 */
function ocf_trace_field($field, $stage, $payload)
{
    $s = &ocf_trace_state();
    if (!$s['active']) {
        return;
    }
    if (!isset($s['fields'][$field])) {
        $s['fields'][$field] = array(
            'field' => $field,
            'stages' => array(),
            'candidates' => array(),
            'evidence' => array(),
        );
    }
    if ($stage === 'candidates') {
        $s['fields'][$field]['candidates'] = is_array($payload) ? $payload : array();
        return;
    }
    if ($stage === 'evidence') {
        $s['fields'][$field]['evidence'] = is_array($payload) ? $payload : array($payload);
        return;
    }
    $s['fields'][$field]['stages'][$stage] = $payload;
}

/**
 * 提交某字段的最终结果到 trace。
 *
 * @param array $fieldTrace {field, rule, value, confidence, decision, evidence}
 * @return void
 */
function ocf_trace_commit($fieldTrace)
{
    $s = &ocf_trace_state();
    if (!$s['active']) {
        return;
    }
    $fieldTrace = is_array($fieldTrace) ? $fieldTrace : array();
    $field = isset($fieldTrace['field']) ? $fieldTrace['field'] : '';
    if ($field === '') {
        return;
    }
    if (!isset($s['fields'][$field])) {
        $s['fields'][$field] = array('field' => $field, 'stages' => array(), 'candidates' => array(), 'evidence' => array());
    }
    foreach (array('rule', 'value', 'confidence', 'decision') as $k) {
        if (array_key_exists($k, $fieldTrace)) {
            $s['fields'][$field][$k] = $fieldTrace[$k];
        }
    }
    if (!empty($fieldTrace['evidence'])) {
        $ev = is_array($fieldTrace['evidence']) ? $fieldTrace['evidence'] : array($fieldTrace['evidence']);
        $s['fields'][$field]['evidence'] = array_values(array_unique(array_merge($s['fields'][$field]['evidence'], $ev)));
    }
}

/**
 * 落盘 trace 到 JSONL（一行一个 invokeId），原子追加。
 *
 * @param float $finalConfidence 综合置信度
 * @param array $warnings        告警
 * @return string|null invokeId（关闭时 null）
 */
function ocf_trace_flush($finalConfidence, $warnings)
{
    $s = &ocf_trace_state();
    if (!$s['active']) {
        return null;
    }
    $dir = ocf_trace_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $path = $dir . '/fusion-trace-' . date('Y-m-d') . '.jsonl';
    $doc = array(
        'invokeId' => $s['invokeId'],
        'ts' => $s['ts'],
        'engineCount' => isset($s['meta']['engineCount']) ? $s['meta']['engineCount'] : 0,
        'fields' => array_values($s['fields']),
        'finalConfidence' => round((float)$finalConfidence, 3),
        'warnings' => array_values($warnings),
    );
    $line = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line !== false) {
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }
    $invokeId = $s['invokeId'];
    $s['active'] = false; /* 落盘后关闭，同进程多次 flush 不重复 */
    return $invokeId;
}
