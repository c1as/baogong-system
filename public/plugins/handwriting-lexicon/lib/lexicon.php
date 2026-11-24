<?php
/**
 * 手写字词库 · 唯一公共库入口
 *
 * OCR 插件只需一行引入即可获得全部 bglx_* 函数与 Bglx* 类：
 *   require_once dirname(__DIR__) . '/handwriting-lexicon/lib/lexicon.php';
 *
 * 本文件不做 BG_PLUGIN_CTX 校验（它不是协议入口，而是供同进程调用的公共库）。
 * 引入失败或词库损坏时，所有查询函数均静默降级为「未命中」，不影响识别主流程。
 * 兼容 PHP 7.4+，不使用命名空间、不使用 8.0+ 语法。
 */

if (defined('BGLX_LEXICON_LOADED')) {
    return;
}
define('BGLX_LEXICON_LOADED', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/corrector.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/auth.php'; // 一并暴露 BglxAuth，满足「引入本文件即获得全部 Bglx* 类」契约

if (!defined('BGLX_VERSION')) {
    define('BGLX_VERSION', '1.0.0');
}

/**
 * 获取 BglxStore 单例。
 *
 * @return BglxStore
 */
function bglx_store()
{
    static $store = null;
    if ($store === null) {
        $store = new BglxStore();
    }
    return $store;
}

/**
 * 通用纠错入口。
 *
 * @param string $type 词条类型：drawing_alias / process_synonym / unit_synonym / char_confusion
 * @param string $raw  原始值
 * @param array  $opts 可含 plugin / auto_min / suggest_min
 * @return array §3.5 查询结果对象
 */
function bglx_correct_text($type, $raw, $opts = array())
{
    $corrector = new BglxCorrector(bglx_store(), $opts);
    return $corrector->correct($type, $raw, $opts);
}

/**
 * 图号纠错：固定 drawing_alias，内部启用两级形近归一。
 *
 * @param string $raw  原始图号
 * @param array  $opts 选项
 * @return array §3.5 查询结果对象
 */
function bglx_correct_partno($raw, $opts = array())
{
    $opts = is_array($opts) ? $opts : array();
    return bglx_correct_text('drawing_alias', $raw, $opts);
}

/**
 * 工序归一：固定 process_synonym。
 *
 * @param string $raw  原始工序
 * @param array  $opts 选项
 * @return array §3.5 查询结果对象
 */
function bglx_norm_process($raw, $opts = array())
{
    $opts = is_array($opts) ? $opts : array();
    return bglx_correct_text('process_synonym', $raw, $opts);
}

/**
 * 单位归一：固定 unit_synonym。
 *
 * @param string $raw  原始单位
 * @param array  $opts 选项
 * @return array §3.5 查询结果对象
 */
function bglx_norm_unit($raw, $opts = array())
{
    $opts = is_array($opts) ? $opts : array();
    return bglx_correct_text('unit_synonym', $raw, $opts);
}

/**
 * 调试用：返回所有候选的逐条判定，不用于生产替换。
 *
 * @param string $type 词条类型
 * @param string $raw  原始值
 * @param array  $opts 选项
 * @return array 结果对象数组
 */
function bglx_query_all($type, $raw, $opts = array())
{
    $opts = is_array($opts) ? $opts : array();
    $corrector = new BglxCorrector(bglx_store(), $opts);
    return $corrector->queryAll($type, $raw, $opts);
}

/**
 * 插件自检用状态摘要。
 *
 * @return array version / data_dir / store_file / entries / schema
 */
function bglx_status()
{
    $store = bglx_store();
    $out = array(
        'version' => BGLX_VERSION,
        'data_dir' => bglx_data_dir(),
        'store_file' => $store->path(),
        'entries' => 0,
        'schema' => BGLX_SCHEMA_VERSION,
    );
    try {
        $export = $store->export();
        $out['entries'] = isset($export['entries']) && is_array($export['entries']) ? count($export['entries']) : 0;
        $out['schema'] = isset($export['schema']) ? (int)$export['schema'] : BGLX_SCHEMA_VERSION;
    } catch (Throwable $e) {
        $out['entries'] = -1;
    }
    return $out;
}
