<?php
/**
 * OCF 集中阈值配置
 *
 * 所有数值阈值只在本文件定义默认值；plugin.json / ctx.config 提供覆盖；
 * 支持按字段差异化（fieldThresholds），算法函数内禁止魔法数字。
 */

if (defined('OCF_CONFIG_LOADED')) {
    return;
}
define('OCF_CONFIG_LOADED', 1);

/**
 * 默认配置（对应架构 §11 Q1–Q8 的保守取值）。
 *
 * @return array
 */
function ocf_defaults()
{
    return array(
        /* L0-L1 */
        'agreeThreshold'   => 0.85,   // 相似度一致判定阈值
        'minAgreeThreshold' => 0.30,  // 一致阈值下限保护
        'literalMinDice'   => 0.75,   // 原文佐证图号 Dice 下限
        'libPartMinDice'   => 0.50,   // 库内图号校正最低相似度

        /* L2 */
        'enableLexicon'    => 1,      // 字库联动总开关（降级时等价关闭）

        /* L3 信誉动态权重（§7.1） */
        'enableFieldReputation' => 1,
        'repMinSamples'    => 3,
        'repAlpha'         => 1.0,
        'repBeta'          => 1.0,
        'repLambda'        => 0.7,
        'repDecay'         => 0.98,
        'repGamma'         => 0.6,
        'priorBase'        => 0.6,
        'priorStrengthBonus' => 0.2,
        'priorWeaknessPenalty' => 0.15,
        'priorMin'         => 0.2,
        'priorMax'         => 0.95,
        'highTrustConf'    => 0.85,   // 仅高于此置信度的高可信单据更新信誉

        /* L4 裁决 */
        'trustedShare'     => 0.6,    // 最大簇可信占比
        'repDelta'         => 0.05,   // 信誉差小于该值判不可裁决
        'preferredEngine'  => '',     // 图号字段首选引擎（同权重 tie-break）

        /* L5 校准（默认关闭，Q3） */
        'enableCalibration' => 0,
        'calibrationMethod' => 'piecewise',

        /* L6 弃权/复核（Q7 差异化） */
        'minConfidence'    => 0.6,    // 综合置信度下限（告警）
        'reviewThreshold'  => 0.6,    // 标量默认复核阈值
        'fieldThresholds'  => array(
            'date'     => 0.60,
            'worker'   => 0.60,
            'workshop' => 0.60,
            'note'     => 0.60,
            'partNo'   => 0.70,
            'partName' => 0.60,
            'process'  => 0.60,
            'qty'      => 0.70,
            'price'    => 0.65,
        ),

        /* 能力与输出约束 */
        'traceLevel'       => 'full', // full|brief|off
        'enableTrace'      => 1,
        'singleEngineMode' => 0,      // Q2 默认关闭
        'maxItems'         => 20,
        'maxWarnings'      => 5,
        'maxRaw'           => 4000,
        'maxWarningChars'  => 80,

        /* 能力标签（Q8，由各插件 plugin.json 的 strengths / weaknesses 注入） */
        'strengths'        => array(),
        'weaknesses'       => array(),
    );
}

/**
 * 合并配置：默认值 → ctx.config → 额外覆盖。
 *
 * @param array $ctx      上下文
 * @param array $override 额外覆盖（可选）
 * @return array
 */
function ocf_config_build($ctx, $override = array())
{
    $cfg = ocf_defaults();
    $user = ocf_ctx_config($ctx);
    if (!empty($user) && is_array($user)) {
        foreach ($user as $k => $v) {
            $cfg[$k] = $v;
        }
    }
    if (!empty($override) && is_array($override)) {
        foreach ($override as $k => $v) {
            $cfg[$k] = $v;
        }
    }
    /* JSON 字符串型设置解析为数组（plugin.json textarea 传入） */
    foreach (array('fieldThresholds', 'strengths', 'weaknesses') as $jsonKey) {
        if (isset($cfg[$jsonKey]) && is_string($cfg[$jsonKey]) && trim($cfg[$jsonKey]) !== '') {
            $decoded = json_decode($cfg[$jsonKey], true);
            if (is_array($decoded)) {
                $cfg[$jsonKey] = $decoded;
            }
        }
    }
    /* 下限保护：一致阈值不得过低导致聚类失效 */
    $agree = ocf_ratio($cfg, 'agreeThreshold', 0.85);
    if ($agree < (float)$cfg['minAgreeThreshold']) {
        $cfg['agreeThreshold'] = (float)$cfg['minAgreeThreshold'];
    } else {
        $cfg['agreeThreshold'] = $agree;
    }
    /* 字段阈值归一化：确保 0~1 */
    if (!isset($cfg['fieldThresholds']) || !is_array($cfg['fieldThresholds'])) {
        $cfg['fieldThresholds'] = ocf_defaults()['fieldThresholds'];
    } else {
        foreach ($cfg['fieldThresholds'] as $f => $v) {
            $cfg['fieldThresholds'][$f] = ocf_ratio(array($f => $v), $f, 0.6);
        }
    }
    return $cfg;
}

/**
 * 统一取值：优先按字段差异化覆盖，其次全局键，最后默认值。
 *
 * @param array  $cfg     配置
 * @param string $key     键
 * @param string $field   字段名（可空）
 * @param mixed  $default 默认值
 * @return mixed
 */
function ocf_config_get($cfg, $key, $field = '', $default = null)
{
    if ($field !== '' && isset($cfg['fieldThresholds']) && is_array($cfg['fieldThresholds'])
        && array_key_exists($field, $cfg['fieldThresholds'])
        && in_array($key, array('reviewThreshold', 'minConfidence'), true)) {
        return $cfg['fieldThresholds'][$field];
    }
    if (is_array($cfg) && array_key_exists($key, $cfg)) {
        return $cfg[$key];
    }
    $defaults = ocf_defaults();
    return array_key_exists($key, $defaults) ? $defaults[$key] : $default;
}

/**
 * 取某字段的有效复核阈值（差异化）。
 *
 * @param array  $cfg   配置
 * @param string $field 字段名
 * @return float
 */
function ocf_field_threshold($cfg, $field)
{
    return (float)ocf_config_get($cfg, 'reviewThreshold', $field, 0.6);
}
