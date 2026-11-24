<?php
/**
 * OCF L6 · 弃权 / 人工复核分级 + 输出收口
 *
 * 触发条件（架构 §7.7）：
 *   - 引擎失败/字段空/Verdict.abstain → abstain（弃权，输出空 + 提示，不参与投票）
 *   - needReview=true → review（转人工复核，输出分歧明细 + 命中规则）
 *   - calConf < 字段阈值 → review（提示复核，仍给值）
 *   - 其余 → accept
 * 收口：items ≤20、warnings ≤5（复核类优先）、每条 warning ≤80 字。
 */

if (defined('OCF_DECISION_LOADED')) {
    return;
}
define('OCF_DECISION_LOADED', 1);

/**
 * 最终决策（架构 §5.4 公共 API）：accept|review|abstain。
 *
 * @param array $verdict Verdict
 * @param float $calConf 校准置信度
 * @param array $cfg     配置
 * @param string $field  字段名（差异化阈值）
 * @return string
 */
function ocf_decide($verdict, $calConf, $cfg, $field = '')
{
    $verdict = is_array($verdict) ? $verdict : array();
    /* 弃权：引擎无法判定 / 全候选被 L1 剔除 / N 路不可解 */
    if (!empty($verdict['abstain'])) {
        return 'abstain';
    }
    if ($verdict['value'] === null || $verdict['value'] === '') {
        if (!empty($verdict['needReview'])) {
            return 'review';
        }
        return 'abstain';
    }
    /* 不可裁决 → 转人工复核 */
    if (!empty($verdict['needReview'])) {
        return 'review';
    }
    /* 低置信但可裁决 → 提示复核（仍给值） */
    $threshold = ocf_field_threshold($cfg, $field);
    if ((float)$calConf < $threshold) {
        return 'review';
    }
    return 'accept';
}

/**
 * 依决策产出字段值：abstain 输出空，其余给 verdict.value。
 *
 * @param array  $verdict  Verdict
 * @param string $decision 决策
 * @return mixed
 */
function ocf_decision_value($verdict, $decision)
{
    if ($decision === 'abstain') {
        return '';
    }
    return isset($verdict['value']) ? $verdict['value'] : '';
}

/**
 * 构造告警文本（含命中规则与证据，复核类优先）。
 *
 * @param array $fieldTrace 字段 trace {field, rule, value, confidence, decision, evidence}
 * @param array $cfg        配置
 * @return array
 */
function ocf_warning_for_field($fieldTrace, $cfg)
{
    $fieldTrace = is_array($fieldTrace) ? $fieldTrace : array();
    $decision = isset($fieldTrace['decision']) ? $fieldTrace['decision'] : 'accept';
    if ($decision === 'accept') {
        return array();
    }
    $field = isset($fieldTrace['field']) ? $fieldTrace['field'] : '';
    $rule = isset($fieldTrace['rule']) ? $fieldTrace['rule'] : '';
    $conf = isset($fieldTrace['confidence']) ? (float)$fieldTrace['confidence'] : 0.0;
    $names = array(
        'date' => '日期', 'worker' => '报工人', 'workshop' => '车间', 'note' => '备注',
        'partNo' => '图号', 'partName' => '名称', 'process' => '工序', 'qty' => '数量', 'price' => '单价',
    );
    $label = isset($names[$field]) ? $names[$field] : $field;
    if ($decision === 'abstain') {
        $text = $label . ' 无法裁决（' . $rule . '），已弃权，请人工填写';
    } else {
        $text = $label . ' 置信度 ' . round($conf * 100) . '%（规则 ' . $rule . '），建议人工复核';
    }
    $maxChars = isset($cfg['maxWarningChars']) ? (int)$cfg['maxWarningChars'] : 80;
    if (ocf_len($text) > $maxChars) {
        $text = ocf_cut($text, $maxChars);
    }
    return array($text);
}

/**
 * 从 trace 汇总 warnings，复核类优先，去重，≤5 条。
 *
 * @param array $traces 字段 trace 列表
 * @param array $cfg    配置
 * @return array
 */
function ocf_build_warnings($traces, $cfg)
{
    $max = isset($cfg['maxWarnings']) ? (int)$cfg['maxWarnings'] : 5;
    $maxChars = isset($cfg['maxWarningChars']) ? (int)$cfg['maxWarningChars'] : 80;
    $priority = array('abstain' => 0, 'review' => 1, 'accept' => 2);
    $collected = array();
    foreach ($traces as $t) {
        $t = is_array($t) ? $t : array();
        $decision = isset($t['decision']) ? $t['decision'] : 'accept';
        if ($decision === 'accept') {
            continue;
        }
        foreach (ocf_warning_for_field($t, $cfg) as $w) {
            $key = $w;
            if (!isset($collected[$key])) {
                $collected[$key] = array('text' => $w, 'rank' => isset($priority[$decision]) ? $priority[$decision] : 3);
            }
        }
    }
    usort($collected, function ($a, $b) {
        if ($a['rank'] === $b['rank']) {
            return 0;
        }
        return $a['rank'] < $b['rank'] ? -1 : 1;
    });
    $out = array();
    foreach ($collected as $c) {
        if (count($out) >= $max) {
            break;
        }
        $text = $c['text'];
        if (ocf_len($text) > $maxChars) {
            $text = ocf_cut($text, $maxChars);
        }
        if (!in_array($text, $out, true)) {
            $out[] = $text;
        }
    }
    return $out;
}

/**
 * 明细条数收口（≤ maxItems）。
 *
 * @param array $items 明细
 * @param array $cfg   配置
 * @return array
 */
function ocf_cap_items($items, $cfg)
{
    $max = isset($cfg['maxItems']) ? (int)$cfg['maxItems'] : 20;
    return array_slice(array_values($items), 0, max(1, $max));
}
