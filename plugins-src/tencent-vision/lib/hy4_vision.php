<?php
/**
 * 混元 Hy4 视觉引擎适配层（T03）
 *
 * OpenAI 兼容 chat/completions：Bearer Key + image_url dataURL + text 提示词。
 * 红线：请求体只允许 model/messages/max_tokens/stream，绝不携带旧版问答接口字段。
 * 输出解析兼容 ```json 围栏与前后说明文字，失败走文本兜底并写中文告警。
 */

if (defined('BG_TV_HY4_LOADED')) {
    return;
}
define('BG_TV_HY4_LOADED', 1);

if (!function_exists('bg_tv_cfg')) {
    require_once __DIR__ . '/util.php';
}

if (!defined('BG_TV_HY4_ENDPOINT')) {
    define('BG_TV_HY4_ENDPOINT', 'https://api.hunyuan.cloud.tencent.com/v1/chat/completions');
}
if (!defined('BG_TV_HY4_MODEL')) {
    define('BG_TV_HY4_MODEL', 'hunyuan-vision');
}

/**
 * 默认提示词模板（PRD §4.4），支持 {TODAY} / {CATALOG} 占位符。
 *
 * @return string
 */
function bg_tv_hy4_default_prompt()
{
    return <<<'TXT'
你是工厂车间的「报工单」识别引擎。请识别图片中的手写/印刷报工单，只输出一个 JSON 对象，不要解释、不要 markdown 代码块。

输出结构：
{"date":"YYYY-MM-DD","worker":"报工人","workshop":"车间","note":"备注","confidence":0.9,"items":[{"partNo":"图号","partName":"零件名称","process":"工序","qty":12,"price":null}]}

识别规则：
1. date：取报工日期/填单日期，补全为 YYYY-MM-DD；只写月日时按当前年份补全；识别不到填空字符串。
2. worker / workshop / note：分别取报工人、车间、备注，没有则填空字符串。
3. items：每行一条报工明细，最多 20 行；表头行、合计行、小计行、签名行不算明细。
4. partNo / partName / process 优先采用「零件库」中完全一致的写法；库里没有的照单据原文填写，不得改写、缩写或编造。
5. process 只填工序名（车/铣/钻/磨/钳/热处理/电镀/装配等），无法判断填空字符串。
6. qty：本行数量，纯数字不带单位；看不清填 null，不要猜。
7. price：单价，纯数字；单据没有填 null。
8. confidence：0~1 之间，表示整单识别把握程度。
9. 模糊、遮挡、无法辨认的单字用英文问号 ? 代替；宁可留空也不要编造内容。

当前日期：{TODAY}
零件库（图号 | 名称 | 工序）：
{CATALOG}
TXT;
}

/**
 * 归一混元 Hy4 配置。
 *
 * @param array $cfg 插件配置
 * @return array
 */
function bg_tv_hy4_cfg($cfg)
{
    return array(
        'apiKey'      => bg_tv_cfg($cfg, 'hy4ApiKey', ''),
        'endpoint'    => bg_tv_cfg($cfg, 'hy4Endpoint', BG_TV_HY4_ENDPOINT),
        'model'       => bg_tv_cfg($cfg, 'hy4Model', BG_TV_HY4_MODEL),
        'prompt'      => bg_tv_cfg($cfg, 'hy4Prompt', bg_tv_hy4_default_prompt()),
        'maxTokens'   => bg_tv_int($cfg, 'maxTokens', 4096, 256, 32768),
        'catalogLimit' => bg_tv_int($cfg, 'catalogLimit', 200, 0, 300),
        'skipTls'     => bg_tv_bool($cfg, 'skipTls', false),
        'timeout'     => bg_tv_int($cfg, 'timeout', 30, 5, 120),
        'retries'     => bg_tv_int($cfg, 'retries', 1, 0, 3),
    );
}

/**
 * 把 ctx.catalog 拼成注入提示词的零件库文本（图号 | 名称 | 工序）。
 *
 * @param array $ctx 插件上下文
 * @param array $cfg 插件配置
 * @return string
 */
function bg_tv_hy4_catalog_text($ctx, $cfg)
{
    $c = bg_tv_hy4_cfg($cfg);
    $catalog = (isset($ctx['catalog']) && is_array($ctx['catalog'])) ? $ctx['catalog'] : array();
    $lines = array();
    foreach ($catalog as $p) {
        if (count($lines) >= $c['catalogLimit']) {
            break;
        }
        if (!is_array($p)) {
            continue;
        }
        $no = bg_tv_str(isset($p['no']) ? $p['no'] : '', 40);
        $name = bg_tv_str(isset($p['name']) ? $p['name'] : '', 40);
        $proc = '';
        if (isset($p['processes']) && is_array($p['processes'])) {
            $proc = implode('、', $p['processes']);
        } elseif (isset($p['process'])) {
            $proc = (string)$p['process'];
        }
        $proc = bg_tv_str($proc, 60);
        if ($no === '' && $name === '') {
            continue;
        }
        $lines[] = $no . ' | ' . $name . ' | ' . $proc;
    }
    return $lines
        ? implode("\n", $lines)
        : '（零件库为空，请完全按单据原文填写图号与名称）';
}

/**
 * 替换提示词占位符。
 *
 * @param string $template 模板
 * @param string $today    当天日期
 * @param string $catalog  零件库文本
 * @return string
 */
function bg_tv_hy4_render_prompt($template, $today, $catalog)
{
    return str_replace(
        array('{TODAY}', '{CATALOG}'),
        array((string)$today, (string)$catalog),
        (string)$template
    );
}

/**
 * 构造 chat/completions 请求体。严格禁止旧版问答接口字段。
 *
 * @param string $image     dataURL 图片
 * @param string $prompt    提示词
 * @param string $model     模型名
 * @param int    $maxTokens 最大输出 token
 * @return array
 */
function bg_tv_hy4_build_body($image, $prompt, $model, $maxTokens)
{
    return array(
        'model' => (string)$model,
        'messages' => array(
            array(
                'role' => 'user',
                'content' => array(
                    array('type' => 'image_url', 'image_url' => array('url' => (string)$image)),
                    array('type' => 'text', 'text' => (string)$prompt),
                ),
            ),
        ),
        'max_tokens' => (int)$maxTokens,
        'stream' => false,
    );
}

/**
 * 校验 dataURL 图片，返回原样 dataURL（逗号必须为英文）。
 *
 * @param string $image 图片 dataURL
 * @return string
 */
function bg_tv_hy4_image($image)
{
    $image = trim((string)$image);
    if ($image === '') {
        throw new RuntimeException('没有收到图片，请重新拍照或选择图片');
    }
    if (!preg_match('#^data:image/(jpeg|pjpeg|jpg|png|webp);base64,#i', $image)) {
        throw new RuntimeException('图片格式不支持，请使用 JPG / PNG / WebP');
    }
    if (strlen($image) > 12000000) {
        throw new RuntimeException('图片过大，请压缩到 8MB 以内再上传');
    }
    return $image;
}

/**
 * 准备一个混元 Hy4 请求 job（含 Bearer 头）。
 *
 * @param array $ctx 插件上下文
 * @param array $cfg 插件配置
 * @return array job
 */
function bg_tv_hy4_prepare($ctx, $cfg)
{
    $c = bg_tv_hy4_cfg($cfg);
    if ($c['apiKey'] === '') {
        throw new RuntimeException('请先填写混元 API Key');
    }
    /* 端点由配置决定：必须是公网 https，防止 Bearer Key 与图片被打到内网/本机 */
    if (!bg_tv_url_https_public($c['endpoint'])) {
        throw new RuntimeException('接口地址不可用：必须是公网 https 地址，不能指向本机或内网');
    }
    $ctx = is_array($ctx) ? $ctx : array();
    $image = bg_tv_hy4_image(isset($ctx['image']) ? $ctx['image'] : '');
    $today = (isset($ctx['today']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ctx['today']))
        ? (string)$ctx['today'] : date('Y-m-d');
    $prompt = bg_tv_hy4_render_prompt(
        $c['prompt'],
        $today,
        bg_tv_hy4_catalog_text($ctx, $cfg)
    );
    $body = bg_tv_hy4_build_body($image, $prompt, $c['model'], $c['maxTokens']);
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('请求参数编码失败');
    }
    return array(
        'url' => $c['endpoint'],
        'headers' => array(
            'Authorization: Bearer ' . $c['apiKey'],
            'Content-Type: application/json',
        ),
        'payload' => $payload,
        'timeout' => $c['timeout'],
        'skipTls' => $c['skipTls'],
        'retries' => $c['retries'],
    );
}

/**
 * HTTP 状态 / 接口错误转中文提示。
 *
 * @param int    $code HTTP 状态码
 * @param string $msg  原始消息
 * @return string
 */
function bg_tv_hy4_error_text($code, $msg)
{
    $code = (int)$code;
    $msg = trim((string)$msg);
    if ($msg === '') {
        $msg = '接口无响应内容';
    }
    if ($code === 401 || $code === 403) {
        return '密钥无效或地域不匹配（HTTP ' . $code . '）：' . $msg;
    }
    if ($code === 404) {
        return '接口地址或模型名有误（HTTP 404）：' . $msg;
    }
    if ($code === 429) {
        return '触发限流，或账号额度用尽（HTTP 429）：' . $msg;
    }
    if ($code >= 500) {
        return '服务端异常，可稍后重试（HTTP ' . $code . '）：' . $msg;
    }
    return '混元 Hy4 调用失败 HTTP ' . $code . '：' . $msg;
}

/**
 * 网络错误转中文提示。
 *
 * @param array $send bg_tv_http_send 结果
 * @return string
 */
function bg_tv_hy4_network_error($send)
{
    $msg = isset($send['err']) && $send['err'] !== '' ? (string)$send['err'] : '无响应';
    if (preg_match('/certificate|SSL|cert/i', $msg)) {
        $msg .= '（HTTPS 证书校验失败，可配置 curl.cainfo 或临时勾选跳过证书校验排查）';
    } elseif (bg_tv_retryable(isset($send['errno']) ? $send['errno'] : 0)) {
        $tries = max(0, (int)(isset($send['attempts']) ? $send['attempts'] : 1) - 1);
        $msg .= '（已重试 ' . $tries . ' 次仍未成功）';
    }
    return '网络请求失败：' . $msg;
}

/**
 * 从模型输出中抠出 JSON 对象/数组：兼容 ```json 围栏与前后说明文字。
 *
 * @param string $text 模型原文
 * @return array|null 解析成功返回数组，失败返回 null
 */
function bg_tv_hy4_extract_json($text)
{
    $t = trim((string)$text);
    if ($t === '') {
        return null;
    }
    if (preg_match('/^```[a-zA-Z0-9_-]*\s*(.*?)\s*```$/s', $t, $m)) {
        $t = trim($m[1]);
    }
    $d = json_decode($t, true);
    if (is_array($d)) {
        return $d;
    }
    foreach (array(array('{', '}'), array('[', ']')) as $pair) {
        $s = strpos($t, $pair[0]);
        $e = strrpos($t, $pair[1]);
        if ($s !== false && $e !== false && $e > $s) {
            $d = json_decode(substr($t, $s, $e - $s + 1), true);
            if (is_array($d)) {
                return $d;
            }
        }
    }
    return null;
}

/**
 * 模型未返回合法 JSON 时的文本兜底：只取原文中确实存在的信息，不臆造明细。
 *
 * @param string $text    模型原文
 * @param array  $catalog 零件库
 * @param string $today   当天日期
 * @return array
 */
function bg_tv_hy4_fallback($text, $catalog, $today)
{
    $res = array('date' => '', 'worker' => '', 'workshop' => '', 'note' => '', 'items' => array());
    $text = (string)$text;

    if (preg_match('/(\d{4})\s*[-\/.年]\s*(\d{1,2})\s*[-\/.月]\s*(\d{1,2})/u', $text, $m)) {
        $res['date'] = bg_tv_date($m[0], $today);
    }
    if ($res['date'] === '' && preg_match('/(?:日期|报工日期|填单日期|生产日期)\s*[:：]?\s*(\d{1,2})\s*[-\/.月]\s*(\d{1,2})\s*日?/u', $text, $m)) {
        $cand = sprintf('%02d-%02d', (int)$m[1], (int)$m[2]);
        $res['date'] = bg_tv_date($cand, $today);
    }
    if (preg_match('/(报工人|报工员|记录人|操作员)\s*[:：]\s*([^\s,，、;：:]{1,12})/u', $text, $m)) {
        $res['worker'] = $m[2];
    } elseif (preg_match('/签\s*名\s*[:：]\s*([^\s,，、;：:]{1,12})/u', $text, $m)) {
        $res['worker'] = $m[1];
    }
    if (preg_match_all('/(车间|工段|班组)\s*[:：]\s*([^\s,，、;：:]{1,12})/u', $text, $mw, PREG_SET_ORDER)) {
        $last = $mw[count($mw) - 1];
        if (!preg_match('/(单|表|栏|名称|号)$/u', $last[2])) {
            $res['workshop'] = $last[2];
        }
    }

    foreach (preg_split('/\r\n|\r|\n/u', $text) as $line) {
        if (count($res['items']) >= 20) {
            break;
        }
        $line = trim($line);
        if ($line === '' || strlen($line) < 4) {
            continue;
        }
        if (preg_match('/(序号|零件名|零件名称|图\s*号|工序|单价|合计|小计|总计|金额|页码|客户名称)/u', $line)
            && !preg_match('/\d{3,}/u', $line)) {
            continue;
        }
        $no = '';
        $name = '';
        $proc = '';
        foreach ((array)$catalog as $p) {
            if (!is_array($p)) {
                continue;
            }
            $pn = isset($p['no']) ? trim((string)$p['no']) : '';
            if ($pn !== '' && bg_tv_contains($line, $pn)) {
                $no = $pn;
                $name = isset($p['name']) ? trim((string)$p['name']) : '';
                if (isset($p['processes']) && is_array($p['processes'])) {
                    foreach ($p['processes'] as $ps) {
                        $ps = trim((string)$ps);
                        if ($ps !== '' && bg_tv_contains($line, $ps)) {
                            $proc = $ps;
                            break;
                        }
                    }
                }
                break;
            }
        }
        if ($no === '' && preg_match('/(?<![A-Za-z0-9])([A-Za-z][A-Za-z0-9\-\/\.]{4,29})(?![A-Za-z0-9])/u', $line, $m)) {
            $no = $m[1];
        }
        if ($no === '') {
            continue;
        }
        $bare = preg_replace('/[A-Za-z0-9][A-Za-z0-9\-\/\.]{4,}/u', ' ', $line);
        $qty = null;
        if (preg_match_all('/(\d+(?:\.\d+)?)/u', $bare, $mm) && $mm[1]) {
            $vals = $mm[1];
            $qty = bg_tv_qty($vals[count($vals) - 1]);
        }
        $res['items'][] = array(
            'partNo' => $no,
            'partName' => $name,
            'process' => $proc,
            'qty' => $qty,
            'price' => null,
        );
    }
    return $res;
}

/**
 * 由解析结果拼可读 raw（必含图号与数量片段，供 L1 佐证）。
 *
 * @param array  $parsed 解析结果
 * @param string $content 模型原文
 * @param string $source  json|text
 * @return string
 */
function bg_tv_hy4_raw_text($parsed, $content, $source)
{
    $parsed = is_array($parsed) ? $parsed : array();
    if ($source !== 'json') {
        $lines = array();
        foreach (preg_split('/\r\n|\r|\n/', (string)$content) as $ln) {
            $ln = trim(preg_replace('/```[a-zA-Z0-9_-]*/', '', $ln));
            if ($ln !== '') {
                $lines[] = $ln;
            }
        }
        return implode("\n", $lines);
    }
    $lines = array();
    foreach (array('date' => '日期', 'worker' => '报工人', 'workshop' => '车间', 'note' => '备注') as $k => $label) {
        if (!empty($parsed[$k])) {
            $lines[] = $label . '：' . $parsed[$k];
        }
    }
    $items = isset($parsed['items']) && is_array($parsed['items']) ? $parsed['items'] : array();
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $seg = array();
        foreach (array('partNo', 'partName', 'process') as $k) {
            if (!empty($it[$k])) {
                $seg[] = (string)$it[$k];
            }
        }
        if (isset($it['qty']) && $it['qty'] !== null && $it['qty'] !== '') {
            $q = bg_tv_qty($it['qty']);
            if ($q !== null) {
                $num = rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
                $seg[] = $num . '件';
            }
        }
        if ($seg) {
            $lines[] = implode(' ', $seg);
        }
    }
    return implode("\n", $lines);
}

/**
 * 解析混元 Hy4 响应为中间 EngineResult（fields 尚未归一）。
 *
 * @param string $rawBody HTTP 响应体
 * @param int    $status  HTTP 状态码
 * @param array  $cfg     插件配置
 * @param array  $ctx     插件上下文
 * @param float  $t0      起始微秒时间
 * @return array EngineResult
 */
function bg_tv_hy4_parse($rawBody, $status, $cfg, $ctx, $t0 = 0.0)
{
    $code = (int)$status;
    $resp = json_decode((string)$rawBody, true);
    $apiMsg = '';
    if (is_array($resp)) {
        if (isset($resp['error']['message'])) {
            $apiMsg = (string)$resp['error']['message'];
        } elseif (isset($resp['message'])) {
            $apiMsg = (string)$resp['message'];
        }
    }
    if ($code >= 400 || ($apiMsg !== '' && !is_array($resp))) {
        throw new RuntimeException(bg_tv_hy4_error_text($code, $apiMsg));
    }
    if (!is_array($resp)) {
        throw new RuntimeException('混元 Hy4 返回内容无法解析（HTTP ' . $code . '），请确认接口地址正确');
    }

    $content = '';
    if (isset($resp['choices'][0]['message']['content'])) {
        $content = $resp['choices'][0]['message']['content'];
    }
    if (is_array($content)) {
        $parts = array();
        foreach ($content as $c) {
            if (is_array($c) && isset($c['text'])) {
                $parts[] = (string)$c['text'];
            } elseif (is_string($c)) {
                $parts[] = $c;
            }
        }
        $content = implode("\n", $parts);
    }
    $content = trim((string)$content);
    if ($content === '') {
        throw new RuntimeException('混元 Hy4 没有返回识别内容，请换一张更清晰的单子重试');
    }

    $warnings = array();
    $finish = isset($resp['choices'][0]['finish_reason']) ? (string)$resp['choices'][0]['finish_reason'] : '';
    if ($finish === 'length') {
        $warnings[] = '模型输出达到长度上限，明细可能不完整';
    }
    $parsed = bg_tv_hy4_extract_json($content);
    $source = 'json';
    if (!is_array($parsed)) {
        $catalog = (isset($ctx['catalog']) && is_array($ctx['catalog'])) ? $ctx['catalog'] : array();
        $today = (isset($ctx['today']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ctx['today']))
            ? (string)$ctx['today'] : date('Y-m-d');
        $parsed = bg_tv_hy4_fallback($content, $catalog, $today);
        $source = 'text';
        $warnings[] = '模型未返回结构化结果，明细按文本兜底解析，请逐条核对';
    }

    $usage = isset($resp['usage']) && is_array($resp['usage']) ? $resp['usage'] : array();
    $usageOut = array(
        'prompt_tokens' => isset($usage['prompt_tokens']) ? (int)$usage['prompt_tokens'] : 0,
        'completion_tokens' => isset($usage['completion_tokens']) ? (int)$usage['completion_tokens'] : 0,
        'total_tokens' => isset($usage['total_tokens']) ? (int)$usage['total_tokens'] : 0,
    );
    $elapsed = ($t0 > 0) ? (int)round((microtime(true) - $t0) * 1000) : 0;
    $invokeId = isset($ctx['invokeId']) ? (string)$ctx['invokeId'] : '';
    bg_tv_log('hy4_usage', array_merge(
        array('invokeId' => $invokeId, 'elapsedMs' => $elapsed, 'model' => bg_tv_cfg($cfg, 'hy4Model', BG_TV_HY4_MODEL)),
        $usageOut
    ));

    $confidence = bg_tv_conf(
        isset($parsed['confidence']) ? $parsed['confidence'] : null,
        ($source === 'json') ? 0.88 : 0.45
    );
    if ($source === 'text') {
        $confidence = min($confidence, 0.60);
    }
    return array(
        'engineId'   => 'hy4Vision',
        'confidence' => round($confidence, 3),
        'fields'     => array('date' => '', 'worker' => '', 'workshop' => '', 'note' => '', 'items' => array()),
        'raw'        => bg_tv_hy4_raw_text($parsed, $content, $source),
        'warnings'   => $warnings,
        'usage'      => $usageOut,
        'elapsedMs'  => $elapsed,
        'ok'         => true,
        '_mid'       => array('parsed' => $parsed, 'source' => $source, 'content' => $content),
    );
}

/**
 * 串行调用混元 Hy4（自检 / 无 curl_multi 降级使用）。
 *
 * @param array $ctx 插件上下文
 * @param array $cfg 插件配置
 * @return array EngineResult
 */
function bg_tv_hy4_call($ctx, $cfg)
{
    $t0 = microtime(true);
    $c = bg_tv_hy4_cfg($cfg);
    $job = bg_tv_hy4_prepare($ctx, $cfg);
    $send = bg_tv_http_send($job, $c['retries']);
    if ($send['err'] !== '') {
        throw new RuntimeException(bg_tv_hy4_network_error($send));
    }
    return bg_tv_hy4_parse($send['body'], $send['code'], $cfg, $ctx, $t0);
}
