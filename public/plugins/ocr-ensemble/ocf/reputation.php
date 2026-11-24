<?php
/**
 * OCF L3 · 字段级信誉（reputation）
 *
 * 公式（架构 §7.1）：
 *   w(e,f) = γ·acc + (1-γ)·selfConf
 *   acc = 冷启动(n<minSamples) ? prior : shrink((correct+α)/(n+α+β), prior, λ) × decay^Δdays
 *   prior = 0.6 基准 + strengths 命中 +0.2 / weaknesses -0.15（钳制 [0.2,0.95]）
 * 任何异常回退 selfConf（保底 0.01）；文件损坏自动重建；写用临时文件 + rename 原子替换。
 */

if (defined('OCF_REPUTATION_LOADED')) {
    return;
}
define('OCF_REPUTATION_LOADED', 1);

/**
 * 信誉文件路径。优先 ctx.DATA_DIR，其次上溯到项目根 data/。
 *
 * @return string
 */
function ocf_rep_path()
{
    $dir = '';
    if (isset($GLOBALS['DATA_DIR']) && is_string($GLOBALS['DATA_DIR'])) {
        $dir = $GLOBALS['DATA_DIR'];
    }
    if ($dir === '') {
        $env = getenv('OCF_DATA_DIR');
        if ($env !== false && trim((string)$env) !== '') {
            $dir = (string)$env;
        }
    }
    if ($dir === '') {
        /* ocf/ → ocr-ensemble/ → plugins/ → public/ → 项目根 */
        $dir = dirname(__DIR__, 4) . '/data';
    }
    return rtrim(str_replace('\\', '/', $dir), '/') . '/ocr-fusion/reputation.json';
}

/**
 * 空信誉文档结构。
 *
 * @return array
 */
function ocf_rep_empty()
{
    return array(
        'version' => 1,
        'updatedAt' => date('c'),
        'engines' => array(),
    );
}

/**
 * 进程内信誉文档缓存（首次读取后复用，flush 时落盘）。
 *
 * @param bool $reset 是否强制重新读取
 * @return array 引用
 */
function &ocf_rep_doc($reset = false)
{
    static $doc = null;
    if ($doc === null || $reset) {
        $path = ocf_rep_path();
        $loaded = null;
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['engines']) && is_array($decoded['engines'])) {
                    $loaded = $decoded;
                }
            }
        }
        $doc = $loaded === null ? ocf_rep_empty() : $loaded;
    }
    return $doc;
}

/**
 * 读取 (引擎, 字段) 统计。
 *
 * @param string $engineId 引擎 id
 * @param string $field    字段名
 * @return array {n, correct, ema, ts}
 */
function ocf_rep_get($engineId, $field)
{
    $doc = &ocf_rep_doc();
    $e = isset($doc['engines'][$engineId]) && is_array($doc['engines'][$engineId])
        ? $doc['engines'][$engineId] : array();
    $st = isset($e[$field]) && is_array($e[$field]) ? $e[$field] : array();
    return array(
        'n' => isset($st['n']) ? max(0, (int)$st['n']) : 0,
        'correct' => isset($st['correct']) ? max(0, (int)$st['correct']) : 0,
        'ema' => isset($st['ema']) ? (float)$st['ema'] : 0.0,
        'ts' => isset($st['ts']) ? (string)$st['ts'] : date('Y-m-d'),
    );
}

/**
 * 写入 (引擎, 字段) 统计（仅内存，需 flush 落盘）。
 *
 * @param string $engineId 引擎 id
 * @param string $field    字段名
 * @param array  $stat     统计
 * @return void
 */
function ocf_rep_put($engineId, $field, $stat)
{
    $doc = &ocf_rep_doc();
    if (!isset($doc['engines'][$engineId]) || !is_array($doc['engines'][$engineId])) {
        $doc['engines'][$engineId] = array();
    }
    $doc['engines'][$engineId][$field] = array(
        'n' => isset($stat['n']) ? (int)$stat['n'] : 0,
        'correct' => isset($stat['correct']) ? (int)$stat['correct'] : 0,
        'ema' => isset($stat['ema']) ? round((float)$stat['ema'], 4) : 0.0,
        'ts' => isset($stat['ts']) ? (string)$stat['ts'] : date('Y-m-d'),
    );
    $doc['updatedAt'] = date('c');
}

/**
 * 计算先验：能力标签命中 +0.2 / 弱点 -0.15，钳制 [0.2,0.95]。
 *
 * @param string $engineId 引擎 id
 * @param string $field    字段名
 * @param array  $cfg      配置
 * @return float
 */
function ocf_rep_prior($engineId, $field, $cfg)
{
    $base = isset($cfg['priorBase']) ? (float)$cfg['priorBase'] : 0.6;
    $bonus = isset($cfg['priorStrengthBonus']) ? (float)$cfg['priorStrengthBonus'] : 0.2;
    $penalty = isset($cfg['priorWeaknessPenalty']) ? (float)$cfg['priorWeaknessPenalty'] : 0.15;
    $lo = isset($cfg['priorMin']) ? (float)$cfg['priorMin'] : 0.2;
    $hi = isset($cfg['priorMax']) ? (float)$cfg['priorMax'] : 0.95;

    $p = $base;
    $strengths = isset($cfg['strengths']) && is_array($cfg['strengths']) ? $cfg['strengths'] : array();
    $weaknesses = isset($cfg['weaknesses']) && is_array($cfg['weaknesses']) ? $cfg['weaknesses'] : array();

    /* 支持两种映射：{engine: [field,...]} 与 {field: [engine,...]} */
    $hasTag = function ($map, $engine, $fld) {
        if (isset($map[$engine]) && is_array($map[$engine])) {
            return in_array($fld, $map[$engine], true) || in_array('*', $map[$engine], true);
        }
        if (isset($map[$fld]) && is_array($map[$fld])) {
            return in_array($engine, $map[$fld], true) || in_array('*', $map[$fld], true);
        }
        return false;
    };
    if ($hasTag($strengths, $engineId, $field)) {
        $p += $bonus;
    }
    if ($hasTag($weaknesses, $engineId, $field)) {
        $p -= $penalty;
    }
    return max($lo, min($hi, $p));
}

/**
 * 弱监督在线更新：以最终裁决值作代理标签。仅高可信单据更新（防多数暴政）。
 *
 * @param string $engineId 引擎 id
 * @param string $field    字段名
 * @param bool   $isWinner 该引擎值是否为赢家
 * @param bool   $trusted  是否为高可信单据（trustedShare 达标且 L1 全通过）
 * @return void
 */
function ocf_rep_update($engineId, $field, $isWinner, $trusted)
{
    if (!$trusted) {
        return; /* 仅高可信单据更新，幅度小 */
    }
    $st = ocf_rep_get($engineId, $field);
    $st['n'] = (int)$st['n'] + 1;
    $st['correct'] = (int)$st['correct'] + ($isWinner ? 1 : 0);
    $obs = $isWinner ? 1.0 : 0.0;
    $st['ema'] = $st['n'] <= 1 ? $obs : (0.7 * (float)$st['ema'] + 0.3 * $obs);
    $st['ts'] = date('Y-m-d');
    ocf_rep_put($engineId, $field, $st);
}

/**
 * 计算动态权重（架构 §5.4 / §7.1 公共 API）。任何异常回退 selfConf。
 *
 * @param string $engineId 引擎 id
 * @param string $field    字段名
 * @param float  $selfConf 引擎自报置信度
 * @param array  $cfg      配置
 * @return float 0.01~1.0
 */
function ocf_weight($engineId, $field, $selfConf, $cfg)
{
    $self = max(0.01, min(1.0, (float)$selfConf));
    if (empty($cfg['enableFieldReputation'])) {
        return $self; /* 关闭 → 回退基线权重 */
    }
    try {
        $stat = ocf_rep_get($engineId, $field);
        $prior = ocf_rep_prior($engineId, $field, $cfg);
        $minSamples = isset($cfg['repMinSamples']) ? (int)$cfg['repMinSamples'] : 3;
        if ((int)$stat['n'] < $minSamples) {
            $acc = $prior; /* 冷启动 */
        } else {
            $alpha = isset($cfg['repAlpha']) ? (float)$cfg['repAlpha'] : 1.0;
            $beta = isset($cfg['repBeta']) ? (float)$cfg['repBeta'] : 1.0;
            $lambda = isset($cfg['repLambda']) ? (float)$cfg['repLambda'] : 0.7;
            $decay = isset($cfg['repDecay']) ? (float)$cfg['repDecay'] : 0.98;
            $raw = ((int)$stat['correct'] + $alpha) / ((int)$stat['n'] + $alpha + $beta);
            $acc = $lambda * $raw + (1 - $lambda) * $prior;
            $days = ocf_days_between($stat['ts'], date('Y-m-d'));
            $acc *= pow($decay, max(0, $days));
        }
        $gamma = isset($cfg['repGamma']) ? (float)$cfg['repGamma'] : 0.6;
        $w = $gamma * $acc + (1 - $gamma) * $self;
        return max(0.01, min(1.0, $w));
    } catch (Throwable $e) {
        error_log('[baogong] ocf: 信誉权重降级 · ' . $e->getMessage());
        return $self;
    }
}

/**
 * 原子落盘信誉文档。写临时文件 + rename 替换，避免并发写损坏。
 *
 * @return bool
 */
function ocf_rep_flush()
{
    $doc = &ocf_rep_doc();
    $path = ocf_rep_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $doc['updatedAt'] = date('c');
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $path . '.tmp.' . getmypid();
    $fp = @fopen($tmp, 'w');
    if ($fp === false) {
        return false;
    }
    $written = @fwrite($fp, $json);
    @fclose($fp);
    if ($written === false) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * 重置某引擎/字段信誉（管理员干预，check.php 用）。传空字段重置整引擎。
 *
 * @param string $engineId 引擎 id
 * @param string $field    字段名（空=整引擎）
 * @return void
 */
function ocf_rep_reset($engineId, $field = '')
{
    $doc = &ocf_rep_doc();
    if ($field === '') {
        unset($doc['engines'][$engineId]);
    } elseif (isset($doc['engines'][$engineId])) {
        unset($doc['engines'][$engineId][$field]);
    }
}

/**
 * P2 预留：基于人工反馈的信誉学习（本期空实现，仅保留接口与数据结构）。
 *
 * @param array $feedback 反馈记录
 * @return void
 */
function ocf_rep_update_from_feedback($feedback)
{
    /* 本期不落地模型学习：仅保留接口与数据结构，避免破坏 R-12 数据闭环 */
}
