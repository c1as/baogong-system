<?php
/**
 * 图片 ↔ 日志四级关联
 *
 * L1 invokeId（trace / 文件名 / 元数据）1.00 或 0.95
 * L2 filename token 0.85
 * L3 mtime ±window 0.60（最小差与次小差 < 30s 判歧义）
 * L4 manual 人工绑定 1.00（永久覆盖自动结果）
 *
 * 歧义时不自动采用，界面提示「手动指定」。
 */

if (!defined('BGLX_REV_LINKER_LOADED')) {
    define('BGLX_REV_LINKER_LOADED', 1);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';

if (!defined('BGLX_REV_LINK_WINDOW')) {
    define('BGLX_REV_LINK_WINDOW', 300);
    define('BGLX_REV_MTIME_AMBIG_GAP', 30);
}

/**
 * 清理由内部排序使用的下划线字段。
 *
 * @param array $hit 命中项
 * @return array
 */
function bglx_rev_clean_hit($hit)
{
    $out = array();
    foreach (is_array($hit) ? $hit : array() as $k => $v) {
        if (strpos((string)$k, '_') !== 0) {
            $out[$k] = $v;
        }
    }
    return $out;
}

/**
 * 提取文件名 token（大小写不敏感，按 - _ 空白 . 切分）。
 *
 * @param string $name 路径或文件名
 * @return array
 */
function bglx_rev_filename_tokens($name)
{
    $name = (string)$name;
    $stem = pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME);
    $parts = preg_split('/[-_\s.]+/u', bglx_lower($stem));
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
 * 从日志解析可用于关联的时间戳。
 *
 * @param array $log 日志投影
 * @return int Unix 时间戳，失败返回 0
 */
function bglx_rev_log_ts($log)
{
    $log = is_array($log) ? $log : array();
    $invoke = isset($log['invoke_id']) ? (string)$log['invoke_id'] : '';
    if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $invoke, $m)) {
        $ts = strtotime($m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] . ':' . $m[5] . ':' . $m[6]);
        if ($ts !== false) {
            return $ts;
        }
    }
    foreach (array('ts', 'timestamp', 'time') as $k) {
        if (!empty($log[$k])) {
            $ts = strtotime((string)$log[$k]);
            if ($ts !== false) {
                return $ts;
            }
        }
    }
    if (!empty($log['date'])) {
        $ts = strtotime((string)$log['date'] . ' 00:00:00');
        if ($ts !== false) {
            return $ts;
        }
    }
    return 0;
}

/**
 * L1：invokeId 精确 / 文件名包含 / trace 元数据命中。
 *
 * @param array $log    日志投影
 * @param array $images ImageMeta 列表
 * @return array 命中列表
 */
function bglx_rev_match_invoke($log, $images)
{
    $invoke = isset($log['invoke_id']) ? (string)$log['invoke_id'] : '';
    if ($invoke === '') {
        return array();
    }
    $lowerInvoke = bglx_lower($invoke);
    $hits = array();
    foreach ($images as $img) {
        if (!is_array($img) || !isset($img['rel_path'])) {
            continue;
        }
        $rel = (string)$img['rel_path'];
        $stem = pathinfo(basename($rel), PATHINFO_FILENAME);
        $lowerStem = bglx_lower($stem);
        $conf = 0.0;
        if ($lowerStem === $lowerInvoke) {
            $conf = 1.0;
        } elseif (strpos($lowerStem, $lowerInvoke) !== false || strpos($lowerInvoke, $lowerStem) !== false) {
            $conf = 0.95;
        } elseif (isset($img['trace_invoke_id'])
                  && bglx_lower((string)$img['trace_invoke_id']) === $lowerInvoke) {
            $conf = 1.0;
        }
        if ($conf > 0) {
            $hits[] = array(
                'image_path' => $rel,
                'strategy' => 'invokeId',
                'confidence' => $conf,
            );
        }
    }
    return $hits;
}

/**
 * L2：图片文件名 token 与日志 partNo / invokeId 尾号做相等或子串匹配。
 *
 * @param array $log    日志投影
 * @param array $images ImageMeta 列表
 * @return array 命中列表
 */
function bglx_rev_match_filename($log, $images)
{
    $log = is_array($log) ? $log : array();
    $tokens = array();
    $add = function ($value) use (&$tokens) {
        foreach (bglx_rev_filename_tokens((string)$value) as $t) {
            if (!in_array($t, $tokens, true)) {
                $tokens[] = $t;
            }
        }
    };
    if (isset($log['fields']) && is_array($log['fields']) && isset($log['fields']['partNo'])) {
        $add($log['fields']['partNo']);
    }
    if (isset($log['items']) && is_array($log['items'])) {
        foreach ($log['items'] as $it) {
            if (is_array($it) && isset($it['partNo'])) {
                $add($it['partNo']);
            }
        }
    }
    if (!empty($log['invoke_id'])) {
        $add(substr((string)$log['invoke_id'], -4));
    }
    if (!$tokens) {
        return array();
    }
    $hits = array();
    foreach ($images as $img) {
        if (!is_array($img) || !isset($img['rel_path'])) {
            continue;
        }
        $rel = (string)$img['rel_path'];
        $imgTokens = bglx_rev_filename_tokens($rel);
        $matched = false;
        foreach ($imgTokens as $it) {
            foreach ($tokens as $lt) {
                if ($it === $lt
                    || (bglx_str_len($lt) >= 3 && strpos($it, $lt) !== false)
                    || (bglx_str_len($it) >= 3 && strpos($lt, $it) !== false)) {
                    $matched = true;
                    break 2;
                }
            }
        }
        if ($matched) {
            $hits[] = array(
                'image_path' => $rel,
                'strategy' => 'filename',
                'confidence' => 0.85,
            );
        }
    }
    return $hits;
}

/**
 * L3：文件 mtime 与日志时间戳的绝对差在窗口内，按时间差升序。
 *
 * @param array $log     日志投影
 * @param array $images  ImageMeta 列表
 * @param int   $window  容差秒
 * @return array 命中列表（含内部字段 _diff）
 */
function bglx_rev_mtime_match($log, $images, $window = 300)
{
    $window = max(1, (int)$window);
    $logTs = bglx_rev_log_ts($log);
    if ($logTs <= 0) {
        return array();
    }
    $hits = array();
    foreach ($images as $img) {
        if (!is_array($img) || !isset($img['rel_path'], $img['mtime'])) {
            continue;
        }
        $diff = abs((int)$img['mtime'] - $logTs);
        if ($diff <= $window) {
            $hits[] = array(
                'image_path' => (string)$img['rel_path'],
                'strategy' => 'mtime',
                'confidence' => 0.60,
                '_diff' => $diff,
            );
        }
    }
    usort($hits, function ($a, $b) {
        return $a['_diff'] <=> $b['_diff'];
    });
    return $hits;
}

/**
 * 单条日志关联一张图片。
 *
 * @param array $log      日志投影
 * @param array $images   ImageMeta 列表
 * @param string $strategy auto / invokeId / filename / mtime / manual
 * @param array $existing 已落库的审查记录（含 manual_image_path 时直接采用）
 * @return array array('image_path','strategy','confidence','ambiguous','alternatives')
 */
function bglx_rev_link_one($log, $images, $strategy, $existing = array())
{
    $log = is_array($log) ? $log : array();
    $images = is_array($images) ? $images : array();
    $existing = is_array($existing) ? $existing : array();
    $strategy = (string)$strategy;

    if (!empty($existing['manual_image_path'])) {
        return array(
            'image_path' => (string)$existing['manual_image_path'],
            'strategy' => 'manual',
            'confidence' => 1.0,
            'ambiguous' => false,
            'alternatives' => array(),
        );
    }
    if ($strategy === 'manual') {
        return array('image_path' => '', 'strategy' => 'none',
                     'confidence' => 0.0, 'ambiguous' => false, 'alternatives' => array());
    }

    $levels = ($strategy === 'auto') ? array('invokeId', 'filename', 'mtime') : array($strategy);
    if (!in_array($strategy, array('auto', 'invokeId', 'filename', 'mtime'), true)) {
        $levels = array('invokeId', 'filename', 'mtime');
    }
    $window = bglx_rev_setting_int('linkTimeWindowSec', BGLX_REV_LINK_WINDOW);

    foreach ($levels as $level) {
        $hits = array();
        if ($level === 'invokeId') {
            $hits = bglx_rev_match_invoke($log, $images);
        } elseif ($level === 'filename') {
            $hits = bglx_rev_match_filename($log, $images);
        } elseif ($level === 'mtime') {
            $hits = bglx_rev_mtime_match($log, $images, $window);
        }
        if (!$hits) {
            continue;
        }
        usort($hits, function ($a, $b) {
            return $b['confidence'] <=> $a['confidence'];
        });
        if (count($hits) > 1 && $hits[0]['confidence'] === $hits[1]['confidence']) {
            // mtime 同档时，最小差与次小差相差 ≥30s 视为可区分
            if ($level === 'mtime' && isset($hits[0]['_diff'], $hits[1]['_diff'])
                && ($hits[1]['_diff'] - $hits[0]['_diff']) >= BGLX_REV_MTIME_AMBIG_GAP) {
                $best = bglx_rev_clean_hit($hits[0]);
                $best['ambiguous'] = false;
                $best['alternatives'] = array();
                return $best;
            }
            $best = bglx_rev_clean_hit($hits[0]);
            $best['ambiguous'] = true;
            $best['alternatives'] = array_slice(array_map('bglx_rev_clean_hit', array_slice($hits, 1, 4)), 0, 4);
            return $best;
        }
        $best = bglx_rev_clean_hit($hits[0]);
        $best['ambiguous'] = false;
        $best['alternatives'] = array();
        return $best;
    }
    return array('image_path' => '', 'strategy' => 'none',
                 'confidence' => 0.0, 'ambiguous' => false, 'alternatives' => array());
}

/**
 * 批量关联：为每条日志附加 link 字段，不修改原始日志字段。
 *
 * @param array $logs    日志列表
 * @param array $images  ImageMeta 列表
 * @param array $opts    array('strategy','existing','window')
 * @return array
 */
function bglx_rev_link_all($logs, $images, $opts = array())
{
    $opts = is_array($opts) ? $opts : array();
    $strategy = isset($opts['strategy']) ? (string)$opts['strategy']
        : bglx_rev_setting_enum('linkStrategy', array('auto', 'invokeId', 'filename', 'mtime', 'manual'), 'auto');
    $existing = isset($opts['existing']) && is_array($opts['existing']) ? $opts['existing'] : array();
    $out = array();
    foreach (is_array($logs) ? $logs : array() as $log) {
        if (!is_array($log)) {
            continue;
        }
        $invoke = isset($log['invoke_id']) ? (string)$log['invoke_id'] : '';
        $prior = ($invoke !== '' && isset($existing[$invoke])) ? $existing[$invoke] : array();
        $log['link'] = bglx_rev_link_one($log, $images, $strategy, $prior);
        $out[] = $log;
    }
    return $out;
}

/**
 * 人工改绑：校验路径合法且确实位于允许的附件目录，再返回落库参数。
 *
 * @param string $itemId    审查记录 ID
 * @param string $imagePath 图片相对路径
 * @return array array('ok'=>bool, ...)
 */
function bglx_rev_bind_manual($itemId, $imagePath)
{
    $imagePath = str_replace('\\', '/', trim((string)$imagePath));
    if ($imagePath === '' || preg_match('#(^|/)\.\.(/|$)#', $imagePath)) {
        return array('ok' => false, 'reason' => '图片路径不合法');
    }
    $abs = bglx_rev_image_abs($imagePath);
    if ($abs === '') {
        return array('ok' => false, 'reason' => '图片不存在或不在允许的附件目录内');
    }
    return array(
        'ok' => true,
        'item_id' => (string)$itemId,
        'image_path' => $imagePath,
        'strategy' => 'manual',
        'confidence' => 1.0,
        'ambiguous' => false,
        'alternatives' => array(),
    );
}
