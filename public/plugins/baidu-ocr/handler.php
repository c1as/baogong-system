<?php
/**
 * 百度云 OCR 识别处理器
 *
 * 由 api.php?action=plugin.invoke 调用，读取 $GLOBALS['BG_PLUGIN_CTX']，
 * 返回统一的识别结果结构（见 public/plugins/mock-ocr/handler.php 顶部说明）。
 *
 * 针对手写报工单的解析策略：
 *   1. 日期：支持 2026-11-20 / 2026.11.20 / 11.20 / 11月20日，缺年份按当年；
 *   2. 零件：图号空格、短横线、点等写法差异先归一化，再精确匹配；失败时按相似度模糊匹配；
 *   3. 工序：工序名包含匹配 + 工序同义字典（铣加工↔铣、钻↔钻孔 等）+ 关键字重合；
 *   4. 数量：优先识别带单位（件/个/只…）的数字，并排除图号内部的数字；
 *   5. 未匹配的行写入 warnings，前端可点选原文手动填充。
 */

/* 仅允许由 api.php 加载，禁止直接访问 */
if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/lib.php';

/*
 * 手写字词库连接层（唯一入口）：一行引入 + 一行初始化。
 * 词库缺失 / 损坏 / 异常时连接层静默降级为「未命中」，不影响本插件识别。
 */
$bglxBridgeFile = dirname(__DIR__) . '/handwriting-lexicon/lib/bridge.php';
if (is_file($bglxBridgeFile)) {
    require_once $bglxBridgeFile;
    bglx_bridge_init('baidu-ocr');
}

$ctx = $GLOBALS['BG_PLUGIN_CTX'];
$cfg = isset($ctx['config']) && is_array($ctx['config']) ? $ctx['config'] : array();
$catalog = isset($ctx['catalog']) && is_array($ctx['catalog']) ? $ctx['catalog'] : array();
$image = isset($ctx['image']) && is_string($ctx['image']) ? $ctx['image'] : '';
$today = isset($ctx['today']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ctx['today'])
    ? (string)$ctx['today'] : date('Y-m-d');

$c = bg_baidu_cfg($cfg);

if (!preg_match('#^data:image/(?:jpeg|png|webp);base64,(.+)$#s', $image, $mt)) {
    throw new RuntimeException('图片格式不支持，请上传 JPG / PNG / WebP');
}
$binary = base64_decode($mt[1], true);
if ($binary === false || $binary === '') {
    throw new RuntimeException('图片解码失败，请重新拍照');
}

$ocr = bg_baidu_ocr($cfg, $binary);
$cells = isset($ocr['cells']) && is_array($ocr['cells']) ? $ocr['cells'] : array();
$lines = $ocr['lines'];
if (!$lines) {
    throw new RuntimeException('没有识别到文字，请把报工单拍清晰、光线充足后重试');
}

/**
 * 按纵坐标把文字块合并成“行”，再按横坐标排序，还原纸面表格的行结构。
 * 手写单常把「图号 / 工序 / 数量」分列书写，先合并成行才能正确配对。
 */
function bg_baidu_rows($cells)
{
    $rows = array();
    foreach ($cells as $c) {
        $cy = $c['top'] + $c['height'] / 2;
        $hit = -1;
        foreach ($rows as $i => $r) {
            $tol = max(12, min($r['h'], $c['height']) * 0.6);
            if (abs($r['cy'] - $cy) <= $tol) {
                $hit = $i;
                break;
            }
        }
        if ($hit < 0) {
            $rows[] = array('cy' => $cy, 'h' => $c['height'], 'cells' => array($c));
        } else {
            $n = count($rows[$hit]['cells']);
            $rows[$hit]['cy'] = ($rows[$hit]['cy'] * $n + $cy) / ($n + 1);
            $rows[$hit]['h'] = max($rows[$hit]['h'], $c['height']);
            $rows[$hit]['cells'][] = $c;
        }
    }
    usort($rows, function ($a, $b) {
        return $a['cy'] <=> $b['cy'];
    });
    $out = array();
    foreach ($rows as $r) {
        usort($r['cells'], function ($a, $b) {
            return $a['left'] <=> $b['left'];
        });
        $parts = array();
        foreach ($r['cells'] as $c) {
            $parts[] = $c['words'];
        }
        $out[] = implode(' ', $parts);
    }
    return $out;
}

$rowTexts = $cells ? bg_baidu_rows($cells) : $lines;
if (!$rowTexts) {
    $rowTexts = $lines;
}
$text = implode("\n", $rowTexts);
/* 明细与日期都按“合并后的行”解析，保证同一行的图号 / 工序 / 数量能配对 */
$parseLines = $rowTexts;
$noLocation = !$cells;

/* ---------------- 文本归一化 ---------------- */

function bg_baidu_norm($s)
{
    $s = str_replace(array('－', '—', '–', '‑', '　'), '-', (string)$s);
    $s = preg_replace('/\s+/u', '', $s);
    return strtoupper($s === null ? '' : $s);
}

function bg_baidu_mlen($s)
{
    if (function_exists('mb_strlen')) {
        return (int)mb_strlen((string)$s, 'UTF-8');
    }
    return strlen((string)$s);
}

/* 去掉分隔符后的图号：90535 R183 / 90535-R183 / 90535.R183 视为同一个 */
function bg_baidu_compact($s)
{
    $s = preg_replace('/[^0-9A-Z\x{4e00}-\x{9fa5}]/u', '', bg_baidu_norm($s));
    return $s === null ? '' : $s;
}

function bg_baidu_lev($a, $b)
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

function bg_baidu_sim($a, $b)
{
    $m = max(strlen($a), strlen($b));
    return $m > 0 ? 1 - bg_baidu_lev($a, $b) / $m : 0;
}

/* 图号精确匹配：纯字母数字图号要求前后不是同类字符，避免 A1 命中 A123 */
function bg_baidu_has_no($norm, $no)
{
    $no = bg_baidu_norm($no);
    if ($no === '' || bg_baidu_mlen($no) < 2) {
        return false;
    }
    if (preg_match('/^[A-Z0-9._-]+$/', $no)) {
        return (bool)preg_match('/(?<![A-Z0-9])' . preg_quote($no, '/') . '(?![A-Z0-9])/', $norm);
    }
    return strpos($norm, $no) !== false;
}

/* 行内连续的字母数字段（>=4 位），如 90535R183；用于匹配带前缀的图号 TD90535R183 */
function bg_baidu_runs($flat)
{
    $runs = array();
    if (preg_match_all('/[A-Z0-9]{4,}/', $flat, $ms)) {
        foreach ($ms[0] as $m) {
            $runs[] = $m;
        }
    }
    return $runs;
}

/* 去掉日期写法，避免 10.11 523R301 被合并成一个长串 */
function bg_baidu_strip_date($s)
{
    return preg_replace(
        array(
            '/20\d{2}\s*[-\/年.]\s*\d{1,2}\s*[-\/月.]\s*\d{1,2}\s*日?/u',
            '/(?<!\d)\d{1,2}\s*[-\/.]\s*\d{1,2}(?!\d)\s*日?/u',
        ),
        ' ',
        (string)$s
    );
}

/* 零件匹配：精确 → 去分隔符包含 → 图号段包含 → 相似度模糊 */
function bg_baidu_match_part($line, $norm, $catalog)
{
    /* 图号归一已收敛到字库连接层，在结果字段上统一批量处理；此处保留原识别逻辑 */
    $norm = bg_baidu_norm($line);

    $lineC = bg_baidu_compact(bg_baidu_strip_date($line));
    $best = null;
    $bestScore = 0;

    /* 只取最长的字母数字段，避免短数字误命中多个图号 */
    $runs = bg_baidu_runs($lineC);
    $maxRun = 0;
    foreach ($runs as $r) {
        $maxRun = max($maxRun, strlen($r));
    }
    $longRuns = array();
    foreach ($runs as $r) {
        if (strlen($r) === $maxRun && $maxRun >= 4) {
            $longRuns[] = $r;
        }
    }

    foreach ($catalog as $p) {
        $no = isset($p['no']) ? (string)$p['no'] : '';
        $noN = bg_baidu_norm($no);
        $noC = bg_baidu_compact($no);
        if ($noN === '') {
            continue;
        }
        $score = 0;
        if (bg_baidu_has_no($norm, $no)) {
            $score = 1;
        } elseif (strlen($noC) >= 4 && strpos($lineC, $noC) !== false) {
            $score = 0.96;
        } elseif ($longRuns) {
            foreach ($longRuns as $run) {
                $runLen = strlen($run);
                if ($runLen < 4) {
                    continue;
                }
                /* 图号尾部可能粘上数量：523R30130 → 取前缀 523R301 */
                for ($l = min($runLen, strlen($noC)); $l >= 4; $l--) {
                    $pre = substr($run, 0, $l);
                    if (strpos($noC, $pre) !== false) {
                        $score = 0.95;
                        break 2;
                    }
                    if (strpos($pre, $noC) !== false) {
                        $score = 0.93;
                        break 2;
                    }
                }
                /* 缺字 / 多字的近似：30607R22 ≈ T306077R22 */
                $sim = bg_baidu_sim($run, $noC);
                if ($sim >= 0.72) {
                    $score = max($score, 0.8 + ($sim - 0.72) * 0.5);
                }
            }
        }
        if (!$score && strlen($noC) >= 5) {
            /* 在图号等长的窗口上取最高相似度，容忍 1-2 个字符的 OCR 误差 */
            $len = strlen($noC);
            $seg = 0;
            $lc = strlen($lineC);
            for ($i = 0; $i + $len <= $lc; $i++) {
                $seg = max($seg, bg_baidu_sim(substr($lineC, $i, $len), $noC));
            }
            if ($seg >= 0.75) {
                $score = 0.72 + ($seg - 0.75) * 0.5;
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $p;
        }
    }
    if ($best !== null) {
        return $best;
    }

    /* 图号没匹配上时再按零件名称匹配 */
    foreach ($catalog as $p) {
        $name = isset($p['name']) ? bg_baidu_norm($p['name']) : '';
        if ($name !== '' && bg_baidu_mlen($name) >= 2 && strpos($norm, $name) !== false) {
            return $p;
        }
    }
    return null;
}

/* 工序关键字字典：把「铣加工」「钻孔」等写法归到同一类；设置里可追加自定义同义词 */
function bg_baidu_proc_base($s)
{
    static $map = array(
        '车' => array('车削', '车加工', '车外圆', '车内孔', '车工', '车'),
        '铣' => array('铣削', '铣加工', '铣工', '铣面', '铣槽', '铣', '锐加工', '锐'),
        '钻' => array('钻孔', '钻削', '钻工', '打孔', '钻'),
        '磨' => array('磨削', '磨工', '平磨', '外圆磨', '内圆磨', '磨'),
        '钳' => array('钳工', '去毛刺', '攻丝', '倒角', '钳'),
        '镗' => array('镗孔', '镗削', '镗'),
        '插' => array('插齿', '插'),
        '滚' => array('滚齿', '滚'),
        '线' => array('线切割', '电火花', '线'),
        '热' => array('热处理', '淬火', '调质', '发黑', '热'),
        '焊' => array('焊接', '焊工', '焊'),
    );
    /* 工序关键字归一已收敛到字库连接层；此处保留最小内置匹配表作为识别启发式 */
    $merged = $map;
    $s = bg_baidu_norm($s);
    if ($s === '') {
        return '';
    }
    foreach ($merged as $base => $words) {
        foreach ($words as $w) {
            if (strpos($s, bg_baidu_norm($w)) !== false) {
                return $base;
            }
        }
    }
    return '';
}

function bg_baidu_match_process($lineNorm, $procs)
{
    /* 工序归一已收敛到字库连接层，在结果字段上统一批量处理；此处保留原包含匹配 */
    $best = '';
    $bestLen = 0;
    foreach ($procs as $pn) {
        $pnn = bg_baidu_norm($pn);
        if ($pnn !== '' && strpos($lineNorm, $pnn) !== false && bg_baidu_mlen($pnn) > $bestLen) {
            $best = (string)$pn;
            $bestLen = bg_baidu_mlen($pnn);
        }
    }
    if ($best !== '') {
        return $best;
    }
    /* 同义字典：写成「铣加工」也能匹配工序「铣」 */
    $lineBase = bg_baidu_proc_base($lineNorm);
    if ($lineBase !== '') {
        foreach ($procs as $pn) {
            if (bg_baidu_proc_base((string)$pn) === $lineBase) {
                return (string)$pn;
            }
        }
    }
    return '';
}

/* 数量：优先带单位的数字，并排除图号内部的数字 */
function bg_baidu_qty($line, $partNo)
{
    /* 先去掉日期写法，避免把 11.20 / 2026-11-20 当成数量 */
    $line = preg_replace(
        array(
            '/20\d{2}\s*[-\/年.]\s*\d{1,2}\s*[-\/月.]\s*\d{1,2}\s*日?/u',
            '/(?<!\d)\d{1,2}\s*[-\/.]\s*\d{1,2}(?!\d)\s*日?/u',
        ),
        ' ',
        (string)$line
    );
    $partC = bg_baidu_compact($partNo);
    $cands = array();
    if (preg_match_all('/(?<![\d.])(\d{1,6}(?:\.\d{1,2})?)\s*(件|个|只|根|套|支|片|pcs)?/iu', $line, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) {
            $v = (float)$m[1];
            $unit = isset($m[2]) ? (string)$m[2] : '';
            if ($v <= 0 || $v > 100000) {
                continue;
            }
            if ($unit === '' && $partC !== '' && strpos($partC, str_replace('.', '', $m[1])) !== false) {
                continue;
            }
            $cands[] = array('v' => $v, 'unit' => $unit);
        }
    }
    for ($i = count($cands) - 1; $i >= 0; $i--) {
        if ($cands[$i]['unit'] !== '') {
            return $cands[$i]['v'];
        }
    }
    return $cands ? $cands[count($cands) - 1]['v'] : 0;
}

/**
 * 把一行按出现的图号切成多段。
 * 手写单常见「表头只写一个日期，下面连写多个零件」，行距小时 OCR 可能把几行并成一行，
 * 这里用图号段作为锚点切开，保证每段各自解析工序和数量。
 */
function bg_baidu_split_parts($line, $catalog)
{
    /*
     * [修复] 保留字段间的空格再切段。
     * 手写单「铣1」「50件」分列书写，若先用 norm 去掉空格会粘成「铣150件」，
     * 数量正则会把 150 当成带单位数字，读成 150 而非 50（静默数据错误）。
     */
    $spaced = preg_replace('/\s+/u', ' ', (string)$line); // 合并多余空白为一个空格，保留分隔
    $spaced = trim(bg_baidu_strip_date($spaced));
    if ($spaced === '') {
        return array();
    }
    /* 在「带空格」文本上定位图号段与偏移（substr 与 PREG_OFFSET_CAPTURE 均为字节偏移，一致） */
    $runs = array();
    if (preg_match_all('/[A-Za-z0-9]{4,}/', $spaced, $ms, PREG_OFFSET_CAPTURE)) {
        foreach ($ms[0] as $m) {
            $runs[] = array('s' => (string)$m[0], 'off' => (int)$m[1]);
        }
    }
    $anchors = array();
    foreach ($runs as $r) {
        $runC = bg_baidu_compact($r['s']); // 匹配时归一化大小写/分隔符，不破坏原文分隔
        $hit = null;
        $hitScore = 0;
        foreach ($catalog as $p) {
            $noC = bg_baidu_compact(isset($p['no']) ? (string)$p['no'] : '');
            if ($noC === '' || $runC === '') {
                continue;
            }
            $score = 0;
            if (strpos($noC, $runC) !== false) {
                $score = 1;
            } elseif (strpos($runC, $noC) !== false) {
                $score = 0.95;
            } else {
                $sim = bg_baidu_sim($runC, $noC);
                if ($sim >= 0.72) {
                    $score = 0.5 + $sim * 0.4;
                }
            }
            if ($score > $hitScore) {
                $hitScore = $score;
                $hit = $p;
            }
        }
        if ($hit !== null) {
            $last = count($anchors) - 1;
            /* 同一零件相邻的重复锚点只保留一个 */
            if ($last < 0 || $anchors[$last]['part'] !== $hit) {
                $anchors[] = array('off' => $r['off'], 'part' => $hit);
            }
        }
    }
    $out = array();
    $n = count($anchors);
    for ($i = 0; $i < $n; $i++) {
        $start = $i === 0 ? 0 : $anchors[$i]['off'];
        $end = $i + 1 < $n ? $anchors[$i + 1]['off'] : strlen($spaced);
        $out[] = array(
            'part' => $anchors[$i]['part'],
            'text' => substr($spaced, $start, $end - $start),
        );
    }
    return $out;
}

/* ---------------- 日期 ---------------- */

$date = '';
$dateHint = '';

/* 1) 带年份：2026-11-20 / 2026.11.20 / 2026年11月20日 */
if (preg_match('/(20\d{2})\s*[-\/年.]\s*(\d{1,2})\s*[-\/月.]\s*(\d{1,2})/u', $text, $dm)) {
    if (checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) {
        $date = sprintf('%04d-%02d-%02d', (int)$dm[1], (int)$dm[2], (int)$dm[3]);
    }
}
if ($date === '' && preg_match('/(?<!\d)(20\d{2})(\d{2})(\d{2})(?!\d)/', $text, $dm)) {
    if (checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) {
        $date = sprintf('%04d-%02d-%02d', (int)$dm[1], (int)$dm[2], (int)$dm[3]);
    }
}

/* 2) 不带年份：日期行里的 11.20 / 11/20 / 11月20日，按当年补全 */
if ($date === '') {
    foreach ($parseLines as $ln) {
        if (!preg_match('/日期|时间|date/i', $ln)) {
            continue;
        }
        if (preg_match('/(?<!\d)(\d{1,2})\s*[-\/.月]\s*(\d{1,2})(?!\d)\s*日?/u', $ln, $dm)) {
            $mo = (int)$dm[1];
            $d = (int)$dm[2];
            $y = (int)date('Y');
            if (checkdate($mo, $d, $y)) {
                $date = sprintf('%04d-%02d-%02d', $y, $mo, $d);
                break;
            }
        }
    }
}

/* 3) 单独一个文字块就是 11.20 这种写法（手写单常见） */
if ($date === '') {
    $tokens = array();
    foreach ($cells as $cell) {
        $tokens[] = trim($cell['words']);
    }
    foreach ($parseLines as $ln) {
        $tokens[] = trim($ln);
    }
    foreach ($tokens as $t) {
        if (preg_match('/^(\d{1,2})\s*[-\/.]\s*(\d{1,2})$/', $t, $dm)) {
            $mo = (int)$dm[1];
            $d = (int)$dm[2];
            $y = (int)date('Y');
            if (checkdate($mo, $d, $y)) {
                $date = sprintf('%04d-%02d-%02d', $y, $mo, $d);
                break;
            }
        }
    }
}

if ($date === '') {
    if ($c['dateFallback'] !== '') {
        $date = $today;
        $dateHint = '未识别到日期，已按当天填充';
    } else {
        $dateHint = '未识别到日期，请手动填写';
    }
}

/* ---------------- 明细行 ---------------- */

$items = array();
$warnings = array();
$candidates = 0;
$unmatched = array();
$procCounts = array();
$procAmbig = array();

/**
 * 生成一条明细：匹配工序、解析数量，并记录工序数量用于提示。
 */
$addItem = function ($part, $segText) use (&$items, &$procCounts, &$procAmbig) {
    $procs = array();
    if (isset($part['processes']) && is_array($part['processes'])) {
        $procs = $part['processes'];
    }
    $proc = bg_baidu_match_process($segText, $procs);
    $qty = bg_baidu_qty($segText, isset($part['no']) ? (string)$part['no'] : '');

    /* 同一类工序有多个（如 20 铣加工 / 30 铣加工）时提示人工选择 */
    $ambig = 0;
    if ($proc !== '') {
        $pb = bg_baidu_proc_base($proc);
        foreach ($procs as $pn) {
            if (bg_baidu_proc_base((string)$pn) === $pb) {
                $ambig++;
            }
        }
    }

    $items[] = array(
        'partNo' => isset($part['no']) ? (string)$part['no'] : '',
        'partName' => isset($part['name']) ? (string)$part['name'] : '',
        'process' => $proc,
        'qty' => $qty,
    );
    $procCounts[] = count($procs);
    $procAmbig[] = $ambig > 1;
};

foreach ($parseLines as $line) {
    $norm = bg_baidu_norm($line);
    if ($norm === '') {
        continue;
    }
    /* 跳过表头、汇总、签名等非明细行 */
    if (preg_match('/报工单|报工日期|日期|姓名|工序|数量|合计|序号|车间|单价|金额|备注|签名|审核|制单|图号/u', $line)) {
        continue;
    }
    /* 纯日期行（如 11.20）不是明细，日期已单独识别 */
    if (preg_match('/^(?:20\d{2}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[-\/.]\d{1,2})$/', $norm)) {
        continue;
    }
    $candidates++;

    /* 一行写了多个零件时先切开，各自解析工序与数量 */
    $segs = bg_baidu_split_parts($line, $catalog);
    if ($segs) {
        foreach ($segs as $seg) {
            $addItem($seg['part'], $seg['text']);
        }
        continue;
    }

    $part = bg_baidu_match_part($line, $norm, $catalog);
    if ($part === null) {
        $hasNumber = (bool)preg_match('/\d/', $line);
        if ($hasNumber && count($unmatched) < 3) {
            $unmatched[] = $line;
        }
        continue;
    }
    $addItem($part, $line);
}

if (!$catalog) {
    $warnings[] = '零件库为空，无法匹配零件，请先在零件列表添加零件';
}
if ($noLocation) {
    $warnings[] = '当前识别类型不返回文字位置，无法还原表格行；建议在识别设置中改用「高精度含位置版」';
}
if (!$items && $candidates > 0) {
    $warnings[] = '未能匹配到零件，请确认零件库中有对应图号，或点选原文手动填充';
}
if (count($unmatched)) {
    $sheet = function_exists('mb_substr') ? mb_substr($unmatched[0], 0, 18, 'UTF-8') : substr($unmatched[0], 0, 18);
    $warnings[] = '有 ' . count($unmatched) . ' 行未匹配，如「' . $sheet . '」，可点选原文手动填充';
}
foreach ($items as $idx => $it) {
    if ($it['qty'] <= 0) {
        $warnings[] = '第 ' . ($idx + 1) . ' 行未识别到数量，请手动补填';
    }
    if ($it['process'] === '' && isset($procCounts[$idx]) && $procCounts[$idx] > 1) {
        $warnings[] = '第 ' . ($idx + 1) . ' 行有多个工序但未识别到，请手动选择';
    } elseif (!empty($procAmbig[$idx])) {
        $warnings[] = '第 ' . ($idx + 1) . ' 行有多个同类工序，请核对工序与单价';
    }
}
if ($dateHint !== '') {
    $warnings[] = $dateHint;
}

/* 手写字词库连接层：一次批量归一明细字段（auto 改写），suggest 并入告警 */
if (function_exists('bglx_bridge_norm_fields')) {
    $normalized = bglx_bridge_norm_fields(
        array('items' => $items),
        array('plugin' => 'baidu-ocr')
    );
    $items = isset($normalized['fields']['items']) ? $normalized['fields']['items'] : $items;
    foreach (bglx_bridge_suggestions() as $bglxWarn) {
        if (count($warnings) >= 5) {
            break;
        }
        $hint = '字库建议：' . $bglxWarn . '，请人工确认';
        if (!in_array($hint, $warnings, true)) {
            $warnings[] = $hint;
        }
    }
}

/* 置信度：匹配行占候选行的比例 */
$ratio = $candidates > 0 ? count($items) / $candidates : 0;
$confidence = $items ? min(0.99, 0.5 + 0.5 * $ratio) : 0.2;

return array(
    'engine' => 'baidu-ocr:' . $c['ocrType'],
    'confidence' => round($confidence, 3),
    'fields' => array(
        'date' => $date,
        'items' => $items,
    ),
    'warnings' => array_slice($warnings, 0, 5),
    'raw' => $text,
);
