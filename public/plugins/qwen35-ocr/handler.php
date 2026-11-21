<?php
/*
 * 报工识别插件 · 通义千问 Qwen3.5-OCR（阿里云百炼 DashScope，OpenAI 兼容接口）
 *
 * 入口文件：由 public/api.php 在 action=plugin.invoke 时引入，
 * 脚本末尾 return 结果数组，不输出任何内容、不 exit。
 */

if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/shared.php';

$ctx   = $GLOBALS['BG_PLUGIN_CTX'];
$cfg   = (isset($ctx['config']) && is_array($ctx['config'])) ? $ctx['config'] : array();
$today = (isset($ctx['today']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ctx['today']))
       ? (string)$ctx['today'] : date('Y-m-d');

/* ---------- 1. 必填配置与输入校验 ---------- */

$api_key = qwenocr_cfg($cfg, 'apiKey', '');
if ($api_key === '') {
    qwenocr_fail(400, '未配置 API Key，请在「识别插件设置」填写阿里云百炼的 API Key');
}

$image = isset($ctx['image']) ? trim((string)$ctx['image']) : '';
if ($image === '') {
    qwenocr_fail(400, '没有收到图片，请重新拍照或选择图片');
}
if (strlen($image) > 14000000) {
    qwenocr_fail(400, '图片过大，请重新拍摄或压缩到 8MB 以内');
}
if (!preg_match('#^data:image/(jpeg|pjpeg|jpg|png|webp);base64,#i', $image)) {
    qwenocr_fail(400, '图片格式不支持，请使用 JPG / PNG / WebP');
}

$endpoint = qwenocr_cfg($cfg, 'endpoint', QWENOCR_DEFAULT_ENDPOINT);
if (!preg_match('#^https://[^[:space:]]+$#i', $endpoint)) {
    qwenocr_fail(400, '接口地址必须以 https:// 开头，请检查设置');
}

$model      = qwenocr_cfg($cfg, 'model', QWENOCR_DEFAULT_MODEL);
$max_tokens = qwenocr_int($cfg, 'maxTokens', 4096, 256, 32768);
$timeout    = qwenocr_int($cfg, 'timeout', 30, 5, 120);
$skip_tls   = qwenocr_bool($cfg, 'skipTls', false);

/* ---------- 2. 零件库注入提示词 ---------- */

$catalog = (isset($ctx['catalog']) && is_array($ctx['catalog'])) ? $ctx['catalog'] : array();
$limit   = qwenocr_int($cfg, 'catalogLimit', 200, 0, 300);
$lines   = array();
foreach ($catalog as $p) {
    if (count($lines) >= $limit) break;
    if (!is_array($p)) continue;
    $no   = qwenocr_str(isset($p['no']) ? $p['no'] : '', 40);
    $name = qwenocr_str(isset($p['name']) ? $p['name'] : '', 40);
    $proc = '';
    if (isset($p['processes']) && is_array($p['processes'])) {
        $proc = implode('、', $p['processes']);
    } elseif (isset($p['process'])) {
        $proc = (string)$p['process'];
    }
    $proc = qwenocr_str($proc, 60);
    if ($no === '' && $name === '') continue;
    $lines[] = $no . ' | ' . $name . ' | ' . $proc;
}
$catalog_text = count($lines) ? implode("\n", $lines) : '（零件库为空，请完全按单据原文填写图号与名称）';

$prompt_tpl = qwenocr_cfg($cfg, 'prompt', qwenocr_default_prompt());
$prompt = str_replace(array('{TODAY}', '{CATALOG}'), array($today, $catalog_text), $prompt_tpl);

/* ---------- 3. 调用千问 OCR ---------- */

$body = array(
    'model'      => $model,
    'messages'   => array(
        array(
            'role'    => 'user',
            'content' => array(
                array('type' => 'image_url', 'image_url' => array('url' => $image)),
                array('type' => 'text', 'text' => $prompt),
            ),
        ),
    ),
    'max_tokens' => $max_tokens,
    'stream'     => false,
);

$http = qwenocr_http_post(
    $endpoint,
    json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    array(
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json',
    ),
    $timeout,
    $skip_tls
);

if ($http['err'] !== '') {
    throw new RuntimeException('无法访问千问OCR接口：' . $http['err'] . '（当前超时 ' . $timeout . ' 秒，可在设置里调大，或检查服务器出网）');
}

$code = $http['code'];
$resp = json_decode($http['body'], true);

$api_err = '';
if (is_array($resp)) {
    if (isset($resp['error']['message'])) $api_err = (string)$resp['error']['message'];
    elseif (isset($resp['message'])) $api_err = (string)$resp['message'];
}
if ($code >= 400 || $api_err !== '') {
    if ($api_err === '') $api_err = substr(trim($http['body']), 0, 200);
    if ($api_err === '') $api_err = '接口无响应内容';
    $hint = '';
    if ($code === 401 || $code === 403) $hint = '（API Key 无效、地域不匹配或未开通该模型）';
    elseif ($code === 404) $hint = '（接口地址或模型名有误）';
    elseif ($code === 429) $hint = '（触发限流，或账号额度用尽）';
    elseif ($code >= 500) $hint = '（服务端异常，可稍后重试）';
    throw new RuntimeException('千问OCR调用失败 HTTP ' . $code . '：' . $api_err . $hint);
}
if (!is_array($resp)) {
    throw new RuntimeException('千问OCR返回内容无法解析（HTTP ' . $code . '），请确认接口地址为 /compatible-mode/v1/chat/completions');
}

/* ---------- 4. 取模型正文 ---------- */

$content = '';
if (isset($resp['choices'][0]['message']['content'])) {
    $content = $resp['choices'][0]['message']['content'];
} elseif (isset($resp['output']['text'])) {
    $content = $resp['output']['text'];
}
if (is_array($content)) {
    $parts = array();
    foreach ($content as $c) {
        if (is_array($c) && isset($c['text'])) $parts[] = (string)$c['text'];
        elseif (is_string($c)) $parts[] = $c;
    }
    $content = implode("\n", $parts);
}
$content = trim((string)$content);
if ($content === '') {
    throw new RuntimeException('千问OCR没有返回识别内容，请换一张更清晰的单子重试');
}

$warnings = array();
$finish = isset($resp['choices'][0]['finish_reason']) ? (string)$resp['choices'][0]['finish_reason'] : '';
if ($finish === 'length') $warnings[] = '模型输出达到长度上限，明细可能不完整';

/* ---------- 5. 解析结果 ---------- */

$parsed = qwenocr_extract_json($content);
$source = 'json';
if (!is_array($parsed)) {
    $parsed = qwenocr_fallback($content, $catalog, $today);
    $source = 'text';
}

if (isset($parsed[0]) && is_array($parsed[0])) {
    $raw_items = $parsed;
} else {
    $raw_items = qwenocr_field($parsed, array('items', '明细', '报工明细', '列表', 'rows', 'lines'));
    if (is_string($raw_items)) $raw_items = array();
}
if (!is_array($raw_items)) $raw_items = array();

$date     = qwenocr_date(qwenocr_pick($parsed, array('date', '报工日期', '日期', '填单日期', '生产日期')), $today);
$worker   = qwenocr_str(qwenocr_pick($parsed, array('worker', '报工人', '报工员', '姓名', '签名')), 40);
$workshop = qwenocr_str(qwenocr_pick($parsed, array('workshop', '车间', '工段', '班组')), 40);
$note     = qwenocr_str(qwenocr_pick($parsed, array('note', '备注', '说明')), 100);

$items = array();
$qty_missing = 0;
foreach ($raw_items as $row) {
    if (count($items) >= 20) break;
    if (!is_array($row)) continue;

    $part_no   = qwenocr_str(qwenocr_pick($row, array('partNo', '图号', '零件号', '零件图号')), 40);
    $part_name = qwenocr_str(qwenocr_pick($row, array('partName', '零件名称', '名称', '品名')), 40);
    $process   = qwenocr_str(qwenocr_pick($row, array('process', '工序', '工序名称')), 40);
    $qty       = qwenocr_qty(qwenocr_field($row, array('qty', '数量', '报工数', '件数')));
    $price     = qwenocr_qty(qwenocr_field($row, array('price', '单价', '价格')));

    if ($part_no === '' && $part_name === '' && $process === '') continue;
    if ($qty === null) {
        $qty = 0;
        $qty_missing++;
    }

    $items[] = array(
        'partNo'   => $part_no,
        'partName' => $part_name,
        'process'  => $process,
        'qty'      => $qty,
        'price'    => $price,
    );
}

/* ---------- 6. 置信度与警告 ---------- */

$confidence = qwenocr_qty(qwenocr_field($parsed, array('confidence', '置信度')));
if ($confidence === null || $confidence > 1) {
    if ($source === 'json') {
        $confidence = $items ? 0.88 : 0.30;
    } else {
        $confidence = $items ? 0.45 : 0.20;
    }
}
if ($qty_missing > 0) $confidence = max(0.1, $confidence - 0.10);
$confidence = min(1, max(0, $confidence));

if ($source === 'text') {
    $warnings[] = '模型未返回结构化结果，明细按文本兜底解析，请逐条核对';
}
if (!$items) {
    $warnings[] = '未识别出报工明细，可对照下方原文手动填充';
}
if ($qty_missing > 0) {
    $warnings[] = '有 ' . $qty_missing . ' 行数量未识别，已置 0，请核对';
}
if ($date === '') {
    $date = $today;
    $warnings[] = '未识别到单据日期，已按今天填充';
}
if (!$catalog) {
    $warnings[] = '零件库为空，图号与名称按单据原文填写';
} elseif (count($catalog) > $limit) {
    $warnings[] = $limit > 0
        ? '零件库共 ' . count($catalog) . ' 条，仅注入前 ' . $limit . ' 条供比对'
        : '未注入零件库，图号与名称按单据原文填写';
}
if (strpos($api_key, 'sk-') !== 0) {
    $warnings[] = 'API Key 不是 sk- 开头，若报 401 请确认是百炼密钥';
}

/* 去空、去重、限长，最多 5 条 */
$clean = array();
foreach ($warnings as $w) {
    $w = qwenocr_str($w, 80);
    if ($w !== '' && !in_array($w, $clean, true)) $clean[] = $w;
}
$warnings = array_slice($clean, 0, 5);

/* ---------- 7. 拼可读原文（不把模型输出的代码块 / JSON 直接展示） ---------- */

$raw_lines = array();
if ($source === 'json') {
    if ($date !== '')     $raw_lines[] = '日期：' . $date;
    if ($worker !== '')   $raw_lines[] = '报工人：' . $worker;
    if ($workshop !== '') $raw_lines[] = '车间：' . $workshop;
    if ($note !== '')     $raw_lines[] = '备注：' . $note;
    foreach ($items as $it) {
        $seg = array();
        if ($it['partNo'] !== '')   $seg[] = $it['partNo'];
        if ($it['partName'] !== '' && $it['partName'] !== $it['partNo']) $seg[] = $it['partName'];
        if ($it['process'] !== '')  $seg[] = $it['process'];
        if ($it['qty'] > 0) {
            $seg[] = rtrim(rtrim(number_format((float)$it['qty'], 2, '.', ''), '0'), '.') . '件';
        }
        if ($seg) $raw_lines[] = implode(' ', $seg);
    }
} else {
    foreach (preg_split('/\r\n|\r|\n/', $content) as $ln) {
        $ln = trim(preg_replace('/```[a-zA-Z0-9_-]*/', '', $ln));
        if ($ln !== '') $raw_lines[] = $ln;
    }
}
$raw_text = implode("\n", $raw_lines);
if ($raw_text === '') $raw_text = '模型只返回了结构化结果，已按上方表单填写';

/* ---------- 8. 返回 ---------- */

return array(
    'engine'     => '千问 Qwen3.5-OCR',
    'confidence' => round($confidence, 3),
    'fields'     => array(
        'date'     => $date,
        'worker'   => $worker,
        'workshop' => $workshop,
        'note'     => $note,
        'items'    => $items,
    ),
    'warnings'   => $warnings,
    'raw'        => qwenocr_cut($raw_text, 4000),
);
