<?php
/*
 * 多 OCR 融合引擎 · 自检脚本
 * 校验参与融合的引擎是否安装 / 启用，以及阈值配置是否合理。
 * 不发起外部网络请求，设置页点「API 链接测试」即可快速定位配置问题。
 */

if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

$ctx = $GLOBALS['BG_PLUGIN_CTX'];
$cfg = isset($ctx['config']) && is_array($ctx['config']) ? $ctx['config'] : array();
$selfId = isset($ctx['id']) ? (string)$ctx['id'] : 'ocr-ensemble';

function ocrens_check_ids($raw)
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

function ocrens_check_ratio($cfg, $key, $default)
{
    if (!isset($cfg[$key]) || trim((string)$cfg[$key]) === '') {
        return array($default, true);
    }
    $raw = trim((string)$cfg[$key]);
    if (!is_numeric($raw)) {
        return array(null, false);
    }
    $v = (float)$raw;
    return array($v, $v >= 0 && $v <= 1);
}

$all = bg_plugin_all();
$want = ocrens_check_ids(isset($cfg['engines']) ? $cfg['engines'] : '');
$auto = !$want;
$details = array();
$usable = 0;

if ($auto) {
    foreach ($all as $p) {
        if ($p['id'] === $selfId || !in_array('report.ocr', $p['capabilities'], true)) {
            continue;
        }
        if (!bg_plugin_is_enabled($p['id'])) {
            continue;
        }
        $usable++;
        $details[] = array(
            'label' => $p['name'],
            'ok' => true,
            'detail' => 'v' . $p['version'] . ' · 自动纳入（已启用）',
        );
    }
} else {
    foreach ($want as $id) {
        if ($id === $selfId) {
            $details[] = array('label' => $id, 'ok' => false, 'detail' => '融合引擎不能把自己作为子引擎');
            continue;
        }
        if (!isset($all[$id])) {
            $details[] = array('label' => $id, 'ok' => false, 'detail' => '未安装或目录名与 id 不一致');
            continue;
        }
        $p = $all[$id];
        if (!in_array('report.ocr', $p['capabilities'], true)) {
            $details[] = array('label' => $p['name'], 'ok' => false, 'detail' => '未声明 report.ocr 能力');
            continue;
        }
        if (!bg_plugin_is_enabled($id)) {
            $details[] = array('label' => $p['name'], 'ok' => false, 'detail' => '已停用，请先在设置页启用');
            continue;
        }
        $usable++;
        $details[] = array(
            'label' => $p['name'],
            'ok' => true,
            'detail' => 'v' . $p['version'] . ' · 已启用',
        );
    }
}

list($agreeThreshold, $agreeOk) = ocrens_check_ratio($cfg, 'agreeThreshold', 0.85);
$details[] = array(
    'label' => '一致判定阈值',
    'ok' => $agreeOk,
    'detail' => $agreeOk
        ? '相似度 ≥ ' . number_format((float)$agreeThreshold, 2) . ' 视为一致'
        : '必须是 0~1 之间的数字',
);

list($minConfidence, $minOk) = ocrens_check_ratio($cfg, 'minConfidence', 0.6);
$details[] = array(
    'label' => '最低置信度',
    'ok' => $minOk,
    'detail' => $minOk
        ? '低于 ' . number_format((float)$minConfidence * 100) . '% 提示人工复核'
        : '必须是 0~1 之间的数字',
);

/* ---- OCF 增强层自检：信誉/校准文件、连接层、黄金样本集 ---- */
function ocf_check_dir()
{
    if (isset($GLOBALS['DATA_DIR']) && is_string($GLOBALS['DATA_DIR']) && $GLOBALS['DATA_DIR'] !== '') {
        return rtrim(str_replace('\\', '/', $GLOBALS['DATA_DIR']), '/');
    }
    return dirname(__DIR__, 3) . '/data';
}
function ocf_check_json_writable($path)
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return array(false, '目录不可写：' . $dir);
    }
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '' || json_decode($raw, true) === null) {
            return array(false, 'JSON 损坏，运行时将自动重建/恒等回退：' . basename($path));
        }
        return array(true, basename($path) . ' 存在且可解析（可重建）');
    }
    return array(true, '尚未生成（首次高可信识别或离线工具会创建）');
}

$ocfDataDir = ocf_check_dir();
list($repOk, $repDetail) = ocf_check_json_writable($ocfDataDir . '/ocr-fusion/reputation.json');
$details[] = array('label' => '字段级信誉文件', 'ok' => $repOk, 'detail' => $repDetail);
list($calOk, $calDetail) = ocf_check_json_writable($ocfDataDir . '/ocr-fusion/calibration.json');
$details[] = array('label' => '校准参数文件', 'ok' => $calOk, 'detail' => $calDetail);

$bridgeFile = dirname(__DIR__) . '/handwriting-lexicon/lib/bridge.php';
$bridgeOk = is_file($bridgeFile);
$details[] = array(
    'label' => '字库连接层',
    'ok' => $bridgeOk,
    'detail' => $bridgeOk ? 'bridge.php 已就绪（缺失时自动降级为未命中，不阻断识别）' : '未找到 bridge.php，L2 字库联动将降级',
);

$goldenFile = __DIR__ . '/tests/golden/manifest.json';
$goldenOk = is_file($goldenFile);
$details[] = array(
    'label' => '黄金样本集',
    'ok' => $goldenOk,
    'detail' => $goldenOk ? 'manifest.json 已就绪，可用 tools/eval.php 评估' : '未找到 tests/golden/manifest.json',
);

$traceDir = $ocfDataDir . '/ocr-logs';
$traceWritable = is_dir($traceDir) ? is_writable($traceDir) : is_writable(dirname($traceDir));
$details[] = array(
    'label' => '裁决链路 trace',
    'ok' => $traceWritable,
    'detail' => $traceWritable ? 'fusion-trace 可写入（旁路 JSONL，invokeId 关联主日志）' : 'ocr-logs 目录不可写，trace 将跳过',
);

$ok = $usable >= 1 && $agreeOk && $minOk;
if ($usable < 2) {
    $details[] = array(
        'label' => '交叉校验',
        'ok' => false,
        'detail' => $usable === 0 ? '没有可用引擎' : '建议至少 2 路引擎，3 路可容一个错误多数',
    );
}

if (!$ok) {
    $message = $usable === 0
        ? '没有可用的识别引擎，请检查参与引擎设置'
        : '配置有误，请查看下方明细';
} elseif ($usable < 2) {
    $message = '配置可用，但仅 1 路引擎，无法交叉校验';
} else {
    $message = '配置可用：' . $usable . ' 路引擎参与融合';
}

return array(
    'ok' => $ok,
    'message' => $message,
    'details' => $details,
);
