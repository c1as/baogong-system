<?php
/**
 * 统一归一化层（T04）
 *
 * 把腾讯云 OCR 的中间行结果与混元 Hy4 的解析结果，统一映射为契约 Fields：
 * date / worker / workshop / note / items[{partNo,partName,process,qty,price}]。
 * 所有日期、数量、字符串长度约束均复用 lib/util.php，不在此处重复实现。
 */

if (defined('BG_TV_NORM_LOADED')) {
    return;
}
define('BG_TV_NORM_LOADED', 1);

if (!function_exists('bg_tv_cfg')) {
    require_once __DIR__ . '/util.php';
}
if (!function_exists('bg_tv_hy4_raw_text')) {
    require_once __DIR__ . '/hy4_vision.php';
}

/**
 * 按候选键取原始值（找不到返回空串）。
 *
 * @param array $arr  输入数组
 * @param array $keys 候选键
 * @return mixed
 */
function bg_tv_pick_field($arr, $keys)
{
    if (!is_array($arr)) {
        return '';
    }
    foreach ($keys as $k) {
        if (array_key_exists($k, $arr) && $arr[$k] !== null && $arr[$k] !== '') {
            return $arr[$k];
        }
    }
    return '';
}

/**
 * 单字段归一（架构 normScalar 签名）。
 *
 * @param string $field 字段名
 * @param mixed  $value 原始值
 * @param array  $cfg   配置（可含 _today）
 * @return mixed
 */
function bg_tv_norm_scalar($field, $value, $cfg)
{
    $today = bg_tv_cfg($cfg, '_today', date('Y-m-d'));
    switch ($field) {
        case 'date':
            return bg_tv_date($value, $today);
        case 'worker':
        case 'workshop':
            return bg_tv_str($value, 40);
        case 'note':
            return bg_tv_str($value, 100);
        case 'partNo':
        case 'partName':
        case 'process':
            return bg_tv_str($value, 40);
        case 'qty':
        case 'price':
        case 'confidence':
            return bg_tv_qty($value);
        default:
            return bg_tv_str($value, 100);
    }
}

/**
 * 归一明细列表。
 *
 * @param mixed $rawItems 原始明细
 * @param array $cfg      配置
 * @return array
 */
function bg_tv_norm_items($rawItems, $cfg)
{
    if (isset($rawItems[0]) && is_array($rawItems[0])) {
        $list = $rawItems;
    } else {
        $list = bg_tv_pick_field($rawItems, array('items', '明细', '报工明细', '列表', 'rows', 'lines'));
        if (!is_array($list)) {
            $list = array();
        }
    }
    $out = array();
    foreach ($list as $row) {
        if (count($out) >= 20 || !is_array($row)) {
            break;
        }
        $no = bg_tv_norm_scalar('partNo', bg_tv_pick_field($row, array('partNo', '图号', '零件号', '零件图号')), $cfg);
        $name = bg_tv_norm_scalar('partName', bg_tv_pick_field($row, array('partName', '零件名称', '名称', '品名')), $cfg);
        $proc = bg_tv_norm_scalar('process', bg_tv_pick_field($row, array('process', '工序', '工序名称')), $cfg);
        $qty = bg_tv_qty(bg_tv_pick_field($row, array('qty', '数量', '报工数', '件数')));
        $price = bg_tv_qty(bg_tv_pick_field($row, array('price', '单价', '价格')));
        if ($no === '' && $name === '' && $proc === '') {
            continue;
        }
        $out[] = array(
            'partNo' => $no,
            'partName' => $name,
            'process' => $proc,
            'qty' => ($qty === null) ? 0 : $qty,
            'price' => $price,
        );
    }
    return $out;
}

/**
 * 从 OCR 还原行中解析报工字段（带标签优先 + 图号正则 + 数量尾数）。
 *
 * @param array $rows OCR 还原行 {text,cells}
 * @param array $cfg  配置
 * @param array $ctx  上下文（含 catalog/today）
 * @return array Fields
 */
function bg_tv_norm_ocr_rows($rows, $cfg, $ctx)
{
    $today = (isset($ctx['today']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ctx['today']))
        ? (string)$ctx['today'] : date('Y-m-d');
    $catalog = (isset($ctx['catalog']) && is_array($ctx['catalog'])) ? $ctx['catalog'] : array();
    $allText = '';
    foreach ($rows as $row) {
        $allText .= (isset($row['text']) ? (string)$row['text'] : '') . "\n";
    }
    $date = bg_tv_date($allText, $today);
    $worker = '';
    $workshop = '';
    $note = '';
    $items = array();

    foreach ($rows as $row) {
        $text = isset($row['text']) ? (string)$row['text'] : '';
        $cells = (isset($row['cells']) && is_array($row['cells'])) ? $row['cells'] : array();
        if ($text === '') {
            continue;
        }

        if ($worker === '' && preg_match('/(报工人|报工员|记录人|操作员|签名)\s*[:：]?\s*([^\s,，、;：:]{1,12})/u', $text, $m)) {
            $cand = trim($m[2]);
            if ($cand !== '' && !preg_match('/^(报工单|姓名|车间|日期)$/u', $cand)) {
                $worker = $cand;
            }
        }
        if ($workshop === '' && preg_match('/(车间|工段|班组)\s*[:：]?\s*([^\s,，、;：:]{1,12})/u', $text, $m)) {
            $cand = trim($m[2]);
            if ($cand !== '' && !preg_match('/(单|表|栏|名称|号)$/u', $cand)) {
                $workshop = $cand;
            }
        }
        if ($note === '' && preg_match('/(备注|说明)\s*[:：]\s*([^\n]{1,40})/u', $text, $m)) {
            $note = bg_tv_str(trim($m[2]), 100);
        }

        // 表头 / 合计 / 签名行跳过；但若该行含形如图号串则仍按明细处理。
        $hasPartLike = (bool)preg_match('/[A-Za-z][A-Za-z0-9\-\/\.]{4,}/', $text);
        if (!$hasPartLike && preg_match('/(合计|小计|总计|签名|序号|图\s*号|零件名称|工序|单价|报工单)/u', $text)) {
            continue;
        }

        $no = '';
        $name = '';
        $proc = '';
        foreach ($catalog as $p) {
            if (!is_array($p)) {
                continue;
            }
            $pn = isset($p['no']) ? trim((string)$p['no']) : '';
            if ($pn !== '' && bg_tv_contains($text, $pn)) {
                $no = $pn;
                $name = isset($p['name']) ? trim((string)$p['name']) : '';
                if (isset($p['processes']) && is_array($p['processes'])) {
                    foreach ($p['processes'] as $ps) {
                        $ps = trim((string)$ps);
                        if ($ps !== '' && bg_tv_contains($text, $ps)) {
                            $proc = $ps;
                            break;
                        }
                    }
                }
                break;
            }
        }
        if ($no === '' && preg_match('/(?<![A-Za-z0-9])([A-Za-z][A-Za-z0-9\-\/\.]{4,29})(?![A-Za-z0-9])/u', $text, $m)) {
            $no = $m[1];
        }
        if ($no === '') {
            continue;
        }

        // 名称与工序：优先从单元格中取，避免把日期/数量误当名称。
        foreach ($cells as $cell) {
            $ct = isset($cell['text']) ? trim((string)$cell['text']) : '';
            if ($ct === '' || $ct === $no || preg_match('/^\d+(?:\.\d+)?$/', $ct)) {
                continue;
            }
            if ($proc === '' && preg_match('/^(车|铣|钻|磨|钳|刨|镗|锯|焊|热处理|电镀|装配|检验|数控| CNC)$/iu', $ct)) {
                $proc = $ct;
                continue;
            }
            if ($name === '' && preg_match('/[\x{4e00}-\x{9fa5}]/u', $ct)
                && !preg_match('/(日期|报工|车间|备注|合计|小计|签名)/u', $ct)) {
                $name = $ct;
            }
        }

        // 数量：取本行独立成格的数字；否则取剥离图号后的末尾裸数字。
        $numbers = array();
        foreach ($cells as $cell) {
            $ct = isset($cell['text']) ? trim((string)$cell['text']) : '';
            if (preg_match('/^\d+(?:\.\d+)?$/', $ct)) {
                $numbers[] = $ct;
            }
        }
        if (!$numbers) {
            $bare = preg_replace('/[A-Za-z][A-Za-z0-9\-\/\.]{4,}/u', ' ', $text);
            if (preg_match_all('/(?<!\d)(\d+(?:\.\d+)?)(?!\d)/u', $bare, $mm) && $mm[1]) {
                $numbers = $mm[1];
            }
        }
        $qty = null;
        if ($numbers) {
            $last = $numbers[count($numbers) - 1];
            $qty = bg_tv_qty($last);
        }
        $items[] = array(
            'partNo' => bg_tv_str($no, 40),
            'partName' => bg_tv_str($name, 40),
            'process' => bg_tv_str($proc, 40),
            'qty' => ($qty === null) ? 0 : $qty,
            'price' => null,
        );
        if (count($items) >= 20) {
            break;
        }
    }

    return array(
        'date' => $date,
        'worker' => bg_tv_str($worker, 40),
        'workshop' => bg_tv_str($workshop, 40),
        'note' => bg_tv_str($note, 100),
        'items' => array_slice($items, 0, 20),
    );
}

/**
 * OCR 中间 EngineResult → 统一 EngineResult。
 *
 * @param array $mid OCR 中间结果（含 _mid.rows）
 * @param array $cfg 配置
 * @return array EngineResult
 */
function bg_tv_norm_ocr($mid, $cfg)
{
    $mid = is_array($mid) ? $mid : array();
    $rows = (isset($mid['_mid']['rows']) && is_array($mid['_mid']['rows'])) ? $mid['_mid']['rows'] : array();
    $ctx = array(
        'catalog' => isset($mid['_catalog']) && is_array($mid['_catalog']) ? $mid['_catalog'] : array(),
        'today' => isset($mid['_today']) ? $mid['_today'] : date('Y-m-d'),
    );
    $fields = bg_tv_norm_ocr_rows($rows, $cfg, $ctx);
    $warnings = isset($mid['warnings']) && is_array($mid['warnings']) ? $mid['warnings'] : array();
    if ($fields['date'] === '' && bg_tv_bool($cfg, 'dateFallback', true)) {
        $fields['date'] = $ctx['today'];
        $warnings[] = '未识别到单据日期，已按今天填充';
    }
    if (!$fields['items']) {
        $warnings[] = '未识别出报工明细，可对照下方原文手动填充';
    }
    return array(
        'engineId' => 'tencentOcr',
        'confidence' => bg_tv_conf(isset($mid['confidence']) ? $mid['confidence'] : 0.0, 0.0),
        'fields' => $fields,
        'raw' => isset($mid['raw']) ? (string)$mid['raw'] : '',
        'warnings' => $warnings,
        'usage' => isset($mid['usage']) && is_array($mid['usage']) ? $mid['usage'] : array('prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0),
        'elapsedMs' => isset($mid['elapsedMs']) ? (int)$mid['elapsedMs'] : 0,
        'ok' => isset($mid['ok']) ? (bool)$mid['ok'] : true,
        '_mid' => isset($mid['_mid']) ? $mid['_mid'] : array(),
    );
}

/**
 * Hy4 解析结果 → 统一 EngineResult。
 *
 * @param array  $parsed 解析结果
 * @param string $source json|text
 * @param array  $cfg    配置
 * @return array EngineResult
 */
function bg_tv_norm_hy4($parsed, $source, $cfg)
{
    $parsed = is_array($parsed) ? $parsed : array();
    $today = bg_tv_cfg($cfg, '_today', date('Y-m-d'));
    $fields = array(
        'date' => bg_tv_norm_scalar('date', bg_tv_pick_field($parsed, array('date', '报工日期', '日期', '填单日期', '生产日期')), $cfg),
        'worker' => bg_tv_norm_scalar('worker', bg_tv_pick_field($parsed, array('worker', '报工人', '报工员', '姓名', '签名')), $cfg),
        'workshop' => bg_tv_norm_scalar('workshop', bg_tv_pick_field($parsed, array('workshop', '车间', '工段', '班组')), $cfg),
        'note' => bg_tv_norm_scalar('note', bg_tv_pick_field($parsed, array('note', '备注', '说明')), $cfg),
        'items' => bg_tv_norm_items($parsed, $cfg),
    );
    if ($fields['date'] === '' && bg_tv_bool($cfg, 'dateFallback', true)) {
        $fields['date'] = $today;
    }
    $confidence = bg_tv_conf(
        isset($parsed['confidence']) ? $parsed['confidence'] : null,
        ($source === 'json') ? 0.88 : 0.45
    );
    $warnings = array();
    if (!$fields['items']) {
        $warnings[] = '未识别出报工明细，可对照下方原文手动填充';
    }
    return array(
        'engineId' => 'hy4Vision',
        'confidence' => ($source === 'text') ? min($confidence, 0.60) : $confidence,
        'fields' => $fields,
        'raw' => bg_tv_hy4_raw_text($parsed, '', $source),
        'warnings' => $warnings,
        'usage' => array('prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0),
        'elapsedMs' => 0,
        'ok' => true,
        '_mid' => array('parsed' => $parsed, 'source' => $source),
    );
}

/**
 * 确保 Hy4 原文解析函数可用（延迟引入，避免循环依赖）。
 *
 * @return void
 */
function bg_tv_norm_ensure_hy4()
{
    if (!function_exists('bg_tv_hy4_raw_text') && is_file(__DIR__ . '/hy4_vision.php')) {
        require_once __DIR__ . '/hy4_vision.php';
    }
}
