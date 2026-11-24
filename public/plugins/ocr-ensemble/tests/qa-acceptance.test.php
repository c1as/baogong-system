<?php
/**
 * QA 独立验收测试（独立验证，不复用工程师自报结论）
 *
 * 运行：php tests/qa-acceptance.test.php
 * 覆盖：
 *   A. mock 双路引擎端到端完整融合（L1/L4/L6、trace 旁路、返回上限）
 *   B. 判定准确率专项五条（2 路佐证、3 路多数、suggest 软证据、L1 硬约束、字库降级）
 *   C. 回归兼容（singleEngineMode Q2、全开关关闭回退基线）
 *   D. 补充边界（3 路各异不可裁决、N 路全拒、trace 关闭、结构不含 trace）
 *
 * 数据目录隔离到系统临时目录，绝不污染交付包 data/。
 */

$GLOBALS['DATA_DIR'] = rtrim(sys_get_temp_dir(), '/') . '/ocf-qa-' . getmypid();
@mkdir($GLOBALS['DATA_DIR'] . '/ocr-fusion', 0775, true);
@mkdir($GLOBALS['DATA_DIR'] . '/ocr-logs', 0775, true);

require_once dirname(__DIR__) . '/ocf/bootstrap.php';

/* ---------------- mock 主程序插件契约 ---------------- */
$GLOBALS['QA_MOCK'] = array('engines' => array(), 'results' => array());

function bg_plugin_all()
{
    return $GLOBALS['QA_MOCK']['engines'];
}
function bg_plugin_is_enabled($id)
{
    return true;
}
function bg_plugin_invoke_engine($id, $ctx)
{
    return isset($GLOBALS['QA_MOCK']['results'][$id]) ? $GLOBALS['QA_MOCK']['results'][$id] : array();
}
/* 字库归一 mock：仅在显式传入 available bridge 时被调用，用于 suggest 边界测试 */
$GLOBALS['QA_BRIDGE_NORM'] = array('action' => 'none', 'value' => '', 'rule' => '', 'original' => '');
function bglx_bridge_norm($kind, $raw, $opts = array())
{
    $r = $GLOBALS['QA_BRIDGE_NORM'];
    $r['original'] = $raw;
    return $r;
}

$pass = 0;
$fail = 0;
function qa_assert($cond, $label, $detail = '')
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "[PASS] " . $label . "\n";
    } else {
        $fail++;
        echo "[FAIL] " . $label . ($detail !== '' ? " · " . $detail : '') . "\n";
    }
}

/** 构造一路引擎结果 */
function qa_result($date, $partNo, $qty, $process, $raw, $conf, $itemNo = null)
{
    if ($itemNo === null) {
        $itemNo = $partNo;
    }
    return array(
        'confidence' => $conf,
        'raw' => $raw,
        'fields' => array(
            'date' => $date,
            'worker' => '张三',
            'workshop' => '一车间',
            'note' => '加急',
            'items' => array(array(
                'partNo' => $itemNo,
                'partName' => '齿轮',
                'process' => $process,
                'qty' => $qty,
                'price' => 12.5,
            )),
        ),
    );
}

/** 装载双路 mock 引擎（均有原文佐证，结果一致） */
function qa_load_engines_agree()
{
    $raw = "2026-11-24 报工人 张三 一车间\n图号 24971B05 齿轮 铣 50件";
    $GLOBALS['QA_MOCK']['engines'] = array(
        'mock-a' => array('id' => 'mock-a', 'name' => '引擎A', 'capabilities' => array('report.ocr')),
        'mock-b' => array('id' => 'mock-b', 'name' => '引擎B', 'capabilities' => array('report.ocr')),
    );
    $GLOBALS['QA_MOCK']['results'] = array(
        'mock-a' => qa_result('2026-11-24', '24971B05', 50, '铣', $raw, 0.90),
        'mock-b' => qa_result('2026-11-24', '24971B05', 50, '铣', $raw, 0.85),
    );
}

/** 清空隔离 trace 文件 */
function qa_clear_trace()
{
    $p = $GLOBALS['DATA_DIR'] . '/ocr-logs/fusion-trace-' . date('Y-m-d') . '.jsonl';
    if (is_file($p)) {
        @unlink($p);
    }
    return $p;
}

/** 读最后一条 trace */
function qa_last_trace($path)
{
    if (!is_file($path)) {
        return null;
    }
    $last = '';
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        $last = $ln;
    }
    return $last === '' ? null : json_decode($last, true);
}

echo "=== QA 独立验收测试（判定准确率专项 + 端到端 + 回归） ===\n\n";

$cfg = ocf_defaults();
$ctx = array('id' => 'ocr-ensemble', 'invokeId' => 'qa-e2e-001', 'today' => '2026-11-24');

/* ==================== A. 端到端双路完整融合 ==================== */
echo "--- A. 端到端双路融合（mock） ---\n";
qa_load_engines_agree();
$tracePath = qa_clear_trace();
$res = ocf_run($ctx, $cfg);

qa_assert(is_array($res) && isset($res['engine'], $res['confidence'], $res['fields'], $res['warnings'], $res['raw']),
    'A1 返回结构含 engine/confidence/fields/warnings/raw');
qa_assert(strpos($res['engine'], '2 路') !== false, 'A2 引擎标识为 2 路融合', $res['engine']);
qa_assert($res['confidence'] >= 0 && $res['confidence'] <= 1, 'A3 综合置信度在 0~1', (string)$res['confidence']);
qa_assert($res['fields']['date'] === '2026-11-24', 'A4 日期字段正确', $res['fields']['date']);
qa_assert(isset($res['fields']['items'][0]['partNo']) && $res['fields']['items'][0]['partNo'] === '24971B05',
    'A5 明细图号采纳有佐证值', isset($res['fields']['items'][0]['partNo']) ? $res['fields']['items'][0]['partNo'] : 'N/A');
qa_assert(count($res['fields']['items']) <= 20, 'A6 items ≤ 20');
qa_assert(count($res['warnings']) <= 5, 'A7 warnings ≤ 5');
$warnTooLong = false;
foreach ($res['warnings'] as $w) {
    if (ocf_len($w) > 80) {
        $warnTooLong = true;
    }
}
qa_assert(!$warnTooLong, 'A8 每条 warning ≤ 80 字');
qa_assert(ocf_len($res['raw']) <= 4000, 'A9 raw ≤ 4000 字', (string)ocf_len($res['raw']));
qa_assert(!array_key_exists('trace', $res), 'A10 trace 不进返回值（旁路）');

$trace = qa_last_trace($tracePath);
qa_assert(is_array($trace) && $trace['invokeId'] === 'qa-e2e-001', 'A11 trace 旁路 JSONL 落盘且 invokeId 关联');
qa_assert(is_array($trace) && $trace['engineCount'] === 2, 'A12 trace 记录引擎数=2');
$hasAllLayers = false;
$hasRule = false;
if (is_array($trace) && !empty($trace['fields'])) {
    $stages = $trace['fields'][0]['stages'];
    $hasAllLayers = isset($stages['L0'], $stages['L1'], $stages['L2'], $stages['L3'], $stages['L4'], $stages['L5'], $stages['L6']);
    $hasRule = isset($trace['fields'][0]['rule']) && $trace['fields'][0]['rule'] !== '';
}
qa_assert($hasAllLayers, 'A13 trace 字段含 L0-L6 全链路');
qa_assert($hasRule, 'A14 trace 含可枚举命中规则');

/* A15-A16：端到端分歧 → L4 不可裁决 → L6 转复核 + warning 输出 */
$rawD1 = "2026-11-24 张三 一车间 图号 24971B05 铣 50件";
$rawD2 = "2026-11-25 张三 一车间 图号 24971B05 铣 50件";
$GLOBALS['QA_MOCK']['engines'] = array(
    'mock-a' => array('id' => 'mock-a', 'name' => '引擎A', 'capabilities' => array('report.ocr')),
    'mock-b' => array('id' => 'mock-b', 'name' => '引擎B', 'capabilities' => array('report.ocr')),
);
$GLOBALS['QA_MOCK']['results'] = array(
    'mock-a' => qa_result('2026-11-24', '24971B05', 50, '铣', $rawD1, 0.70),
    'mock-b' => qa_result('2026-11-25', '24971B05', 50, '铣', $rawD2, 0.70),
);
$cfgNoRep = $cfg;
$cfgNoRep['enableFieldReputation'] = 0; /* 双方权重相等→2way 信誉差=0→不可裁决 */
qa_clear_trace();
$resDis = ocf_run($ctx, $cfgNoRep);
$dateReviewWarn = false;
foreach ($resDis['warnings'] as $w) {
    if (strpos($w, '日期') !== false && strpos($w, '复核') !== false) {
        $dateReviewWarn = true;
    }
}
qa_assert($dateReviewWarn, 'A15 端到端分歧触发 L6 日期复核告警', implode(' | ', $resDis['warnings']));
$tpDis = $GLOBALS['DATA_DIR'] . '/ocr-logs/fusion-trace-' . date('Y-m-d') . '.jsonl';
$traceDis = qa_last_trace($tpDis);
qa_assert(is_array($traceDis) && $traceDis['invokeId'] === 'qa-e2e-001', 'A16 复核场景 trace 仍旁路落盘');
qa_load_engines_agree(); /* 恢复默认 mock 供后续用例 */

/* ==================== B. 判定准确率专项 ==================== */
echo "\n--- B1. 2 路分歧：有佐证方胜出 ---\n";
$ctxT = array('id' => 'ocr-ensemble');
$b1 = ocf_arbitrate(array(
    array('engineId' => 'e1', 'engine' => 'e1', 'field' => 'partNo', 'value' => '24971B05',
          'selfConf' => 0.6, 'weight' => 0.6, 'supported' => true, 'evidence' => 'raw run 24971B05', 'lexAction' => 'none'),
    array('engineId' => 'e2', 'engine' => 'e2', 'field' => 'partNo', 'value' => 'ZZZZZ999',
          'selfConf' => 0.99, 'weight' => 0.99, 'supported' => false, 'evidence' => '', 'lexAction' => 'none'),
), $ctxT, $cfg);
qa_assert($b1['rule'] === 'L1_SOLE_SUPPORT' && $b1['value'] === '24971B05',
    'B1-1 一方无佐证被剔除，采纳有佐证方（即使对方置信更高）', 'rule=' . $b1['rule'] . ' value=' . $b1['value']);
/* L1 不可绕过：全部无佐证时弃权 */
$b1all = ocf_arbitrate(array(
    array('engineId' => 'e1', 'engine' => 'e1', 'field' => 'partNo', 'value' => 'A', 'weight' => 0.9, 'supported' => false),
    array('engineId' => 'e2', 'engine' => 'e2', 'field' => 'partNo', 'value' => 'B', 'weight' => 0.8, 'supported' => false),
), $ctxT, $cfg);
qa_assert($b1all['rule'] === 'L1_ALL_REJECTED' && $b1all['abstain'] === true,
    'B1-2 全部无佐证→弃权（L1 一票否决不可绕过）');
/* 关键安全边界：无佐证候选即使字库 auto 命中，也不得被 L2 规则采纳（L1 不可被 auto 绕过） */
$b1auto = ocf_arbitrate(array(
    array('engineId' => 'e1', 'engine' => 'e1', 'field' => 'partNo', 'value' => 'ZZZZZ999',
          'selfConf' => 0.99, 'weight' => 0.99, 'supported' => false, 'evidence' => '', 'lexAction' => 'auto', 'lexRule' => 'drawing_alias:x'),
    array('engineId' => 'e2', 'engine' => 'e2', 'field' => 'partNo', 'value' => '24971B05',
          'selfConf' => 0.6, 'weight' => 0.6, 'supported' => true, 'evidence' => '佐证', 'lexAction' => 'none'),
), $ctxT, $cfg);
qa_assert($b1auto['value'] !== 'ZZZZZ999' && $b1auto['rule'] !== 'L2_LEX_AUTO',
    'B1-3 无佐证候选即使 auto 命中也被 L1 剔除（L1 不可被字库绕过）', 'rule=' . $b1auto['rule'] . ' value=' . $b1auto['value']);

echo "\n--- B2. 3 路分歧：2 路同簇多数 ---\n";
/* 同簇 0.51+0.51=1.02，单路 0.9 → share≈0.53<trustedShare，强制进入 3way 的 MAJORITY_2_OF_3 */
$mkc = function ($v, $e, $w) {
    return array('engineId' => $e, 'engine' => $e, 'field' => 'partNo', 'value' => $v,
                 'selfConf' => $w, 'weight' => $w, 'supported' => true, 'evidence' => '佐证', 'lexAction' => 'none');
};
$b2 = ocf_arbitrate(array($mkc('24971B05', 'e1', 0.51), $mkc('24971B05', 'e2', 0.51), $mkc('ZZZZZ999', 'e3', 0.90)), $ctxT, $cfg);
qa_assert(in_array($b2['rule'], array('MAJORITY_2_OF_3', 'MAJORITY_WEIGHTED'), true) && $b2['value'] === '24971B05',
    'B2-1 3 路 2 同簇采纳多数簇（含强制进 3way 分支）', 'rule=' . $b2['rule'] . ' value=' . $b2['value']);
$b2b = ocf_arbitrate(array($mkc('A', 'e1', 0.9), $mkc('B', 'e2', 0.8), $mkc('C', 'e3', 0.7)), $ctxT, $cfg);
/* 三路各异：层级分出胜负→LAYER_RANK_WIN(needReview=false)；拉不开→UNRESOLVABLE_3WAY(needReview=true) */
$b2bOk = ($b2b['rule'] === 'LAYER_RANK_WIN' && $b2b['needReview'] === false && $b2b['value'] === 'A')
    || ($b2b['rule'] === 'UNRESOLVABLE_3WAY' && $b2b['needReview'] === true);
qa_assert($b2bOk, 'B2-2 三路各异按层级裁决或转复核，行为自洽', 'rule=' . $b2b['rule'] . ' needReview=' . var_export($b2b['needReview'], true));

echo "\n--- B3. 字库 suggest 软证据不强制改写赢家 ---\n";
/* ocf_lex_apply：suggest 只记 action，不改 value */
$GLOBALS['QA_BRIDGE_NORM'] = array('action' => 'suggest', 'value' => '24971b05', 'rule' => 'drawing_alias:2L→4', 'original' => '');
$candSuggest = array(array(
    'engineId' => 'e1', 'engine' => 'e1', 'field' => 'partNo', 'value' => '2L59711305',
    'selfConf' => 0.9, 'weight' => 0.9, 'supported' => true, 'evidence' => '原文佐证', 'lexAction' => 'none', 'lexRule' => '',
));
$applied = ocf_lex_apply($candSuggest, array('available' => true, 'degraded' => false), $cfg);
qa_assert($applied[0]['value'] === '2L59711305' && $applied[0]['lexAction'] === 'suggest',
    'B3-1 suggest 档不改候选值、仅标软证据', 'value=' . $applied[0]['value'] . ' action=' . $applied[0]['lexAction']);
qa_assert(strpos($applied[0]['evidence'], '仅提示') !== false, 'B3-2 suggest 证据标注"仅提示"');
$b3verdict = ocf_arbitrate(array(
    $applied[0],
    array('engineId' => 'e2', 'engine' => 'e2', 'field' => 'partNo', 'value' => 'OTHER01',
          'selfConf' => 0.8, 'weight' => 0.8, 'supported' => true, 'evidence' => '佐证', 'lexAction' => 'none'),
), $ctxT, $cfg);
qa_assert(!in_array($b3verdict['rule'], array('L2_LEX_AUTO', 'L2_LEX_AUTO_WINS'), true),
    'B3-3 suggest 不触发 L2 硬归一裁决规则', 'rule=' . $b3verdict['rule']);
/* auto 档才改值 */
$GLOBALS['QA_BRIDGE_NORM'] = array('action' => 'auto', 'value' => '24971b05', 'rule' => 'drawing_alias', 'original' => '');
$appliedAuto = ocf_lex_apply($candSuggest, array('available' => true, 'degraded' => false), $cfg);
qa_assert($appliedAuto[0]['value'] === '24971b05' && $appliedAuto[0]['lexAction'] === 'auto' && isset($appliedAuto[0]['lexOriginal']),
    'B3-4 auto 档改值并保留 lexOriginal 供回验');

echo "\n--- B4. L1 硬约束：无佐证库内图号不被采纳（端到端） ---\n";
/* 引擎B 给出库内存在但原文无佐证的图号，且自报置信更高 + catalog 中有该图号 */
$rawB = "2026-11-24 报工人 张三 一车间\n图号 未识别清楚 齿轮 铣 50件";
$GLOBALS['QA_MOCK']['engines'] = array(
    'mock-a' => array('id' => 'mock-a', 'name' => '引擎A', 'capabilities' => array('report.ocr')),
    'mock-b' => array('id' => 'mock-b', 'name' => '引擎B', 'capabilities' => array('report.ocr')),
);
$GLOBALS['QA_MOCK']['results'] = array(
    'mock-a' => qa_result('2026-11-24', '24971B05', 50, '铣', "2026-11-24 张三 一车间 图号 24971B05 铣 50件", 0.80),
    'mock-b' => qa_result('2026-11-24', '88888AAA', 50, '铣', $rawB, 0.99),
);
$ctxB4 = $ctx;
$ctxB4['catalog'] = array(array('no' => '88888AAA', 'name' => '猜测件'), array('no' => '24971B05', 'name' => '齿轮'));
qa_clear_trace();
$resB4 = ocf_run($ctxB4, $cfg);
qa_assert($resB4['fields']['items'][0]['partNo'] !== '88888AAA',
    'B4-1 库内存在但原文无佐证的图号未被采纳', 'partNo=' . $resB4['fields']['items'][0]['partNo']);
qa_assert($resB4['fields']['items'][0]['partNo'] === '24971B05',
    'B4-2 采纳原文有佐证的图号');

echo "\n--- B5. 降级：字库连接层不可用识别不中断 ---\n";
/* bootstrap 未引入 bridge.php → function_exists('bglx_bridge_init') 为 false，走降级 */
qa_load_engines_agree();
qa_clear_trace();
$bridgeStatus = array('available' => false, 'degraded' => true);
$resB5 = ocf_run($ctx, $cfg);
qa_assert(is_array($resB5) && isset($resB5['fields']['items']) && count($resB5['fields']['items']) >= 1,
    'B5-1 字库连接层缺席时融合仍正常产出明细');
qa_assert($resB5['fields']['date'] === '2026-11-24', 'B5-2 降级路径字段值正确');
/* 直接验证 ocf_lex_apply 在 degraded 下不动值 */
$degraded = ocf_lex_apply($candSuggest, $bridgeStatus, $cfg);
qa_assert($degraded[0]['value'] === '2L59711305' && $degraded[0]['lexAction'] === 'none',
    'B5-3 degraded 时 lexAction=none 等价词库未启用');

/* ==================== C. 回归兼容 ==================== */
echo "\n--- C1. singleEngineMode（Q2）单插件直出 ---\n";
qa_load_engines_agree();
$cfgSingle = $cfg;
$cfgSingle['singleEngineMode'] = 1;
qa_clear_trace();
$resC1 = ocf_run($ctx, $cfgSingle);
qa_assert(strpos($resC1['engine'], '单引擎直出') !== false, 'C1-1 开启后走单引擎直出', $resC1['engine']);
qa_assert($resC1['fields']['date'] === '2026-11-24' && isset($resC1['fields']['items'][0]),
    'C1-2 直出第一路结构化结果');
$hasSingleWarn = false;
foreach ($resC1['warnings'] as $w) {
    if (strpos($w, '单引擎直出') !== false) {
        $hasSingleWarn = true;
    }
}
qa_assert($hasSingleWarn, 'C1-3 直出模式有明确告警标识');

echo "\n--- C2. 关闭所有增强开关回退基线 ---\n";
$cfgBase = $cfg;
$cfgBase['enableFieldReputation'] = 0;
$cfgBase['enableCalibration'] = 0;
$cfgBase['enableLexicon'] = 0;
$cfgBase['enableTrace'] = 0;
qa_assert(abs(ocf_weight('any', 'partNo', 0.73, $cfgBase) - 0.73) < 1e-9,
    'C2-1 关闭信誉后权重回退自报置信度');
qa_load_engines_agree();
$tp = qa_clear_trace();
$resC2 = ocf_run($ctx, $cfgBase);
qa_assert(is_array($resC2) && $resC2['fields']['date'] === '2026-11-24' && isset($resC2['fields']['items'][0]),
    'C2-2 全关增强后基线路径仍正常产出');
qa_assert(!is_file($tp), 'C2-3 enableTrace=0 时不落 trace 文件');

/* ==================== D. 补充边界 ==================== */
echo "\n--- D. 补充边界 ---\n";
$dB4 = ocf_arbitrate_nway(
    array($mkc('A', 'e1', 0.5), $mkc('B', 'e2', 0.5), $mkc('C', 'e3', 0.5), $mkc('D', 'e4', 0.5)),
    ocf_cluster(array($mkc('A', 'e1', 0.5), $mkc('B', 'e2', 0.5), $mkc('C', 'e3', 0.5), $mkc('D', 'e4', 0.5)), 0.85),
    array('rep' => 'A', 'weight' => 0.5, 'size' => 1), 0.75, $cfg);
qa_assert(in_array($dB4['rule'], array('NWAY_LAYER_WIN', 'UNRESOLVABLE_NWAY'), true), 'D1 N 路规则可枚举');
/* 校准恒等：enableCalibration 关闭时任何输入原样返回 */
qa_assert(abs(ocf_calibrate(0.999, 1.0, 1.0, $cfgBase) - 0.999) < 1e-9, 'D2 关闭校准恒等（边界 0.999）');
/* 空候选不致命 */
$emptyV = ocf_arbitrate(array(), $ctxT, $cfg);
qa_assert($emptyV['rule'] === 'L1_ALL_REJECTED' && $emptyV['abstain'], 'D3 空候选集安全弃权');

echo "\n=== QA 专项结果：通过 " . $pass . " 项，失败 " . $fail . " 项 ===\n";
exit($fail > 0 ? 1 : 0);
