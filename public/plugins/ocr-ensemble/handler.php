<?php
/*
 * 报工识别插件 · 多 OCR 融合引擎
 *
 * 不直接访问外网：按设置逐个调用其它识别插件的 handler.php，
 * 对日期 / 报工人 / 车间 / 明细行做投票聚类与置信度加权，
 * 再按一致性系数打折得到综合置信度，并输出分歧告警。
 *
 * 由 api.php?action=plugin.invoke 引入，脚本末尾 return 结果数组。
 */

if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

$ctx = $GLOBALS['BG_PLUGIN_CTX'];
$cfg = isset($ctx['config']) && is_array($ctx['config']) ? $ctx['config'] : array();
$selfId = isset($ctx['id']) ? (string)$ctx['id'] : 'ocr-ensemble';

/* 多路引擎串行调用，耗时可能超过默认 30s，适当放宽 */
if (function_exists('set_time_limit')) {
    @set_time_limit(180);
}

/* ================================================================ 工具 */

function ocrens_conf($cfg, $key, $default = '')
{
    if (!is_array($cfg) || !isset($cfg[$key])) {
        return $default;
    }
    $v = $cfg[$key];
    if (is_array($v) || is_object($v)) {
        return $default;
    }
    $v = trim((string)$v);
    return $v === '' ? $default : $v;
}

function ocrens_ratio($cfg, $key, $default)
{
    $raw = ocrens_conf($cfg, $key, '');
    if ($raw === '' || !is_numeric($raw)) {
        return $default;
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

function ocrens_ids($raw)
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

/* 置信度为 0 时给一个最小权重，避免所有候选同权后随机选中 */
function ocrens_weight($conf)
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

function ocrens_compact($v)
{
    $s = preg_replace('/[^0-9A-Za-z\x{4e00}-\x{9fa5}]/u', '', (string)$v);
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}

function ocrens_len($s)
{
    return function_exists('mb_strlen') ? (int)mb_strlen((string)$s, 'UTF-8') : strlen((string)$s);
}

function ocrens_cut($s, $max)
{
    $s = (string)$s;
    if (function_exists('mb_substr')) {
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

function ocrens_ename($name)
{
    return ocrens_cut(trim((string)$name), 12);
}

function ocrens_fmt($n)
{
    $s = rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

function ocrens_date($v)
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

/* 字符级 Dice：单字 + 二元组，对中文错字 / 少字较稳健 */
function ocrens_grams($text)
{
    $s = ocrens_compact($text);
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

function ocrens_dice($a, $b)
{
    if ($a === $b) {
        return 1.0;
    }
    $ga = ocrens_grams($a);
    $gb = ocrens_grams($b);
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

/* 图号对齐：容忍 1~2 位字母前缀差异（如 TD04510R04 与 04510R04） */
function ocrens_part_same($a, $b)
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

/* 工序对齐：除相似度外，还容忍「铣加 / 铣加工」这类少字差异；一方缺失时视为同一行 */
function ocrens_proc_same($a, $b, $threshold)
{
    if ($a === '' || $b === '') {
        return true;
    }
    if ($a === $b || ocrens_dice($a, $b) >= $threshold) {
        return true;
    }
    $short = strlen($a) <= strlen($b) ? $a : $b;
    $long = strlen($a) <= strlen($b) ? $b : $a;
    return strlen($short) >= 2 && strpos($long, $short) !== false;
}

/* 原文里的串与库内图号是否同源：允许 1~2 位字母前缀差异，也允许手写多/少一两个字符 */
function ocrens_run_supports($run, $noC)
{
    if ($run === '' || $noC === '') {
        return false;
    }
    if (ocrens_part_same($run, $noC)) {
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
    return ocrens_dice($run, $noC) >= 0.75;
}

/* 判断图号能否在该引擎原文中找到依据（防止引擎按零件库"猜"出原文没有的图号） */
function ocrens_literal_support($part, $rawCompact, $rawRuns = array())
{
    if ($part === '' || ($rawCompact === '' && !$rawRuns)) {
        return true;
    }
    if ($rawCompact !== '' && strpos($rawCompact, $part) !== false) {
        return true;
    }
    foreach ($rawRuns as $run) {
        if (ocrens_run_supports($run, $part)) {
            return true;
        }
    }
    return false;
}

/* 原文中图号样式的连续串（保留分隔符，避免日期、数量粘在一起） */
function ocrens_raw_runs($raw)
{
    $s = (string)$raw;
    $s = function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    $out = array();
    if (preg_match_all('/[0-9A-Z]{5,}/', $s, $m)) {
        foreach ($m[0] as $run) {
            $out[$run] = $run;
        }
    }
    return $out;
}

/* 零件库索引：去分隔符图号 → 库内原始写法 */
function ocrens_catalog_index($catalog)
{
    $out = array();
    foreach ($catalog as $p) {
        if (!is_array($p)) {
            continue;
        }
        $no = isset($p['no']) ? trim((string)$p['no']) : '';
        $noC = ocrens_compact($no);
        if ($noC === '' || isset($out[$noC])) {
            continue;
        }
        $out[$noC] = $no;
    }
    return $out;
}

/* 各路原文里确有佐证的库内图号（含前缀差异、手写多字少字），作为图号校正候选 */
function ocrens_catalog_supported($index, $rawRuns)
{
    $out = array();
    foreach ($rawRuns as $run) {
        foreach ($index as $noC => $noRaw) {
            if ($noC === '') {
                continue;
            }
            if (ocrens_run_supports($run, $noC)) {
                $out[$noC] = $noRaw;
            }
        }
    }
    return $out;
}

/* 用零件库校正图号：精确命中直接用，否则取原文佐证候选中最接近的一个 */
function ocrens_lib_part($part, $index, $supported, $minDice)
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
        $d = ocrens_dice($part, $noC);
        if ($d < $minDice || $d <= $bestScore) {
            continue;
        }
        $bestScore = $d;
        $best = $noRaw;
    }
    return $best;
}

/**
 * 文本字段投票：按相似度聚类，累计引擎置信度作为支持权重，
 * 取累计权重最大的簇；代表值取簇内置信度最高的那条。
 */
function ocrens_vote($cands, $threshold, $baseTotal = null, $exact = false)
{
    $items = array();
    foreach ($cands as $c) {
        $v = isset($c['value']) ? trim((string)$c['value']) : '';
        if ($v === '') {
            continue;
        }
        $items[] = array(
            'value' => $v,
            'conf' => isset($c['conf']) ? (float)$c['conf'] : 0,
            'engine' => isset($c['engine']) ? (string)$c['engine'] : '',
        );
    }
    $res = array('value' => '', 'support' => 0.0, 'total' => 0.0, 'byEngine' => array());
    if (!$items) {
        return $res;
    }
    usort($items, function ($a, $b) {
        if ($a['conf'] == $b['conf']) {
            return 0;
        }
        return $a['conf'] > $b['conf'] ? -1 : 1;
    });

    $clusters = array();
    foreach ($items as $it) {
        $placed = false;
        foreach ($clusters as $idx => $cl) {
            $same = $exact
                ? ($cl['rep']['value'] === $it['value'])
                : (ocrens_dice($cl['rep']['value'], $it['value']) >= $threshold);
            if ($same) {
                $clusters[$idx]['members'][] = $it;
                $clusters[$idx]['weight'] += ocrens_weight($it['conf']);
                if ($it['conf'] > $clusters[$idx]['rep']['conf']) {
                    $clusters[$idx]['rep'] = $it;
                }
                $placed = true;
                break;
            }
        }
        if (!$placed) {
            $clusters[] = array(
                'rep' => $it,
                'members' => array($it),
                'weight' => ocrens_weight($it['conf']),
            );
        }
    }

    $total = 0.0;
    foreach ($clusters as $cl) {
        $total += $cl['weight'];
    }
    $win = null;
    foreach ($clusters as $cl) {
        if ($win === null
            || $cl['weight'] > $win['weight'] + 1e-9
            || (abs($cl['weight'] - $win['weight']) <= 1e-9 && $cl['rep']['conf'] > $win['rep']['conf'])) {
            $win = $cl;
        }
    }

    $res['value'] = $win['rep']['value'];
    $res['support'] = $win['weight'];
    $res['total'] = ($baseTotal !== null && (float)$baseTotal > $total) ? (float)$baseTotal : $total;
    foreach ($items as $it) {
        $res['byEngine'][$it['engine']] = $it['value'];
    }
    return $res;
}

/**
 * 数值字段：置信度加权中位数，避免某一路毛刺把整行带偏。
 */
function ocrens_median($pairs, $baseTotal = null)
{
    $items = array();
    foreach ($pairs as $p) {
        if (!isset($p['value']) || !is_numeric($p['value'])) {
            continue;
        }
        $items[] = array(
            'value' => (float)$p['value'],
            'weight' => ocrens_weight(isset($p['conf']) ? $p['conf'] : 0),
            'engine' => isset($p['engine']) ? (string)$p['engine'] : '',
        );
    }
    $res = array('value' => null, 'support' => 0.0, 'total' => 0.0, 'byEngine' => array());
    if (!$items) {
        return $res;
    }
    usort($items, function ($a, $b) {
        if ($a['value'] == $b['value']) {
            return 0;
        }
        return $a['value'] < $b['value'] ? -1 : 1;
    });

    $total = 0.0;
    foreach ($items as $it) {
        $total += $it['weight'];
    }
    $half = $total / 2;
    $acc = 0.0;
    $median = null;
    $count = count($items);
    for ($i = 0; $i < $count; $i++) {
        $acc += $items[$i]['weight'];
        if ($acc > $half + 1e-9) {
            $median = $items[$i]['value'];
            break;
        }
        if (abs($acc - $half) <= 1e-9) {
            /* 偶数权重正好对半时取已观测到的值，不凭空平均出未出现过的数量 */
            $median = $items[$i]['value'];
            break;
        }
    }
    if ($median === null) {
        $median = $items[$count - 1]['value'];
    }

    $support = 0.0;
    foreach ($items as $it) {
        if (abs($it['value'] - $median) < 1e-6) {
            $support += $it['weight'];
        }
        $res['byEngine'][$it['engine']] = $it['value'];
    }
    $res['value'] = $median;
    $res['support'] = $support;
    $res['total'] = ($baseTotal !== null && (float)$baseTotal > $total) ? (float)$baseTotal : $total;
    return $res;
}

/* ================================================================ 1. 选择引擎 */

$agreeThreshold = ocrens_ratio($cfg, 'agreeThreshold', 0.85);
if ($agreeThreshold < 0.3) {
    $agreeThreshold = 0.3;
}
$minConfidence = ocrens_ratio($cfg, 'minConfidence', 0.6);

$warnings = array();
$all = bg_plugin_all();
$want = ocrens_ids(ocrens_conf($cfg, 'engines', ''));
$engines = array();
if (!$want) {
    foreach ($all as $p) {
        if ($p['id'] === $selfId || !in_array('report.ocr', $p['capabilities'], true)) {
            continue;
        }
        if (!bg_plugin_is_enabled($p['id'])) {
            continue;
        }
        $engines[] = $p;
    }
} else {
    foreach ($want as $id) {
        if ($id === $selfId) {
            continue;
        }
        if (!isset($all[$id])) {
            $warnings[] = '引擎 ' . $id . ' 未安装，已跳过';
            continue;
        }
        $p = $all[$id];
        if (!in_array('report.ocr', $p['capabilities'], true)) {
            $warnings[] = $p['name'] . ' 不支持报工识别，已跳过';
            continue;
        }
        if (!bg_plugin_is_enabled($id)) {
            $warnings[] = $p['name'] . ' 已停用，已跳过';
            continue;
        }
        $engines[] = $p;
    }
}
if (!$engines) {
    throw new RuntimeException('没有可用的识别引擎，请检查「参与融合的引擎」设置，或先启用其它识别插件');
}
if (count($engines) < 2) {
    $warnings[] = '仅 1 路引擎参与融合，无法交叉校验';
}

/* ================================================================ 2. 逐路调用 */

$results = array();
foreach ($engines as $p) {
    try {
        $r = bg_plugin_invoke_engine($p['id'], $ctx);
        $r['name'] = $p['name'];
        $results[] = $r;
    } catch (Throwable $e) {
        $warnings[] = $p['name'] . ' 调用失败：' . $e->getMessage();
    }
}
if (!$results) {
    throw new RuntimeException('所有引擎均调用失败：' . implode('；', array_slice($warnings, 0, 2)));
}

$sumConf = 0.0;
foreach ($results as $r) {
    $sumConf += (float)$r['confidence'];
}
$meanConf = $sumConf / count($results);

/* ================================================================ 3. 标量字段投票 */

$agreeValues = array();
$votes = array();
foreach (array('date', 'worker', 'workshop', 'note') as $field) {
    $cands = array();
    foreach ($results as $r) {
        $val = isset($r['fields'][$field]) ? $r['fields'][$field] : '';
        if ($field === 'date') {
            $val = ocrens_date($val);
        }
        $cands[] = array('value' => $val, 'conf' => $r['confidence'], 'engine' => $r['name']);
    }
    $v = ocrens_vote($cands, $agreeThreshold, null, $field === 'date');
    $votes[$field] = $v;
    if ($v['total'] > 0) {
        $agreeValues[] = $v['support'] / $v['total'];
    }
}

$fieldNames = array('date' => '日期', 'worker' => '报工人', 'workshop' => '车间', 'note' => '备注');
$disagreements = array();
foreach (array('date', 'worker', 'workshop', 'note') as $field) {
    $v = $votes[$field];
    if ($v['total'] > 0 && $v['support'] < $v['total'] - 1e-9) {
        $seg = array();
        foreach ($v['byEngine'] as $eng => $val) {
            if ($field === 'date' ? $val === $v['value'] : ocrens_dice($v['value'], $val) >= $agreeThreshold) {
                continue;
            }
            $seg[] = ocrens_ename($eng) . '=' . ocrens_cut($val, 12);
        }
        if ($seg) {
            $disagreements[] = $fieldNames[$field] . ' 存在分歧：' . implode(' / ', array_slice($seg, 0, 3));
        }
    }
}

/* ================================================================ 4. 明细行对齐与投票 */

$groups = array();
foreach ($results as $r) {
    $engineId = isset($r['plugin']['id']) ? $r['plugin']['id'] : $r['name'];
    $items = isset($r['fields']['items']) && is_array($r['fields']['items']) ? $r['fields']['items'] : array();
    foreach ($items as $idx => $it) {
        if (!is_array($it)) {
            continue;
        }
        $no = ocrens_compact(isset($it['partNo']) ? $it['partNo'] : '');
        $name = ocrens_compact(isset($it['partName']) ? $it['partName'] : '');
        $proc = ocrens_compact(isset($it['process']) ? $it['process'] : '');
        $kind = $no !== '' ? 'no' : ($name !== '' ? 'name' : '');
        $part = $no !== '' ? $no : $name;

        /* 容错归组：图号前缀差异（TD… / …）、工序少字都不再拆成两行 */
        $target = null;
        if ($kind !== '') {
            foreach ($groups as $gi => $g) {
                /* 同一路引擎在这一组里已有行，说明它确实报了多行（如 铣1 / 铣2），另起一组 */
                if ($g['kind'] !== $kind || isset($g['rows'][$engineId])) {
                    continue;
                }
                $same = $kind === 'no'
                    ? ocrens_part_same($g['part'], $part)
                    : (ocrens_dice($g['part'], $part) >= $agreeThreshold);
                if ($same && ocrens_proc_same($g['proc'], $proc, $agreeThreshold)) {
                    $target = $gi;
                    break;
                }
            }
        }
        if ($target === null) {
            /* 图号与名称都缺失时无法可靠对齐，按引擎分行保留 */
            $groups[] = array('kind' => $kind, 'part' => $part, 'proc' => $proc, 'rows' => array());
            $target = count($groups) - 1;
        }
        /* 缺工序的一行被别的引擎补上工序后，组内后续行才能按工序正确区分 */
        if ($groups[$target]['proc'] === '' && $proc !== '') {
            $groups[$target]['proc'] = $proc;
        }
        $conf = (float)$r['confidence'];
        if (!isset($groups[$target]['rows'][$engineId]) || $conf > $groups[$target]['rows'][$engineId]['conf']) {
            $groups[$target]['rows'][$engineId] = array(
                'row' => $it,
                'conf' => $conf,
                'engine' => $r['name'],
                'id' => $engineId,
            );
        }
    }
}

/* 单路独有、且图号在该路原文中找不到依据的行，多为引擎按零件库推测，丢弃以免填错 */
$rawByEngine = array();
foreach ($results as $r) {
    $engineId = isset($r['plugin']['id']) ? $r['plugin']['id'] : $r['name'];
    $raw = isset($r['raw']) ? (string)$r['raw'] : '';
    $rawByEngine[$engineId] = array(
        'compact' => ocrens_compact($raw),
        'runs' => ocrens_raw_runs($raw),
    );
}
$dropped = array();
foreach ($groups as $gi => $g) {
    if (count($g['rows']) !== 1 || $g['kind'] !== 'no' || $g['part'] === '') {
        continue;
    }
    $only = reset($g['rows']);
    $rawInfo = isset($rawByEngine[$only['id']]) ? $rawByEngine[$only['id']] : array('compact' => '', 'runs' => array());
    if (ocrens_literal_support($g['part'], $rawInfo['compact'], $rawInfo['runs'])) {
        continue;
    }
    $dropped[] = $only['engine'] . '=' . $g['part'];
    unset($groups[$gi]);
}
if ($dropped) {
    $groups = array_values($groups);
    $warnings[] = '已忽略 ' . count($dropped) . ' 行原文中查不到的识别结果（'
        . implode(' / ', array_slice($dropped, 0, 2))
        . '），可能是引擎按零件库推测，请以原文为准';
}

/* 图号按零件库校正：各路识别成 26971B05 / 25971305 的同一行，
   只要原文里有 24971B05 的依据，就归到库内 L24971B05，并合并成一行 */
$catalog = isset($ctx['catalog']) && is_array($ctx['catalog']) ? $ctx['catalog'] : array();
$libIndex = ocrens_catalog_index($catalog);
$rawRuns = array();
foreach ($results as $r) {
    foreach (ocrens_raw_runs(isset($r['raw']) ? $r['raw'] : '') as $run) {
        $rawRuns[$run] = $run;
    }
}
$libSupported = $libIndex ? ocrens_catalog_supported($libIndex, $rawRuns) : array();
foreach ($groups as $gi => $g) {
    $groups[$gi]['canon'] = ($g['kind'] === 'no' && $libSupported)
        ? ocrens_lib_part($g['part'], $libIndex, $libSupported, 0.5)
        : '';
}
$mergedGroups = array();
foreach ($groups as $g) {
    $target = null;
    if ($g['canon'] !== '') {
        foreach ($mergedGroups as $mi => $m) {
            /* 同一零件还要工序对得上才合并，避免把 铣1 / 铣2 并成一行 */
            if ($m['canon'] === $g['canon'] && ocrens_proc_same($m['proc'], $g['proc'], $agreeThreshold)) {
                $target = $mi;
                break;
            }
        }
    }
    if ($target === null) {
        $mergedGroups[] = $g;
        continue;
    }
    foreach ($g['rows'] as $engineId => $row) {
        if (!isset($mergedGroups[$target]['rows'][$engineId]) || $row['conf'] > $mergedGroups[$target]['rows'][$engineId]['conf']) {
            $mergedGroups[$target]['rows'][$engineId] = $row;
        }
    }
    if ($mergedGroups[$target]['proc'] === '' && $g['proc'] !== '') {
        $mergedGroups[$target]['proc'] = $g['proc'];
    }
}
$groups = $mergedGroups;

$items = array();
$rowDisagreements = array();
$corrections = array();
$rowNo = 0;
foreach ($groups as $group) {
    $rowNo++;
    $rows = array_values($group['rows']);
    $base = 0.0;
    foreach ($rows as $row) {
        $base += ocrens_weight($row['conf']);
    }

    $partNoCands = array();
    $partNameCands = array();
    $processCands = array();
    $qtyPairs = array();
    $pricePairs = array();
    foreach ($rows as $row) {
        $it = $row['row'];
        $partNoCands[] = array('value' => isset($it['partNo']) ? $it['partNo'] : '', 'conf' => $row['conf'], 'engine' => $row['engine']);
        $partNameCands[] = array('value' => isset($it['partName']) ? $it['partName'] : '', 'conf' => $row['conf'], 'engine' => $row['engine']);
        $processCands[] = array('value' => isset($it['process']) ? $it['process'] : '', 'conf' => $row['conf'], 'engine' => $row['engine']);
        if (isset($it['qty']) && is_numeric($it['qty'])) {
            $qtyPairs[] = array('value' => (float)$it['qty'], 'conf' => $row['conf'], 'engine' => $row['engine']);
        }
        if (isset($it['price']) && $it['price'] !== null && is_numeric($it['price'])) {
            $pricePairs[] = array('value' => (float)$it['price'], 'conf' => $row['conf'], 'engine' => $row['engine']);
        }
    }

    /* 数量：有正数时忽略 0（通常代表该路没识别到） */
    $hasPositive = false;
    foreach ($qtyPairs as $p) {
        if ($p['value'] > 0) {
            $hasPositive = true;
            break;
        }
    }
    if ($hasPositive) {
        $qtyPairs = array_values(array_filter($qtyPairs, function ($p) {
            return $p['value'] > 0;
        }));
    }

    $partNo = ocrens_vote($partNoCands, $agreeThreshold, $base);
    $partName = ocrens_vote($partNameCands, $agreeThreshold, $base);
    $process = ocrens_vote($processCands, $agreeThreshold, $base);
    $qty = ocrens_median($qtyPairs, $base);
    /* 单价允许缺失：只用提供了单价的引擎计算一致性，避免“没填”被当成分歧 */
    $price = ocrens_median($pricePairs);

    foreach (array($partNo, $partName, $process, $qty, $price) as $v) {
        if ($v['total'] > 0) {
            $agreeValues[] = $v['support'] / $v['total'];
        }
    }

    /* 库内能确认的图号以库内写法为准，避免各路都读错时投票选出不存在的图号 */
    $partNoValue = $partNo['value'];
    $canon = isset($group['canon']) ? $group['canon'] : '';
    if ($canon !== '') {
        $canonKey = ocrens_compact($canon);
        $seenRead = array();
        foreach ($rows as $row) {
            $read = isset($row['row']['partNo']) ? trim((string)$row['row']['partNo']) : '';
            $readKey = ocrens_compact($read);
            if ($readKey === '' || $readKey === $canonKey || ocrens_part_same($readKey, $canonKey) || isset($seenRead[$readKey])) {
                continue;
            }
            $seenRead[$readKey] = true;
            $corrections[] = $read . '→' . $canon;
        }
        $partNoValue = $canon;
    }

    $items[] = array(
        'partNo' => $partNoValue,
        'partName' => $partName['value'],
        'process' => $process['value'],
        'qty' => $qty['value'] === null ? 0 : $qty['value'],
        'price' => $price['value'] === null ? null : $price['value'],
    );

    $diff = array();
    $labels = array('图号' => $partNo, '名称' => $partName, '工序' => $process, '数量' => $qty, '单价' => $price);
    if ($canon !== '') {
        /* 图号已按零件库校正，不再重复报告各路的读法差异 */
        unset($labels['图号']);
    }
    foreach ($labels as $label => $v) {
        if ($v['total'] <= 0 || $v['support'] >= $v['total'] - 1e-9) {
            continue;
        }
        $numeric = ($label === '数量' || $label === '单价');
        $seg = array();
        foreach ($v['byEngine'] as $eng => $val) {
            if ($numeric) {
                if ($v['value'] !== null && abs((float)$val - (float)$v['value']) < 1e-6) {
                    continue;
                }
            } elseif (ocrens_dice($v['value'], $val) >= $agreeThreshold) {
                continue;
            }
            $seg[] = ocrens_ename($eng) . '=' . ($numeric ? ocrens_fmt($val) : ocrens_cut($val, 12));
        }
        if ($seg) {
            $diff[] = $label . ' ' . implode('/', array_slice($seg, 0, 3));
        }
    }
    if ($diff) {
        $rowDisagreements[] = '第 ' . $rowNo . ' 行：' . implode('；', array_slice($diff, 0, 2));
    }
}

/* ================================================================ 5. 综合置信度 */

$agreement = $agreeValues ? array_sum($agreeValues) / count($agreeValues) : 0.0;
$combined = $meanConf * (0.5 + 0.5 * $agreement);
$combined = max(0.0, min(1.0, $combined));

if ($combined < $minConfidence) {
    $warnings[] = '综合置信度 ' . round($combined * 100) . '%，低于 ' . round($minConfidence * 100) . '%，建议人工复核';
}
foreach ($disagreements as $w) {
    $warnings[] = $w;
}
foreach ($rowDisagreements as $w) {
    $warnings[] = $w;
}
if ($corrections) {
    $warnings[] = '图号已按零件库校正：' . implode(' / ', array_slice($corrections, 0, 3));
}
if (!$items) {
    $warnings[] = '未识别出报工明细，可对照下方原文手动填充';
}

/* ================================================================ 6. 返回 */

$budget = 3600;
$rawParts = array();
foreach ($results as $r) {
    $txt = isset($r['raw']) ? trim((string)$r['raw']) : '';
    if ($txt === '' || $budget < 200) {
        continue;
    }
    $seg = '【' . $r['name'] . '】' . "\n" . ocrens_cut($txt, $budget);
    $rawParts[] = $seg;
    $budget -= ocrens_len($seg);
}
$raw = $rawParts ? implode("\n\n", $rawParts) : '融合引擎只返回了结构化结果，已按上方表单填写';

return array(
    'engine' => '多 OCR 融合（' . count($results) . ' 路）',
    'confidence' => round($combined, 3),
    'fields' => array(
        'date' => $votes['date']['value'],
        'worker' => $votes['worker']['value'],
        'workshop' => $votes['workshop']['value'],
        'note' => $votes['note']['value'],
        'items' => $items,
    ),
    'warnings' => $warnings,
    'raw' => ocrens_cut($raw, 4000),
);
