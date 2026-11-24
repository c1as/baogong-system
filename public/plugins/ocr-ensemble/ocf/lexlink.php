<?php
/**
 * OCF L2 · 字库归一/纠错联动
 *
 * 通过 bglx_bridge_* 连接层做 partNo/process/unit 归一：
 *   - auto：改值入投票，且改写后需重新做 L1 佐证（由编排层负责回验）；
 *   - suggest：只进 evidence/trace，不改值、不入赢家；
 *   - 连接层降级：全部 lexAction=none，等价词库未启用。
 */

if (defined('OCF_LEXLINK_LOADED')) {
    return;
}
define('OCF_LEXLINK_LOADED', 1);

/**
 * 字段 → 字库归一类型。
 *
 * @param string $field 字段名
 * @return string partno|process|unit|''
 */
function ocf_lex_kind($field)
{
    $map = array(
        'partNo' => 'partno',
        'partName' => 'text',
        'process' => 'process',
        'unit' => 'unit',
        'qty' => '',      // qty 是数值，单位归一在引擎侧已完成，此处不重复
        'price' => '',
        'date' => '',
        'worker' => 'text',
        'workshop' => '',
        'note' => '',
    );
    return isset($map[$field]) ? $map[$field] : '';
}

/**
 * 把 LexiconResult 转成可读证据串（供 evidence / trace）。
 *
 * @param array $lexResult 连接层结果
 * @return string
 */
function ocf_lex_to_evidence($lexResult)
{
    $lexResult = is_array($lexResult) ? $lexResult : array();
    $action = isset($lexResult['action']) ? (string)$lexResult['action'] : 'none';
    $rule = isset($lexResult['rule']) ? (string)$lexResult['rule'] : '';
    $value = isset($lexResult['value']) ? (string)$lexResult['value'] : '';
    $original = isset($lexResult['original']) ? (string)$lexResult['original'] : '';
    if ($action === 'auto') {
        return '字库 auto 命中 ' . $rule . '：' . $original . '→' . $value;
    }
    if ($action === 'suggest') {
        return '字库 suggest ' . $rule . '：' . $original . '→' . $value . '（仅提示）';
    }
    return '';
}

/**
 * 对候选集合应用字库联动（架构 §7.3）。
 *
 * @param array $cands   候选数组（每个含 field/value...）
 * @param array $bridge  连接层状态 bglx_bridge_status()
 * @param array $cfg     配置
 * @return array 更新 lexAction/lexRule/value 并追加 evidence 的候选
 */
function ocf_lex_apply($cands, $bridge, $cfg)
{
    $bridge = is_array($bridge) ? $bridge : array();
    $available = isset($bridge['available']) ? (bool)$bridge['available'] : false;
    $degraded = isset($bridge['degraded']) ? (bool)$bridge['degraded'] : false;
    $enabled = !empty($cfg['enableLexicon']);

    foreach ($cands as $i => $c) {
        $c = is_array($c) ? $c : array();
        $c['lexAction'] = isset($c['lexAction']) ? $c['lexAction'] : 'none';
        $c['lexRule'] = isset($c['lexRule']) ? (string)$c['lexRule'] : '';
        $c['evidence'] = isset($c['evidence']) ? (string)$c['evidence'] : '';

        if (!$available || $degraded || !$enabled || !function_exists('bglx_bridge_norm')) {
            /* 降级：等价词库未启用 */
            $cands[$i] = $c;
            continue;
        }
        $field = isset($c['field']) ? (string)$c['field'] : '';
        $kind = ocf_lex_kind($field);
        if ($kind === '') {
            $cands[$i] = $c;
            continue;
        }
        $raw = isset($c['value']) ? (string)$c['value'] : '';
        if ($raw === '') {
            $cands[$i] = $c;
            continue;
        }
        $lex = bglx_bridge_norm($kind, $raw, array('plugin' => 'ocr-ensemble'));
        $c['lexAction'] = isset($lex['action']) ? $lex['action'] : 'none';
        $c['lexRule'] = isset($lex['rule']) ? (string)$lex['rule'] : '';
        $ev = ocf_lex_to_evidence($lex);
        if ($ev !== '') {
            $c['evidence'] = $c['evidence'] === '' ? $ev : $c['evidence'] . '；' . $ev;
        }
        if ($c['lexAction'] === 'auto') {
            $c['value'] = isset($lex['value']) ? $lex['value'] : $raw;
            $c['lexOriginal'] = $raw; /* 记录原值便于 L1 回验与 trace */
        }
        $cands[$i] = $c;
    }
    return $cands;
}
