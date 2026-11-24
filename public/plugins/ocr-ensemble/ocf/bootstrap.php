<?php
/**
 * OCF（OCR Fusion）公共引导层
 *
 * 职责：ctx 校验、mb_* 兼容包装、基础工具（ocf_conf / ocf_ratio / ocf_ids）、
 *       统一 require 顺序。所有增强层在被本文件引导后加载，避免循环依赖。
 *
 * 兼容 PHP 7.4+：不使用命名空间、不使用 8.0+ 语法；任何函数均带 function_exists 守卫，
 * 防止插件同进程重复 require 导致重定义致命错误。
 */

if (defined('OCF_BOOTSTRAP_LOADED')) {
    return;
}
define('OCF_BOOTSTRAP_LOADED', 1);

/* ------------------------------------------------------------------ ctx 校验 */

/**
 * 校验融合引擎上下文。
 *
 * @param mixed $ctx 上下文
 * @return bool 合法返回 true；否则 false（不抛异常，由入口决定 403）
 */
function ocf_ctx_valid($ctx)
{
    return is_array($ctx);
}

/**
 * 从 ctx 提取配置数组（只读 ctx.config，不新增全局契约）。
 *
 * @param array $ctx 上下文
 * @return array
 */
function ocf_ctx_config($ctx)
{
    if (!is_array($ctx) || !isset($ctx['config']) || !is_array($ctx['config'])) {
        return array();
    }
    return $ctx['config'];
}

/* ------------------------------------------------------------- mb_* polyfill */

/**
 * 安全的字符串长度（字符数）。mbstring 缺失时按 UTF-8 字节回退。
 *
 * @param string $s 输入
 * @return int
 */
function ocf_mb_len($s)
{
    $s = (string)$s;
    if (function_exists('mb_strlen')) {
        return (int)mb_strlen($s, 'UTF-8');
    }
    return (int)strlen($s);
}

/**
 * 安全的子串截取。mbstring 缺失时按 UTF-8 字节回退（不切坏多字节字符边界时尽量对齐）。
 *
 * @param string $s     输入
 * @param int    $start 起始位置
 * @param int|null $len 长度
 * @return string
 */
function ocf_mb_sub($s, $start, $len = null)
{
    $s = (string)$s;
    $start = (int)$start;
    if (function_exists('mb_substr')) {
        return $len === null
            ? mb_substr($s, $start, null, 'UTF-8')
            : mb_substr($s, $start, (int)$len, 'UTF-8');
    }
    if ($len === null) {
        return substr($s, $start);
    }
    return substr($s, $start, (int)$len);
}

/**
 * 安全的大写转换（UTF-8）。
 *
 * @param string $s 输入
 * @return string
 */
function ocf_mb_upper($s)
{
    $s = (string)$s;
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}

/**
 * 安全的小写转换（UTF-8）。
 *
 * @param string $s 输入
 * @return string
 */
function ocf_mb_lower($s)
{
    $s = (string)$s;
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/* ---------------------------------------------------------------- 基础工具 */

/**
 * 读取字符串型配置（数组/对象一律回退默认值）。
 *
 * @param array  $cfg      配置
 * @param string $key      键
 * @param mixed  $default  默认值
 * @return mixed
 */
function ocf_conf($cfg, $key, $default = '')
{
    if (!is_array($cfg) || !isset($cfg[$key])) {
        return $default;
    }
    $v = $cfg[$key];
    if (is_array($v) || is_object($v)) {
        return $default;
    }
    if (is_bool($v)) {
        return $v ? '1' : '0';
    }
    $s = trim((string)$v);
    return $s === '' ? $default : $s;
}

/**
 * 读取 0~1 比例型配置，越界截断，非法回退默认值。
 *
 * @param array  $cfg      配置
 * @param string $key      键
 * @param float  $default  默认值
 * @return float
 */
function ocf_ratio($cfg, $key, $default)
{
    $raw = ocf_conf($cfg, $key, '');
    if ($raw === '' || !is_numeric($raw)) {
        return (float)$default;
    }
    $v = (float)$raw;
    if ($v < 0) {
        return 0.0;
    }
    if ($v > 1) {
        return 1.0;
    }
    return $v;
}

/**
 * 读取整数型配置，支持 min/max 钳制。
 *
 * @param array $cfg     配置
 * @param string $key    键
 * @param int $default   默认值
 * @param int $min       下限
 * @param int $max       上限
 * @return int
 */
function ocf_int($cfg, $key, $default, $min = null, $max = null)
{
    $raw = ocf_conf($cfg, $key, '');
    $v = ($raw === '' || !is_numeric($raw)) ? (int)$default : (int)$raw;
    if ($min !== null && $v < (int)$min) {
        $v = (int)$min;
    }
    if ($max !== null && $v > (int)$max) {
        $v = (int)$max;
    }
    return $v;
}

/**
 * 读取布尔开关（0/1/true/false/yes/no/on/off）。
 *
 * @param array  $cfg      配置
 * @param string $key      键
 * @param bool   $default  默认值
 * @return bool
 */
function ocf_bool($cfg, $key, $default = false)
{
    $raw = ocf_conf($cfg, $key, '');
    if ($raw === '' || $raw === null) {
        return (bool)$default;
    }
    $s = ocf_mb_lower(trim((string)$raw));
    if (in_array($s, array('1', 'true', 'yes', 'on'), true)) {
        return true;
    }
    if (in_array($s, array('0', 'false', 'no', 'off'), true)) {
        return false;
    }
    return (bool)$default;
}

/**
 * 解析插件 id 列表（逗号/空格/分号分隔），去重并校验格式。
 *
 * @param mixed $raw 原始串
 * @return array
 */
function ocf_ids($raw)
{
    $out = array();
    foreach (preg_split('/[\s,，、;；]+/u', trim((string)$raw)) as $id) {
        $id = strtolower(trim($id));
        if ($id === '' || !preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id)) {
            continue;
        }
        if (!in_array($id, $out, true)) {
            $out[] = $id;
        }
    }
    return $out;
}

/**
 * 计算两个 YYYY-MM-DD 日期相隔天数（绝对值，解析失败返回 0）。
 *
 * @param string $a 日期 A
 * @param string $b 日期 B
 * @return int
 */
function ocf_days_between($a, $b)
{
    $ta = strtotime((string)$a);
    $tb = strtotime((string)$b);
    if ($ta === false || $tb === false) {
        return 0;
    }
    return (int)abs((int)(($tb - $ta) / 86400));
}

/**
 * 兼容旧入口：把基线 ocrens_* 工具映射到 ocf_*（若主程序仍残留旧调用）。
 * 仅在对应 ocf 函数存在时定义，避免覆盖。
 */
if (!function_exists('ocrens_conf')) {
    function ocrens_conf($cfg, $key, $default = '')
    {
        return ocf_conf($cfg, $key, $default);
    }
}
if (!function_exists('ocrens_ratio')) {
    function ocrens_ratio($cfg, $key, $default)
    {
        return ocf_ratio($cfg, $key, $default);
    }
}
if (!function_exists('ocrens_ids')) {
    function ocrens_ids($raw)
    {
        return ocf_ids($raw);
    }
}

/* -------------------------------------------------------------- 统一引入顺序 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/similarity.php';
require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/literal.php';
require_once __DIR__ . '/lexlink.php';
require_once __DIR__ . '/reputation.php';
require_once __DIR__ . '/cluster.php';
require_once __DIR__ . '/arbiter.php';
require_once __DIR__ . '/calibrate.php';
require_once __DIR__ . '/decision.php';
require_once __DIR__ . '/trace.php';
require_once __DIR__ . '/run.php';
