<?php
/**
 * 百度云 OCR 自检脚本（设置页的「API 链接测试」）
 *
 * 由 api.php?action=plugin.check 加载，返回：
 *   array('ok' => bool, 'message' => '结论', 'details' => [['label'=>'', 'ok'=>bool, 'detail'=>'']])
 * 会真实请求一次百度云 token 接口，用于验证网络、密钥与接口地址。
 */

/* 仅允许由 api.php 加载，禁止直接访问 */
if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/lib.php';

$ctx = $GLOBALS['BG_PLUGIN_CTX'];
$cfg = isset($ctx['config']) && is_array($ctx['config']) ? $ctx['config'] : array();
$c = bg_baidu_cfg($cfg);

$details = array();

$curl = function_exists('curl_init');
$details[] = array(
    'label' => 'PHP curl 扩展',
    'ok' => $curl,
    'detail' => $curl ? '已启用' : '未启用，服务器无法访问百度云',
);

$keyOk = $c['apiKey'] !== '' && $c['secretKey'] !== '';
$details[] = array(
    'label' => '密钥配置',
    'ok' => $keyOk,
    'detail' => $keyOk ? 'API Key 与 Secret Key 已保存' : '请填写 API Key 与 Secret Key 并先保存',
);

$details[] = array(
    'label' => '接口地址',
    'ok' => true,
    'detail' => $c['endpoint'],
);
$typeLabels = array(
    'accurate_location' => '高精度含位置版 accurate（可还原表格行）',
    'accurate_basic' => '高精度版 accurate_basic（无坐标）',
    'general_basic' => '标准版 general_basic',
);
$details[] = array(
    'label' => '识别类型',
    'ok' => true,
    'detail' => isset($typeLabels[$c['ocrType']]) ? $typeLabels[$c['ocrType']] : $c['ocrType'],
);
$details[] = array(
    'label' => '证书校验',
    'ok' => true,
    'detail' => $c['insecure'] ? '已跳过（调试模式，正式环境请关闭）' : '严格校验',
);

$aliasRaw = isset($c['procAlias']) ? trim((string)$c['procAlias']) : '';
$aliasCount = 0;
if ($aliasRaw !== '') {
    foreach (preg_split('/\r\n|\r|\n/', $aliasRaw) as $ln) {
        $ln = trim($ln);
        if ($ln !== '' && !preg_match('/^[#;]/', $ln) && preg_match('/^([^:：=]+)[:：=](.+)$/u', $ln)) {
            $aliasCount++;
        }
    }
}
$details[] = array(
    'label' => '工序同义字典',
    'ok' => true,
    'detail' => $aliasCount > 0
        ? '自定义 ' . $aliasCount . ' 条（与内置字典合并）'
        : '未配置自定义，使用内置字典',
);

if (!$keyOk) {
    return array(
        'ok' => false,
        'message' => '缺少密钥：填写 API Key 与 Secret Key 并保存后再测试',
        'details' => $details,
    );
}

try {
    $t0 = microtime(true);
    $token = bg_baidu_token($cfg, true);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $head = substr($token, 0, 6);
    $details[] = array(
        'label' => '获取 access_token',
        'ok' => true,
        'detail' => '成功 · ' . $ms . ' ms · ' . $head . '…（' . strlen($token) . ' 字符）',
    );
    return array(
        'ok' => true,
        'message' => '接口连接正常，密钥有效（' . $ms . ' ms）',
        'details' => $details,
    );
} catch (Throwable $e) {
    $details[] = array(
        'label' => '获取 access_token',
        'ok' => false,
        'detail' => $e->getMessage(),
    );
    return array(
        'ok' => false,
        'message' => '接口测试失败：' . $e->getMessage(),
        'details' => $details,
    );
}
