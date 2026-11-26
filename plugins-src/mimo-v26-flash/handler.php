<?php
/**
 * mimo-v26-flash · 报工单拍照识别
 * 调用小米 MiMo 开放平台 OpenAI 兼容接口（mimo-v2.6-flash，全模态理解），
 * 按《OCR 插件接入文档》的执行契约返回归一化结果。
 */

if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

require __DIR__ . '/shared.php';

/* 手写字词库连接层（唯一入口）：一行引入 + 一行初始化，失败静默降级为未命中 */
$bglxBridgeFile = dirname(__DIR__) . '/handwriting-lexicon/lib/bridge.php';
if (is_file($bglxBridgeFile)) {
    require_once $bglxBridgeFile;
    bglx_bridge_init('mimo-v26-flash');
}

$ctx = $GLOBALS['BG_PLUGIN_CTX'];
$catalog = is_array($ctx['catalog']) ? $ctx['catalog'] : array();
$cfg = is_array($ctx['config']) ? $ctx['config'] : array();
$warnings = array();

/* ---------- 参数检查 ---------- */

$key = mimoocr_cfg($cfg, 'apiKey');
if ($key === '') {
    throw new RuntimeException('未配置 MiMo API Key，请到「识别插件设置」填写（platform.xiaomimimo.com 控制台获取）');
}

$endpoint = mimoocr_cfg($cfg, 'endpoint');
if ($endpoint === '') {
    $endpoint = MIMOOCR_DEFAULT_ENDPOINT;
}
if (substr(strtolower($endpoint), 0, 8) !== 'https://') {
    throw new RuntimeException('接口地址必须为 https:// 开头');
}
/* 安全：只允许官方域名，避免把 API Key 发往任意地址 */
$mimo_host = strtolower((string)parse_url($endpoint, PHP_URL_HOST));
if (!in_array($mimo_host, array('api.xiaomimimo.com', 'token-plan-cn.xiaomimimo.com'), true)) {
    throw new RuntimeException('接口地址不在官方域名白名单（api.xiaomimimo.com）内');
}

$model = mimoocr_cfg($cfg, 'model');
if ($model === '') {
    $model = MIMOOCR_DEFAULT_MODEL;
}

$thinking = in_array($cfg['thinking'] ?? '', array('enabled', 'disabled'), true) ? $cfg['thinking'] : 'disabled';
$maxTokens = mimoocr_int($cfg, 'maxTokens', 8192, 256, 131072);
$timeout = mimoocr_int($cfg, 'timeout', 45, 10, 180);
$retries = mimoocr_int($cfg, 'retries', 1, 0, 2);
$limit = mimoocr_int($cfg, 'catalogLimit', 200, 0, 300);
$skipTls = mimoocr_bool($cfg, 'skipTls');

$image = isset($ctx['image']) ? (string)$ctx['image'] : '';
if ($image === '') {
    throw new RuntimeException('没有收到图片，请重新拍照或选图');
}
$parts = explode(',', $image, 2);
$meta = count($parts) === 2 ? $parts[0] : '';
$payload = count($parts) === 2 ? $parts[1] : '';
if (stripos($meta, 'data:image/') !== 0 || stripos($meta, ';base64') === false) {
    throw new RuntimeException('图片必须是 base64 dataURL（data:image/...;base64,...）');
}
if ($payload === '') {
    throw new RuntimeException('图片内容为空，请重新拍照或选图');
}
if (strlen($payload) > 14000000) {
    throw new RuntimeException('图片太大，请压缩到 8MB 以内再试');
}

/* ---------- 零件库清单 ---------- */

$catalogLines = array();
foreach ($catalog as $idx => $part) {
    if (!is_array($part)) {
        continue;
    }
    $no = mimoocr_str(isset($part['no']) ? $part['no'] : '', 40);
    $name = mimoocr_str(isset($part['name']) ? $part['name'] : '', 40);
    $procs = array();
    if (isset($part['processes']) && is_array($part['processes'])) {
        foreach ($part['processes'] as $p) {
            $ps = mimoocr_str($p, 20);
            if ($ps !== '') {
                $procs[] = $ps;
            }
        }
    }
    $catalogLines[] = ($idx + 1) . '. ' . $no . ' | ' . $name . ' | ' . implode('、', $procs);
    if (count($catalogLines) >= $limit) {
        break;
    }
}
$catalogText = $catalogLines ? implode("\n", $catalogLines) : '（零件库为空）';

/* ---------- 组装请求（OpenAI Chat Completions 兼容） ---------- */

$prompt = mimoocr_cfg($cfg, 'prompt');
if ($prompt === '') {
    $prompt = mimoocr_default_prompt();
}
$prompt = str_replace('{TODAY}', (string)$ctx['today'], $prompt);
$prompt = str_replace('{CATALOG}', $catalogText, $prompt);

$body = array(
    'model' => $model,
    'messages' => array(
        array(
            'role' => 'user',
            'content' => array(
                array('type' => 'image_url', 'image_url' => array('url' => $image)),
                array('type' => 'text', 'text' => $prompt),
            ),
        ),
    ),
    'max_completion_tokens' => $maxTokens,
    'stream' => false,
    'thinking' => array('type' => $thinking),
);
if ($thinking !== 'enabled') {
    // 思考关闭时降低随机性，稳定输出 JSON；思考开启时服务端会强制为默认值
    $body['temperature'] = 0.2;
}

$headers = array(
    'Content-Type: application/json',
    'api-key: ' . $key, // MiMo 官方推荐的鉴权头（也可用 Authorization: Bearer）
    'Expect:',
);

$res = mimoocr_post($endpoint, $body, $headers, $timeout, $skipTls, $retries);

/* ---------- 响应与错误处理 ---------- */

if ($res['err'] !== '') {
    throw new RuntimeException('调用 MiMo 接口失败：' . $res['err'] . '。请检查服务器出网与接口地址，或调大「超时」「自动重试次数」');
}

$code = (int)$res['code'];
if ($code < 200 || $code >= 300) {
    $msg = '';
    $j = json_decode($res['body'], true);
    if (is_array($j)) {
        if (isset($j['error'])) {
            $e = $j['error'];
            if (is_string($e)) {
                $msg = $e;
            } elseif (is_array($e)) {
                if (isset($e['message']) && is_string($e['message'])) {
                    $msg = $e['message'];
                } elseif (isset($e['msg']) && is_string($e['msg'])) {
                    $msg = $e['msg'];
                }
            }
        }
        if ($msg === '' && isset($j['message']) && is_string($j['message'])) {
            $msg = $j['message'];
        }
    }
    $map = array(
        400 => '请求格式错误（HTTP 400）：参数或图片格式不符（图片仅支持 JPEG/PNG/GIF/WebP/BMP、≤50MB）',
        401 => '认证失败（HTTP 401）：API Key 无效，或 Token Plan 密钥与按量付费接口地址混用',
        402 => '余额不足（HTTP 402）：请到 MiMo 控制台充值',
        403 => '拒绝访问（HTTP 403）：服务暂不支持当前地区，或 API Key 被风控',
        404 => '资源不存在（HTTP 404）：接口地址或模型名有误，或该模型不支持图像输入',
        421 => '内容审核拦截（HTTP 421）：请更换图片或调整识别指令',
        429 => '请求超限（HTTP 429）：触发限流（该模型 RPM 100 / TPM 10M），或 Token Plan 额度耗尽',
        500 => 'MiMo 服务端故障（HTTP 500），重试后仍失败，请稍后再试',
        503 => 'MiMo 服务繁忙（HTTP 503），重试后仍失败，请稍后再试',
    );
    $head = isset($map[$code]) ? $map[$code] : ('HTTP ' . $code . '：请检查接口地址与网络');
    $msg = mimoocr_cut($msg, 160);
    throw new RuntimeException($head . ($msg !== '' ? '。' . $msg : ''));
}

$j = json_decode($res['body'], true);
if (!is_array($j)) {
    throw new RuntimeException('MiMo 返回不是合法 JSON：' . mimoocr_cut((string)$res['body'], 120));
}

$finish = '';
if (isset($j['choices'][0]['finish_reason']) && is_string($j['choices'][0]['finish_reason'])) {
    $finish = $j['choices'][0]['finish_reason'];
}

$content = '';
if (isset($j['choices'][0]['message']) && is_array($j['choices'][0]['message'])) {
    $message = $j['choices'][0]['message'];
    if (isset($message['content'])) {
        if (is_string($message['content'])) {
            $content = $message['content'];
        } elseif (is_array($message['content'])) {
            $buf = array();
            foreach ($message['content'] as $part) {
                if (is_string($part)) {
                    $buf[] = $part;
                } elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $buf[] = $part['text'];
                }
            }
            $content = implode('', $buf);
        }
    }
}
$content = trim($content);
if ($content === '') {
    if ($finish === 'length') {
        throw new RuntimeException('MiMo 输出达到最大 Token 上限且没有正文：请调大「最大输出 Token」'
            . ($thinking === 'enabled' ? '，或把思考模式改为「关闭」' : ''));
    }
    throw new RuntimeException('MiMo 没有返回识别内容，请检查模型名与接口地址是否正确');
}

if ($finish === 'length') {
    $warnings[] = $thinking === 'enabled'
        ? '输出达到最大 Token 上限，思考占用了额度：请调大「最大输出 Token」或关闭思考模式'
        : '识别内容可能不完整，可在设置中调大「最大输出 Token」';
} elseif ($finish === 'content_filter') {
    $warnings[] = '内容被平台审核拦截，部分字段可能缺失';
} elseif ($finish === 'repetition_truncation') {
    $warnings[] = '内容因重复被截断，请重新拍照识别';
}

// 密钥类型与接口地址是否匹配（401 的常见原因）
$lcEndpoint = strtolower($endpoint);
if (preg_match('/^(tp|ttp)-/', $key) && strpos($lcEndpoint, 'token-plan') === false) {
    $warnings[] = 'Token Plan 密钥需配合 token-plan 专属接口地址，否则会认证失败';
} elseif (substr($key, 0, 3) === 'sk-' && strpos($lcEndpoint, 'token-plan') !== false) {
    $warnings[] = '按量付费密钥与 Token Plan 接口地址不匹配，请核对密钥与地址';
} elseif (!preg_match('/^(sk|tp|ttp)-/', $key)) {
    $warnings[] = 'API Key 前缀异常，请确认是 MiMo 开放平台的密钥';
}

/* ---------- 解析与归一化 ---------- */

$clean = mimoocr_extract_json($content);
$data = null;
$parseNote = '';
if ($clean !== '') {
    $decode = json_decode($clean, true);
    if (is_array($decode)) {
        $data = $decode;
    } else {
        $parseNote = '模型返回的 JSON 无法解析，已按文本规则解析原文';
    }
} else {
    $parseNote = '未返回 JSON，已按文本规则解析原文';
}

$fallback = function () use ($content, $catalogLines) {
    return mimoocr_fallback($content, $catalogLines);
};

if (is_array($data)) {
    $items = array();
    $rawItems = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : array();
    if (is_array($rawItems)) {
        foreach ($rawItems as $it) {
            if (!is_array($it)) {
                continue;
            }
            $price = isset($it['price']) && is_numeric($it['price']) ? (float)$it['price'] : null;
            $items[] = array(
                'partNo' => mimoocr_str(mimoocr_field(isset($it['partNo']) ? $it['partNo'] : null, ''), 40),
                'partName' => mimoocr_str(mimoocr_field(isset($it['partName']) ? $it['partName'] : null, ''), 40),
                'process' => mimoocr_str(mimoocr_field(isset($it['process']) ? $it['process'] : null, ''), 40),
                'qty' => mimoocr_qty(mimoocr_field(isset($it['qty']) ? $it['qty'] : null, 0)),
                'price' => $price,
            );
            if (count($items) >= 20) {
                break;
            }
        }
    }
    $fields = array(
        'date' => mimoocr_date(mimoocr_field(isset($data['date']) ? $data['date'] : null, ''), $ctx['today']),
        'worker' => mimoocr_str(mimoocr_field(isset($data['worker']) ? $data['worker'] : null, ''), 40),
        'workshop' => mimoocr_str(mimoocr_field(isset($data['workshop']) ? $data['workshop'] : null, ''), 40),
        'note' => mimoocr_str(mimoocr_field(isset($data['note']) ? $data['note'] : null, ''), 100),
        'items' => $items,
    );
    if (!$items) {
        $warnings[] = '未识别到报工明细行，请检查图片或改用手动填写';
    }
    if ($fields['date'] === '') {
        $warnings[] = '未识别到报工日期，请手动补填';
    }
    if ($parseNote !== '') {
        $warnings[] = $parseNote;
    }
    $confRaw = (isset($data['confidence']) && !is_array($data['confidence']) && $data['confidence'] !== '' && $data['confidence'] !== null)
        ? $data['confidence'] : null;
    $confidence = is_numeric($confRaw) ? (float)$confRaw : 0.5;
} else {
    $fb = $fallback();
    $fields = $fb['fields'];
    $warnings = array_merge($warnings, $fb['warnings']);
    if ($parseNote !== '') {
        $warnings[] = $parseNote;
    }
    $confidence = 0.3;
}

/* 字库连接层：normal data 与 fallback 两条分支共用同一处批量归一 */
if (function_exists('bglx_bridge_norm_fields')) {
    $bglxNorm = bglx_bridge_norm_fields($fields, array('plugin' => 'mimo-v26-flash'));
    if (isset($bglxNorm['fields']) && is_array($bglxNorm['fields'])) {
        $fields = $bglxNorm['fields'];
    }
    foreach (bglx_bridge_suggestions() as $sug) {
        $warnings[] = '字库建议：' . $sug . '，请人工确认';
    }
}

// 去重、限长：最多 5 条告警，每条不超过 80 字
$uniq = array();
foreach ($warnings as $w) {
    $w = mimoocr_str($w, 80);
    if ($w === '' || in_array($w, $uniq, true)) {
        continue;
    }
    $uniq[] = $w;
    if (count($uniq) >= 5) {
        break;
    }
}

/* ---------- 原文 ---------- */

/* 给界面看的「识别原文」：只列识别到的字段和明细行，不把模型 JSON 原样倒出来。
   明细行形如「24971B05 铣孔 80件」，点一行即可在下方继续手动补填。 */
$rawLines = array();
if ($fields['date'] !== '') {
    $rawLines[] = '日期：' . $fields['date'];
}
if ($fields['worker'] !== '') {
    $rawLines[] = '报工人：' . $fields['worker'];
}
if ($fields['workshop'] !== '') {
    $rawLines[] = '车间：' . $fields['workshop'];
}
if ($fields['note'] !== '') {
    $rawLines[] = '备注：' . $fields['note'];
}
foreach ($fields['items'] as $it) {
    $label = $it['partNo'] !== '' ? $it['partNo'] : $it['partName'];
    if ($label === '') {
        $label = '未识别零件';
    } elseif ($it['partName'] !== '' && $it['partName'] !== $label) {
        $label .= '（' . $it['partName'] . '）';
    }
    if ($it['process'] !== '') {
        $label .= ' ' . $it['process'];
    }
    if ((float)$it['qty'] > 0) {
        $label .= ' ' . $it['qty'] . '件';
    }
    $rawLines[] = $label;
}
$raw = $rawLines ? implode("\n", $rawLines) : $mimoocr_cut($content, 400);
$raw = mimoocr_cut($raw, 4000);

return array(
    'engine' => mimoocr_str('小米 MiMo ' . $model, 40),
    'confidence' => max(0, min(1, (float)$confidence)),
    'fields' => $fields,
    'warnings' => $uniq,
    'raw' => $raw,
);
