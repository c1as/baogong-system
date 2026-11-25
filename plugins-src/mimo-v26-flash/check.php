<?php
/**
 * mimo-v26-flash · 自检：设置页「API 链接测试」
 * 校验 API Key、接口地址、出网方式，并做一次最小的模型调用。
 */

if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

require __DIR__ . '/shared.php';

$cfg = isset($GLOBALS['BG_PLUGIN_CTX']['config']) && is_array($GLOBALS['BG_PLUGIN_CTX']['config'])
    ? $GLOBALS['BG_PLUGIN_CTX']['config'] : array();
$details = array();
$ok = true;

/* ---------- 配置检查 ---------- */

$key = mimoocr_cfg($cfg, 'apiKey');
$keyOk = $key !== '';
if ($keyOk) {
    if (substr($key, 0, 3) === 'sk-') {
        $kind = '按量付费密钥（sk-）';
    } elseif (substr($key, 0, 3) === 'tp-') {
        $kind = 'Token Plan 密钥（tp-）';
    } elseif (substr($key, 0, 4) === 'ttp-') {
        $kind = 'Token Plan 团队版密钥（ttp-）';
    } else {
        $kind = '前缀未知，可能不是 MiMo 密钥';
    }
    $details[] = array('label' => 'API Key', 'ok' => true, 'detail' => '已填写，长度 ' . strlen($key) . '，' . $kind);
} else {
    $ok = false;
    $details[] = array('label' => 'API Key', 'ok' => false, 'detail' => '请填写 API Key');
}

$endpoint = mimoocr_cfg($cfg, 'endpoint');
if ($endpoint === '') {
    $endpoint = MIMOOCR_DEFAULT_ENDPOINT;
}
$endpointOk = substr(strtolower($endpoint), 0, 8) === 'https://';
$details[] = array('label' => '接口地址', 'ok' => $endpointOk, 'detail' => $endpointOk ? mimoocr_cut($endpoint, 120) : '必须为 https:// 开头的地址');
if (!$endpointOk) {
    $ok = false;
}

$model = mimoocr_cfg($cfg, 'model');
if ($model === '') {
    $model = MIMOOCR_DEFAULT_MODEL;
}
$details[] = array('label' => '模型', 'ok' => true, 'detail' => mimoocr_cut($model, 60));

// 密钥类型与接口地址的匹配性（401 的头号原因）
if ($keyOk && $endpointOk) {
    $lc = strtolower($endpoint);
    if (preg_match('/^(tp|ttp)-/', $key) && strpos($lc, 'token-plan') === false) {
        $ok = false;
        $details[] = array('label' => '密钥与地址匹配', 'ok' => false, 'detail' => 'Token Plan 密钥需配合 token-plan 专属接口地址');
    } elseif (substr($key, 0, 3) === 'sk-' && strpos($lc, 'token-plan') !== false) {
        $ok = false;
        $details[] = array('label' => '密钥与地址匹配', 'ok' => false, 'detail' => '按量付费密钥与 Token Plan 接口地址不匹配');
    } elseif (!preg_match('/^(sk|tp|ttp)-/', $key)) {
        $ok = false;
        $details[] = array('label' => '密钥与地址匹配', 'ok' => false, 'detail' => '密钥前缀未知，应为 sk-/tp-/ttp-');
    } else {
        $details[] = array('label' => '密钥与地址匹配', 'ok' => true, 'detail' => '密钥类型与接口地址一致');
    }
}

if (function_exists('bg_http')) {
    $net = 'lib/net.php 的 bg_http（系统统一出网，带 CA 与代理）';
} elseif (function_exists('curl_init')) {
    $net = 'cURL 扩展';
} else {
    $net = '原生 Socket（无 cURL 扩展）';
}
$details[] = array('label' => '出网方式', 'ok' => true, 'detail' => $net);

/* ---------- 真实调用一次（文本最小请求，关闭思考以加快自检） ---------- */

if ($ok) {
    $timeout = max(10, min(40, mimoocr_int($cfg, 'timeout', 45, 10, 180)));
    $retries = mimoocr_int($cfg, 'retries', 1, 0, 2);
    $body = array(
        'model' => $model,
        'messages' => array(
            array('role' => 'user', 'content' => '只回复两个字：正常'),
        ),
        'max_completion_tokens' => 8,
        'stream' => false,
        'thinking' => array('type' => 'disabled'),
    );
    $headers = array(
        'Content-Type: application/json',
        'api-key: ' . $key,
        'Expect:',
    );

    $res = mimoocr_post($endpoint, $body, $headers, $timeout, false, $retries);
    if ($res['err'] !== '') {
        $ok = false;
        $details[] = array('label' => '接口连通', 'ok' => false, 'detail' => mimoocr_cut('连接失败：' . $res['err'], 160));
    } elseif ($res['code'] < 200 || $res['code'] >= 300) {
        $ok = false;
        $msg = '';
        $j = json_decode($res['body'], true);
        if (is_array($j) && isset($j['error'])) {
            $e = $j['error'];
            if (is_string($e)) {
                $msg = $e;
            } elseif (is_array($e) && isset($e['message']) && is_string($e['message'])) {
                $msg = $e['message'];
            }
        }
        $map = array(
            400 => '请求格式错误，检查参数',
            401 => '认证失败：Key 无效，或 Token Plan 密钥与按量付费地址混用',
            402 => '余额不足，请到 MiMo 控制台充值',
            403 => '拒绝访问：地区限制或 Key 被风控',
            404 => '资源不存在：接口地址/模型名有误，或不支持图像输入',
            421 => '内容审核拦截',
            429 => '触发限流（RPM 100 / TPM 10M）或 Token Plan 额度耗尽',
            500 => 'MiMo 服务端故障，请稍后重试',
            503 => 'MiMo 服务繁忙，请稍后重试',
        );
        $code = (int)$res['code'];
        $head = isset($map[$code]) ? $map[$code] : '请检查接口地址与网络';
        $msg = mimoocr_cut($msg, 80);
        $details[] = array('label' => '接口连通', 'ok' => false, 'detail' => mimoocr_cut('HTTP ' . $code . '：' . $head . ($msg !== '' ? '。' . $msg : ''), 160));
    } else {
        $details[] = array('label' => '接口连通', 'ok' => true, 'detail' => 'HTTP 200，耗时 ' . $res['ms'] . 'ms，重试 ' . max(0, $res['attempts'] - 1) . ' 次');
        $j = json_decode($res['body'], true);
        $content = '';
        if (is_array($j) && isset($j['choices'][0]['message']['content'])) {
            $c = $j['choices'][0]['message']['content'];
            if (is_string($c)) {
                $content = trim($c);
            } elseif (is_array($c)) {
                $buf = array();
                foreach ($c as $part) {
                    if (is_string($part)) {
                        $buf[] = $part;
                    } elseif (is_array($part) && isset($part['text'])) {
                        $buf[] = (string)$part['text'];
                    }
                }
                $content = trim(implode('', $buf));
            }
        }
        if ($content !== '') {
            $details[] = array('label' => '模型调用', 'ok' => true, 'detail' => mimoocr_cut($model . ' 回复「' . $content . '」', 160));
        } else {
            $ok = false;
            $details[] = array('label' => '模型调用', 'ok' => false, 'detail' => 'HTTP 200 但没有返回正文，请检查模型名是否正确');
        }
    }
}

/* ---------- 结论 ---------- */

$bad = array();
foreach ($details as $d) {
    if (!$d['ok']) {
        $bad[] = $d['detail'];
    }
}
$message = $ok
    ? 'MiMo 接口与模型调用正常；图片识别能力请在「拍照识别」中用一张真实报工单照片验证'
    : mimoocr_cut('未通过：' . ($bad ? implode('；', $bad) : '未知问题'), 160);

return array(
    'ok' => $ok,
    'message' => $message,
    'details' => $details,
);
