<?php
/**
 * 配置与路径解析
 *
 * 在不掌握主程序源码的前提下，对 data/plugins.json 做多形态兼容解析：
 *   plugins.<id>.config / <id>.config / <id> / plugins.<id>.settings
 * 并支持环境变量 BGLX_ADMIN_TOKEN、BGLX_DATA_DIR 兜底。
 */

if (!defined('BGLX_CONFIG_LOADED')) {
    define('BGLX_CONFIG_LOADED', 1);
}

/** 插件 id（目录名与 id 严格一致） */
if (!defined('BGLX_PLUGIN_ID')) {
    define('BGLX_PLUGIN_ID', 'handwriting-lexicon');
}

/** 兜底阈值：自动纠正 / 人工确认。可被插件设置覆盖。 */
if (!defined('BGLX_AUTO_MIN')) {
    define('BGLX_AUTO_MIN', 0.90);
}
if (!defined('BGLX_SUGGEST_MIN')) {
    define('BGLX_SUGGEST_MIN', 0.75);
}

/** 词库结构版本号 */
if (!defined('BGLX_SCHEMA_VERSION')) {
    define('BGLX_SCHEMA_VERSION', 1);
}

/** 文件锁等待上限（秒） */
if (!defined('BGLX_LOCK_TIMEOUT')) {
    define('BGLX_LOCK_TIMEOUT', 3);
}

/**
 * 读取 data/plugins.json 中与本插件相关的配置片段。
 *
 * 主程序结构未知，这里按常见形态逐一尝试；任何异常都降级为空数组，
 * 避免配置读取失败影响查询主流程。
 *
 * @return array 配置键值对（键名保持插件设置中的原始 key）
 */
function bglx_plugin_config()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = array();

    $file = bglx_data_dir() . '/plugins.json';
    if (!is_file($file)) {
        return $cache;
    }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return $cache;
    }
    $doc = json_decode($raw, true);
    if (!is_array($doc)) {
        return $cache;
    }

    $ids = array(BGLX_PLUGIN_ID, 'handwriting_lexicon');
    $containers = array($doc);
    if (isset($doc['plugins']) && is_array($doc['plugins'])) {
        $containers[] = $doc['plugins'];
    }
    if (isset($doc['items']) && is_array($doc['items'])) {
        // items 通常是列表，逐项按 id 判定
        foreach ($doc['items'] as $item) {
            if (is_array($item) && isset($item['id']) && in_array((string)$item['id'], $ids, true)) {
                $containers[] = $item;
            }
        }
    }

    foreach ($containers as $container) {
        foreach ($ids as $id) {
            $candidate = null;
            if (isset($container[$id]) && is_array($container[$id])) {
                $candidate = $container[$id];
            }
            if ($candidate === null) {
                continue;
            }
            // config / settings 两种嵌套形态
            foreach (array('config', 'settings') as $wrap) {
                if (isset($candidate[$wrap]) && is_array($candidate[$wrap])) {
                    $candidate = $candidate[$wrap];
                    break;
                }
            }
            if (is_array($candidate)) {
                $cache = $candidate;
                return $cache;
            }
        }
    }

    // 兼容「扁平列表 + id 字段」的 plugins.json
    foreach ($doc as $v) {
        if (!is_array($v) || !isset($v['id']) || !in_array((string)$v['id'], $ids, true)) {
            continue;
        }
        if (isset($v['config']) && is_array($v['config'])) {
            $cache = $v['config'];
        } elseif (isset($v['settings']) && is_array($v['settings'])) {
            $cache = $v['settings'];
        }
        break;
    }
    return $cache;
}

/**
 * 读取单个设置项：插件配置优先，其次环境变量，最后默认值。
 *
 * @param string $key      设置键
 * @param string $default  默认值
 * @return string 字符串形式的配置值
 */
function bglx_setting($key, $default = '')
{
    $cfg = bglx_plugin_config();
    $value = isset($cfg[$key]) ? $cfg[$key] : null;
    if (is_array($value) || is_object($value)) {
        $value = null;
    }
    if ($value === null || trim((string)$value) === '') {
        $envMap = array(
            'adminToken' => 'BGLX_ADMIN_TOKEN',
            'lexiconFile' => 'BGLX_LEXICON_FILE',
            'autoThreshold' => 'BGLX_AUTO_MIN',
            'suggestThreshold' => 'BGLX_SUGGEST_MIN',
            'trustedProxy' => 'BGLX_TRUSTED_PROXY',
        );
        if (isset($envMap[$key])) {
            $env = getenv($envMap[$key]);
            if ($env !== false && trim((string)$env) !== '') {
                $value = $env;
            }
        }
    }
    if ($value === null || trim((string)$value) === '') {
        return $default;
    }
    return trim((string)$value);
}

/**
 * 数据目录：优先全局 DATA_DIR（主程序注入），其次从 lib/ 上溯四级到项目根。
 *
 * lib/ → 插件根 → plugins → public → 项目根，故 dirname(__DIR__, 4) 为项目根。
 *
 * @return string 绝对路径（无末尾斜杠）
 */
function bglx_data_dir()
{
    $dir = '';
    if (isset($GLOBALS['DATA_DIR']) && is_string($GLOBALS['DATA_DIR'])) {
        $dir = $GLOBALS['DATA_DIR'];
    }
    if ($dir === '') {
        $env = getenv('BGLX_DATA_DIR');
        if ($env !== false) {
            $dir = (string)$env;
        }
    }
    if ($dir === '') {
        $dir = dirname(__DIR__, 4) . '/data';
    }
    $dir = str_replace('\\', '/', $dir);
    return rtrim($dir, '/');
}

/**
 * 词库文件绝对路径。设置项 lexiconFile 可覆盖默认位置。
 *
 * @return string 绝对路径
 */
function bglx_store_file()
{
    $override = bglx_setting('lexiconFile', '');
    if ($override !== '' && $override[0] === '/') {
        return rtrim(str_replace('\\', '/', $override), '/');
    }
    if ($override !== '' && preg_match('#^[A-Za-z]:[/\\]#', $override)) {
        return rtrim(str_replace('\\', '/', $override), '/');
    }
    return bglx_data_dir() . '/handwriting_lexicon.json';
}

/**
 * 读取自动纠正 / 人工确认阈值。
 *
 * @return array array('auto' => float, 'suggest' => float)
 */
function bglx_thresholds()
{
    $auto = (float)bglx_setting('autoThreshold', (string)BGLX_AUTO_MIN);
    $suggest = (float)bglx_setting('suggestThreshold', (string)BGLX_SUGGEST_MIN);
    if (!is_finite($auto) || $auto < 0 || $auto > 1) {
        $auto = BGLX_AUTO_MIN;
    }
    if (!is_finite($suggest) || $suggest < 0 || $suggest > 1) {
        $suggest = BGLX_SUGGEST_MIN;
    }
    if ($suggest > $auto) {
        $suggest = $auto;
    }
    return array('auto' => $auto, 'suggest' => $suggest);
}

/**
 * 当前本地时间（Asia/Shanghai 由部署环境保证，格式与 BG_PLUGIN_CTX['today'] 一致）。
 *
 * @return string Y-m-d H:i:s
 */
function bglx_now()
{
    return date('Y-m-d H:i:s');
}

/* ============================================================================
 * 人工判断审查子系统 · 配置读取（T01）
 *
 * 设计要点：
 *  1) 审查由主程序加载 check.php 时，bglx_setting() 读文件可能与上下文不一致，
 *     故统一收口到 bglx_rev_setting()，优先读 BG_PLUGIN_CTX['config']。
 *  2) 默认值集中在一张表，与 plugin.json 的 default 一一对应（v3 新增 4 项，共 28 项）。
 *  3) 路径类设置一律相对 bglx_data_dir() 解析，绝对路径原样保留。
 *     注意：attachmentDirs / logDir 的历史字面值属于作者 F5 保护段，一个字都不改写。
 * ==========================================================================*/

/**
 * 审查子系统默认配置（原 24 项 + v3 逐条判定/归档 4 项，共 28 项）。
 *
 * @return array key => string 形式的默认值
 */
function bglx_rev_defaults()
{
    return array(
        'reviewEnabled' => '0',
        'attachmentDirs' => "data/uploads/\ndata/attachments/\nuploads/\npublic/uploads/",
        'attachmentExtensions' => 'jpg,jpeg,png,webp',
        'attachmentMaxBytes' => '8388608',
        'thumbnailSize' => '320',
        'logDir' => 'data/ocr-logs/',
        'logFilePattern' => 'YYYY-MM-DD.jsonl',
        'fusionTracePattern' => 'fusion-trace-YYYY-MM-DD.jsonl',
        'fusionTraceEnabled' => '1',
        'linkStrategy' => 'auto',
        'linkTimeWindowSec' => '300',
        'aiEnabled' => '0',
        'aiEndpoint' => 'https://api.hunyuan.cloud.tencent.com/v1/chat/completions',
        'aiKey' => '',
        'aiModel' => 'hunyuan',
        'aiTimeoutSec' => '30',
        'aiMaxCandidates' => '50',
        'aiFallbackToManual' => '1',
        'autoImport' => '0',
        'manualImportStatus' => 'active',
        'aiImportStatus' => 'pending',
        'keepOriginalImage' => '1',
        'maskSensitive' => '1',
        'reviewPageSize' => '50',
        /* v3 新增 4 项：以下为追加项，上方 24 项（含 F5 目录默认值）逐字不动 */
        'reviewRetentionDays' => '90',      // 审查记录保留天数；0 表示禁用到期归档
        'reviewArchiveEnabled' => '1',      // 到期归档总开关
        'reviewArchiveDir' => 'review-archive/', // 相对 data 目录解析，做穿越校验
        'wholeImageJudgeEnabled' => '0',    // 整图批量快捷默认关闭（Q1）
    );
}

/**
 * 读取单项审查配置：BG_PLUGIN_CTX['config'] 优先，其次插件配置/环境变量，最后默认值。
 *
 * @param string $key     设置键
 * @param string $default 显式默认值；传空串时回落到 bglx_rev_defaults()
 * @return string 字符串形式的配置值
 */
function bglx_rev_setting($key, $default = '')
{
    $fallbacks = bglx_rev_defaults();
    $fallback = ($default !== '') ? (string)$default
        : (isset($fallbacks[$key]) ? (string)$fallbacks[$key] : '');

    $ctx = isset($GLOBALS['BG_PLUGIN_CTX']) && is_array($GLOBALS['BG_PLUGIN_CTX'])
        ? $GLOBALS['BG_PLUGIN_CTX'] : array();
    $ctxCfg = isset($ctx['config']) && is_array($ctx['config']) ? $ctx['config'] : array();
    if (array_key_exists($key, $ctxCfg)
        && !is_array($ctxCfg[$key]) && !is_object($ctxCfg[$key])) {
        $value = trim((string)$ctxCfg[$key]);
        return $value === '' ? $fallback : $value;
    }

    $value = trim((string)bglx_setting($key, ''));
    return $value === '' ? $fallback : $value;
}

/**
 * 布尔型审查配置。
 *
 * @param string $key     设置键
 * @param bool   $default 默认值
 * @return bool
 */
function bglx_rev_setting_bool($key, $default = false)
{
    return bglx_to_bool(bglx_rev_setting($key, $default ? '1' : '0'), (bool)$default);
}

/**
 * 整数型审查配置。
 *
 * @param string $key     设置键
 * @param int    $default 默认值
 * @return int
 */
function bglx_rev_setting_int($key, $default = 0)
{
    return (int)bglx_rev_setting($key, (string)(int)$default);
}

/**
 * 列表型审查配置：按换行 / 逗号 / 中文逗号 / 顿号切分，保序去重。
 *
 * @param string $key     设置键
 * @param array  $default 默认值
 * @return array
 */
function bglx_rev_setting_list($key, $default = array())
{
    $raw = bglx_rev_setting($key, '');
    if (trim($raw) === '') {
        $raw = is_array($default) ? implode("\n", $default) : '';
    }
    $parts = preg_split('/[\r\n,，、]+/u', trim($raw));
    if (!is_array($parts)) {
        return array();
    }
    $out = array();
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p !== '' && !in_array($p, $out, true)) {
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * 枚举型审查配置：非法值回落到默认值。
 *
 * @param string $key     设置键
 * @param array  $allowed 允许值
 * @param string $default 默认值
 * @return string
 */
function bglx_rev_setting_enum($key, $allowed, $default)
{
    $value = bglx_rev_setting($key, (string)$default);
    return in_array($value, (array)$allowed, true) ? $value : (string)$default;
}

/**
 * 把相对路径解析为 bglx_data_dir() 下的绝对路径；绝对路径原样规范化。
 *
 * 本函数只做路径拼接，不校验存在性；存在性与穿越校验由各使用方按需执行。
 *
 * @param string $rel 相对或绝对路径
 * @return string 绝对路径（无末尾斜杠，目录分隔符统一为 /）
 */
function bglx_rev_data_path($rel)
{
    $rel = str_replace('\\', '/', trim((string)$rel));
    if ($rel === '') {
        return bglx_data_dir();
    }
    if ($rel[0] === '/' || preg_match('#^[A-Za-z]:/#', $rel)) {
        return rtrim($rel, '/');
    }
    return rtrim(bglx_data_dir(), '/') . '/' . ltrim($rel, '/');
}

/**
 * v3 归档与判定开关的运行值读取（集中校验，避免各调用点口径漂移）。
 *
 * retentionDays=0 表示禁用到期归档，仅保留 items>20000 的条数裁剪（Q5）；
 * 目录字符串本身不做存在性校验，穿越校验由 bglx_rev_safe_archive_dir() 完成。
 *
 * @return array array('retention_days'=>int,'enabled'=>bool,'dir'=>string,
 *                     'whole_image_judge'=>bool)
 */
function bglx_rev_archive_config()
{
    $days = bglx_rev_setting_int('reviewRetentionDays', 90);
    if ($days < 0) {
        $days = 0;
    }
    return array(
        'retention_days' => $days,
        'enabled' => bglx_rev_setting_bool('reviewArchiveEnabled', true),
        'dir' => bglx_rev_setting('reviewArchiveDir', 'review-archive/'),
        'whole_image_judge' => bglx_rev_setting_bool('wholeImageJudgeEnabled', false),
    );
}

