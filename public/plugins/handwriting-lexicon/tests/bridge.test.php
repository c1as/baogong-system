<?php
/**
 * 手写字词库 · 连接层单元测试（零依赖）
 *
 * 运行：php tests/bridge.test.php
 * 覆盖：auto / suggest / none、批量归一、熔断降级、词库文件缺失降级。
 * 说明：连接层是请求级静态状态；熔断用例放在最后，避免污染前序用例。
 */

require_once dirname(__DIR__) . '/lib/bridge.php';

$pass = 0;
$fail = 0;

/**
 * 断言辅助。
 *
 * @param bool   $condition 断言条件
 * @param string $label     用例名
 * @param string $detail    失败说明
 */
function bglx_bridge_assert($condition, $label, $detail = '')
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

/**
 * 创建隔离的临时词库目录，并复制种子数据。
 *
 * @param string $name     目录名
 * @param bool   $withSeed 是否复制种子
 * @return string 数据目录绝对路径
 */
function bglx_bridge_tmpdir($name, $withSeed = true)
{
    $dir = rtrim(sys_get_temp_dir(), '/') . '/bglx-bridge-' . getmypid() . '-' . $name;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $target = $dir . '/handwriting_lexicon.json';
    @unlink($target);
    if ($withSeed) {
        @copy(dirname(__DIR__) . '/fixtures/demo-entries.json', $target);
    }
    return $dir;
}

echo "=== 手写字词库连接层单元测试 ===\n\n";

/* --- A. 正常加载 + auto / suggest / none --- */
$GLOBALS['DATA_DIR'] = bglx_bridge_tmpdir('seed', true);
if (function_exists('bglx_reload')) {
    bglx_reload();
}
$ok = bglx_bridge_init('bridge-test', array());
bglx_bridge_assert($ok === true, 'A1 init 返回可用', '实际 available=' . var_export($ok, true));

$r = bglx_bridge_norm('partno', '2L59711305', array('plugin' => '*'));
bglx_bridge_assert(
    $r['action'] === 'auto' && $r['value'] === '24971b05' && $r['degraded'] === false && $r['rule'] !== '',
    'A2 图号 auto 命中归一为 24971b05 且规则非空',
    json_encode($r, JSON_UNESCAPED_UNICODE)
);

$r = bglx_bridge_norm('process', '铣2', array('plugin' => '*'));
bglx_bridge_assert(
    $r['action'] === 'suggest' && $r['value'] === '铣2',
    'A3 工序 suggest 只提示不改值',
    json_encode($r, JSON_UNESCAPED_UNICODE)
);

$sug = bglx_bridge_suggestions();
bglx_bridge_assert(count($sug) >= 1, 'A4 suggest 提示已缓存', json_encode($sug, JSON_UNESCAPED_UNICODE));

$r = bglx_bridge_norm('partno', 'ZZZ_NOT_FOUND', array('plugin' => '*'));
bglx_bridge_assert(
    $r['action'] === 'none' && $r['value'] === 'ZZZ_NOT_FOUND',
    'A5 未命中原样返回',
    json_encode($r, JSON_UNESCAPED_UNICODE)
);

/* --- B. 批量归一 --- */
$fields = array(
    'date' => '2026-11-24',
    'items' => array(
        array('partNo' => '2L59711305', 'partName' => '轴套', 'process' => '铣2', 'qty' => 50),
        array('partNo' => '307437/61', 'partName' => '齿轮', 'process' => '钻孔', 'qty' => 20),
    ),
);
$n = bglx_bridge_norm_fields($fields, array('plugin' => '*'));
bglx_bridge_assert(
    isset($n['fields'], $n['changes'], $n['suggestions'])
    && $n['fields']['items'][0]['partNo'] === '24971b05'
    && $n['fields']['items'][1]['partNo'] === '307437r61'
    && count($n['changes']) === 2,
    'B1 批量归一改写两处图号并记录变更',
    json_encode($n, JSON_UNESCAPED_UNICODE)
);
bglx_bridge_assert($n['fields']['items'][0]['process'] === '铣2', 'B2 suggest 工序不被批量改写');

/* --- C. 状态结构 --- */
$status = bglx_bridge_status();
bglx_bridge_assert(
    isset($status['available'], $status['degraded'], $status['failCount'], $status['version'])
    && $status['degraded'] === false && $status['failCount'] === 0,
    'C1 status 结构合法且未降级',
    json_encode($status, JSON_UNESCAPED_UNICODE)
);

/* --- D. 词库文件缺失降级（用独立进程验证 init 行为） --- */
$missingDir = bglx_bridge_tmpdir('missing', false);
$probe = '<?php' . PHP_EOL
    . 'require ' . var_export(dirname(__DIR__) . '/lib/bridge.php', true) . ';' . PHP_EOL
    . '$GLOBALS["DATA_DIR"]=' . var_export($missingDir, true) . ';' . PHP_EOL
    . 'bglx_bridge_init("bridge-test-missing");' . PHP_EOL
    . '$s=bglx_bridge_status();' . PHP_EOL
    . '$r=bglx_bridge_norm("partno","2L59711305");' . PHP_EOL
    . 'echo json_encode(array("degraded"=>$s["degraded"],"action"=>$r["action"],"degraded_r"=>$r["degraded"]));';
$probeFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bglx-probe-' . getmypid() . '.php';
file_put_contents($probeFile, $probe);
$nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
$probeOut = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile) . ' 2>' . $nullDevice);
@unlink($probeFile);
$probeData = json_decode((string)$probeOut, true);
bglx_bridge_assert(
    is_array($probeData) && $probeData['degraded'] === true
    && $probeData['action'] === 'none' && $probeData['degraded_r'] === true,
    'D1 词库文件缺失时 degraded 且 norm 原样返回',
    '实际输出=' . var_export($probeOut, true)
);

/* --- E. 请求级熔断（连续失败 ≥3 次后短路） --- */
bglx_bridge_mark_fail();
bglx_bridge_mark_fail();
$sBefore = bglx_bridge_status();
bglx_bridge_assert($sBefore['failCount'] === 2 && $sBefore['degraded'] === false, 'E1 两次失败尚未熔断');
bglx_bridge_mark_fail();
$sAfter = bglx_bridge_status();
bglx_bridge_assert($sAfter['degraded'] === true, 'E2 第三次失败触发熔断');
$r = bglx_bridge_norm('partno', '2L59711305');
bglx_bridge_assert(
    $r['action'] === 'none' && $r['degraded'] === true && $r['rule'] === 'degraded',
    'E3 熔断后短路为 none',
    json_encode($r, JSON_UNESCAPED_UNICODE)
);

echo "\n=== 结果：通过 " . $pass . " 项，失败 " . $fail . " 项 ===\n";
exit($fail > 0 ? 1 : 0);
