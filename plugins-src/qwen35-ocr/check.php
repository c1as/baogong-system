<?php
/*
 * 通义千问 Qwen3.5-OCR 插件自检（设置页「API 链接测试」调用，不受启用状态影响）
 * 用一次极小请求同时验证：密钥、出网、接口地址、模型名。
 */

if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/shared.php';

$ctx = $GLOBALS['BG_PLUGIN_CTX'];
$cfg = (isset($ctx['config']) && is_array($ctx['config'])) ? $ctx['config'] : array();

$api_key  = qwenocr_cfg($cfg, 'apiKey', '');
$endpoint = qwenocr_cfg($cfg, 'endpoint', QWENOCR_DEFAULT_ENDPOINT);
$model    = qwenocr_cfg($cfg, 'model', QWENOCR_DEFAULT_MODEL);
$skip_tls = qwenocr_bool($cfg, 'skipTls', false);

/* 自检不跟随识别超时，单独限制在 10~40 秒 */
$timeout = qwenocr_int($cfg, 'timeout', 30, 5, 120);
$timeout = max(10, min(40, $timeout));

$details = array();

$ok_key = ($api_key !== '');
$ok_url = (bool)preg_match('#^https://[^[:space:]]+$#i', $endpoint);
$qwen_host = strtolower((string)parse_url($endpoint, PHP_URL_HOST));
if ($ok_url && !in_array($qwen_host, array('dashscope.aliyuncs.com', 'dashscope-intl.aliyuncs.com'), true)) {
    $ok_url = false;
}

$detail_key = '未填写';
if ($ok_key) {
    $detail_key = (strpos($api_key, 'sk-') === 0)
        ? '已填写，长度 ' . strlen($api_key) . ' 字符'
        : '已填写，但不以 sk- 开头，请确认是百炼密钥';
}
$details[] = array('label' => 'API Key', 'ok' => $ok_key, 'detail' => $detail_key);

$ca = qwenocr_ca();
$details[] = array(
    'label'  => '出网方式',
    'ok'     => function_exists('curl_init') || function_exists('stream_socket_client'),
    'detail' => (function_exists('curl_init') ? 'curl' : 'SSL socket')
              . '，CA：' . ($skip_tls ? '已跳过校验' : ($ca !== '' ? '已加载' : '使用系统默认')),
);

if (!$ok_url) {
    $details[] = array('label' => '接口地址', 'ok' => false, 'detail' => '必须是 https://dashscope.aliyuncs.com 官方域名：' . qwenocr_cut($endpoint, 100));
    return array(
        'ok'      => false,
        'message' => '接口地址格式不正确，应填 https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions',
        'details' => array_slice($details, 0, 10),
    );
}
$details[] = array('label' => '接口地址', 'ok' => true, 'detail' => qwenocr_cut($endpoint, 120));
$details[] = array('label' => '模型', 'ok' => ($model !== ''), 'detail' => $model !== '' ? $model : '未填写，将用默认 qwen3.5-ocr');

if (!$ok_key) {
    return array(
        'ok'      => false,
        'message' => '请先填写阿里云百炼的 API Key（控制台「API-KEY 管理」里 sk- 开头的密钥）',
        'details' => array_slice($details, 0, 10),
    );
}

/* 最小请求：不带图片，只让模型回两个字，验证鉴权与模型可用性 */
$body = array(
    'model'      => $model,
    'messages'   => array(
        array('role' => 'user', 'content' => array(
            array('type' => 'text', 'text' => '只回复两个字：正常'),
        )),
    ),
    'max_tokens' => 8,
    'stream'     => false,
);

$start = microtime(true);
$http  = qwenocr_http_post(
    $endpoint,
    json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    array('Authorization: Bearer ' . $api_key, 'Content-Type: application/json'),
    $timeout,
    $skip_tls
);
$ms = (int)round((microtime(true) - $start) * 1000);

if ($http['err'] !== '') {
    $details[] = array('label' => '接口连通', 'ok' => false, 'detail' => qwenocr_cut($http['err'], 140));
    return array(
        'ok'      => false,
        'message' => '服务器没能连上千问接口，请检查出网、防火墙或代理（耗时 ' . $ms . 'ms）',
        'details' => array_slice($details, 0, 10),
    );
}
$details[] = array('label' => '接口连通', 'ok' => true, 'detail' => 'HTTP ' . $http['code'] . '，耗时 ' . $ms . 'ms');

$resp = json_decode($http['body'], true);
$api_err = '';
if (is_array($resp)) {
    if (isset($resp['error']['message'])) $api_err = (string)$resp['error']['message'];
    elseif (isset($resp['message'])) $api_err = (string)$resp['message'];
}

$ok_call = true;
$call_detail = '模型可正常应答';
if (is_array($resp) && isset($resp['choices'][0]['message']['content'])) {
    $call_detail = '模型应答：' . qwenocr_str($resp['choices'][0]['message']['content'], 40);
} elseif ($http['code'] === 200) {
    $call_detail = '返回 200，但结构不是 OpenAI 兼容格式，请核对接口地址';
    $ok_call = false;
} else {
    $ok_call = false;
    if ($http['code'] === 401 || $http['code'] === 403) {
        $call_detail = '鉴权未通过：' . ($api_err !== '' ? qwenocr_cut($api_err, 100) : '请核对 API Key 与地域');
    } elseif ($http['code'] === 404) {
        $call_detail = '接口或模型不存在：' . ($api_err !== '' ? qwenocr_cut($api_err, 100) : $model);
    } elseif ($http['code'] === 429) {
        $call_detail = '网络与密钥可达，但被限流或额度用尽';
    } else {
        $call_detail = 'HTTP ' . $http['code'] . '：' . ($api_err !== '' ? qwenocr_cut($api_err, 100) : '参数被拒绝，鉴权通常已通过');
    }
}
$details[] = array('label' => '模型调用', 'ok' => $ok_call, 'detail' => $call_detail);

$all_ok = $ok_key && $ok_url && $ok_call;

return array(
    'ok'      => $all_ok,
    'message' => $all_ok
        ? ('配置可用，' . $model . ' 调用成功，耗时 ' . $ms . 'ms')
        : '接口可达但调用未完全通过：' . qwenocr_cut($call_detail, 90),
    'details' => array_slice($details, 0, 10),
);
