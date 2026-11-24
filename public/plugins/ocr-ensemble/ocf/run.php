<?php
/**
 * OCF 总编排入口：ocf_run(ctx, cfg)
 *
 * 顺序：选引擎 → 逐路调用 → L0 归一 → 标量 L1-L6 → 明细 L1-L6 → 综合置信度 → 收口 → trace 旁路。
 * 所有增强开关可独立关闭回退基线；单路失败 try/catch 记告警继续；字库失败静默降级。
 */

if (defined('OCF_RUN_LOADED')) {
    return;
}
define('OCF_RUN_LOADED', 1);

/**
 * 选择参与融合的引擎（仅启用且声明 report.ocr，排除自身）。
 *
 * @param array  $ctx     上下文
 * @param array  $cfg     配置
 * @param string $selfId  自身插件 id
 * @param array  $warnings 告警（引用，追加）
 * @return array 插件列表
 */
function ocf_select_engines($ctx, $cfg, $selfId, &$warnings)
{
    $all = function_exists('bg_plugin_all') ? bg_plugin_all() : array();
    $want = ocf_ids(ocf_conf($cfg, 'engines', ''));
    $engines = array();
    $isEnabled = function ($id) {
        return function_exists('bg_plugin_is_enabled') ? bg_plugin_is_enabled($id) : true;
    };
    if (!$want) {
        foreach ($all as $p) {
            if (!is_array($p) || $p['id'] === $selfId || !in_array('report.ocr', isset($p['capabilities']) ? $p['capabilities'] : array(), true)) {
                continue;
            }
            if (!$isEnabled($p['id'])) {
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
            if (!in_array('report.ocr', isset($p['capabilities']) ? $p['capabilities'] : array(), true)) {
                $warnings[] = (isset($p['name']) ? $p['name'] : $id) . ' 不支持报工识别，已跳过';
                continue;
            }
            if (!$isEnabled($id)) {
                $warnings[] = (isset($p['name']) ? $p['name'] : $id) . ' 已停用，已跳过';
                continue;
            }
            $engines[] = $p;
        }
    }
    return $engines;
}

/**
 * 逐路调用引擎（try/catch 单路失败继续）。
 *
 * @param array $engines  引擎列表
 * @param array $ctx      上下文
 * @param array $warnings 告警（引用）
 * @return array 归一后的 EngineResult 列表
 */
function ocf_invoke_all($engines, $ctx, $cfg, &$warnings)
{
    $results = array();
    foreach ($engines as $p) {
        try {
            $r = bg_plugin_invoke_engine($p['id'], $ctx);
            $r = is_array($r) ? $r : array();
            $r['name'] = isset($p['name']) ? $p['name'] : $p['id'];
            $r['plugin'] = array('id' => $p['id']);
            $results[] = ocf_norm_engine_result($r, $cfg);
        } catch (Throwable $e) {
            $warnings[] = (isset($p['name']) ? $p['name'] : $p['id']) . ' 调用失败：' . $e->getMessage();
        }
    }
    return $results;
}

/**
 * 单路原文信息（L1 用）。
 *
 * @param array $er 归一引擎结果
 * @return array {compact, runs, text, quantities}
 */
function ocf_raw_info($er)
{
    $raw = isset($er['raw']) ? $er['raw'] : '';
    return array(
        'compact' => ocf_compact($raw),
        'runs' => ocf_raw_runs($raw),
        'text' => $raw,
        'quantities' => ocf_raw_quantities($raw),
    );
}

/**
 * 构造一个字段的候选集合（贯穿 L1-L4）。
 *
 * @param array  $results 归一引擎结果
 * @param string $field   字段名
 * @param array  $cfg     配置
 * @param array  $rawInfos 原文信息（按引擎 id 索引）
 * @return array 候选数组
 */
function ocf_field_candidates($results, $field, $cfg, $rawInfos)
{
    $cands = array();
    foreach ($results as $er) {
        $value = isset($er['fields'][$field]) ? $er['fields'][$field] : '';
        $kind = ($field === 'partNo') ? 'partNo'
            : (($field === 'qty') ? 'qty'
            : (($field === 'process') ? 'process' : 'scalar'));
        $rawInfo = isset($rawInfos[$er['engineId']]) ? $rawInfos[$er['engineId']] : array();
        $verify = ocf_literal_verify($value, $rawInfo, $kind, $cfg);
        $weight = ocf_weight($er['engineId'], $field, $er['selfConf'], $cfg);
        $cands[] = array(
            'engineId' => $er['engineId'],
            'engine' => $er['engineName'],
            'field' => $field,
            'value' => $value,
            'selfConf' => $er['selfConf'],
            'weight' => $weight,
            'supported' => $verify['supported'],
            'evidence' => $verify['evidence'],
            'literalRule' => $verify['rule'],
            'lexAction' => 'none',
            'lexRule' => '',
        );
    }
    return $cands;
}

/**
 * 裁决一个字段的完整 L1-L6 流程，返回字段结果与 trace 片段。
 *
 * @param array  $cands   候选
 * @param string $field   字段名
 * @param array  $ctx     上下文
 * @param array  $cfg     配置
 * @param array  $bridge   连接层状态
 * @param string $kind     L1 类型（标量用 scalar）
 * @param string $baseField 基础字段名（明细行带行号后缀时用于逻辑判断，默认同 field）
 * @return array {value, confidence, decision, rule, trace}
 */
function ocf_resolve_field($cands, $field, $ctx, $cfg, $bridge, $kind = 'scalar', $baseField = '')
{
    $baseField = ($baseField === '') ? $field : $baseField;
    /* L2 字库联动（候选上的 field 已是基础字段名） */
    $cands = ocf_lex_apply($cands, $bridge, $cfg);
    ocf_trace_field($field, 'L0', array('count' => count($cands)));
    ocf_trace_field($field, 'candidates', $cands);

    $supportInfo = array();
    foreach ($cands as $c) {
        $supportInfo[] = array('engine' => $c['engine'], 'rule' => $c['literalRule'], 'evidence' => $c['evidence']);
    }
    ocf_trace_field($field, 'L1', array('verdicts' => $supportInfo));
    ocf_trace_field($field, 'L2', array('actions' => array_map(function ($c) {
        return array('engine' => $c['engine'], 'action' => $c['lexAction'], 'rule' => $c['lexRule']);
    }, $cands)));

    /* L3 聚类权重 */
    $agree = isset($cfg['agreeThreshold']) ? (float)$cfg['agreeThreshold'] : 0.85;
    $vote = ocf_vote($cands, $agree, null, ($baseField === 'date'));
    ocf_trace_field($field, 'L3', array(
        'value' => $vote['value'], 'support' => round($vote['support'], 3),
        'total' => round($vote['total'], 3), 'share' => round($vote['share'], 3),
    ));

    /* L4 裁决 */
    $verdict = ocf_arbitrate($cands, $ctx, $cfg);
    ocf_trace_field($field, 'L4', array('rule' => $verdict['rule'], 'needReview' => $verdict['needReview']));

    /* L5 校准：rawConf 用该字段最大簇占比×平均自报置信 */
    $selfSum = 0.0;
    foreach ($cands as $c) {
        $selfSum += (float)$c['selfConf'];
    }
    $meanSelf = $cands ? $selfSum / count($cands) : 0.0;
    $agreement = $vote['share'];
    $combined = ocf_combined_conf($meanSelf, $agreement);
    $rep = $vote['share'];
    $calConf = round(ocf_calibrate($combined, $rep, $agreement, $cfg), 3);
    ocf_trace_field($field, 'L5', array('rawConf' => round($combined, 3), 'calConf' => $calConf));

    /* L6 决策（用基础字段名取差异化阈值） */
    $decision = ocf_decide($verdict, $calConf, $cfg, $baseField);
    ocf_trace_field($field, 'L6', array('decision' => $decision));

    $value = ocf_decision_value($verdict, $decision);
    $evidence = array();
    foreach ($cands as $c) {
        if ($c['evidence'] !== '') {
            $evidence[] = $c['evidence'];
        }
    }

    $trace = array(
        'field' => $field,
        'rule' => $verdict['rule'],
        'value' => $value,
        'confidence' => $calConf,
        'decision' => $decision,
        'evidence' => array_slice(array_values(array_unique($evidence)), 0, 5),
    );
    ocf_trace_commit($trace);

    return array(
        'value' => $value,
        'confidence' => $calConf,
        'decision' => $decision,
        'rule' => $verdict['rule'],
        'verdict' => $verdict,
        'trace' => $trace,
    );
}

/**
 * 明细行归组（迁移基线：图号/名称 + 工序容错归组，图号前缀差异容忍）。
 *
 * @param array  $results 归一引擎结果
 * @param float  $agree   一致阈值
 * @return array groups
 */
function ocf_group_items($results, $agree)
{
    $groups = array();
    foreach ($results as $er) {
        $engineId = $er['engineId'];
        $items = isset($er['fields']['items']) ? $er['fields']['items'] : array();
        foreach ($items as $it) {
            $no = ocf_compact(isset($it['partNo']) ? $it['partNo'] : '');
            $name = ocf_compact(isset($it['partName']) ? $it['partName'] : '');
            $proc = ocf_compact(isset($it['process']) ? $it['process'] : '');
            $kind = $no !== '' ? 'no' : ($name !== '' ? 'name' : '');
            $part = $no !== '' ? $no : $name;
            $target = null;
            if ($kind !== '') {
                foreach ($groups as $gi => $g) {
                    if ($g['kind'] !== $kind || isset($g['rows'][$engineId])) {
                        continue;
                    }
                    $same = $kind === 'no'
                        ? ocf_part_same($g['part'], $part)
                        : (ocf_dice($g['part'], $part) >= $agree);
                    if ($same && ocf_proc_same($g['proc'], $proc, $agree)) {
                        $target = $gi;
                        break;
                    }
                }
            }
            if ($target === null) {
                $groups[] = array('kind' => $kind, 'part' => $part, 'proc' => $proc, 'rows' => array());
                $target = count($groups) - 1;
            }
            if ($groups[$target]['proc'] === '' && $proc !== '') {
                $groups[$target]['proc'] = $proc;
            }
            $conf = $er['selfConf'];
            if (!isset($groups[$target]['rows'][$engineId]) || $conf > $groups[$target]['rows'][$engineId]['conf']) {
                $groups[$target]['rows'][$engineId] = array(
                    'row' => $it, 'conf' => $conf, 'engine' => $er['engineName'], 'id' => $engineId,
                );
            }
        }
    }
    return array_values($groups);
}

/**
 * 单路独有且原文无佐证的行丢弃（防引擎按零件库猜测），迁移基线。
 *
 * @param array $groups   分组
 * @param array $rawInfos 原文信息
 * @param array $warnings 告警（引用）
 * @return array
 */
function ocf_drop_unsupported($groups, $rawInfos, &$warnings)
{
    $dropped = array();
    foreach ($groups as $gi => $g) {
        if (count($g['rows']) !== 1 || $g['kind'] !== 'no' || $g['part'] === '') {
            continue;
        }
        $only = reset($g['rows']);
        $rawInfo = isset($rawInfos[$only['id']]) ? $rawInfos[$only['id']] : array('compact' => '', 'runs' => array());
        if (ocf_literal_support($g['part'], isset($rawInfo['compact']) ? $rawInfo['compact'] : '', isset($rawInfo['runs']) ? $rawInfo['runs'] : array())) {
            continue;
        }
        $dropped[] = $only['engine'] . '=' . $g['part'];
        unset($groups[$gi]);
    }
    if ($dropped) {
        $groups = array_values($groups);
        $warnings[] = '已忽略 ' . count($dropped) . ' 行原文中查不到的识别结果（'
            . implode(' / ', array_slice($dropped, 0, 2)) . '），请以原文为准';
    }
    return $groups;
}

/**
 * 图号按零件库校正 + 同零件同工序合并（迁移基线）。
 *
 * @param array $groups  分组
 * @param array $catalog 零件库
 * @param array $rawInfos 原文信息
 * @param array $cfg     配置
 * @return array
 */
function ocf_canon_groups($groups, $catalog, $rawInfos, $cfg)
{
    $agree = isset($cfg['agreeThreshold']) ? (float)$cfg['agreeThreshold'] : 0.85;
    $minDice = isset($cfg['libPartMinDice']) ? (float)$cfg['libPartMinDice'] : 0.5;
    $index = ocf_catalog_index($catalog);
    $allRuns = array();
    foreach ($rawInfos as $info) {
        foreach (isset($info['runs']) ? $info['runs'] : array() as $run) {
            $allRuns[$run] = $run;
        }
    }
    $supported = $index ? ocf_catalog_supported($index, $allRuns) : array();
    foreach ($groups as $gi => $g) {
        $groups[$gi]['canon'] = ($g['kind'] === 'no' && $supported)
            ? ocf_lib_part($g['part'], $index, $supported, $minDice) : '';
    }
    $merged = array();
    foreach ($groups as $g) {
        $target = null;
        if ($g['canon'] !== '') {
            foreach ($merged as $mi => $m) {
                if ($m['canon'] === $g['canon'] && ocf_proc_same($m['proc'], $g['proc'], $agree)) {
                    $target = $mi;
                    break;
                }
            }
        }
        if ($target === null) {
            $merged[] = $g;
            continue;
        }
        foreach ($g['rows'] as $engineId => $row) {
            if (!isset($merged[$target]['rows'][$engineId]) || $row['conf'] > $merged[$target]['rows'][$engineId]['conf']) {
                $merged[$target]['rows'][$engineId] = $row;
            }
        }
        if ($merged[$target]['proc'] === '' && $g['proc'] !== '') {
            $merged[$target]['proc'] = $g['proc'];
        }
    }
    return $merged;
}

/**
 * 总入口：融合结果（架构 §5.4 ocf_run）。
 *
 * @param array $ctx 上下文
 * @param array $cfg 配置
 * @return array {engine, confidence, fields, warnings, raw}
 */
function ocf_run($ctx, $cfg)
{
    $ctx = is_array($ctx) ? $ctx : array();
    $cfg = is_array($cfg) ? $cfg : ocf_config_build($ctx);
    $selfId = isset($ctx['id']) ? (string)$ctx['id'] : 'ocr-ensemble';
    $warnings = array();

    /* 字库连接层（可选，静默降级） */
    $bridge = array('available' => false, 'degraded' => true);
    if (function_exists('bglx_bridge_init')) {
        bglx_bridge_init('ocr-ensemble');
        $bridge = bglx_bridge_status();
    }

    /* 选择 + 调用引擎 */
    $engines = ocf_select_engines($ctx, $cfg, $selfId, $warnings);
    if (!$engines) {
        throw new RuntimeException('没有可用的识别引擎，请检查「参与融合的引擎」设置，或先启用其它识别插件');
    }
    if (count($engines) < 2) {
        $warnings[] = '仅 1 路引擎参与融合，无法交叉校验';
    }
    $results = ocf_invoke_all($engines, $ctx, $cfg, $warnings);
    if (!$results) {
        throw new RuntimeException('所有引擎均调用失败：' . implode('；', array_slice($warnings, 0, 2)));
    }

    /* singleEngineMode（Q2）：跳过融合，直接返回第一路 */
    if (!empty($cfg['singleEngineMode'])) {
        $first = $results[0];
        $firstFields = $first['fields'];
        $firstFields['items'] = ocf_cap_items($firstFields['items'], $cfg);
        return array(
            'engine' => '单引擎直出（' . $first['engineName'] . '）',
            'confidence' => round($first['selfConf'], 3),
            'fields' => $firstFields,
            'warnings' => ocf_cap_warnings(array_merge($warnings, array('单引擎直出模式：已跳过融合判定')), $cfg),
            'raw' => ocf_cut($first['raw'], isset($cfg['maxRaw']) ? (int)$cfg['maxRaw'] : 4000),
        );
    }

    /* trace begin */
    $traceCtx = $ctx;
    $traceCtx['engineCount'] = count($results);
    ocf_trace_begin($traceCtx, $cfg);

    /* 原文信息索引 */
    $rawInfos = array();
    foreach ($results as $er) {
        $rawInfos[$er['engineId']] = ocf_raw_info($er);
    }

    /* 标量字段 L1-L6 */
    $fieldTraces = array();
    $fields = array('date' => '', 'worker' => '', 'workshop' => '', 'note' => '');
    $fieldConf = array();
    foreach (array('date', 'worker', 'workshop', 'note') as $field) {
        $cands = ocf_field_candidates($results, $field, $cfg, $rawInfos);
        $res = ocf_resolve_field($cands, $field, $ctx, $cfg, $bridge, 'scalar');
        $fields[$field] = $res['value'];
        $fieldConf[$field] = $res['confidence'];
        $fieldTraces[] = $res['trace'];
    }

    /* 明细行：归组 → 丢弃无佐证 → 库内校正合并 */
    $agree = isset($cfg['agreeThreshold']) ? (float)$cfg['agreeThreshold'] : 0.85;
    $catalog = isset($ctx['catalog']) && is_array($ctx['catalog']) ? $ctx['catalog'] : array();
    $groups = ocf_group_items($results, $agree);
    $groups = ocf_drop_unsupported($groups, $rawInfos, $warnings);
    $groups = ocf_canon_groups($groups, $catalog, $rawInfos, $cfg);

    $items = array();
    $rowNo = 0;
    foreach ($groups as $g) {
        $rowNo++;
        $rows = array_values($g['rows']);
        $base = 0.0;
        foreach ($rows as $row) {
            $base += ocf_base_weight($row['conf']);
        }
        /* 各明细字段构造候选：值来自该行，权重=动态权重，佐证用该引擎原文 */
        $makeCands = function ($field, $kind) use ($rows, $rawInfos, $cfg) {
            $out = array();
            foreach ($rows as $row) {
                $val = isset($row['row'][$field]) ? $row['row'][$field] : '';
                $rawInfo = isset($rawInfos[$row['id']]) ? $rawInfos[$row['id']] : array();
                $verify = ocf_literal_verify($val, $rawInfo, $kind, $cfg);
                $weight = ocf_weight($row['id'], $field, $row['conf'], $cfg);
                $out[] = array(
                    'engineId' => $row['id'], 'engine' => $row['engine'], 'field' => $field,
                    'value' => $val, 'selfConf' => $row['conf'], 'weight' => $weight,
                    'supported' => $verify['supported'], 'evidence' => $verify['evidence'],
                    'literalRule' => $verify['rule'], 'lexAction' => 'none', 'lexRule' => '',
                );
            }
            return $out;
        };
        $resPartNo = ocf_resolve_field($makeCands('partNo', 'partNo'), 'partNo.' . $rowNo, $ctx, $cfg, $bridge, 'partNo', 'partNo');
        $resPartName = ocf_resolve_field($makeCands('partName', 'scalar'), 'partName.' . $rowNo, $ctx, $cfg, $bridge, 'scalar', 'partName');
        $resProcess = ocf_resolve_field($makeCands('process', 'process'), 'process.' . $rowNo, $ctx, $cfg, $bridge, 'process', 'process');
        $resQty = ocf_resolve_field($makeCands('qty', 'qty'), 'qty.' . $rowNo, $ctx, $cfg, $bridge, 'qty', 'qty');
        $resPrice = ocf_resolve_field($makeCands('price', 'scalar'), 'price.' . $rowNo, $ctx, $cfg, $bridge, 'scalar', 'price');

        $fieldTraces[] = $resPartNo['trace'];
        $fieldTraces[] = $resProcess['trace'];
        $fieldTraces[] = $resQty['trace'];

        /* 库内能确认的图号以库内写法为准 */
        $partNoValue = $resPartNo['value'];
        $canon = isset($g['canon']) ? $g['canon'] : '';
        if ($canon !== '') {
            $partNoValue = $canon;
        }
        $qtyVal = $resQty['value'];
        $priceVal = $resPrice['value'];
        $items[] = array(
            'partNo' => (string)$partNoValue,
            'partName' => (string)$resPartName['value'],
            'process' => (string)$resProcess['value'],
            'qty' => ($qtyVal === null || $qtyVal === '') ? 0 : (float)$qtyVal,
            'price' => ($priceVal === null || $priceVal === '') ? null : (float)$priceVal,
        );
    }
    $items = ocf_cap_items($items, $cfg);

    /* 综合置信度：平均自报 × (0.5+0.5×agreement) */
    $sumConf = 0.0;
    foreach ($results as $er) {
        $sumConf += $er['selfConf'];
    }
    $meanConf = $sumConf / count($results);
    $agreements = array();
    foreach ($fieldTraces as $t) {
        if (isset($t['confidence'])) {
            $agreements[] = (float)$t['confidence'];
        }
    }
    $agreement = $agreements ? array_sum($agreements) / count($agreements) : 0.0;
    $combined = ocf_combined_conf($meanConf, $agreement);
    $finalConf = round(ocf_calibrate($combined, $agreement, $agreement, $cfg), 3);

    /* warnings：字段级复核 + 引擎/归组告警，统一收口 */
    $fieldWarnings = ocf_build_warnings($fieldTraces, $cfg);
    $minConf = isset($cfg['minConfidence']) ? (float)$cfg['minConfidence'] : 0.6;
    if ($finalConf < $minConf && $finalConf > 0) {
        $warnings[] = '综合置信度 ' . round($finalConf * 100) . '%，低于 ' . round($minConf * 100) . '%，建议人工复核';
    }
    if (!$items) {
        $warnings[] = '未识别出报工明细，可对照下方原文手动填充';
    }
    $allWarnings = ocf_cap_warnings(array_merge($warnings, $fieldWarnings), $cfg);

    /* 原文拼接 */
    $budget = 3600;
    $rawParts = array();
    foreach ($results as $er) {
        $txt = trim($er['raw']);
        if ($txt === '' || $budget < 200) {
            continue;
        }
        $seg = '【' . $er['engineName'] . '】' . "\n" . ocf_cut($txt, $budget);
        $rawParts[] = $seg;
        $budget -= ocf_len($seg);
    }
    $rawText = $rawParts ? implode("\n\n", $rawParts) : '融合引擎只返回了结构化结果，已按上方表单填写';

    /* trace 旁路落盘（不进返回值） */
    ocf_trace_flush($finalConf, $allWarnings);

    /* 信誉在线更新（仅高可信单据）：以共识值为代理标签 */
    if (!empty($cfg['enableFieldReputation']) && $finalConf >= (isset($cfg['highTrustConf']) ? (float)$cfg['highTrustConf'] : 0.85)) {
        foreach ($results as $er) {
            foreach (array('date', 'partNo', 'process', 'qty') as $f) {
                $isWinner = false;
                if ($f === 'date' && $er['fields']['date'] === $fields['date']) {
                    $isWinner = true;
                }
                ocf_rep_update($er['engineId'], $f, $isWinner, true);
            }
        }
        ocf_rep_flush();
    }

    return array(
        'engine' => '多 OCR 融合（' . count($results) . ' 路）',
        'confidence' => $finalConf,
        'fields' => array(
            'date' => $fields['date'],
            'worker' => $fields['worker'],
            'workshop' => $fields['workshop'],
            'note' => $fields['note'],
            'items' => $items,
        ),
        'warnings' => $allWarnings,
        'raw' => ocf_cut($rawText, isset($cfg['maxRaw']) ? (int)$cfg['maxRaw'] : 4000),
    );
}

/**
 * 合并并收口告警（全局上限，复核类已在 ocf_build_warnings 排序）。
 *
 * @param array $warnings 告警
 * @param array $cfg      配置
 * @return array
 */
function ocf_cap_warnings($warnings, $cfg)
{
    $max = isset($cfg['maxWarnings']) ? (int)$cfg['maxWarnings'] : 5;
    $maxChars = isset($cfg['maxWarningChars']) ? (int)$cfg['maxWarningChars'] : 80;
    $out = array();
    foreach ($warnings as $w) {
        $w = (string)$w;
        if (ocf_len($w) > $maxChars) {
            $w = ocf_cut($w, $maxChars);
        }
        if ($w !== '' && !in_array($w, $out, true)) {
            $out[] = $w;
        }
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}
