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
