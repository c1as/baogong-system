<?php
/**
 * OCF 相似度与工具函数（从基线 handler.php 的 ocrens_* 逐函数迁移）
 *
 * 语义与基线完全一致：字符级 Dice（uni-gram + bi-gram）、图号前缀对齐、
 * 工序少字容忍、原文佐证 run、零件库索引与校正、最小编辑距离。
 * 唯一改动：保底权重函数命名为 ocf_base_weight，避免与 L3 动态权重 ocf_weight 冲突。
 */

if (defined('OCF_SIMILARITY_LOADED')) {
    return;
}
define('OCF_SIMILARITY_LOADED', 1);

/**
 * 置信度保底权重（原 ocrens_weight）：0 时也给 0.01，避免同权随机选中。
 *
 * @param float $conf 置信度
 * @return float
 */
function ocf_base_weight($conf)
{
    $c = (float)$conf;
    if ($c < 0) {
        $c = 0;
    }
    if ($c > 1) {
        $c = 1;
    }
    return $c > 0 ? $c : 0.01;
}

/**
 * 紧凑化：仅保留数字/字母/汉字并转大写（mb_* 经包装）。
 *
 * @param mixed $v 输入
 * @return string
 */
function ocf_compact($v)
{
    $s = preg_replace('/[^0-9A-Za-z\x{4e00}-\x{9fa5}]/u', '', (string)$v);
    return ocf_mb_upper($s === null ? '' : $s);
}

/**
 * 字符长度（包装 mb_strlen）。
 *
 * @param string $s 输入
 * @return int
 */
function ocf_len($s)
{
    return ocf_mb_len($s);
}

/**
 * 截断显示：mbstring 下超出max保留 max-1 字加省略号；否则按字节 3 倍回退。
 *
 * @param string $s   输入
 * @param int    $max 最大字符数
 * @return string
 */
function ocf_cut($s, $max)
{
    $s = (string)$s;
    $max = (int)$max;
    if (function_exists('mb_strlen')) {
        if (mb_strlen($s, 'UTF-8') > $max) {
            return mb_substr($s, 0, $max - 1, 'UTF-8') . '…';
        }
        return $s;
    }
    if (strlen($s) > $max * 3) {
        return substr($s, 0, $max * 3 - 3) . '...';
    }
    return $s;
}

/**
 * 引擎名缩短显示。
 *
 * @param string $name 引擎名
 * @return string
 */
function ocf_ename($name)
{
    return ocf_cut(trim((string)$name), 12);
}

/**
 * 数字友好显示（去掉多余 0 与小数点）。
 *
 * @param float $n 数值
 * @return string
 */
function ocf_fmt($n)
{
    $s = rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

/**
 * 严格日期校验：YYYY-MM-DD 且真实存在，否则空串。
 *
 * @param mixed $v 输入
 * @return string
 */
function ocf_date($v)
{
    $s = trim((string)$v);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return '';
    }
    $d = explode('-', $s);
    if (count($d) !== 3 || !checkdate((int)$d[1], (int)$d[2], (int)$d[0])) {
        return '';
    }
    return $s;
}

/**
 * 字符级 n-gram（uni + bi），供 Dice 使用。
 *
 * @param string $text 输入
 * @return array
 */
function ocf_grams($text)
{
    $s = ocf_compact($text);
    $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars) || !$chars) {
        return array();
    }
    $grams = array();
    foreach ($chars as $ch) {
        $g = 'u:' . $ch;
        $grams[$g] = isset($grams[$g]) ? $grams[$g] + 1 : 1;
    }
    $n = count($chars);
    for ($i = 0; $i < $n - 1; $i++) {
        $g = 'b:' . $chars[$i] . $chars[$i + 1];
        $grams[$g] = isset($grams[$g]) ? $grams[$g] + 1 : 1;
    }
    return $grams;
}

/**
 * Dice 相似度（基于 ocf_grams），对中文错字/少字稳健。
 *
 * @param string $a 串 A
 * @param string $b 串 B
 * @return float 0~1
 */
function ocf_dice($a, $b)
{
    if ($a === $b) {
        return 1.0;
    }
    $ga = ocf_grams($a);
    $gb = ocf_grams($b);
    if (!$ga && !$gb) {
        return 1.0;
    }
    if (!$ga || !$gb) {
        return 0.0;
    }
    $na = 0;
    foreach ($ga as $n) {
        $na += $n;
    }
    $nb = 0;
    foreach ($gb as $n) {
        $nb += $n;
    }
    $inter = 0;
    foreach ($ga as $g => $n) {
        if (isset($gb[$g])) {
            $inter += min($n, $gb[$g]);
        }
    }
    return (2 * $inter) / ($na + $nb);
}

/**
 * 图号对齐：容忍 1~2 位字母前缀差异（TD04510R04 与 04510R04）。
 *
 * @param string $a 图号 A
 * @param string $b 图号 B
 * @return bool
 */
function ocf_part_same($a, $b)
{
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    $short = strlen($a) <= strlen($b) ? $a : $b;
    $long = strlen($a) <= strlen($b) ? $b : $a;
    if (strlen($short) < 5) {
        return false;
    }
    $extra = substr($long, 0, strlen($long) - strlen($short));
    if (!preg_match('/^[A-Za-z]{1,2}$/', $extra)) {
        return false;
    }
    return substr($long, -strlen($short)) === $short;
}

/**
 * 工序对齐：Dice 达阈值或包含关系（容忍「铣加工/铣」少字）；一方缺失视为同一行。
 *
 * @param string $a         工序 A
 * @param string $b         工序 B
 * @param float  $threshold 一致阈值
 * @return bool
 */
function ocf_proc_same($a, $b, $threshold)
{
    if ($a === '' || $b === '') {
        return true;
    }
    if ($a === $b || ocf_dice($a, $b) >= $threshold) {
        return true;
    }
    $short = strlen($a) <= strlen($b) ? $a : $b;
    $long = strlen($a) <= strlen($b) ? $b : $a;
    return strlen($short) >= 2 && strpos($long, $short) !== false;
}

/**
 * 最小编辑距离（辅助相似度）。
 *
 * @param string $a 串 A
 * @param string $b 串 B
 * @return int
 */
function ocf_lev($a, $b)
{
    $la = strlen($a);
    $lb = strlen($b);
    if ($la === 0) {
        return $lb;
    }
    if ($lb === 0) {
        return $la;
    }
    $prev = range(0, $lb);
    for ($i = 1; $i <= $la; $i++) {
        $cur = array($i);
        $ca = $a[$i - 1];
        for ($j = 1; $j <= $lb; $j++) {
            $cost = ($ca === $b[$j - 1]) ? 0 : 1;
            $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
        }
        $prev = $cur;
    }
    return $prev[$lb];
}
