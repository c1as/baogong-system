<?php
/**
 * OCR 融合增强引擎 · 单元测试（零依赖）
 *
 * 运行：php tests/ocf.test.php
 * 覆盖：相似度基线一致性、L1 佐证、信誉冷启动/衰减/权重、2/3/N 路裁决、
 *       校准单调性与恒等回退、L6 分级、items/warnings 收口、trace 完整性。
 * 不依赖主程序：仅引入 ocf 模块，使用系统临时目录隔离数据文件。
 */

/* 隔离数据目录（须在引入 bootstrap 前设置，供 reputation/calibrate/trace 解析路径） */
$GLOBALS['DATA_DIR'] = rtrim(sys_get_temp_dir(), '/') . '/ocf-test-' . getmypid();
if (!is_dir($GLOBALS['DATA_DIR'] . '/ocr-fusion')) {
    @mkdir($GLOBALS['DATA_DIR'] . '/ocr-fusion', 0775, true);
}
if (!is_dir($GLOBALS['DATA_DIR'] . '/ocr-logs')) {
    @mkdir($GLOBALS['DATA_DIR'] . '/ocr-logs', 0775, true);
}

require_once dirname(__DIR__) . '/ocf/bootstrap.php';

$pass = 0;
$fail = 0;

/**
 * 断言辅助。
 *
 * @param bool   $cond   条件
 * @param string $label  用例名
 * @param string $detail 失败说明
 */
function ocf_test_assert($cond, $label, $detail = '')
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "[PASS] " . $label . "\n";
    } else {
        $fail++;
        echo "[FAIL] " . $label . ($detail !== '' ? " · " . $detail : '') . "\n";
    }
}

/** 构造候选 */
function ocf_test_cand($value, $engine, $weight, $supported = true, $lexAction = 'none', $lexRule = '')
{
    return array(
        'engineId' => $engine, 'engine' => $engine, 'field' => 'partNo',
        'value' => $value, 'selfConf' => $weight, 'weight' => $weight,
        'supported' => $supported, 'evidence' => '', 'literalRule' => 'L1_X',
        'lexAction' => $lexAction, 'lexRule' => $lexRule,
    );
}

echo "=== OCR 融合增强引擎单元测试 ===\n\n";

/* ---- A. 相似度与基线一致性 ---- */
echo "--- A. 相似度与工具 ---\n";
ocf_test_assert(ocf_dice('24971b05', '24971b05') === 1.0, 'A1 相同串 Dice=1');
ocf_test_assert(ocf_dice('24971b05', 'ABCDEFGH') < 0.3, 'A2 完全不同串 Dice 低');
ocf_test_assert(ocf_compact('TD-24510 R04') === 'TD24510R04', 'A3 compact 归一大小写与分隔符');
ocf_test_assert(ocf_part_same('TD04510R04', '04510R04') === true, 'A4 图号前缀差异对齐');
ocf_test_assert(ocf_date('2026-13-01') === '' && ocf_date('2026-11-24') === '2026-11-24', 'A5 严格日期校验');
ocf_test_assert(ocf_lev('abc', 'abd') === 1, 'A6 编辑距离');

/* ---- B. 聚类与投票（接收动态权重） ---- */
echo "\n--- B. 聚类与动态权重投票 ---\n";
$candsVote = array(
    ocf_test_cand('24971b05', 'baidu', 0.9),
    ocf_test_cand('24971b05', 'qwen', 0.8),
    ocf_test_cand('XXXXX999', 'mimo', 0.3),
);
$vote = ocf_vote($candsVote, 0.85);
ocf_test_assert($vote['value'] === '24971b05' && $vote['share'] > 0.6, 'B1 加权投票选出最大簇并输出 share');
$median = ocf_median(array(
    array('value' => 50, 'weight' => 0.9, 'engine' => 'a'),
    array('value' => 50, 'weight' => 0.8, 'engine' => 'b'),
    array('value' => 150, 'weight' => 0.3, 'engine' => 'c'),
));
ocf_test_assert($median['value'] == 50, 'B2 加权中位数抗毛刺');
$medianEven = ocf_median(array(
    array('value' => 10, 'weight' => 0.5, 'engine' => 'a'),
    array('value' => 20, 'weight' => 0.5, 'engine' => 'b'),
));
ocf_test_assert($medianEven['value'] == 10 || $medianEven['value'] == 20, 'B3 偶数权重取已观测值不臆造平均');

/* ---- C. 信誉冷启动与动态权重 ---- */
echo "\n--- C. 字段级信誉 ---\n";
$cfg = ocf_defaults();
$prior = ocf_rep_prior('baidu-ocr', 'partNo', $cfg);
ocf_test_assert(abs($prior - 0.6) < 1e-9, 'C1 无标签冷启动 prior=0.6');
$cfgTagged = $cfg;
$cfgTagged['strengths'] = array('baidu-ocr' => array('partNo'));
$priorBonus = ocf_rep_prior('baidu-ocr', 'partNo', $cfgTagged);
ocf_test_assert(abs($priorBonus - 0.8) < 1e-9, 'C2 strengths 命中 prior+0.2');
$wCold = ocf_weight('unknown-engine', 'partNo', 0.7, $cfg);
ocf_test_assert($wCold > 0 && $wCold <= 1, 'C3 冷启动权重落在 0~1');
$cfgOff = $cfg;
$cfgOff['enableFieldReputation'] = 0;
ocf_test_assert(abs(ocf_weight('x', 'f', 0.73, $cfgOff) - 0.73) < 1e-9, 'C4 关闭信誉回退自报置信度');
ocf_rep_put('test-engine', 'partNo', array('n' => 20, 'correct' => 19, 'ema' => 0.95, 'ts' => date('Y-m-d')));
$wMature = ocf_weight('test-engine', 'partNo', 0.5, $cfg);
/* 按 §7.1 公式：raw=20/22≈0.909, acc=0.7*0.909+0.3*0.6≈0.816, w=0.6*0.816+0.4*0.5≈0.69 */
ocf_test_assert($wMature > 0.65 && $wMature < 0.75, 'C5 历史高准确率引擎权重按公式提升');
ocf_test_assert(ocf_rep_flush() === true && is_file(ocf_rep_path()), 'C6 信誉原子落盘成功');
$doc = json_decode((string)file_get_contents(ocf_rep_path()), true);
ocf_test_assert(isset($doc['engines']['test-engine']['partNo']['n']), 'C7 信誉 JSON 结构合法');
ocf_rep_reset('test-engine');

/* ---- D. L1 原文佐证 ---- */
echo "\n--- D. L1 原文佐证 ---\n";
$info = array('compact' => 'TD24971B05X50', 'runs' => array('24971B05'), 'text' => 'TD24971B05 铣 50件', 'quantities' => array(50.0));
$v = ocf_literal_verify('24971b05', $info, 'partNo', $cfg);
ocf_test_assert($v['supported'] === true, 'D1 图号原文佐证通过');
$v2 = ocf_literal_verify('ZZZZZ999', $info, 'partNo', $cfg);
ocf_test_assert($v2['supported'] === false, 'D2 无佐证图号被剔除');
$v3 = ocf_literal_verify('150', $info, 'qty', $cfg);
ocf_test_assert($v3['supported'] === false, 'D3 无佐证数量被剔除（R-06）');
$v4 = ocf_literal_verify('50', $info, 'qty', $cfg);
ocf_test_assert($v4['supported'] === true, 'D4 有佐证数量通过');
ocf_test_assert(count(ocf_raw_runs('图号 24971B05 / 307437R61')) === 2, 'D5 原文 run 提取');

/* ---- E. L4 裁决分支 ---- */
echo "\n--- E. 2/3/N 路裁决 ---\n";
$ctxT = array('id' => 'ocr-ensemble');
$v2way = ocf_arbitrate(array(
    ocf_test_cand('A', 'e1', 0.7, true),
    ocf_test_cand('B', 'e2', 0.5, true),
), $ctxT, $cfg);
ocf_test_assert(in_array($v2way['rule'], array('REP_HIGHER_WINS', 'MAJORITY_WEIGHTED', 'L2_LEX_AUTO_WINS')), 'E1 2 路分歧有明确规则');
$v2tie = ocf_arbitrate_2way(
    array(ocf_test_cand('A', 'e1', 0.7), ocf_test_cand('B', 'e2', 0.7)),
    array('rep' => 'A', 'weight' => 0.7, 'size' => 1), 0.5, $cfg
);
ocf_test_assert($v2tie['rule'] === 'UNRESOLVABLE_2WAY' && $v2tie['needReview'], 'E2 信誉差<阈值转复核');
$v3 = ocf_arbitrate(array(
    ocf_test_cand('A', 'e1', 0.9), ocf_test_cand('A', 'e2', 0.8), ocf_test_cand('B', 'e3', 0.3),
), $ctxT, $cfg);
ocf_test_assert($v3['rule'] === 'MAJORITY_2_OF_3' || $v3['rule'] === 'MAJORITY_WEIGHTED', 'E3 3 路 2 同簇多数采纳');
$vAllReject = ocf_arbitrate(array(
    ocf_test_cand('A', 'e1', 0.9, false), ocf_test_cand('B', 'e2', 0.8, false),
), $ctxT, $cfg);
ocf_test_assert($vAllReject['rule'] === 'L1_ALL_REJECTED' && $vAllReject['abstain'], 'E4 全部无佐证→弃权');
$vSole = ocf_arbitrate(array(
    ocf_test_cand('A', 'e1', 0.9, true), ocf_test_cand('B', 'e2', 0.8, false),
), $ctxT, $cfg);
ocf_test_assert($vSole['rule'] === 'L1_SOLE_SUPPORT' && $vSole['value'] === 'A', 'E5 佐证唯一胜出');
$vLex = ocf_arbitrate(array(
    ocf_test_cand('A', 'e1', 0.7, true, 'auto', 'drawing_alias:x'),
    ocf_test_cand('B', 'e2', 0.9, true, 'none'),
), $ctxT, $cfg);
ocf_test_assert($vLex['rule'] === 'L2_LEX_AUTO' && $vLex['value'] === 'A', 'E6 字库 auto 唯一命中胜出');
$vSuggest = ocf_arbitrate(array(
    ocf_test_cand('A', 'e1', 0.9, true, 'suggest', 'x'),
    ocf_test_cand('B', 'e2', 0.8, true, 'none'),
), $ctxT, $cfg);
/* suggest 不入赢家：不得命中 L2_LEX_AUTO / L2_LEX_AUTO_WINS，赢家只由佐证或信誉产生 */
ocf_test_assert(
    !in_array($vSuggest['rule'], array('L2_LEX_AUTO', 'L2_LEX_AUTO_WINS'), true)
    && in_array($vSuggest['rule'], array('REP_HIGHER_WINS', 'MAJORITY_WEIGHTED'), true),
    'E7 suggest 只入证据不入赢家',
    '实际 rule=' . $vSuggest['rule'] . ' value=' . $vSuggest['value']
);
$vNway = ocf_arbitrate_nway(
    array(ocf_test_cand('A', 'e1', 0.5), ocf_test_cand('B', 'e2', 0.5), ocf_test_cand('C', 'e3', 0.5), ocf_test_cand('D', 'e4', 0.5)),
    ocf_cluster(array(ocf_test_cand('A', 'e1', 0.5), ocf_test_cand('B', 'e2', 0.5), ocf_test_cand('C', 'e3', 0.5), ocf_test_cand('D', 'e4', 0.5)), 0.85),
    array('rep' => 'A', 'weight' => 0.5, 'size' => 1), 0.75, $cfg
);
ocf_test_assert(in_array($vNway['rule'], array('NWAY_LAYER_WIN', 'UNRESOLVABLE_NWAY')), 'E8 N 路规则可枚举');

/* ---- F. L5 校准 ---- */
echo "\n--- F. 置信度校准 ---\n";
$cfgOffCal = $cfg;
$cfgOffCal['enableCalibration'] = 0;
ocf_test_assert(abs(ocf_calibrate(0.77, 0.8, 0.7, $cfgOffCal) - 0.77) < 1e-9, 'F1 关闭校准恒等回退');
$cfgOn = $cfg;
$cfgOn['enableCalibration'] = 1;
ocf_test_assert(abs(ocf_calibrate(0.77, 0.8, 0.7, $cfgOn) - 0.77) < 1e-9, 'F2 无参数文件恒等回退');
$bins = array(
    array('lo' => 0.0, 'hi' => 0.5, 'qlo' => 0.0, 'qhi' => 0.4),
    array('lo' => 0.5, 'hi' => 1.0, 'qlo' => 0.4, 'qhi' => 0.95),
);
$p1 = ocf_cal_piecewise(0.3, $bins);
$p2 = ocf_cal_piecewise(0.7, $bins);
ocf_test_assert($p1 < $p2 && $p1 >= 0 && $p2 <= 1, 'F3 分段线性单调保序且有界');
ocf_test_assert(ocf_cal_platt(0.8, 1.0, 0.0) > 0.7, 'F4 Platt 输出合理');
ocf_test_assert(ocf_combined_conf(0.8, 1.0) === 0.8 && ocf_combined_conf(0.8, 0.0) === 0.4, 'F5 基线综合置信度公式');

/* ---- G. L6 决策与收口 ---- */
echo "\n--- G. 弃权/复核与收口 ---\n";
ocf_test_assert(ocf_decide(array('value' => null, 'abstain' => true, 'needReview' => true), 0.3, $cfg) === 'abstain', 'G1 弃权判定');
ocf_test_assert(ocf_decide(array('value' => 'A', 'abstain' => false, 'needReview' => true), 0.9, $cfg) === 'review', 'G2 不可裁决转复核');
ocf_test_assert(ocf_decide(array('value' => 'A', 'abstain' => false, 'needReview' => false), 0.4, $cfg) === 'review', 'G3 低置信提示复核');
ocf_test_assert(ocf_decide(array('value' => 'A', 'abstain' => false, 'needReview' => false), 0.9, $cfg) === 'accept', 'G4 达标接受');
$bigItems = array_fill(0, 30, array('partNo' => 'X'));
ocf_test_assert(count(ocf_cap_items($bigItems, $cfg)) === 20, 'G5 items 收口至 20');
$warns = ocf_build_warnings(array(
    array('field' => 'partNo', 'decision' => 'review', 'rule' => 'UNRESOLVABLE_2WAY', 'confidence' => 0.5),
    array('field' => 'qty', 'decision' => 'abstain', 'rule' => 'L1_ALL_REJECTED', 'confidence' => 0.2),
), $cfg);
ocf_test_assert(count($warns) <= 5 && strpos($warns[0], '弃权') !== false, 'G6 复核类告警优先且≤5');

/* ---- H. trace 完整性 ---- */
echo "\n--- H. 裁决链路 trace ---\n";
ocf_trace_begin(array('id' => 'ocr-ensemble', 'engineCount' => 3, 'invokeId' => 'test-invoke-001'), $cfg);
ocf_trace_field('partNo', 'L1', array('supported' => true));
ocf_trace_field('partNo', 'L2', array('action' => 'auto'));
ocf_trace_commit(array('field' => 'partNo', 'rule' => 'MAJORITY_WEIGHTED', 'value' => '24971b05', 'confidence' => 0.93, 'decision' => 'accept', 'evidence' => array('原文佐证')));
$invokeId = ocf_trace_flush(0.93, array());
ocf_test_assert($invokeId === 'test-invoke-001', 'H1 invokeId 保持');
$tracePath = $GLOBALS['DATA_DIR'] . '/ocr-logs/fusion-trace-' . date('Y-m-d') . '.jsonl';
ocf_test_assert(is_file($tracePath), 'H2 trace JSONL 落盘');
$lastLine = '';
foreach (file($tracePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
    $lastLine = $ln;
}
$traceDoc = json_decode($lastLine, true);
ocf_test_assert(
    is_array($traceDoc) && isset($traceDoc['fields'][0]['stages']['L1'], $traceDoc['fields'][0]['stages']['L2'])
    && $traceDoc['fields'][0]['rule'] === 'MAJORITY_WEIGHTED',
    'H3 字段链路含 L1/L2 与命中规则'
);

echo "\n=== 结果：通过 " . $pass . " 项，失败 " . $fail . " 项 ===\n";
exit($fail > 0 ? 1 : 0);
