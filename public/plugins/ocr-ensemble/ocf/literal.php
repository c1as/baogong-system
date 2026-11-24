<?php
/**
 * OCF L1 · 原文佐证硬约束（一票否决）
 *
 * 迁移基线 ocrens_literal_support / run_supports / raw_runs / catalog_* / lib_part，
 * 并扩展到数量与工序。任何库内图号/纠正值/数量若无原文佐证，不得进入 L3/L4。
 * 任一层级不得绕过 L1；若全部候选被剔除，保留原文片段并强制复核。
 */

if (defined('OCF_LITERAL_LOADED')) {
    return;
}
define('OCF_LITERAL_LOADED', 1);

/**
 * 原文中图号样式的连续串（保留大小写，去重）。
 *
 * @param string $raw 原文
 * @return array 键=值=大写连续串
 */
function ocf_raw_runs($raw)
{
    $s = ocf_mb_upper((string)$raw);
    $out = array();
    if (preg_match_all('/[0-9A-Z]{5,}/', $s, $m)) {
        foreach ($m[0] as $run) {
            $out[$run] = $run;
        }
    }
    return $out;
}

/**
 * 原文串与库内图号是否同源（前缀差异 / 包含 / Dice≥0.75），迁移自基线。
 *
 * @param string $run  原文串
 * @param string $noC  库内紧凑图号
 * @return bool
 */
function ocf_run_supports($run, $noC)
{
    if ($run === '' || $noC === '') {
        return false;
    }
    if (ocf_part_same($run, $noC)) {
        return true;
    }
    if (strlen($run) >= 5 && strpos($noC, $run) !== false) {
        return true;
    }
    if (strlen($noC) >= 5 && strpos($run, $noC) !== false) {
        return true;
    }
    if (abs(strlen($run) - strlen($noC)) > 3) {
        return false;
    }
    return ocf_dice($run, $noC) >= 0.75;
}

/**
 * 图号能否在该路原文找到依据（防引擎按零件库猜值），迁移自基线。
 *
 * @param string $part       紧凑候选图号
 * @param string $rawCompact 紧凑原文
 * @param array  $rawRuns    原文 run 列表
 * @return bool
 */
function ocf_literal_support($part, $rawCompact, $rawRuns = array())
{
    if ($part === '' || ($rawCompact === '' && !$rawRuns)) {
        return true;
    }
    if ($rawCompact !== '' && strpos($rawCompact, $part) !== false) {
        return true;
    }
    foreach ($rawRuns as $run) {
        if (ocf_run_supports($run, $part)) {
            return true;
        }
    }
    return false;
}

/**
 * 零件库索引：紧凑图号 → 库内原始写法。
 *
 * @param array $catalog 零件库
 * @return array
 */
function ocf_catalog_index($catalog)
{
    $out = array();
    foreach ((array)$catalog as $p) {
        if (!is_array($p)) {
            continue;
        }
        $no = isset($p['no']) ? trim((string)$p['no']) : '';
        $noC = ocf_compact($no);
        if ($noC === '' || isset($out[$noC])) {
            continue;
        }
        $out[$noC] = $no;
    }
    return $out;
}

/**
 * 各路原文里确有佐证的库内图号，作为图号校正候选。
 *
 * @param array $index   库索引
 * @param array $rawRuns 全部原文 run
 * @return array
 */
function ocf_catalog_supported($index, $rawRuns)
{
    $out = array();
    foreach ($rawRuns as $run) {
        foreach ($index as $noC => $noRaw) {
            if ($noC === '') {
                continue;
            }
            if (ocf_run_supports($run, $noC)) {
                $out[$noC] = $noRaw;
            }
        }
    }
    return $out;
}

/**
 * 用零件库校正图号：精确命中直接用，否则取原文佐证候选中最接近者。
 *
 * @param string $part     候选图号
 * @param array  $index    库索引
 * @param array  $supported 佐证候选
 * @param float  $minDice  最低相似度
 * @return string 校正后的库内写法，空表示无法校正
 */
function ocf_lib_part($part, $index, $supported, $minDice)
{
    if ($part === '' || !$index) {
        return '';
    }
    if (isset($index[$part])) {
        return $index[$part];
    }
    $best = '';
    $bestScore = 0.0;
    foreach ($supported as $noC => $noRaw) {
        $d = ocf_dice($part, $noC);
        if ($d < $minDice || $d <= $bestScore) {
            continue;
        }
        $bestScore = $d;
        $best = $noRaw;
    }
    return $best;
}

/**
 * 从原文提取带单位数字串（数量佐证用）。
 *
 * @param string $raw 原文
 * @return array 数值列表
 */
function ocf_raw_quantities($raw)
{
    $s = preg_replace(
        array(
            '/20\d{2}\s*[-\/年.]\s*\d{1,2}\s*[-\/月.]\s*\d{1,2}\s*日?/u',
            '/(?<!\d)\d{1,2}\s*[-\/.]\s*\d{1,2}(?!\d)\s*日?/u',
        ),
        ' ',
        (string)$raw
    );
    $out = array();
    if (preg_match_all('/(?<![\d.])(\d{1,6}(?:\.\d{1,2})?)\s*(件|个|只|根|套|支|片|pcs)?/iu', $s, $m, PREG_SET_ORDER)) {
        foreach ($m as $one) {
            $out[] = (float)$one[1];
        }
    }
    return $out;
}

/**
 * 统一佐证判定入口（架构 §7.2）。
 *
 * @param mixed  $value   候选值
 * @param array  $rawInfo 该路原文信息 {compact, runs, text, quantities}
 * @param string $kind    partNo|qty|process|scalar
 * @param array  $cfg     配置
 * @return array {supported:bool, evidence:string, rule:string}
 */
function ocf_literal_verify($value, $rawInfo, $kind, $cfg)
{
    $rawInfo = is_array($rawInfo) ? $rawInfo : array();
    $compact = isset($rawInfo['compact']) ? $rawInfo['compact'] : '';
    $runs = isset($rawInfo['runs']) && is_array($rawInfo['runs']) ? $rawInfo['runs'] : array();
    $text = isset($rawInfo['text']) ? $rawInfo['text'] : '';
    $s = trim((string)$value);

    if ($s === '') {
        return array('supported' => false, 'evidence' => '候选值为空', 'rule' => 'L1_EMPTY');
    }
    if ($compact === '' && !$runs && $text === '') {
        /* 该路无原文（纯结构化结果）：不做硬否决，标为弱佐证交 L4 */
        return array('supported' => true, 'evidence' => '无原文可校验（结构化直出）', 'rule' => 'L1_NO_RAW_WEAK');
    }

    if ($kind === 'partNo') {
        $key = ocf_compact($s);
        if (ocf_literal_support($key, $compact, $runs)) {
            return array('supported' => true, 'evidence' => '原文佐证图号 ' . $s, 'rule' => 'L1_PARTNO_SUPPORTED');
        }
        return array('supported' => false, 'evidence' => '原文无图号 ' . $s . ' 的佐证', 'rule' => 'L1_PARTNO_REJECTED');
    }

    if ($kind === 'qty') {
        $quantities = isset($rawInfo['quantities']) && is_array($rawInfo['quantities'])
            ? $rawInfo['quantities'] : ocf_raw_quantities($text);
        $v = (float)$s;
        foreach ($quantities as $q) {
            if (abs($q - $v) < 1e-6) {
                return array('supported' => true, 'evidence' => '原文佐证数量 ' . ocf_fmt($v), 'rule' => 'L1_QTY_SUPPORTED');
            }
        }
        /* 数量无佐证：硬剔除（R-06），但 v=0 表示未识别，不参与投票 */
        return array('supported' => false, 'evidence' => '原文无数量 ' . ocf_fmt($v) . ' 的佐证', 'rule' => 'L1_QTY_REJECTED');
    }

    if ($kind === 'process') {
        $normText = ocf_compact($text);
        $normProc = ocf_compact($s);
        if ($normProc !== '' && strpos($normText, $normProc) !== false) {
            return array('supported' => true, 'evidence' => '原文佐证工序 ' . $s, 'rule' => 'L1_PROC_SUPPORTED');
        }
        /* 工序弱佐证不直接剔除，交 L4（架构 §7.2） */
        return array('supported' => true, 'evidence' => '工序原文弱佐证 ' . $s, 'rule' => 'L1_PROC_WEAK');
    }

    /* 标量：日期严格 + raw 可定位；worker/workshop 弱佐证 */
    if (strpos($text, $s) !== false || strpos($compact, ocf_compact($s)) !== false) {
        return array('supported' => true, 'evidence' => '原文可定位 ' . $s, 'rule' => 'L1_SCALAR_SUPPORTED');
    }
    return array('supported' => true, 'evidence' => '标量弱佐证 ' . $s, 'rule' => 'L1_SCALAR_WEAK');
}
