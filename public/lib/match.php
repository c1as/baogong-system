<?php
/* 模块: lib/match
   职责: 图号/名称的精确与模糊匹配
   依赖: 无
   注意: 算法需与前端保持一致 */

/* ---------------- 图号模糊匹配（与 v1.7 前端算法一致） ---------------- */

function bg_norm_key($v)
{
    $s = str_replace(array('－', '—', '–', '‑', '　'), '-', (string)$v);
    $s = preg_replace('/\s+/u', '', $s);
    return strtoupper($s);
}

function bg_compact_key($v)
{
    return preg_replace('/[^0-9A-Z\x{4e00}-\x{9fa5}]/u', '', bg_norm_key($v));
}

function bg_slen($s)
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function bg_ssub($s, $start, $len)
{
    return function_exists('mb_substr') ? mb_substr($s, $start, $len, 'UTF-8') : substr($s, $start, $len);
}

function bg_strip_date($s)
{
    $s = preg_replace('/20\d{2}\s*[-\/年.]\s*\d{1,2}\s*[-\/月.]\s*\d{1,2}\s*日?/u', ' ', (string)$s);
    return preg_replace('/(?<!\d)\d{1,2}\s*[-\/.]\s*\d{1,2}(?!\d)\s*日?/u', ' ', $s);
}

function bg_sim_key($a, $b)
{
    $A = preg_split('//u', (string)$a, -1, PREG_SPLIT_NO_EMPTY);
    $B = preg_split('//u', (string)$b, -1, PREG_SPLIT_NO_EMPTY);
    $m = count($A);
    $n = count($B);
    if (!$m || !$n) {
        return 0;
    }
    $prev = range(0, $n);
    for ($i = 1; $i <= $m; $i++) {
        $cur = array($i);
        for ($j = 1; $j <= $n; $j++) {
            $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + ($A[$i - 1] === $B[$j - 1] ? 0 : 1));
        }
        $prev = $cur;
    }
    return 1 - $prev[$n] / max($m, $n);
}

/* 返回 [{part, score}]，按分数降序 */
function bg_fuzzy_candidates($parts, $text, $limit = 5)
{
    $bare = bg_strip_date($text);
    $raw = bg_norm_key($bare);
    $flat = bg_compact_key($bare);
    if ($raw === '') {
        return array();
    }
    preg_match_all('/[0-9A-Z]{4,}/', $flat, $mm);
    $runs = $mm[0];
    $maxRun = 0;
    foreach ($runs as $r) {
        $maxRun = max($maxRun, strlen($r));
    }
    $longRuns = array();
    foreach ($runs as $r) {
        if (strlen($r) === $maxRun) {
            $longRuns[] = $r;
        }
    }

    $scored = array();
    foreach ($parts as $p) {
        $no = bg_norm_key(isset($p['no']) ? $p['no'] : '');
        $noC = bg_compact_key(isset($p['no']) ? $p['no'] : '');
        $nm = bg_norm_key(isset($p['name']) ? $p['name'] : '');
        $score = 0;
        if ($no !== '' && strpos($raw, $no) !== false) {
            $score = 1;
        } elseif (bg_slen($noC) >= 4 && strpos($flat, $noC) !== false) {
            $score = 0.96;
        } else {
            $noLen = bg_slen($noC);
            foreach ($longRuns as $r) {
                $rl = strlen($r);
                if ($rl < 4 || $noLen < 4) {
                    continue;
                }
                /* 整段图号被零件号包含：最强证据（如 307437R61 对应 T307437R61） */
                if ($rl >= 5 && $noLen >= $rl && strpos($noC, $r) !== false) {
                    $score = max($score, 0.97);
                    continue;
                }
                /* 以整段编辑距离为主：只差一两个字符的才是最近候选，
                   避免 4 位数字恰好出现在别的图号里就被判成 0.9 以上（如 26971B05 → BF326970-V2） */
                $sim = bg_sim_key($r, $noC);
                $s = 0;
                if ($sim >= 0.8) {
                    $s = 0.86 + ($sim - 0.8) * 0.5;
                } elseif ($sim >= 0.62) {
                    $s = 0.72 + ($sim - 0.62) * 0.78;
                } elseif ($sim >= 0.55) {
                    $s = 0.62 + ($sim - 0.55) * 0.6;
                }
                /* 首 4 位一致（常见漏写前缀字母）少量加分 */
                if ($s > 0 && $rl >= 5 && $noLen >= 5 && substr($r, 0, 4) === substr($noC, 0, 4)) {
                    $s += 0.02;
                }
                $score = max($score, $s);
            }
            if (!$score && bg_slen($noC) >= 5) {
                /* 原文里夹着别的字符时，允许在图号长度窗口内滑动比对 */
                $len = bg_slen($noC);
                $seg = 0;
                $flatLen = bg_slen($flat);
                for ($i = 0; $i + $len <= $flatLen; $i++) {
                    $seg = max($seg, bg_sim_key(bg_ssub($flat, $i, $len), $noC));
                }
                if ($seg >= 0.78) {
                    $score = 0.68 + ($seg - 0.78) * 0.5;
                }
            }
        }
        if (!$score && bg_slen($nm) >= 2 && strpos($raw, $nm) !== false) {
            $score = 0.9;
        }
        if ($score > 0) {
            $scored[] = array('part' => $p, 'score' => $score);
        }
    }
    usort($scored, function ($a, $b) use ($flat) {
        if (abs($b['score'] - $a['score']) > 1e-9) {
            return $b['score'] <=> $a['score'];
        }
        /* 同分时与原文更接近的图号优先，避免按更新时间随意取一条 */
        $sa = bg_sim_key($flat, bg_compact_key(isset($a['part']['no']) ? $a['part']['no'] : ''));
        $sb = bg_sim_key($flat, bg_compact_key(isset($b['part']['no']) ? $b['part']['no'] : ''));
        return $sb <=> $sa;
    });
    return array_slice($scored, 0, $limit);
}

/* 先精确（归一化 / 去分隔符 / 名称），再模糊 */
function bg_match_part($parts, $no, $name)
{
    $n = bg_norm_key($no);
    $nm = bg_norm_key($name);
    $hit = null;
    foreach ($parts as $p) {
        if ($n !== '' && bg_norm_key(isset($p['no']) ? $p['no'] : '') === $n) {
            $hit = $p;
            break;
        }
    }
    if (!$hit && $n !== '') {
        $nc = bg_compact_key($n);
        foreach ($parts as $p) {
            if ($nc !== '' && bg_compact_key(isset($p['no']) ? $p['no'] : '') === $nc) {
                $hit = $p;
                break;
            }
        }
    }
    if (!$hit && $nm !== '') {
        foreach ($parts as $p) {
            if (bg_norm_key(isset($p['name']) ? $p['name'] : '') === $nm) {
                $hit = $p;
                break;
            }
        }
    }
    if (!$hit && $n !== '') {
        foreach ($parts as $p) {
            if (isset($p['name']) && $p['name'] !== '' && bg_norm_key($p['name']) === $n) {
                $hit = $p;
                break;
            }
        }
    }
    if ($hit) {
        return array('part' => $hit, 'score' => 1);
    }
    $fz = bg_fuzzy_candidates($parts, trim($no . ' ' . $name), 3);
    if (!$fz) {
        return null;
    }
    $top = $fz[0];
    $second = isset($fz[1]) ? $fz[1] : null;
    /* 只有少量字符重合的弱证据不自动填充，留给人工选择 */
    if ($top['score'] < 0.72) {
        return null;
    }
    /* 强证据直接采用；弱证据需明显领先，避免多候选同分时自动填错零件 */
    if ($top['score'] < 0.95) {
        $margin = $second ? $top['score'] - $second['score'] : 1.0;
        if ($margin < 0.04) {
            return null;
        }
    }
    return $top;
}

function bg_all_parts_for_match($pdo)
{
    $out = array();
    foreach ($pdo->query('SELECT * FROM parts ORDER BY updated_at DESC, id DESC') as $row) {
        $out[] = bg_part_row($row);
    }
    return $out;
}
