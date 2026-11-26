<?php
/**
 * 输出模式与轻量择优层（T04）
 *
 * 只做「两路之间的字段级轻量择优」，不实现 ocr-ensemble 的 L0–L6 裁决：
 * 单字段优先级 = 有值优先 → 一致取主引擎 → 原文佐证优先 → 自报置信度高者。
 * 三种模式：fuse（单结果）/ parallel（主结果 + alternates）/ primary（仅主引擎）。
 */

if (defined('BG_TV_FUSE_LOADED')) {
    return;
}
define('BG_TV_FUSE_LOADED', 1);

if (!function_exists('bg_tv_cfg')) {
    require_once __DIR__ . '/util.php';
}

/**
 * 引擎 id → 中文名（用于 alternates 与告警）。
 *
 * @param string $engineId 引擎 id
 * @return string
 */
function bg_tv_result_label($engineId)
{
    return ($engineId === 'hy4Vision') ? '混元 Hy4' : '腾讯云 OCR';
}

/**
 * 引擎 id → 对外 engine 前缀。
 *
 * @param string $engineId 引擎 id
 * @return string
 */
function bg_tv_engine_prefix($engineId)
{
    return 'tencent-vision/' . (($engineId === 'hy4Vision') ? 'hy4Vision' : 'tencentOcr');
}

/**
 * 按主引擎排序，保证返回的第一个结果恒为主引擎。
 *
 * @param array  $results 引擎结果集合（id => EngineResult）
 * @param string $primary 主引擎 id
 * @return array 索引数组 [主, 备]
 */
function bg_tv_order_by_primary($results, $primary)
{
    $results = is_array($results) ? $results : array();
    $ids = array();
    if (isset($results[$primary])) {
        $ids[] = $primary;
    }
    foreach ($results as $id => $r) {
        if ($id !== $primary && is_array($r) && !empty($r['ok'])) {
            $ids[] = $id;
        }
    }
    $out = array();
    foreach ($ids as $id) {
        if (isset($results[$id])) {
            $out[] = $results[$id];
        }
    }
    while (count($out) < 2) {
        $out[] = array(
            'engineId' => '',
            'confidence' => 0.0,
            'fields' => array('date' => '', 'worker' => '', 'workshop' => '', 'note' => '', 'items' => array()),
            'raw' => '',
            'warnings' => array(),
            'ok' => false,
        );
    }
    return array($out[0], $out[1]);
}

/**
 * 单字段择优：命中即止。
 *
 * @param string $field 字段名
 * @param array  $a     主引擎结果
 * @param array  $b     备引擎结果
 * @param array  $cfg   配置
 * @return mixed
 */
function bg_tv_pick_value($field, $a, $b, $cfg)
{
    $fa = isset($a['fields']) && is_array($a['fields']) ? $a['fields'] : array();
    $fb = isset($b['fields']) && is_array($b['fields']) ? $b['fields'] : array();
    $va = isset($fa[$field]) ? $fa[$field] : '';
    $vb = isset($fb[$field]) ? $fb[$field] : '';
    if (bg_tv_empty($va) && bg_tv_empty($vb)) {
        return '';
    }
    if (bg_tv_empty($va)) {
        return $vb;
    }
    if (bg_tv_empty($vb)) {
        return $va;
    }
    $agree = bg_tv_float($cfg, 'agreeThreshold', 0.85, 0.0, 1.0);
    if (bg_tv_same($va, $vb, $agree)) {
        return $va; // 一致：取主引擎
    }
    $sa = bg_tv_raw_support($va, isset($a['raw']) ? $a['raw'] : '');
    $sb = bg_tv_raw_support($vb, isset($b['raw']) ? $b['raw'] : '');
    if ($sa && !$sb) {
        return $va;
    }
    if ($sb && !$sa) {
        return $vb;
    }
    $ca = isset($a['confidence']) ? (float)$a['confidence'] : 0.0;
    $cb = isset($b['confidence']) ? (float)$b['confidence'] : 0.0;
    return ($ca >= $cb) ? $va : $vb;
}

/**
 * 明细择优：按 partNo 对齐后逐字段调用 bg_tv_pick_value，最多 20 行。
 *
 * @param array $aItems 主引擎明细
 * @param array $bItems 备引擎明细
 * @param array $cfg    配置
 * @param string $aRaw  主引擎原文
 * @param string $bRaw  备引擎原文
 * @return array
 */
function bg_tv_fuse_items($aItems, $bItems, $cfg, $aRaw = '', $bRaw = '')
{
    $aItems = is_array($aItems) ? $aItems : array();
    $bItems = is_array($bItems) ? $bItems : array();
    $ca = isset($cfg['_aConf']) ? (float)$cfg['_aConf'] : 0.0;
    $cb = isset($cfg['_bConf']) ? (float)$cfg['_bConf'] : 0.0;
    $fakeA = array('fields' => array(), 'raw' => $aRaw, 'confidence' => $ca);
    $fakeB = array('fields' => array(), 'raw' => $bRaw, 'confidence' => $cb);
    $fields = array('partNo', 'partName', 'process', 'qty', 'price');

    $mapB = array();
    foreach ($bItems as $idx => $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = !empty($item['partNo']) ? strtoupper((string)$item['partNo']) : ('#' . $idx);
        if (!isset($mapB[$key])) {
            $mapB[$key] = $item;
        }
    }
    $used = array();
    $out = array();
    foreach ($aItems as $idx => $item) {
        if (count($out) >= 20 || !is_array($item)) {
            break;
        }
        $key = !empty($item['partNo']) ? strtoupper((string)$item['partNo']) : ('#' . $idx);
        $other = isset($mapB[$key]) ? $mapB[$key] : array();
        $used[$key] = true;
        $merged = array();
        foreach ($fields as $f) {
            $fakeA['fields'][$f] = isset($item[$f]) ? $item[$f] : '';
            $fakeB['fields'][$f] = isset($other[$f]) ? $other[$f] : '';
            $merged[$f] = bg_tv_pick_value($f, $fakeA, $fakeB, $cfg);
        }
        $out[] = array(
            'partNo' => bg_tv_str($merged['partNo'], 40),
            'partName' => bg_tv_str($merged['partName'], 40),
            'process' => bg_tv_str($merged['process'], 40),
            'qty' => bg_tv_qty($merged['qty']),
            'price' => bg_tv_qty($merged['price']),
        );
    }
    foreach ($bItems as $item) {
        if (count($out) >= 20 || !is_array($item)) {
            break;
        }
        $key = !empty($item['partNo']) ? strtoupper((string)$item['partNo']) : '';
        if ($key !== '' && isset($used[$key])) {
            continue;
        }
        $out[] = array(
            'partNo' => bg_tv_str(isset($item['partNo']) ? $item['partNo'] : '', 40),
            'partName' => bg_tv_str(isset($item['partName']) ? $item['partName'] : '', 40),
            'process' => bg_tv_str(isset($item['process']) ? $item['process'] : '', 40),
            'qty' => bg_tv_qty(isset($item['qty']) ? $item['qty'] : null),
            'price' => bg_tv_qty(isset($item['price']) ? $item['price'] : null),
        );
    }
    return array_slice($out, 0, 20);
}

/**
 * fuse 模式：产出统一 Fields（单结果）。
 *
 * @param array $results 引擎结果集合
 * @param array $cfg     配置
 * @return array Fields
 */
function bg_tv_fuse($results, $cfg)
{
    $primary = bg_tv_cfg($cfg, 'primaryEngine', 'tencentOcr');
    list($a, $b) = bg_tv_order_by_primary($results, $primary);
    $local = is_array($cfg) ? $cfg : array();
    $local['_aConf'] = isset($a['confidence']) ? $a['confidence'] : 0.0;
    $local['_bConf'] = isset($b['confidence']) ? $b['confidence'] : 0.0;
    $fa = isset($a['fields']) && is_array($a['fields']) ? $a['fields'] : array();
    $fb = isset($b['fields']) && is_array($b['fields']) ? $b['fields'] : array();
    $fields = array();
    foreach (array('date', 'worker', 'workshop', 'note') as $f) {
        $fields[$f] = bg_tv_pick_value($f, $a, $b, $local);
    }
    $fields['items'] = bg_tv_fuse_items(
        isset($fa['items']) ? $fa['items'] : array(),
        isset($fb['items']) ? $fb['items'] : array(),
        $local,
        isset($a['raw']) ? $a['raw'] : '',
        isset($b['raw']) ? $b['raw'] : ''
    );
    return $fields;
}

/**
 * 计算各路字段差异（compareMode 使用）。
 *
 * @param array $a 主引擎结果
 * @param array $b 备引擎结果
 * @return array 差异描述数组
 */
function bg_tv_diff($a, $b)
{
    $fa = isset($a['fields']) && is_array($a['fields']) ? $a['fields'] : array();
    $fb = isset($b['fields']) && is_array($b['fields']) ? $b['fields'] : array();
    $out = array();
    foreach (array('date' => '日期', 'worker' => '报工人', 'workshop' => '车间', 'note' => '备注') as $k => $label) {
        $va = isset($fa[$k]) ? $fa[$k] : '';
        $vb = isset($fb[$k]) ? $fb[$k] : '';
        if (!bg_tv_same($va, $vb, 0.85)) {
            $out[] = $label . '差异：' . bg_tv_str($va, 20) . ' / ' . bg_tv_str($vb, 20);
        }
    }
    $ia = isset($fa['items']) && is_array($fa['items']) ? $fa['items'] : array();
    $ib = isset($fb['items']) && is_array($fb['items']) ? $fb['items'] : array();
    $mapB = array();
    foreach ($ib as $item) {
        if (is_array($item) && !empty($item['partNo'])) {
            $mapB[strtoupper((string)$item['partNo'])] = $item;
        }
    }
    foreach ($ia as $item) {
        if (!is_array($item) || count($out) >= 4) {
            break;
        }
        $no = isset($item['partNo']) ? strtoupper((string)$item['partNo']) : '';
        if ($no === '' || !isset($mapB[$no])) {
            continue;
        }
        $other = $mapB[$no];
        foreach (array('qty', 'process') as $f) {
            $va = isset($item[$f]) ? $item[$f] : '';
            $vb = isset($other[$f]) ? $other[$f] : '';
            if (!bg_tv_same($va, $vb, 0.85)) {
                $out[] = $no . ' ' . $f . '差异：' . bg_tv_str($va, 12) . ' / ' . bg_tv_str($vb, 12);
            }
        }
    }
    return array_slice($out, 0, 4);
}

/**
 * parallel 模式：主字段取主引擎，各路结果存入 alternates[]。
 *
 * @param array $results 引擎结果集合
 * @param array $cfg     配置
 * @return array FinalResult 骨架（尚未 finalize）
 */
function bg_tv_parallel($results, $cfg)
{
    $primary = bg_tv_cfg($cfg, 'primaryEngine', 'tencentOcr');
    list($a, $b) = bg_tv_order_by_primary($results, $primary);
    $alts = array();
    foreach (array($a, $b) as $r) {
        if (empty($r['ok']) || $r['engineId'] === '') {
            continue;
        }
        $alts[] = array(
            'engine' => bg_tv_result_label($r['engineId']),
            'confidence' => bg_tv_conf(isset($r['confidence']) ? $r['confidence'] : 0.0, 0.0),
            'fields' => isset($r['fields']) && is_array($r['fields']) ? $r['fields'] : array(),
            'raw' => isset($r['raw']) ? (string)$r['raw'] : '',
            'warnings' => isset($r['warnings']) && is_array($r['warnings']) ? $r['warnings'] : array(),
        );
    }
    $out = array(
        'engine' => bg_tv_engine_prefix($a['engineId']),
        'confidence' => bg_tv_conf(isset($a['confidence']) ? $a['confidence'] : 0.0, 0.0),
        'fields' => isset($a['fields']) && is_array($a['fields']) ? $a['fields'] : array(),
        'warnings' => array(),
        'raw' => isset($a['raw']) ? (string)$a['raw'] : '',
    );
    if (count($alts) > 1) {
        $out['alternates'] = $alts;
    }
    return $out;
}

/**
 * primary 模式：仅取主引擎结果。
 *
 * @param array $results 引擎结果集合
 * @param array $cfg     配置
 * @return array FinalResult 骨架（尚未 finalize）
 */
function bg_tv_primary($results, $cfg)
{
    $primary = bg_tv_cfg($cfg, 'primaryEngine', 'tencentOcr');
    list($a) = bg_tv_order_by_primary($results, $primary);
    return array(
        'engine' => bg_tv_engine_prefix($a['engineId']),
        'confidence' => bg_tv_conf(isset($a['confidence']) ? $a['confidence'] : 0.0, 0.0),
        'fields' => isset($a['fields']) && is_array($a['fields']) ? $a['fields'] : array(),
        'warnings' => array(),
        'raw' => isset($a['raw']) ? (string)$a['raw'] : '',
    );
}
