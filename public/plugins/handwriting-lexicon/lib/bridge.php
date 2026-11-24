<?php
/**
 * 手写字词库 · 连接层（bglx_bridge_*）
 *
 * 唯一对外门面：所有 OCR 插件与融合引擎经此调用字库能力，不再各自 require lexicon.php。
 * 设计要点（架构 §2.3 / Q5）：
 *   - 薄封装 bglx_*，契约不变（auto/suggest/none）；
 *   - 静默降级：任何失败 ≡ 未命中（action=none），绝不改变字段语义、绝不抛给上层；
 *   - 请求级熔断：单请求连续失败 ≥3 次后短路，全部返回 none；
 *   - 幂等 init：同进程多次调用无副作用。
 *
 * 兼容 PHP 7.4+，纯过程式，全函数带 function_exists / 常量守卫防重定义。
 */

if (defined('BGLX_BRIDGE_LOADED')) {
    return;
}
define('BGLX_BRIDGE_LOADED', 1);

/** 熔断阈值：单请求连续失败次数达到该值即短路 */
if (!defined('BGLX_BRIDGE_MAX_FAILS')) {
    define('BGLX_BRIDGE_MAX_FAILS', 3);
}
/** 连接层版本 */
if (!defined('BGLX_BRIDGE_VERSION')) {
    define('BGLX_BRIDGE_VERSION', '1.0.0');
}

/**
 * 连接层进程内状态（请求级，随 PHP 进程结束而释放）。
 *
 * @return array 引用
 */
function &bglx_bridge_state()
{
    static $s = array(
        'initialized' => false,
        'pluginId' => '',
        'available' => false,
        'degraded' => false,
        'failCount' => 0,
        'suggestions' => array(),
        'opts' => array(),
    );
    return $s;
}

/**
 * 探测并加载 lexicon.php（候选路径覆盖同级与 fixed-plugins 布局）。
 *
 * @return bool 是否可用（函数已存在）
 */
function bglx_bridge_load_lexicon()
{
    if (function_exists('bglx_correct_partno') && function_exists('bglx_norm_process')) {
        return true;
    }
    $candidates = array(
        __DIR__ . '/lexicon.php',
        dirname(__DIR__, 2) . '/handwriting-lexicon/lib/lexicon.php',
        dirname(__DIR__, 4) . '/public/plugins/handwriting-lexicon/lib/lexicon.php',
    );
    foreach ($candidates as $file) {
        if (!is_file($file)) {
            continue;
        }
        try {
            require_once $file;
        } catch (Throwable $e) {
            error_log('[baogong] bglx_bridge: 引入 lexicon.php 失败 · ' . $e->getMessage());
            continue;
        }
        break;
    }
    return function_exists('bglx_correct_partno') && function_exists('bglx_norm_process');
}

/**
 * 初始化连接层（幂等）。返回是否可用。
 *
 * @param string $pluginId 调用方插件 id（用于词条 scope 过滤）
 * @param array  $opts     可含 auto_min / suggest_min
 * @return bool
 */
function bglx_bridge_init($pluginId, $opts = array())
{
    $s = &bglx_bridge_state();
    $pluginId = trim((string)$pluginId);
    $opts = is_array($opts) ? $opts : array();
    if ($s['initialized'] && $s['pluginId'] === $pluginId) {
        /* 幂等：同插件重复 init 只刷新选项，不重载词库 */
        $s['opts'] = $opts;
        return $s['available'];
    }
    $s['initialized'] = true;
    $s['pluginId'] = $pluginId;
    $s['opts'] = $opts;
    $s['failCount'] = 0;
    $s['degraded'] = false;
    $s['suggestions'] = array();
    try {
        $s['available'] = bglx_bridge_load_lexicon();
    } catch (Throwable $e) {
        error_log('[baogong] bglx_bridge: init 降级 · ' . $e->getMessage());
        $s['available'] = false;
    }
    if (!$s['available']) {
        $s['degraded'] = true;
        return false;
    }
    /* 词库存储文件缺失 / 路径错误也视为降级（§2.3），但仍 available（函数可用） */
    try {
        if (function_exists('bglx_status')) {
            $st = bglx_status();
            if (!is_array($st) || !isset($st['store_file']) || !is_file((string)$st['store_file'])) {
                $s['degraded'] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('[baogong] bglx_bridge: 状态探测降级 · ' . $e->getMessage());
        $s['degraded'] = true;
    }
    return $s['available'];
}

/**
 * 记录一次失败并判断是否熔断。
 *
 * @return bool 当前是否已熔断
 */
function bglx_bridge_mark_fail()
{
    $s = &bglx_bridge_state();
    $s['failCount']++;
    if ($s['failCount'] >= BGLX_BRIDGE_MAX_FAILS) {
        $s['degraded'] = true;
        return true;
    }
    return false;
}

/**
 * 构造未命中 / 降级的 LexiconResult。
 *
 * @param string $raw      原始值
 * @param string $kind     类型
 * @param string $rule     命中/降级规则标识
 * @param bool   $degraded 是否降级
 * @return array
 */
function bglx_bridge_none($raw, $kind, $rule = 'none', $degraded = false)
{
    return array(
        'value' => (string)$raw,
        'action' => 'none',
        'rule' => (string)$rule,
        'original' => (string)$raw,
        'kind' => (string)$kind,
        'degraded' => (bool)$degraded,
        'confidence' => 0.0,
        'matched_id' => '',
    );
}

/**
 * 单值归一（架构 §5.3 / §5.2 LexiconResult）。
 *
 * kind ∈ text|partno|process|unit。任何失败返回 action=none，不抛出。
 *
 * @param string $kind 类型
 * @param string $raw  原始值
 * @param array  $opts 可含 plugin / auto_min / suggest_min
 * @return array LexiconResult{value, action, rule, original, degraded, ...}
 */
function bglx_bridge_norm($kind, $raw, $opts = array())
{
    $s = &bglx_bridge_state();
    $raw = (string)$raw;
    $kind = (string)$kind;
    $opts = is_array($opts) ? $opts : array();

    /* 熔断 / 未初始化 / 不可用：直接短路为未命中 */
    if ($s['degraded'] || !$s['available']) {
        return bglx_bridge_none($raw, $kind, 'degraded', true);
    }

    $plugin = isset($opts['plugin']) && trim((string)$opts['plugin']) !== ''
        ? trim((string)$opts['plugin']) : $s['pluginId'];
    $callOpts = array('plugin' => $plugin);
    foreach (array('auto_min', 'suggest_min') as $k) {
        if (array_key_exists($k, $opts)) {
            $callOpts[$k] = $opts[$k];
        } elseif (array_key_exists($k, $s['opts'])) {
            $callOpts[$k] = $s['opts'][$k];
        }
    }

    try {
        $r = null;
        switch ($kind) {
            case 'partno':
                $r = function_exists('bglx_correct_partno') ? bglx_correct_partno($raw, $callOpts) : null;
                break;
            case 'process':
                $r = function_exists('bglx_norm_process') ? bglx_norm_process($raw, $callOpts) : null;
                break;
            case 'unit':
                $r = function_exists('bglx_norm_unit') ? bglx_norm_unit($raw, $callOpts) : null;
                break;
            case 'text':
                $r = function_exists('bglx_correct_text') ? bglx_correct_text('char_confusion', $raw, $callOpts) : null;
                break;
            default:
                return bglx_bridge_none($raw, $kind, 'unknown_kind');
        }
        if (!is_array($r) || !isset($r['action'])) {
            bglx_bridge_mark_fail();
            return bglx_bridge_none($raw, $kind, 'bad_result', true);
        }
        $action = isset($r['action']) ? (string)$r['action'] : 'none';
        $value = ($action === 'auto' && isset($r['corrected']))
            ? (string)$r['corrected'] : $raw;
        $rule = 'none';
        if ($action !== 'none') {
            $matched = isset($r['matched_id']) ? (string)$r['matched_id'] : '';
            $type = isset($r['type']) ? (string)$r['type'] : $kind;
            $strategy = isset($r['strategy']) ? (string)$r['strategy'] : '';
            $rule = $type . ($matched !== '' ? ':' . $matched : '') . ($strategy !== '' ? '/' . $strategy : '');
        }
        /* suggest 档：缓存提示（去重），不入投票 */
        if ($action === 'suggest') {
            $suggestion = $raw . '→' . (isset($r['corrected']) ? (string)$r['corrected'] : $raw);
            if (!in_array($suggestion, $s['suggestions'], true)) {
                $s['suggestions'][] = $suggestion;
            }
        }
        return array(
            'value' => $value,
            'action' => $action,
            'rule' => $rule,
            'original' => $raw,
            'kind' => $kind,
            'degraded' => false,
            'confidence' => isset($r['confidence']) ? (float)$r['confidence'] : 0.0,
            'matched_id' => isset($r['matched_id']) ? (string)$r['matched_id'] : '',
        );
    } catch (Throwable $e) {
        error_log('[baogong] bglx_bridge: norm 降级 · ' . $e->getMessage());
        bglx_bridge_mark_fail();
        return bglx_bridge_none($raw, $kind, 'exception', true);
    }
}

/**
 * 字段 → 归一类型映射。
 *
 * @param string $field 字段名
 * @return string partno|process|unit|''
 */
function bglx_bridge_field_kind($field)
{
    $map = array(
        'partNo' => 'partno',
        'drawingNo' => 'partno',
        'process' => 'process',
        'unit' => 'unit',
    );
    return isset($map[$field]) ? $map[$field] : '';
}

/**
 * 批量归一一个引擎结果的字段（架构 §5.3）。
 *
 * 输入字段支持标量 date/worker/workshop/note 与 items 明细行。
 * 返回 ['fields'=>归一后字段, 'changes'=>变更列表, 'suggestions'=>建议列表]。
 *
 * @param array $fields 引擎 fields
 * @param array $opts   可含 plugin
 * @return array
 */
function bglx_bridge_norm_fields($fields, $opts = array())
{
    $fields = is_array($fields) ? $fields : array();
    $changes = array();
    $kinds = array('partNo' => 'partno', 'process' => 'process', 'unit' => 'unit');

    if (isset($fields['items']) && is_array($fields['items'])) {
        foreach ($fields['items'] as $i => $it) {
            if (!is_array($it)) {
                continue;
            }
            foreach ($kinds as $f => $kind) {
                if ($kind === 'unit') {
                    continue; /* 单位通常隐含在 qty 文本，不单独归一明细字段 */
                }
                if (!isset($it[$f]) || trim((string)$it[$f]) === '') {
                    continue;
                }
                $r = bglx_bridge_norm($kind, (string)$it[$f], $opts);
                if ($r['action'] === 'auto' && $r['value'] !== (string)$it[$f]) {
                    $changes[] = array('index' => $i, 'field' => $f, 'from' => (string)$it[$f], 'to' => $r['value'], 'rule' => $r['rule']);
                    $fields['items'][$i][$f] = $r['value'];
                }
            }
        }
    }
    return array(
        'fields' => $fields,
        'changes' => $changes,
        'suggestions' => bglx_bridge_suggestions(),
    );
}

/**
 * 取回本次请求的 suggest 提示（已去重）。
 *
 * @return array
 */
function bglx_bridge_suggestions()
{
    $s = &bglx_bridge_state();
    return array_values($s['suggestions']);
}

/**
 * 连接层状态（架构 §5.3）。
 *
 * @return array available / degraded / failCount / version / pluginId
 */
function bglx_bridge_status()
{
    $s = &bglx_bridge_state();
    return array(
        'available' => (bool)$s['available'],
        'degraded' => (bool)$s['degraded'],
        'failCount' => (int)$s['failCount'],
        'version' => BGLX_BRIDGE_VERSION,
        'pluginId' => (string)$s['pluginId'],
    );
}
