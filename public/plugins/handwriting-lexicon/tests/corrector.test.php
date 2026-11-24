<?php
/**
 * 手写字词库 · 纠错引擎单元测试（零依赖）
 *
 * 运行：php tests/corrector.test.php
 * 不依赖主程序、不依赖数据库，使用系统临时目录中的词库副本。
 */

require_once dirname(__DIR__) . '/lib/lexicon.php';

$pass = 0;
$fail = 0;

/**
 * 断言辅助。
 *
 * @param bool   $condition 断言条件
 * @param string $label     用例名称
 * @param string $detail    失败时的补充说明
 */
function bglx_test_assert($condition, $label, $detail = '')
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "[PASS] " . $label . "\n";
    } else {
        $fail++;
        echo "[FAIL] " . $label . ($detail !== '' ? " · " . $detail : '') . "\n";
    }
}

/** 创建隔离的临时词库文件，内容为种子数据 */
function bglx_test_store($name, $seedFile = '')
{
    $dir = rtrim(sys_get_temp_dir(), '/') . '/bglx-test-' . getmypid();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/' . $name . '.json';
    @unlink($file);
    @unlink($file . '.lock');
    if ($seedFile !== '' && is_file($seedFile)) {
        @copy($seedFile, $file);
    }
    return new BglxStore($file);
}

echo "=== 手写字词库纠错引擎单元测试 ===\n\n";

$fixture = dirname(__DIR__) . '/fixtures/demo-entries.json';
$store = bglx_test_store('seed', $fixture);
$corrector = new BglxCorrector($store, array('auto_min' => 0.90, 'suggest_min' => 0.75));

echo "--- A. 种子数据：四类词条 × 三档动作 ---\n";

$r = $corrector->correct('drawing_alias', '2L59711305');
bglx_test_assert(
    $r['corrected'] === '24971b05' && $r['action'] === 'auto' && $r['strategy'] === 'char_norm',
    '图号 2L59711305 两级归一后自动纠正为 24971b05',
    '实际 corrected=' . $r['corrected'] . ' action=' . $r['action'] . ' strategy=' . $r['strategy']
);

$r = $corrector->correct('drawing_alias', '307437/61');
bglx_test_assert(
    $r['corrected'] === '307437r61' && $r['action'] === 'auto',
    '图号 307437/61 经 /→7 归一后自动纠正',
    '实际 corrected=' . $r['corrected'] . ' action=' . $r['action']
);

$r = $corrector->correct('drawing_alias', '24971bO5');
bglx_test_assert(
    $r['corrected'] === '24971b05' && $r['action'] === 'auto',
    '图号 24971bO5 经 O→0 归一后自动纠正'
);

$r = $corrector->correct('process_synonym', '铣2');
bglx_test_assert(
    $r['action'] === 'suggest',
    '工序 铣2 命中中置信度词条，仅建议确认',
    '实际 action=' . $r['action'] . ' corrected=' . $r['corrected']
);
bglx_test_assert(
    $r['action'] === 'suggest' && $r['original'] === '铣2',
    'suggest 档保留原值不替换'
);

$r = $corrector->correct('process_synonym', '铣');
bglx_test_assert(
    $r['corrected'] === '铣' && $r['action'] === 'auto',
    '工序 铣 为合法标准值，命中后 auto 且值不变'
);

$r = $corrector->correct('unit_synonym', '个');
bglx_test_assert(
    $r['action'] === 'suggest' && $r['corrected'] === '个',
    '单位 个 命中 0.88 词条，suggest 且保留原值'
);

$r = $corrector->correct('drawing_alias', '完全不存在的图号XYZ');
bglx_test_assert(
    $r['action'] === 'none' && $r['corrected'] === '完全不存在的图号XYZ' && $r['confidence'] === 0.0,
    '归一后未命中词条时原样返回（保守原则）'
);

echo "\n--- B. 阈值覆盖与公共库函数 ---\n";

$r = $corrector->correct('process_synonym', '铣2', array('auto_min' => 0.80, 'suggest_min' => 0.70));
bglx_test_assert($r['action'] === 'auto', '下调 auto 阈值后 0.85 词条转为 auto');

$r = bglx_correct_partno('2L59711305');
bglx_test_assert(isset($r['corrected'], $r['confidence'], $r['matched_id'], $r['action']), '公共库 bglx_correct_partno 返回 4 个兼容键');
$r = bglx_norm_process('铣');
bglx_test_assert(is_array($r) && isset($r['action']), '公共库 bglx_norm_process 可调用');
$r = bglx_norm_unit('个');
bglx_test_assert(is_array($r) && isset($r['action']), '公共库 bglx_norm_unit 可调用');
$all = bglx_query_all('drawing_alias', '2L59711305');
bglx_test_assert(is_array($all) && count($all) >= 1, '公共库 bglx_query_all 返回候选数组');
$status = bglx_status();
bglx_test_assert(isset($status['version'], $status['store_file'], $status['entries']), '公共库 bglx_status 结构合法');
bglx_reload();
bglx_test_assert(true, 'bglx_reload 可重复调用无致命错误');

echo "\n--- C. 多候选降级 / 启用与审核过滤 / 作用范围 ---\n";

$store2 = bglx_test_store('conflict');
$store2->create(array(
    'type' => 'drawing_alias', 'standard' => 'AAAA1111',
    'variants' => array('ZZTEST99'), 'confidence' => 0.95,
));
$store2->create(array(
    'type' => 'drawing_alias', 'standard' => 'BBBB2222',
    'variants' => array('ZZTEST99'), 'confidence' => 0.95,
));
$c2 = new BglxCorrector($store2, array('auto_min' => 0.90, 'suggest_min' => 0.75));
$r = $c2->correct('drawing_alias', 'ZZTEST99');
bglx_test_assert(
    $r['action'] === 'suggest' && count($r['candidates']) >= 2,
    '多候选标准值不同强制降级 suggest',
    '实际 action=' . $r['action'] . ' candidates=' . count($r['candidates'])
);

$store3 = bglx_test_store('states');
$e = $store3->create(array(
    'type' => 'drawing_alias', 'standard' => 'STATE0001',
    'variants' => array('ST0001'), 'confidence' => 0.95,
));
$store3->setEnabled($e['id'], false);
$c3 = new BglxCorrector($store3);
bglx_test_assert($c3->correct('drawing_alias', 'ST0001')['action'] === 'none', '停用词条不参与查询');
$store3->setStatus($e['id'], 'pending');
$store3->setEnabled($e['id'], true);
$c3 = new BglxCorrector($store3);
bglx_test_assert($c3->correct('drawing_alias', 'ST0001')['action'] === 'none', '待审核词条不参与查询');
$store3->setStatus($e['id'], 'active');
$c3 = new BglxCorrector($store3);
bglx_test_assert($c3->correct('drawing_alias', 'ST0001')['action'] === 'auto', '审核通过且启用后词条生效');

$store4 = bglx_test_store('scope');
$store4->create(array(
    'type' => 'drawing_alias', 'standard' => 'SCOPE0001',
    'variants' => array('SC0001'), 'confidence' => 0.95, 'scope' => array('other-plugin'),
));
$c4 = new BglxCorrector($store4);
bglx_test_assert($c4->correct('drawing_alias', 'SC0001', array('plugin' => 'baidu-ocr'))['action'] === 'none', '作用范围不匹配时不生效');
bglx_test_assert($c4->correct('drawing_alias', 'SC0001', array('plugin' => 'other-plugin'))['action'] === 'auto', '作用范围匹配时生效');

echo "\n--- D. CRUD、校验与导入 ---\n";

$store5 = bglx_test_store('crud');
$a = $store5->create(array(
    'type' => 'process_synonym', 'standard' => '车',
    'variants' => array('车削', '车工'), 'confidence' => 0.91,
));
bglx_test_assert((bool)preg_match('/^LX-\d{6}$/', $a['id']), 'create 分配 LX-000001 格式 ID', '实际 id=' . $a['id']);
bglx_test_assert($store5->get($a['id'])['standard'] === '车', 'get 能读到刚创建的词条');
$b = $store5->update($a['id'], array('standard' => '车削', 'confidence' => 0.93));
bglx_test_assert($b['standard'] === '车削' && (float)$b['confidence'] === 0.93, 'update 补丁生效');
bglx_test_assert($store5->remove($a['id']) === true && $store5->get($a['id']) === null, 'delete 后无法再读到词条');
$c = $store5->create(array('type' => 'unit_synonym', 'standard' => '件', 'variants' => array('只'), 'confidence' => 0.9));
$bglxMax = 0;
foreach ($store5->all() as $it) {
    if (preg_match('/^LX-(\d{6})$/', $it['id'], $mm)) {
        $bglxMax = max($bglxMax, (int)$mm[1]);
    }
}
bglx_test_assert((int)substr($c['id'], 3) > $bglxMax - 1 && $c['id'] !== $a['id'], '删除后 ID 不复用', '实际 id=' . $c['id']);

$invalidThrown = false;
try {
    $store5->create(array('type' => 'drawing_alias', 'standard' => '', 'variants' => array(), 'confidence' => 2));
} catch (BglxValidationException $e) {
    $invalidThrown = isset($e->errors['standard'], $e->errors['variants'], $e->errors['confidence']);
}
bglx_test_assert($invalidThrown, '非法词条触发 BglxValidationException 且返回字段级错误');

$store6 = bglx_test_store('import');
$imp = $store6->import("铣 = 线, 绕, 鉄\n钻 = 打眼, 钻孔攻丝\n# 注释行", 'procAlias', 'merge');
bglx_test_assert($imp['added'] === 2 && count($imp['errors']) === 0, 'procAlias 导入解析 2 条词条', json_encode($imp, JSON_UNESCAPED_UNICODE));
$imp2 = $store6->import("铣 = 线, 绕, 鉄", 'procAlias', 'merge');
bglx_test_assert($imp2['added'] === 0 && $imp2['skipped'] === 1, 'merge 模式跳过重复词条');
$csvThrown = false;
try {
    $store6->import('a,b,c', 'csv', 'merge');
} catch (RuntimeException $e) {
    $csvThrown = (strpos($e->getMessage(), '后续版本') !== false);
}
bglx_test_assert($csvThrown, 'CSV 导入明确提示未开放（P1）');

echo "\n=== 结果：" . $pass . " 通过 / " . $fail . " 失败 ===\n";
exit($fail === 0 ? 0 : 1);
