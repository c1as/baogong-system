<?php
/**
 * 离线工具 · 拟合置信度校准参数（纯 PHP，零依赖）
 *
 * 用法：php tools/fit-calibration.php [golden_dir]
 * 读黄金样本集 cases/*.json 的 {predicted, correct} 观测对，
 * 拟合 Platt A/B（logit 空间梯度下降）与分段线性 bins（等频分箱 + 保序合并），
 * 写 data/ocr-fusion/calibration.json，并输出 ECE（10 分箱）。
 *
 * 说明：黄金样本 case 可选 confidence 与 correct 字段；缺失时跳过该样本。
 * 拟合结果需 ECE 不劣于基线（恒等映射）才建议开启 enableCalibration。
 */

require_once dirname(__DIR__) . '/ocf/bootstrap.php';

$goldenDir = isset($argv[1]) ? $argv[1] : (dirname(__DIR__) . '/tests/golden/cases');

/**
 * 读取观测对 (predicted, correct)。
 *
 * @param string $dir 样本目录
 * @return array
 */
function ocf_fit_collect($dir)
{
    $pairs = array();
    if (!is_dir($dir)) {
        return $pairs;
    }
    foreach (glob(rtrim($dir, '/') . '/*.json') as $file) {
        $doc = json_decode((string)@file_get_contents($file), true);
        if (!is_array($doc)) {
            continue;
        }
        $conf = null;
        if (isset($doc['confidence']) && is_numeric($doc['confidence'])) {
            $conf = (float)$doc['confidence'];
        } elseif (isset($doc['predicted']) && is_numeric($doc['predicted'])) {
            $conf = (float)$doc['predicted'];
        }
        $correct = null;
        if (isset($doc['correct']) && is_bool($doc['correct'])) {
            $correct = $doc['correct'] ? 1 : 0;
        } elseif (isset($doc['label']) && is_numeric($doc['label'])) {
            $correct = (int)$doc['label'];
        }
        if ($conf !== null && $correct !== null) {
            $pairs[] = array(max(0.001, min(0.999, $conf)), $correct);
        }
    }
    return $pairs;
}

/**
 * 拟合 Platt A/B（最小化 logistic loss，梯度下降 + L2）。
 *
 * @param array $pairs 观测对
 * @return array {A,B}
 */
function ocf_fit_platt($pairs)
{
    $A = 1.0;
    $B = 0.0;
    $lr = 0.05;
    $iter = 400;
    $n = max(1, count($pairs));
    for ($it = 0; $it < $iter; $it++) {
        $gA = 0.0;
        $gB = 0.0;
        foreach ($pairs as $p) {
            $conf = $p[0];
            $y = $p[1];
            $z = log($conf / (1 - $conf));
            $s = $A * $z + $B;
            $pred = 1 / (1 + exp(-max(-30, min(30, $s))));
            $d = $pred - $y;
            $gA += $d * $z;
            $gB += $d;
        }
        $gA = $gA / $n + 0.0001 * $A;
        $gB = $gB / $n + 0.0001 * $B;
        $A -= $lr * $gA;
        $B -= $lr * $gB;
    }
    return array('A' => round($A, 4), 'B' => round($B, 4));
}

/**
 * 等频分箱 + 保序合并，输出 4 段校准 bins。
 *
 * @param array $pairs 观测对
 * @return array bins
 */
function ocf_fit_bins($pairs)
{
    if (!$pairs) {
        return array();
    }
    usort($pairs, function ($a, $b) {
        return $a[0] <=> $b[0];
    });
    $n = count($pairs);
    $k = min(4, max(1, (int)ceil($n / 8)));
    $bins = array();
    for ($b = 0; $b < $k; $b++) {
        $loIdx = (int)floor($b * $n / $k);
        $hiIdx = (int)floor(($b + 1) * $n / $k) - 1;
        if ($hiIdx < $loIdx) {
            $hiIdx = $loIdx;
        }
        $slice = array_slice($pairs, $loIdx, $hiIdx - $loIdx + 1);
        if (!$slice) {
            continue;
        }
        $lo = $slice[0][0];
        $hi = $slice[count($slice) - 1][0];
        $sum = 0;
        foreach ($slice as $p) {
            $sum += $p[1];
        }
        $q = round($sum / count($slice), 4);
        $bins[] = array(
            'lo' => round($lo, 4), 'hi' => round($hi, 4),
            'qlo' => isset($bins[count($bins) - 1]) ? $bins[count($bins) - 1]['qhi'] : 0.0,
            'qhi' => $q,
        );
    }
    /* 保序合并：若 qhi 下降，与前一段合并取均值 */
    for ($i = 1; $i < count($bins); $i++) {
        if ($bins[$i]['qhi'] < $bins[$i - 1]['qhi']) {
            $mergedQ = ($bins[$i]['qhi'] + $bins[$i - 1]['qhi']) / 2;
            $bins[$i - 1]['qhi'] = round($mergedQ, 4);
            $bins[$i]['qlo'] = round($mergedQ, 4);
            $bins[$i]['qhi'] = round($mergedQ, 4);
        }
    }
    if ($bins) {
        $bins[0]['lo'] = 0.0;
        $bins[count($bins) - 1]['hi'] = 1.0;
        $bins[count($bins) - 1]['qhi'] = max($bins[count($bins) - 1]['qhi'], 0.99);
    }
    return $bins;
}

/**
 * 计算 ECE（10 分箱期望校准误差）。
 *
 * @param array $pairs 观测对
 * @param callable $calibrator 校准函数
 * @return float
 */
function ocf_fit_ece($pairs, $calibrator)
{
    if (!$pairs) {
        return 0.0;
    }
    $n = count($pairs);
    $bins = array_fill(0, 10, array('conf' => 0.0, 'acc' => 0.0, 'cnt' => 0));
    foreach ($pairs as $p) {
        $c = max(0.0, min(0.999, (float)$calibrator($p[0])));
        $idx = min(9, (int)floor($c * 10));
        $bins[$idx]['conf'] += $c;
        $bins[$idx]['acc'] += $p[1];
        $bins[$idx]['cnt']++;
    }
    $ece = 0.0;
    foreach ($bins as $b) {
        if ($b['cnt'] === 0) {
            continue;
        }
        $ece += ($b['cnt'] / $n) * abs($b['acc'] / $b['cnt'] - $b['conf'] / $b['cnt']);
    }
    return round($ece, 4);
}

/* ---------------- 主流程 ---------------- */
echo "=== OCR 融合 · 置信度校准拟合 ===\n";
$pairs = ocf_fit_collect($goldenDir);
echo "样本观测对：" . count($pairs) . " 条\n";
if (count($pairs) < 8) {
    echo "样本不足（<8），无法可靠拟合。请先在 cases/*.json 中提供 confidence + correct/correct=false 字段。\n";
    echo "将写出恒等默认参数（ECE 不劣化但不改善）。\n";
    $bins = array(array('lo' => 0.0, 'hi' => 1.0, 'qlo' => 0.0, 'qhi' => 1.0));
    $platt = array('A' => 1.0, 'B' => 0.0);
} else {
    $platt = ocf_fit_platt($pairs);
    $bins = ocf_fit_bins($pairs);
}

$identityEce = ocf_fit_ece($pairs, function ($p) { return $p; });
$piecewiseEce = ocf_fit_ece($pairs, function ($p) use ($bins) { return ocf_cal_piecewise($p, $bins); });
$plattEce = ocf_fit_ece($pairs, function ($p) use ($platt) { return ocf_cal_platt($p, $platt['A'], $platt['B']); });

$method = 'piecewise';
if ($plattEce < $piecewiseEce && $plattEce <= $identityEce) {
    $method = 'platt';
}
$bestEce = min($identityEce, $piecewiseEce, $plattEce);
echo "ECE 对比：恒等 " . $identityEce . " · 分段 " . $piecewiseEce . " · Platt " . $plattEce . "\n";
echo "推荐方法：" . $method . "（最优 ECE " . $bestEce . "）\n";
if ($bestEce > $identityEce + 1e-9) {
    echo "警告：拟合后 ECE 未优于恒等映射，建议保持 enableCalibration 关闭。\n";
}

$doc = array(
    'version' => 1,
    'method' => $method,
    'platt' => $platt,
    'bins' => $bins,
    'ece' => $bestEce,
    'fittedAt' => date('c'),
);
$outDir = ocf_rep_path();
$outPath = dirname($outDir) . '/calibration.json';
if (!is_dir(dirname($outPath))) {
    @mkdir(dirname($outPath), 0775, true);
}
$tmp = $outPath . '.tmp.' . getmypid();
file_put_contents($tmp, json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
@rename($tmp, $outPath);
echo "已写入：" . $outPath . "\n";
