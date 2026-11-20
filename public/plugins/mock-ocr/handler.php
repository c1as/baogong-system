<?php
/**
 * 模拟识别插件处理器（演示用）
 *
 * 接入约定：
 *   - 由 api.php?action=plugin.invoke 调用
 *   - 读取 $GLOBALS['BG_PLUGIN_CTX']：image / catalog / config / today / locale
 *   - return 统一结果数组：
 *     [
 *       'engine' => '引擎名',
 *       'confidence' => 0.92,
 *       'fields' => [
 *         'date' => 'YYYY-MM-DD',
 *         'items' => [ ['partNo'=>'', 'partName'=>'', 'process'=>'', 'qty'=>1], ... ],
 *       ],
 *       'warnings' => ['...'],
 *       'raw' => '识别到的原文',
 *     ]
 *
 * 真实插件只需把这里替换成 OCR / 大模型调用，并按上面的结构返回即可。
 */

/* 仅允许由 api.php 加载，禁止直接访问 */
if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

$ctx = $GLOBALS['BG_PLUGIN_CTX'];
$cfg = isset($ctx['config']) && is_array($ctx['config']) ? $ctx['config'] : array();
$catalog = isset($ctx['catalog']) && is_array($ctx['catalog']) ? $ctx['catalog'] : array();
$image = isset($ctx['image']) && is_string($ctx['image']) ? $ctx['image'] : '';
$today = isset($ctx['today']) ? (string)$ctx['today'] : date('Y-m-d');

$engine = isset($cfg['engineLabel']) && $cfg['engineLabel'] !== '' ? $cfg['engineLabel'] : 'mock-ocr';

$rows = isset($cfg['rows']) && is_numeric($cfg['rows']) ? (int)$cfg['rows'] : 2;
$rows = max(1, min(6, $rows));

$date = $today;
if (isset($cfg['dateMode']) && $cfg['dateMode'] === 'yesterday') {
    $date = date('Y-m-d', strtotime($today . ' -1 day'));
}

/* 同一张图片得到同样的结果，便于反复演示 */
$seed = (int)(crc32(substr($image, 0, 2048)) % 2147483647);
$rand = function () use (&$seed) {
    $seed = (int)(($seed * 1103515245 + 12345) % 2147483647);
    return $seed;
};

$items = array();
$warnings = array();
if (!$catalog) {
    $warnings[] = '零件库为空，只能填充日期';
} else {
    $used = array();
    for ($i = 0; $i < $rows; $i++) {
        $part = null;
        for ($try = 0; $try < 8; $try++) {
            $idx = $rand() % count($catalog);
            if (!isset($used[$idx])) {
                $used[$idx] = true;
                $part = $catalog[$idx];
                break;
            }
        }
        if ($part === null) {
            $part = $catalog[$rand() % count($catalog)];
        }
        $proc = '';
        if (!empty($part['processes'])) {
            $proc = (string)$part['processes'][$rand() % count($part['processes'])];
        }
        $qty = 1 + ($rand() % 40);
        $items[] = array(
            'partNo' => isset($part['no']) ? (string)$part['no'] : '',
            'partName' => isset($part['name']) ? (string)$part['name'] : '',
            'process' => $proc,
            'qty' => $qty,
        );
    }
}

$rawLines = array('报工单（模拟识别）', '日期：' . $date);
foreach ($items as $it) {
    $rawLines[] = $it['partNo'] . "\t" . $it['process'] . "\t" . $it['qty'];
}

return array(
    'engine' => $engine,
    'confidence' => 0.86 + ($rand() % 12) / 100,
    'fields' => array(
        'date' => $date,
        'items' => $items,
    ),
    'warnings' => array_merge($warnings, array('演示插件只生成模拟结果，未做真实文字识别')),
    'raw' => implode("\n", $rawLines),
);
