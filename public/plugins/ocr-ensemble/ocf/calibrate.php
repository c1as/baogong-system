<?php
/**
 * OCF L5 · 置信度校准（纯 PHP 无依赖）
 *
 * 基线综合置信度：combined = meanConf × (0.5 + 0.5 × agreement)。
 * 校准两层：分段线性（默认，单调保序、缺参数退化为恒等）+ Platt（可选）。
 * 默认关闭（Q3），文件缺失/损坏一律恒等，保证不劣化。
 */

if (defined('OCF_CALIBRATE_LOADED')) {
    return;
}
define('OCF_CALIBRATE_LOADED', 1);

/**
 * 校准参数文件路径。
 *
 * @return string
 */
function ocf_cal_path()
{
    $dir = '';
    if (isset($GLOBALS['DATA_DIR']) && is_string($GLOBALS['DATA_DIR'])) {
        $dir = $GLOBALS['DATA_DIR'];
    }
    if ($dir === '') {
        $env = getenv('OCF_DATA_DIR');
        if ($env !== false && trim((string)$env) !== '') {
            $dir = (string)$env;
        }
    }
    if ($dir === '') {
        $dir = dirname(__DIR__, 4) . '/data';
    }
    return rtrim(str_replace('\\', '/', $dir), '/') . '/ocr-fusion/calibration.json';
}

/**
 * 加载校准参数（文件缺失/损坏返回空数组 → 恒等）。
 *
 * @return array
 */
function ocf_cal_load()
{
    $path = ocf_cal_path();
    if (!is_file($path)) {
        return array();
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return array();
    }
    $doc = json_decode($raw, true);
    if (!is_array($doc) || !isset($doc['method'])) {
        return array();
    }
    return $doc;
}

/**
 * 分段线性校准：p 落在 [lo,hi] 线性插值到 [qlo,qhi]；区间外取端点；保序单调。
 *
 * @param float $p    原始置信度 0~1
 * @param array $bins 分段数组
 * @return float
 */
function ocf_cal_piecewise($p, $bins)
{
    $p = max(0.0, min(1.0, (float)$p));
    if (!is_array($bins) || !$bins) {
        return $p;
    }
    /* 按 lo 升序，保证保序 */
    usort($bins, function ($a, $b) {
        return (float)$a['lo'] <=> (float)$b['lo'];
    });
    $prevHi = 0.0;
    $prevQHi = 0.0;
    foreach ($bins as $i => $bin) {
        if (!is_array($bin) || !isset($bin['lo'], $bin['hi'], $bin['qlo'], $bin['qhi'])) {
            continue;
        }
        $lo = max(0.0, min(1.0, (float)$bin['lo']));
        $hi = max(0.0, min(1.0, (float)$bin['hi']));
        $qlo = max(0.0, min(1.0, (float)$bin['qlo']));
        $qhi = max(0.0, min(1.0, (float)$bin['qhi']));
        if ($hi < $lo) {
            $hi = $lo;
        }
        /* 低于首段下界：用首段 qlo 线性外推（钳制端点） */
        if ($i === 0 && $p < $lo) {
            return $qlo;
        }
        if ($p >= $lo && $p <= $hi) {
            $span = $hi - $lo;
            if ($span <= 1e-9) {
                return $qhi;
            }
            $t = ($p - $lo) / $span;
            return max(0.0, min(1.0, $qlo + $t * ($qhi - $qlo)));
        }
        $prevHi = $hi;
        $prevQHi = $qhi;
    }
    return $prevQHi > 0 ? $prevQHi : $p;
}

/**
 * Platt 校准（logit 空间）：p' = 1/(1+exp(-(A·z+B))), z=ln(p/(1-p))。
 *
 * @param float $p 原始置信度
 * @param float $A 斜率
 * @param float $B 截距
 * @return float
 */
function ocf_cal_platt($p, $A, $B)
{
    $p = max(0.001, min(0.999, (float)$p));
    $z = log($p / (1 - $p));
    $v = (float)$A * $z + (float)$B;
    /* 数值稳定 sigmoid */
    if ($v >= 0) {
        $e = exp(-$v);
        return 1 / (1 + $e);
    }
    $e = exp($v);
    return $e / (1 + $e);
}

/**
 * 置信度校准（架构 §5.4 公共 API）。
 *
 * @param float $rawConf   原始综合置信度
 * @param float $rep       字段级信誉（0~1）
 * @param float $agreement 一致性系数
 * @param array $cfg       配置
 * @return float 校准后置信度 0~1（3 位小数由调用方统一 round）
 */
function ocf_calibrate($rawConf, $rep, $agreement, $cfg)
{
    $p = max(0.0, min(1.0, (float)$rawConf));
    if (empty($cfg['enableCalibration'])) {
        return $p; /* 默认关闭 → 恒等 */
    }
    $cal = ocf_cal_load();
    if (!$cal) {
        return $p; /* 文件缺失/损坏 → 恒等 */
    }
    $p = max(0.001, min(0.999, $p));
    /* 信誉因子（弱）：高信誉占比提升置信，避免"一致但都错"过度自信 */
    $rep = max(0.0, min(1.0, (float)$rep));
    $p = max(0.001, min(0.999, $p * (0.9 + 0.1 * $rep)));
    $method = isset($cal['method']) ? (string)$cal['method'] : 'piecewise';
    if ($method === 'platt' && isset($cal['platt']['A'], $cal['platt']['B'])) {
        return ocf_cal_platt($p, (float)$cal['platt']['A'], (float)$cal['platt']['B']);
    }
    if (isset($cal['bins']) && is_array($cal['bins'])) {
        return ocf_cal_piecewise($p, $cal['bins']);
    }
    return $p;
}

/**
 * 基线综合置信度公式（保留）：meanConf × (0.5 + 0.5 × agreement)。
 *
 * @param float $meanConf  平均自报置信度
 * @param float $agreement 一致性系数
 * @return float
 */
function ocf_combined_conf($meanConf, $agreement)
{
    $v = (float)$meanConf * (0.5 + 0.5 * (float)$agreement);
    return max(0.0, min(1.0, $v));
}
