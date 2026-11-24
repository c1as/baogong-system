<?php
/**
 * OCF L4 · 分层裁决器（核心）
 *
 * 统一原则：原文佐证 > 字库硬归一 > 字段级信誉 > 多数投票 > 人工复核。
 * ocf_arbitrate() 第一步无条件执行 L1 硬过滤，任何分支无法绕过；
 * suggest 只在 evidence 出现，不改变 value；rule 为可枚举常量字符串。
 */

if (defined('OCF_ARBITER_LOADED')) {
    return;
}
define('OCF_ARBITER_LOADED', 1);

/**
 * 构造 Verdict（统一出口，rule 可枚举）。
 *
 * @param mixed  $value      赢家值
 * @param string $rule       命中规则常量
 * @param bool   $needReview 是否待复核
 * @param bool   $abstain    是否弃权
 * @param float  $conflict   冲突度 0~1
 * @param mixed  $evidence   证据
 * @return array
 */
function ocf_verdict($value, $rule, $needReview, $abstain, $conflict, $evidence = '')
{
    return array(
        'value' => $value,
        'rule' => (string)$rule,
        'needReview' => (bool)$needReview,
        'abstain' => (bool)$abstain,
        'conflict' => max(0.0, min(1.0, (float)$conflict)),
        'evidence' => is_array($evidence) ? $evidence : (($evidence === '' || $evidence === null) ? array() : array($evidence)),
    );
}

/**
 * 层级评分：佐证强度(2/1/0) > 字库硬归一(auto=2/none=1/suggest=0) > 动态权重。
 * suggest 不参与决定（评 0）。
 *
 * @param array $c 候选
 * @return array
 */
function ocf_layer_score($c)
{
    $c = is_array($c) ? $c : array();
    $support = !empty($c['supported']) ? 2 : 0;
    $lex = isset($c['lexAction']) ? (string)$c['lexAction'] : 'none';
    $lexScore = ($lex === 'auto') ? 2 : (($lex === 'suggest') ? 0 : 1);
    $weight = ocf_cand_weight($c);
    return array($support, $lexScore, $weight);
}

/**
 * 层级比对排序（稳定： PHP usort 7.4 稳定）。
 *
 * @param array $cands 候选
 * @param array $cfg   配置
 * @return array 排序后的候选
 */
function ocf_rank_by_layers($cands, $cfg)
{
    $delta = isset($cfg['repDelta']) ? (float)$cfg['repDelta'] : 0.05;
    $ranked = array_values($cands);
    usort($ranked, function ($x, $y) use ($delta) {
        $lx = ocf_layer_score($x);
        $ly = ocf_layer_score($y);
        for ($i = 0; $i < 3; $i++) {
            $threshold = ($i === 2) ? $delta : 1e-9;
            if (abs($lx[$i] - $ly[$i]) > $threshold) {
                return $lx[$i] > $ly[$i] ? -1 : 1;
            }
        }
        return 0;
    });
    return $ranked;
}

/**
 * 判断层级比对是否已拉开明确差距（第一名各维度不低于第二名，且至少一维显著领先）。
 *
 * @param array $ranked 排序后候选
 * @param array $cfg    配置
 * @return bool
 */
function ocf_layer_gap_clear($ranked, $cfg)
{
    if (count($ranked) < 2) {
        return true;
    }
    $a = ocf_layer_score($ranked[0]);
    $b = ocf_layer_score($ranked[1]);
    $delta = isset($cfg['repDelta']) ? (float)$cfg['repDelta'] : 0.05;
    /* 绝对不得让 suggest 或 unsupported 的候选靠权重翻盘 */
    if ($a[0] < $b[0] || $a[1] < $b[1]) {
        return false;
    }
    return ($a[0] > $b[0]) || ($a[1] > $b[1]) || (($a[2] - $b[2]) > $delta);
}

/**
 * 层级比对证据串。
 *
 * @param array $ranked 排序后候选
 * @return array
 */
function ocf_rank_evidence($ranked)
{
    $out = array();
    foreach (array_slice($ranked, 0, 3) as $c) {
        $out[] = array(
            'value' => isset($c['value']) ? $c['value'] : '',
            'engine' => isset($c['engine']) ? $c['engine'] : (isset($c['engineId']) ? $c['engineId'] : ''),
            'supported' => !empty($c['supported']),
            'lexAction' => isset($c['lexAction']) ? $c['lexAction'] : 'none',
            'weight' => ocf_cand_weight($c),
        );
    }
    return $out;
}

/**
 * 统一裁决入口：命中即止，所有分支都先过 L1（架构 §7.5）。
 *
 * @param array $cands 候选（含 supported/lexAction/weight/value）
 * @param array $ctx   上下文
 * @param array $cfg   配置
 * @return array Verdict
 */
function ocf_arbitrate($cands, $ctx, $cfg)
{
    $cands = is_array($cands) ? array_values($cands) : array();
    /* ① L1 硬过滤（最高优先级，任何分支不可绕过） */
    $ok = array_values(array_filter($cands, function ($c) {
        return is_array($c) && !empty($c['supported']);
    }));
    if (!$ok) {
        return ocf_verdict(null, 'L1_ALL_REJECTED', true, true, 1.0, array('全部候选无原文佐证'));
    }
    if (count($ok) === 1) {
        $ev = isset($ok[0]['evidence']) ? $ok[0]['evidence'] : '';
        return ocf_verdict($ok[0]['value'], 'L1_SOLE_SUPPORT', false, false, 0.0, $ev);
    }

    /* ② 字库 auto 唯一命中 */
    $autos = array_values(array_filter($ok, function ($c) {
        return isset($c['lexAction']) && $c['lexAction'] === 'auto';
    }));
    if (count($autos) === 1) {
        $rule = isset($autos[0]['lexRule']) ? $autos[0]['lexRule'] : 'L2_LEX_AUTO';
        return ocf_verdict($autos[0]['value'], 'L2_LEX_AUTO', false, false, 0.2, array($rule));
    }

    /* ③ 加权聚类，取最大簇 */
    $agree = isset($cfg['agreeThreshold']) ? (float)$cfg['agreeThreshold'] : 0.85;
    $clusters = ocf_cluster($ok, $agree);
    $win = ocf_max_cluster($clusters);
    $total = ocf_total_weight($ok);
    $share = $total > 1e-9 ? $win['weight'] / $total : 0.0;
    $conflict = 1 - $share;

    /* ④ 可信占比达标 → 采纳（一致场景快速收敛） */
    $trustedShare = isset($cfg['trustedShare']) ? (float)$cfg['trustedShare'] : 0.6;
    if ($share >= $trustedShare) {
        return ocf_verdict($win['rep'], 'MAJORITY_WEIGHTED', false, false, $conflict, array('簇权重占比' => round($share, 3)));
    }

    /* ⑤ 按路数进入分歧裁决 */
    $n = count($ok);
    if ($n === 2) {
        return ocf_arbitrate_2way($ok, $win, $conflict, $cfg);
    }
    if ($n === 3) {
        return ocf_arbitrate_3way($ok, $clusters, $win, $conflict, $cfg);
    }
    return ocf_arbitrate_nway($ok, $clusters, $win, $conflict, $cfg);
}

/**
 * 2 路：佐证方 → 字库命中方 → 信誉高方 → 差<repDelta 不可裁决。
 *
 * @param array $ok       通过 L1 的候选（2 个）
 * @param array $win       最大簇
 * @param float $conflict  冲突度
 * @param array $cfg       配置
 * @return array Verdict
 */
function ocf_arbitrate_2way($ok, $win, $conflict, $cfg)
{
    $a = $ok[0];
    $b = $ok[1];
    /* ① L1 佐证强度：都有佐证则跳过（此处 ok 已全 supported，用权重代理佐证强度） */
    $sa = ocf_layer_score($a);
    $sb = ocf_layer_score($b);
    if ($sa[0] !== $sb[0]) {
        $w = $sa[0] > $sb[0] ? $a : $b;
        return ocf_verdict($w['value'], 'L1_SUPPORTED_WINS', false, false, $conflict, array('佐证强度胜出'));
    }
    /* ② 字库 auto 命中方 */
    $aa = ($sa[1] === 2);
    $bb = ($sb[1] === 2);
    if ($aa !== $bb) {
        $w = $aa ? $a : $b;
        return ocf_verdict($w['value'], 'L2_LEX_AUTO_WINS', false, false, $conflict, array('字库硬归一胜出'));
    }
    /* ③ 信誉/动态权重高方 */
    $d = $sa[2] - $sb[2];
    $repDelta = isset($cfg['repDelta']) ? (float)$cfg['repDelta'] : 0.05;
    if (abs($d) >= $repDelta) {
        $w = $d > 0 ? $a : $b;
        return ocf_verdict($w['value'], 'REP_HIGHER_WINS', false, false, $conflict, array('信誉差' => round($d, 3)));
    }
    /* ④ 不可裁决 → 转人工复核（不简单二选一） */
    return ocf_verdict(null, 'UNRESOLVABLE_2WAY', true, false, $conflict, array(
        '信誉差' => round($d, 3),
        '候选' => array($a['value'], $b['value']),
    ));
}

/**
 * 3 路：L1 重计数 → 2 路同簇多数 → 层级比对 → 复核。
 *
 * @param array $ok       通过 L1 候选
 * @param array $clusters 簇
 * @param array $win       最大簇
 * @param float $conflict  冲突度
 * @param array $cfg       配置
 * @return array Verdict
 */
function ocf_arbitrate_3way($ok, $clusters, $win, $conflict, $cfg)
{
    /* ① 2 路同簇多数（L1 已在入口重计数） */
    if (count($clusters) <= 2 && $win['size'] >= 2) {
        return ocf_verdict($win['rep'], 'MAJORITY_2_OF_3', false, false, $conflict, array(
            '簇大小' => $win['size'],
            '权重' => round($win['weight'], 3),
        ));
    }
    /* ② 三路各异：层级比对 */
    if (count($clusters) >= 2) {
        $ranked = ocf_rank_by_layers($ok, $cfg);
        if (ocf_layer_gap_clear($ranked, $cfg)) {
            return ocf_verdict($ranked[0]['value'], 'LAYER_RANK_WIN', false, false, $conflict, ocf_rank_evidence($ranked));
        }
    }
    return ocf_verdict(null, 'UNRESOLVABLE_3WAY', true, false, $conflict, array(
        '三方候选' => array_column($ok, 'value'),
    ));
}

/**
 * N 路：L1 剔除（入口已做）→ 字库重聚类 → 占比不足启动层级比对 → 弃权+复核。
 *
 * @param array $ok       通过 L1 候选
 * @param array $clusters 簇
 * @param array $win       最大簇
 * @param float $conflict  冲突度
 * @param array $cfg       配置
 * @return array Verdict
 */
function ocf_arbitrate_nway($ok, $clusters, $win, $conflict, $cfg)
{
    $ranked = ocf_rank_by_layers($ok, $cfg);
    if (ocf_layer_gap_clear($ranked, $cfg)) {
        return ocf_verdict($ranked[0]['value'], 'NWAY_LAYER_WIN', false, false, $conflict, ocf_rank_evidence($ranked));
    }
    return ocf_verdict(null, 'UNRESOLVABLE_NWAY', true, true, $conflict, array(
        '全部候选' => array_column($ok, 'value'),
        '冲突度' => round($conflict, 3),
    ));
}
