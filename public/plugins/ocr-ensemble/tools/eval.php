<?php
/**
 * 离线工具 · 黄金样本集评估（纯 PHP，零依赖）
 *
 * 用法：php tools/eval.php [golden_dir]
 * 遍历 cases/*.json，对比「基线候选值」与「融合裁决值」（若样本已带 ocf 结果），
 * 输出：字段级准确率、一致率、复核率、可追溯率、ECE（G1–G4 + R-07）。
 *
 * 样本格式见 tests/golden/manifest.json：
 *   {image_ref, expect:{date,worker,workshop,items}, base:{字段值}, ocf:{字段值, confidence, decision, rule, trace}, tags:[]}
 * 缺失 base/ocf 时按 expect==值 处理，便于先建立样本后回填。
 */

require_once dirname(__DIR__) . '/ocf/bootstrap.php';

$goldenDir = isset($argv[1]) ? $argv[1] : (dirname(__DIR__) . '/tests/golden/cases');

/**
 * 读取全部样本。
 *
 * @param string $dir 样本目录
 * @return array
 */
function ocf_eval_cases($dir)
{
    $cases = array();
    if (!is_dir($dir)) {
        return $cases;
    }
    foreach (glob(rtrim($dir, '/') . '/*.json') as $file) {
        $doc = json_decode((string)@file_get_contents($file), true);
        if (is_array($doc)) {
            $cases[] = $doc;
        }
    }
    return $cases;
}

/**
 * 安全取字段值。
 *
 * @param array  $doc   样本
 * @param string $group base|ocf|expect
 * @param string $field 字段
 * @return mixed
 */
function ocf_eval_pick($doc, $group, $field)
{
    if (isset($doc[$group]) && is_array($doc[$group]) && array_key_exists($field, $doc[$group])) {
        return $doc[$group][$field];
    }
    if (isset($doc['expect']) && is_array($doc['expect']) && array_key_exists($field, $doc['expect'])) {
        return $doc['expect'][$field];
    }
    return null;
}

/**
 * 值相等（数值容忍浮点误差，其余字符串比较）。
 *
 * @param mixed $a 值 A
 * @param mixed $b 值 B
 * @return bool
 */
function ocf_eval_same($a, $b)
{
    if (is_numeric($a) && is_numeric($b)) {
        return abs((float)$a - (float)$b) < 1e-6;
    }
    return (string)$a === (string)$b;
}

echo "=== OCR 融合 · 黄金样本集评估 ===\n";
$cases = ocf_eval_cases($goldenDir);
echo "样本数：" . count($cases) . "\n\n";
if (!$cases) {
    echo "暂无样本。请在 tests/golden/cases/ 放入 *.json，或传入目录参数。\n";
    exit(0);
}

$fields = array('date', 'worker', 'workshop', 'partNo', 'process', 'qty');
$baseCorrect = 0;
$ocfCorrect = 0;
$fieldTotal = 0;
$agreeCount = 0;
$reviewCount = 0;
$traceCount = 0;
$confPairs = array();

foreach ($cases as $doc) {
    $hasReview = false;
    $hasTrace = true;
    foreach ($fields as $f) {
        $expect = ocf_eval_pick($doc, 'expect', $f);
        if ($expect === null) {
            continue;
        }
        $fieldTotal++;
        $base = ocf_eval_pick($doc, 'base', $f);
        $ocf = ocf_eval_pick($doc, 'ocf', $f);
        if (ocf_eval_same($base, $expect)) {
            $baseCorrect++;
        }
        if (ocf_eval_same($ocf, $expect)) {
            $ocfCorrect++;
        }
        /* 一致率：样本声明 agreement 或 ocf 值与 base 相同 */
        if (isset($doc['agreement']) && (float)$doc['agreement'] >= 0.6) {
            $agreeCount++;
        } elseif (ocf_eval_same($base, $ocf)) {
            $agreeCount++;
        }
    }
    $decision = isset($doc['ocf']['decision']) ? $doc['ocf']['decision'] : 'accept';
    if ($decision === 'review' || $decision === 'abstain') {
        $hasReview = true;
        $reviewCount++;
    }
    if (isset($doc['ocf']['trace']) && is_array($doc['ocf']['trace']) && !empty($doc['ocf']['trace'])) {
        $traceCount++;
    } elseif (isset($doc['ocf']['rule']) && $doc['ocf']['rule'] !== '') {
        $traceCount++; /* 命中规则可枚举即视为可追溯 */
    } else {
        $hasTrace = false;
    }
    if (isset($doc['ocf']['confidence']) && is_numeric($doc['ocf']['confidence'])
        && isset($doc['correct']) && is_bool($doc['correct'])) {
        $confPairs[] = array((float)$doc['ocf']['confidence'], $doc['correct'] ? 1 : 0);
    }
}

$n = max(1, count($cases));
$baseAcc = $fieldTotal ? $baseCorrect / $fieldTotal : 0.0;
$ocfAcc = $fieldTotal ? $ocfCorrect / $fieldTotal : 0.0;
$agreeRate = $fieldTotal ? $agreeCount / $fieldTotal : 0.0;
$reviewRate = $reviewCount / $n;
$traceRate = $traceCount / $n;

/* ECE（10 分箱） */
$ece = 0.0;
if ($confPairs) {
    $bins = array_fill(0, 10, array('conf' => 0.0, 'acc' => 0.0, 'cnt' => 0));
    foreach ($confPairs as $p) {
        $idx = min(9, (int)floor(max(0.0, min(0.999, $p[0])) * 10));
        $bins[$idx]['conf'] += $p[0];
        $bins[$idx]['acc'] += $p[1];
        $bins[$idx]['cnt']++;
    }
    $nc = count($confPairs);
    foreach ($bins as $b) {
        if ($b['cnt'] === 0) {
            continue;
        }
        $ece += ($b['cnt'] / $nc) * abs($b['acc'] / $b['cnt'] - $b['conf'] / $b['cnt']);
    }
}

printf("关键字段 top1 准确率：基线 %.2f%% → 融合 %.2f%%（%+.2f pp）\n", $baseAcc * 100, $ocfAcc * 100, ($ocfAcc - $baseAcc) * 100);
printf("字段级一致率：%.2f%%\n", $agreeRate * 100);
printf("人工复核率：%.2f%%（%d/%d 单）\n", $reviewRate * 100, $reviewCount, $n);
printf("裁决可追溯率：%.2f%%（%d/%d 单）\n", $traceRate * 100, $traceCount, $n);
printf("ECE（%d 对置信观测）：%.4f%s\n", count($confPairs), $ece, $confPairs ? '' : '（无样本，未计算）');

echo "\n=== 目标对照 ===\n";
echo "G2 关键字段 +5pp 或 ≥98%：" . (($ocfAcc - $baseAcc) >= 0.05 || $ocfAcc >= 0.98 ? "达标" : "未达标") . "\n";
echo "G3 复核率下降 ≥30%：需在两次 eval 报告间对比，当前基线快照见 manifest.json\n";
echo "G4 可追溯率 100%：" . ($traceRate >= 1.0 ? "达标" : "未达标") . "\n";
echo "R-07 ECE 不劣化：需与 fit-calibration.php 输出的恒等 ECE 对照\n";
