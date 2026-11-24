<?php
/**
 * OCF L0 · 结构化归一
 *
 * 消除「同值不同形」：统一字段命名、日期格式、单位写法、字段长度截断，
 * 只做格式归一，不改变语义值。所有限制来自 config.php。
 */

if (defined('OCF_NORMALIZE_LOADED')) {
    return;
}
define('OCF_NORMALIZE_LOADED', 1);

/**
 * 单标量字段归一：日期严格化、单位清洗、长度截断。
 *
 * @param string $field 字段名
 * @param mixed  $v     原始值
 * @param array  $cfg   配置
 * @return mixed 归一后的值
 */
function ocf_norm_scalar($field, $v, $cfg)
{
    if ($v === null) {
        return '';
    }
    if (is_array($v) || is_object($v)) {
        return '';
    }
    $s = trim((string)$v);
    if ($field === 'date') {
        return ocf_date($s);
    }
    /* 字段长度上限：note 100，worker/workshop/partNo 等 40 */
    $limit = ($field === 'note') ? 100 : 40;
    if (ocf_len($s) > $limit) {
        $s = ocf_cut($s, $limit);
    }
    return $s;
}

/**
 * 明细行归一：逐字段截断 + 数值字段规整。
 *
 * @param array $item 单条明细
 * @param array $cfg  配置
 * @return array
 */
function ocf_norm_item($item, $cfg)
{
    $item = is_array($item) ? $item : array();
    $out = array(
        'partNo' => ocf_norm_scalar('partNo', isset($item['partNo']) ? $item['partNo'] : '', $cfg),
        'partName' => ocf_norm_scalar('partName', isset($item['partName']) ? $item['partName'] : '', $cfg),
        'process' => ocf_norm_scalar('process', isset($item['process']) ? $item['process'] : '', $cfg),
        'qty' => 0,
        'price' => null,
    );
    $qty = isset($item['qty']) ? $item['qty'] : 0;
    $out['qty'] = is_numeric($qty) ? (float)$qty : 0.0;
    if (isset($item['price']) && $item['price'] !== null && $item['price'] !== '' && is_numeric($item['price'])) {
        $out['price'] = (float)$item['price'];
    }
    return $out;
}

/**
 * 统一一路引擎结果为标准视图（EngineResult → 归一字段）。
 *
 * @param array $r   单路引擎结果（含 fields / confidence / raw / name / plugin）
 * @param array $cfg 配置
 * @return array {engineId, engineName, selfConf, fields, strengths, raw}
 */
function ocf_norm_engine_result($r, $cfg)
{
    $r = is_array($r) ? $r : array();
    $fields = isset($r['fields']) && is_array($r['fields']) ? $r['fields'] : array();
    $engineId = '';
    if (isset($r['plugin']) && is_array($r['plugin']) && isset($r['plugin']['id'])) {
        $engineId = (string)$r['plugin']['id'];
    } elseif (isset($r['engineId'])) {
        $engineId = (string)$r['engineId'];
    } elseif (isset($r['engine'])) {
        $engineId = (string)$r['engine'];
    }
    $engineName = isset($r['name']) ? (string)$r['name']
        : (isset($r['engine']) ? (string)$r['engine'] : $engineId);

    $normFields = array(
        'date' => ocf_norm_scalar('date', isset($fields['date']) ? $fields['date'] : '', $cfg),
        'worker' => ocf_norm_scalar('worker', isset($fields['worker']) ? $fields['worker'] : '', $cfg),
        'workshop' => ocf_norm_scalar('workshop', isset($fields['workshop']) ? $fields['workshop'] : '', $cfg),
        'note' => ocf_norm_scalar('note', isset($fields['note']) ? $fields['note'] : '', $cfg),
        'items' => array(),
    );
    if (isset($fields['items']) && is_array($fields['items'])) {
        foreach ($fields['items'] as $it) {
            $normFields['items'][] = ocf_norm_item($it, $cfg);
        }
    }
    return array(
        'engineId' => $engineId,
        'engineName' => $engineName,
        'selfConf' => isset($r['confidence']) ? (float)$r['confidence'] : 0.0,
        'fields' => $normFields,
        'strengths' => isset($r['strengths']) && is_array($r['strengths']) ? $r['strengths'] : array(),
        'raw' => isset($r['raw']) ? (string)$r['raw'] : '',
    );
}
