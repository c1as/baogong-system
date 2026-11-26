<?php
/**
 * 字库桥接封装（T01 / R06）
 *
 * 本插件只通过 handwriting-lexicon/lib/bridge.php 暴露的 bglx_bridge_* 使用字库能力；
 * 不内嵌任何同义/形近字典。bridge 缺失、异常或熔断时静默降级，不阻断识别。
 */

if (defined('BG_TV_LEX_LOADED')) {
    return;
}
define('BG_TV_LEX_LOADED', 1);

if (!function_exists('bg_tv_cfg')) {
    require_once __DIR__ . '/util.php';
}

if (!defined('BG_TV_LEX_PLUGIN_ID')) {
    define('BG_TV_LEX_PLUGIN_ID', 'tencent-vision');
}

/**
 * 多候选路径探测并引入字库连接层（架构 §9.4）。
 *
 * @return bool bridge 是否可用
 */
function bg_tv_lex_load_bridge()
{
    if (function_exists('bglx_bridge_init')) {
        return true;
    }
    // __DIR__ = .../public/plugins/tencent-vision/lib
    $candidates = array(
        dirname(__DIR__, 2) . '/handwriting-lexicon/lib/bridge.php',                // plugins 同级（标准布局）
        dirname(__DIR__, 3) . '/handwriting-lexicon/lib/bridge.php',                // 上溯一级
        dirname(__DIR__, 4) . '/public/plugins/handwriting-lexicon/lib/bridge.php', // 项目根布局
        dirname(__DIR__, 3) . '/fixed-plugins/handwriting-lexicon/lib/bridge.php',  // 固定插件目录布局
    );
    foreach (array_values(array_unique($candidates)) as $file) {
        if (!is_file($file)) {
            continue;
        }
        try {
            require_once $file;
        } catch (Throwable $e) {
            bg_tv_log('lex_bridge_load_fail', array('file' => $file, 'error' => $e->getMessage()));
            continue;
        }
        break;
    }
    return function_exists('bglx_bridge_init');
}

/**
 * 初始化字库连接层（幂等）。
 *
 * @param string $pluginId 调用方插件 id
 * @param array  $opts     可含 auto_min / suggest_min
 * @return bool 是否可用
 */
function bg_tv_lex_init($pluginId = BG_TV_LEX_PLUGIN_ID, $opts = array())
{
    if (!bg_tv_lex_load_bridge()) {
        return false;
    }
    $opts = is_array($opts) ? $opts : array();
    if (!isset($opts['auto_min'])) {
        $opts['auto_min'] = 0.85;
    }
    if (!isset($opts['suggest_min'])) {
        $opts['suggest_min'] = 0.6;
    }
    try {
        return (bool)bglx_bridge_init((string)$pluginId, $opts);
    } catch (Throwable $e) {
        bg_tv_log('lex_init_fail', array('error' => $e->getMessage()));
        return false;
    }
}

/**
 * 对单路 fields 施加字库纠错；auto 改值、suggest 仅提示。
 *
 * @param array  $fields   统一字段
 * @param string $pluginId 调用方插件 id
 * @return array{fields:array,warnings:array,available:bool,degraded:bool,changes:array}
 */
function bg_tv_lex_apply($fields, $pluginId = BG_TV_LEX_PLUGIN_ID)
{
    $fields = is_array($fields) ? $fields : array();
    $out = array(
        'fields' => $fields,
        'warnings' => array(),
        'available' => false,
        'degraded' => false,
        'changes' => array(),
    );
    $pluginId = (string)$pluginId;
    if (!bg_tv_lex_init($pluginId)) {
        $out['degraded'] = true;
        $out['warnings'][] = '字库连接层不可用，已跳过纠错';
        return $out;
    }
    try {
        $n = bglx_bridge_norm_fields($fields, array('plugin' => $pluginId));
        if (is_array($n) && isset($n['fields']) && is_array($n['fields'])) {
            $out['fields'] = $n['fields'];
            $out['changes'] = isset($n['changes']) && is_array($n['changes']) ? $n['changes'] : array();
        }
        foreach ((array)bglx_bridge_suggestions() as $sug) {
            $s = bg_tv_str($sug, 60);
            if ($s !== '') {
                $out['warnings'][] = '字库建议：' . $s . '，请人工确认';
            }
        }
        $status = bglx_bridge_status();
        if (is_array($status) && !empty($status['degraded'])) {
            $out['degraded'] = true;
            $out['warnings'][] = '字库连接层不可用，已跳过纠错';
        }
        $out['available'] = true;
    } catch (Throwable $e) {
        bg_tv_log('lex_apply_fail', array('error' => $e->getMessage()));
        $out['degraded'] = true;
        $out['warnings'][] = '字库连接层不可用，已跳过纠错';
    }
    return $out;
}
