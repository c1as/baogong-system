<?php
/**
 * T04 单元测试：字段归一 / 择优规则 / 三模式 / 输出上限 / 字库降级
 * 运行：php tests/normalize_fuse.test.php
 */

$bg_tv_test_total = 0;
$bg_tv_test_pass = 0;
$bg_tv_test_fail = array();

/**
 * 断言辅助。
 *
 * @param bool   $cond 条件
 * @param string $name 用例名
 * @return void
 */
function bg_tv_test_assert($cond, $name)
{
    global $bg_tv_test_total, $bg_tv_test_pass, $bg_tv_test_fail;
    $bg_tv_test_total++;
    if ($cond) {
        $bg_tv_test_pass++;
        echo "  [OK] " . $name . "\n";
    } else {
        $bg_tv_test_fail[] = $name;
        echo "  [FAIL] " . $name . "\n";
    }
}

require_once __DIR__ . '/../lib/util.php';
require_once __DIR__ . '/../lib/tencent_ocr.php';
require_once __DIR__ . '/../lib/hy4_vision.php';
require_once __DIR__ . '/../lib/normalize.php';
require_once __DIR__ . '/../lib/fuse.php';
require_once __DIR__ . '/../lib/lex.php';

echo "== T04 归一化 / 择优 / 收口 ==\n";

/* 1. OCR 行 → 统一字段 */
$detections = json_decode(file_get_contents(__DIR__ . '/fixtures/ocr_text_detections.json'), true);
$lines = array();
foreach ($detections as $d) {
    $lines[] = $d['DetectedText'];
}
$mid = array(
    'engineId' => 'tencentOcr',
    'confidence' => 0.9,
    'raw' => implode("\n", $lines),
    'warnings' => array(),
    'usage' => array('prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0),
    'elapsedMs' => 12,
    'ok' => true,
    '_today' => '2026-11-20',
    '_catalog' => array(),
    '_mid' => array('rows' => bg_tv_ocr_rows($detections)),
);
$nOcr = bg_tv_norm_ocr($mid, array());
bg_tv_test_assert($nOcr['fields']['date'] === '2026-11-20', 'OCR 日期归一');
bg_tv_test_assert($nOcr['fields']['worker'] === '张三', 'OCR 报工人归一');
bg_tv_test_assert($nOcr['fields']['workshop'] === '一车间', 'OCR 车间归一');
bg_tv_test_assert(count($nOcr['fields']['items']) === 3, 'OCR 还原 3 行明细（合计行跳过）');
$qtys = array_column($nOcr['fields']['items'], 'qty');
bg_tv_test_assert(array_map('floatval', $qtys) === array(80.0, 50.0, 50.0), 'OCR 三行数量 80/50/50，实际 ' . implode(',', $qtys));
bg_tv_test_assert(strpos($nOcr['raw'], 'TD24510R04') !== false && strpos($nOcr['raw'], '80') !== false, 'OCR raw 保留图号与数量');

/* 2. Hy4 解析 → 统一字段 */
$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/hy4_contents.json'), true);
$parsed = bg_tv_hy4_extract_json($fixtures['pure_json']);
$nHy4 = bg_tv_norm_hy4($parsed, 'json', array('_today' => '2026-11-20'));
bg_tv_test_assert($nHy4['fields']['date'] === '2026-11-20', 'Hy4 日期归一');
bg_tv_test_assert(count($nHy4['fields']['items']) === 2, 'Hy4 明细 2 行');
bg_tv_test_assert($nHy4['fields']['items'][1]['price'] === 12.5, 'Hy4 单价归一为数值');
bg_tv_test_assert(strpos($nHy4['raw'], 'AB123456') !== false, 'Hy4 raw 保留图号');

/* 3. 择优规则（有值 → 一致 → 佐证 → 置信度） */
$cfg = array('agreeThreshold' => '0.85');
$itemA = array('partNo' => 'X1', 'partName' => '甲', 'process' => '铣', 'qty' => 80, 'price' => null);
$itemB = array('partNo' => 'Y2', 'partName' => '乙', 'process' => '钻', 'qty' => 50, 'price' => null);
$a = array(
    'engineId' => 'tencentOcr', 'confidence' => 0.90, 'ok' => true,
    'fields' => array('date' => '2026-11-20', 'worker' => '甲', 'workshop' => '', 'note' => '', 'items' => array($itemA)),
    'raw' => 'X1 80', 'warnings' => array(),
);
$b = array(
    'engineId' => 'hy4Vision', 'confidence' => 0.80, 'ok' => true,
    'fields' => array('date' => '2026-11-20', 'worker' => '乙', 'workshop' => '一车间', 'note' => '', 'items' => array($itemB)),
    'raw' => 'Y2 50', 'warnings' => array(),
);
bg_tv_test_assert(bg_tv_pick_value('workshop', $a, $b, $cfg) === '一车间', '规则①：有值优先');
$sameA = $a; $sameA['fields']['worker'] = '张三';
$sameB = $b; $sameB['fields']['worker'] = '张三'; $sameB['confidence'] = 0.5;
bg_tv_test_assert(bg_tv_pick_value('worker', $sameA, $sameB, $cfg) === '张三', '规则②：一致取主引擎');
$supA = $a; $supA['fields']['note'] = 'AAA1111'; $supA['raw'] = '没有相关片段';
$supB = $b; $supB['fields']['note'] = 'BBB2222'; $supB['raw'] = '原文 BBB2222 数量 50';
bg_tv_test_assert(bg_tv_pick_value('note', $supA, $supB, $cfg) === 'BBB2222', '规则③：原文佐证优先降权');
$confA = $a; $confA['fields']['note'] = '甲'; $confA['raw'] = '';
$confB = $b; $confB['fields']['note'] = '乙'; $confB['raw'] = '';
bg_tv_test_assert(bg_tv_pick_value('note', $confA, $confB, $cfg) === '甲', '规则④：置信度高者胜');

/* 4. 明细对齐合并 */
$merged = bg_tv_fuse_items(
    array(array('partNo' => 'X1', 'partName' => '甲', 'process' => '铣', 'qty' => 80, 'price' => null)),
    array(
        array('partNo' => 'X1', 'partName' => '甲', 'process' => '铣', 'qty' => 80, 'price' => null),
        array('partNo' => 'Y2', 'partName' => '乙', 'process' => '钻', 'qty' => 50, 'price' => null),
    ),
    $cfg, 'X1 80', 'Y2 50'
);
bg_tv_test_assert(count($merged) === 2, '明细按 partNo 对齐后合并为 2 行');
bg_tv_test_assert($merged[0]['qty'] == 80 && $merged[1]['partNo'] === 'Y2', '对齐行与独有行均保留');

/* 5. 三种输出模式 */
$results = array('tencentOcr' => $a, 'hy4Vision' => $b);
$fused = bg_tv_fuse($results, $cfg);
bg_tv_test_assert($fused['worker'] === '甲' && isset($fused['items']), 'fuse 产出单结果 Fields');
$parallel = bg_tv_parallel($results, $cfg);
bg_tv_test_assert(isset($parallel['alternates']) && count($parallel['alternates']) === 2, 'parallel 留存 2 路 alternates');
bg_tv_test_assert($parallel['alternates'][0]['engine'] === '腾讯云 OCR', 'alternates 首位为主引擎');
bg_tv_test_assert($parallel['fields']['worker'] === '甲', 'parallel 标准字段取主引擎');
$primary = bg_tv_primary($results, $cfg);
bg_tv_test_assert($primary['engine'] === 'tencent-vision/tencentOcr', 'primary engine 前缀由主引擎决定');
bg_tv_test_assert(!isset($primary['alternates']), 'primary 不输出 alternates');
bg_tv_test_assert(count(bg_tv_diff($a, $b)) >= 1, 'compareMode 差异可检测');

/* 6. finalize 硬上限 */
$bigItems = array();
for ($i = 0; $i < 25; $i++) {
    $bigItems[] = array('partNo' => 'P' . $i, 'partName' => '名称', 'process' => '铣', 'qty' => 1, 'price' => null);
}
$bigWarnings = array();
for ($i = 0; $i < 8; $i++) {
    $bigWarnings[] = str_repeat('长', 100) . $i;
}
$final = bg_tv_finalize(array(
    'engine' => 'tencent-vision/tencentOcr',
    'confidence' => 1.5,
    'fields' => array('date' => '2026-13-45', 'worker' => '张三', 'workshop' => '', 'note' => '', 'items' => $bigItems),
    'warnings' => $bigWarnings,
    'raw' => str_repeat('图', 5000),
    '_today' => '2026-11-20',
));
bg_tv_test_assert(count($final['fields']['items']) === 20, 'items 截断到 20');
bg_tv_test_assert(count($final['warnings']) <= 5, 'warnings 截断到 5');
$overLen = false;
foreach ($final['warnings'] as $w) {
    if (mb_strlen($w, 'UTF-8') > 80) {
        $overLen = true;
    }
}
bg_tv_test_assert(!$overLen, '每条 warning ≤80 字');
bg_tv_test_assert(mb_strlen($final['raw'], 'UTF-8') <= 4000, 'raw 截断到 4000 字');
bg_tv_test_assert($final['fields']['date'] === '', '非法日期置空');
bg_tv_test_assert($final['confidence'] === 1.0, '置信度夹逼到 1 且保留 3 位');
bg_tv_test_assert(!isset($final['_today']) && $final['plugin']['id'] === 'tencent-vision', '内部键不外泄且 plugin.id 正确');

/* 7. 字库桥降级路径（bridge 不可用时静默降级并写告警） */
$lex = bg_tv_lex_apply($a['fields'], 'tencent-vision');
bg_tv_test_assert(isset($lex['fields']) && isset($lex['warnings']) && isset($lex['available']), '字库 apply 返回结构完整');
if (!$lex['available']) {
    $joined = implode('|', $lex['warnings']);
    bg_tv_test_assert(strpos($joined, '字库连接层不可用') !== false, 'bridge 缺失时写降级告警且字段原样');
} else {
    bg_tv_test_assert(is_array($lex['fields']['items']), 'bridge 可用时字段正常返回');
}

echo "结果：{$bg_tv_test_pass}/{$bg_tv_test_total} 通过\n";
if ($bg_tv_test_fail) {
    exit(1);
}
