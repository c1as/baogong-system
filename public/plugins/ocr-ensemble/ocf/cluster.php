<?php
/**
 * OCF L3 · 聚类与投票（保留并增强基线算法）
 *
 * ocf_vote / ocf_median 与基线 ocrens_vote / ocrens_median 算法完全一致
 * （Dice 聚类、代表值取簇内最高权重、加权中位数、偶数权重取已观测值不臆造平均）。
 * 唯一改动：weight 由 ocf_weight() 提供的动态权重填入（兼容旧 conf 字段），
 * 新增 share = support / total 供 L4/L5 使用。
 */

if (defined('OCF_CLUSTER_LOADED')) {
    return;
}
define('OCF_CLUSTER_LOADED', 1);

/**
 * 提取候选项权重：优先动态 weight 字段，回退 conf 保底。
 *
 * @param array $c 候选
 * @return float
 */
function ocf_cand_weight($c)
{
    $c = is_array($c) ? $c : array();
    if (isset($c['weight']) && is_numeric($c['weight'])) {
        return ocf_base_weight($c['weight']);
    }
    if (isset($c['conf']) && is_numeric($c['conf'])) {
        return ocf_base_weight($c['conf']);
    }
    return ocf_base_weight(0);
}

/**
 * 文本字段投票：按相似度聚类，累计权重取最大簇；代表值取簇内权重最高者。
 *
 * @param array      $cands     候选数组（value/conf|weight/engine）
 * @param float      $threshold 一致阈值
 * @param float|null $baseTotal 分母下限（用于 agreement）
 * @param bool       $exact     是否精确匹配（日期用）
 * @return array {value, support, total, share, clusters, byEngine}
 */
function ocf_vote($cands, $threshold, $baseTotal = null, $exact = false)
{
    $items = array();
    foreach ($cands as $c) {
        $v = isset($c['value']) ? trim((string)$c['value']) : '';
        if ($v === '') {
            continue;
        }
        $items[] = array(
            'value' => $v,
            'weight' => ocf_cand_weight($c),
            'engine' => isset($c['engine']) ? (string)$c['engine'] : (isset($c['engineId']) ? (string)$c['engineId'] : ''),
        );
    }
    $res = array('value' => '', 'support' => 0.0, 'total' => 0.0, 'share' => 0.0, 'clusters' => array(), 'byEngine' => array());
    if (!$items) {
        return $res;
    }
    usort($items, function ($a, $b) {
        if ($a['weight'] == $b['weight']) {
            return 0;
        }
        return $a['weight'] > $b['weight'] ? -1 : 1;
    });

    $clusters = array();
    foreach ($items as $it) {
        $placed = false;
        foreach ($clusters as $idx => $cl) {
            $same = $exact
                ? ($cl['rep']['value'] === $it['value'])
                : (ocf_dice($cl['rep']['value'], $it['value']) >= $threshold);
            if ($same) {
                $clusters[$idx]['members'][] = $it;
                $clusters[$idx]['weight'] += $it['weight'];
                if ($it['weight'] > $clusters[$idx]['rep']['weight']) {
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
                'weight' => $it['weight'],
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
            || (abs($cl['weight'] - $win['weight']) <= 1e-9 && $cl['rep']['weight'] > $win['rep']['weight'])) {
            $win = $cl;
        }
    }

    $res['value'] = $win['rep']['value'];
    $res['support'] = $win['weight'];
    $res['total'] = ($baseTotal !== null && (float)$baseTotal > $total) ? (float)$baseTotal : $total;
    $res['share'] = $res['total'] > 0 ? $res['support'] / $res['total'] : 0.0;
    $res['clusters'] = $clusters;
    foreach ($items as $it) {
        $res['byEngine'][$it['engine']] = $it['value'];
    }
    return $res;
}

/**
 * 数值字段：置信度/动态权重加权中位数（保留基线偶数取已观测值逻辑）。
 *
 * @param array      $pairs     候选数组（value/conf|weight/engine）
 * @param float|null $baseTotal 分母下限
 * @return array {value, support, total, share, byEngine}
 */
function ocf_median($pairs, $baseTotal = null)
{
    $items = array();
    foreach ($pairs as $p) {
        if (!isset($p['value']) || !is_numeric($p['value'])) {
            continue;
        }
        $items[] = array(
            'value' => (float)$p['value'],
            'weight' => ocf_cand_weight($p),
            'engine' => isset($p['engine']) ? (string)$p['engine'] : (isset($p['engineId']) ? (string)$p['engineId'] : ''),
        );
    }
    $res = array('value' => null, 'support' => 0.0, 'total' => 0.0, 'share' => 0.0, 'byEngine' => array());
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
        if ($acc > $half + 1e-9 || abs($acc - $half) <= 1e-9) {
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
    $res['share'] = $res['total'] > 0 ? $res['support'] / $res['total'] : 0.0;
    return $res;
}

/**
 * L4 用聚类：对已通过 L1 的候选按 Dice 阈值聚类，输出 {rep, weight, size, members}。
 *
 * @param array $cands     候选
 * @param float $threshold 一致阈值
 * @return array 簇列表
 */
function ocf_cluster($cands, $threshold)
{
    $vote = ocf_vote($cands, $threshold);
    return isset($vote['clusters']) ? $vote['clusters'] : array();
}

/**
 * 取最大簇（按 weight，平局按成员最高 weight）。
 *
 * @param array $clusters 簇列表
 * @return array 空簇数组 array('rep'=>'','weight'=>0,'size'=>0,'members'=>array())
 */
function ocf_max_cluster($clusters)
{
    $win = null;
    foreach ($clusters as $cl) {
        if ($win === null
            || $cl['weight'] > $win['weight'] + 1e-9
            || (abs($cl['weight'] - $win['weight']) <= 1e-9 && count($cl['members']) > count($win['members']))) {
            $win = $cl;
        }
    }
    if ($win === null) {
        return array('rep' => '', 'weight' => 0.0, 'size' => 0, 'members' => array());
    }
    return array(
        'rep' => $win['rep']['value'],
        'weight' => $win['weight'],
        'size' => count($win['members']),
        'members' => $win['members'],
    );
}

/**
 * 第二大簇（3way 用）。
 *
 * @param array $clusters 簇列表
 * @param array $win      最大簇
 * @return array
 */
function ocf_second_cluster($clusters, $win)
{
    $sorted = $clusters;
    usort($sorted, function ($a, $b) {
        return $b['weight'] <=> $a['weight'];
    });
    if (isset($sorted[1])) {
        return array(
            'rep' => $sorted[1]['rep']['value'],
            'weight' => $sorted[1]['weight'],
            'size' => count($sorted[1]['members']),
            'members' => $sorted[1]['members'],
        );
    }
    return array('rep' => '', 'weight' => 0.0, 'size' => 0, 'members' => array());
}

/**
 * 候选总权重（含全部通过 L1 的候选）。
 *
 * @param array $cands 候选
 * @return float
 */
function ocf_total_weight($cands)
{
    $total = 0.0;
    foreach ($cands as $c) {
        $total += ocf_cand_weight($c);
    }
    return $total;
}
