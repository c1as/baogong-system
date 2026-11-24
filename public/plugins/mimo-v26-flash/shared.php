<?php
/**
 * mimo-v26-flash · 公共函数（出网调用、JSON 提取、字段归一化）
 * 只能由本插件的 handler.php / check.php 引入（入口处已校验 BG_PLUGIN_CTX）。
 */

if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

if (!defined('MIMOOCR_DEFAULT_ENDPOINT')) {
    define('MIMOOCR_DEFAULT_ENDPOINT', 'https://api.xiaomimimo.com/v1/chat/completions');
    define('MIMOOCR_DEFAULT_MODEL', 'mimo-v2.6-flash');
}

/* ---------- mbstring 兼容（精简 PHP 环境可能未启用，避免兜底路径 Fatal error） ---------- */
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return strlen((string)$s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null, $enc = null) {
        $s = (string)$s;
        return $len === null ? substr($s, $start) : substr($s, $start, $len);
    }
}
if (!function_exists('mb_convert_kana')) {
    /* 缺 mbstring 时无法做全角转半角，原样返回（图号多为半角，影响有限） */
    function mb_convert_kana($s, $mode = '', $enc = null) { return $s; }
}

/** 识别指令默认值；可在设置页修改。{TODAY}/{CATALOG} 由 handler 运行时替换 */
function mimoocr_default_prompt()
{
    return "你是工厂车间的「报工单」识别引擎，请识别图片中的报工单/生产流转单，只输出一个 JSON 对象，不要解释、不要 markdown 代码块。\n"
        . "输出结构：\n"
        . "{\"date\":\"YYYY-MM-DD\",\"worker\":\"报工人\",\"workshop\":\"车间\",\"note\":\"备注\",\"confidence\":0.9,\"items\":[{\"partNo\":\"图号\",\"partName\":\"零件名称\",\"process\":\"工序\",\"qty\":12,\"price\":null}]}\n\n"
        . "识别规则：\n"
        . "1. date：取报工日期/填单日期，必须补全成 YYYY-MM-DD；只写月日时按当前年份补全年份；识别不到填空字符串。\n"
        . "2. worker / workshop / note：分别取报工人（或签名、班组长）、车间（或工段、班组）、备注，没有则填空字符串。\n"
        . "3. items：每行一条报工明细，最多 20 行；表头行、合计行、小计行、签名行不算明细。\n"
        . "4. partNo / partName / process 必须优先采用下方「零件库」中完全一致的写法；零件库里没有的，照单据原文填写，不得改写、缩写或编造。\n"
        . "5. process 只能是工序名（如车、铣、钻、磨、钳、热处理、电镀、装配），无法判断填空字符串。\n"
        . "6. qty：本行报工数量，纯数字，单位不要写进去；看不清或无法确认填 null，不要猜。\n"
        . "7. price：该件单价，纯数字；单据没有价格填 null。\n"
        . "8. confidence：0~1 之间，表示整单识别的把握程度。\n"
        . "9. 模糊、遮挡、无法辨认的单字用英文问号 ? 代替；宁可留空也不要编造内容。\n\n"
        . "当前日期：{TODAY}\n"
        . "零件库（图号 | 零件名称 | 可选工序）：\n"
        . "{CATALOG}";
}

function mimoocr_cfg($cfg, $key)
{
    $v = isset($cfg[$key]) && is_scalar($cfg[$key]) ? $cfg[$key] : '';
    return trim((string)$v);
}

function mimoocr_int($cfg, $key, $default, $min, $max)
{
    $v = isset($cfg[$key]) ? trim((string)$cfg[$key]) : '';
    if ($v === '' || !is_numeric($v)) {
        return $default;
    }
    $n = (int)$v;
    if ($n < $min) {
        return $min;
    }
    if ($n > $max) {
        return $max;
    }
    return $n;
}

function mimoocr_bool($cfg, $key)
{
    $v = isset($cfg[$key]) ? (string)$cfg[$key] : '';
    return in_array($v, array('1', 'true', 'yes', 'on'), true);
}

/** 统一的失败出口：api.php 提供 bg_fail 时用之，否则抛异常由上层转 502 */
function mimoocr_fail($status, $msg)
{
    if (function_exists('bg_fail')) {
        bg_fail($status, $msg);
    }
    throw new RuntimeException($msg);
}

/** 服务器 CA 证书：优先 php.ini curl.cainfo，再找 lib/cacert.pem */
function mimoocr_ca()
{
    $configured = ini_get('curl.cainfo');
    if (is_string($configured) && $configured !== '' && is_file($configured)) {
        return $configured;
    }
    $candidates = array(
        __DIR__ . '/../../lib/cacert.pem',
        __DIR__ . '/../../../lib/cacert.pem',
    );
    foreach ($candidates as $f) {
        if (is_file($f)) {
            return $f;
        }
    }
    return '';
}

/**
 * 单次 HTTPS POST：优先使用系统统一出网封装 bg_http（lib/net.php，自动带 CA 与代理），
 * 不可用时退回 cURL，最后退回流式 Socket。
 * 返回 array('code'=>HTTP状态, 'body'=>响应体, 'err'=>传输层错误, 'ms'=>耗时毫秒)
 */
function mimoocr_http_post($url, $payload, $headers, $timeout, $skip_tls)
{
    $t0 = microtime(true);

    // 统一把数组请求体编码成 JSON 字符串（三种出网方式共用）
    if (is_array($payload)) {
        $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // 方式 1：系统 lib/net.php 的 bg_http（接入文档要求的统一出网方式）
    if (function_exists('bg_http')) {
        $res = bg_http('POST', $url, $headers, $payload, $timeout, $skip_tls ? true : false);
        return array(
            'code' => (int)$res['status'],
            'body' => $res['body'] === null ? '' : (string)$res['body'],
            'err' => $res['error'] === null ? '' : (string)$res['error'],
            'ms' => isset($res['ms']) ? (int)$res['ms'] : (int)round((microtime(true) - $t0) * 1000),
        );
    }

    // 方式 2：cURL
    if (function_exists('curl_init')) {
        $c = curl_init();
        curl_setopt($c, CURLOPT_URL, $url);
        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($c, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($c, CURLOPT_CONNECTTIMEOUT, min(10, (int)$timeout));
        curl_setopt($c, CURLOPT_TIMEOUT, (int)$timeout);
        curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($c, CURLOPT_MAXREDIRS, 3);
        curl_setopt($c, CURLOPT_USERAGENT, 'baogong-mimo-ocr/1.0');
        if ($skip_tls) {
            curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
        } else {
            $ca = mimoocr_ca();
            if ($ca !== '') {
                curl_setopt($c, CURLOPT_CAINFO, $ca);
            }
        }
        $body = curl_exec($c);
        $errno = curl_errno($c);
        $error = curl_error($c);
        $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        if ($body === false) {
            return array('code' => 0, 'body' => '', 'err' => '连接错误(' . $errno . ')：' . $error, 'ms' => (int)round((microtime(true) - $t0) * 1000));
        }
        return array('code' => $code, 'body' => (string)$body, 'err' => '', 'ms' => (int)round((microtime(true) - $t0) * 1000));
    }

    // 方式 3：流式 Socket（最后兜底）
    $u = parse_url($url);
    if (!is_array($u) || empty($u['host']) || !isset($u['scheme']) || !isset($u['port'])) {
        return array('code' => 0, 'body' => '', 'err' => '接口地址无法解析：' . mimoocr_cut($url, 80), 'ms' => 0);
    }
    $host = $u['host'];
    $port = (int)$u['port'];
    $scheme = strtolower((string)$u['scheme']);
    $path = (isset($u['path']) && $u['path'] !== '') ? $u['path'] : '/';
    if (isset($u['query']) && $u['query'] !== '') {
        $path .= '?' . $u['query'];
    }

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(($scheme === 'https' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, (int)$timeout);
    if (!$fp) {
        return array('code' => 0, 'body' => '', 'err' => '连接失败(' . $errno . ')：' . $errstr, 'ms' => (int)round((microtime(true) - $t0) * 1000));
    }
    if ($scheme === 'https' && !$skip_tls) {
        $ca = mimoocr_ca();
        stream_context_set_option($fp, 'ssl', 'verify_peer', true);
        stream_context_set_option($fp, 'ssl', 'verify_peer_name', true);
        if ($ca !== '') {
            stream_context_set_option($fp, 'ssl', 'cafile', $ca);
        }
        @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    }
    $timeoutSec = max(1, (int)$timeout);
    stream_set_timeout($fp, $timeoutSec);
    $lines = array();
    foreach ($headers as $h) {
        $h = (string)$h;
        if ($h === '' || substr($h, -1) === ':') { // 如 "Expect:" 仅用于 cURL 去头，原始请求不发
            continue;
        }
        $lines[] = $h;
    }
    $head = "POST " . $path . " HTTP/1.1\r\n"
        . "Host: " . $host . "\r\n"
        . "Connection: close\r\n"
        . implode("\r\n", $lines) . "\r\n\r\n";
    fwrite($fp, $head . $payload);
    $raw = '';
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    $meta = stream_get_meta_data($fp);
    fclose($fp);
    if (isset($meta['timed_out']) && $meta['timed_out']) {
        return array('code' => 0, 'body' => '', 'err' => '读取响应超时（' . $timeoutSec . ' 秒）', 'ms' => (int)round((microtime(true) - $t0) * 1000));
    }
    if ($raw === '') {
        return array('code' => 0, 'body' => '', 'err' => '服务器没有返回任何内容', 'ms' => (int)round((microtime(true) - $t0) * 1000));
    }
    $parts = explode("\r\n\r\n", $raw, 2);
    $headersBlock = isset($parts[0]) ? $parts[0] : '';
    $rest = isset($parts[1]) ? $parts[1] : '';
    $statusLine = isset($parts[0]) ? explode("\r\n", $parts[0]) : array();
    $statusLine = isset($statusLine[0]) ? $statusLine[0] : '';
    $code = 0;
    if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $statusLine, $mm)) {
        $code = (int)$mm[1];
    }
    $chunked = (bool)preg_match('/Transfer-Encoding:\s*chunked/i', $headersBlock);
    $body = $chunked ? mimoocr_dechunk($rest) : $rest;
    return array('code' => $code, 'body' => $body, 'err' => '', 'ms' => (int)round((microtime(true) - $t0) * 1000));
}

/**
 * 带退避重试的 POST：仅对瞬时错误重试（传输失败 / 429 限流 / 5xx），
 * 400/401/404 等业务错误立即返回，避免掩盖配置问题。
 * $retries 为额外重试次数（0~2）。返回值在 http_post 基础上附带 attempts。
 */
function mimoocr_post($url, $payload, $headers, $timeout, $skip_tls, $retries)
{
    $retries = max(0, min(2, (int)$retries));
    $attempts = 1;
    $res = mimoocr_http_post($url, $payload, $headers, $timeout, $skip_tls);
    for ($i = 1; $i <= $retries; $i++) {
        $transient = ($res['err'] !== '') || in_array($res['code'], array(429, 500, 502, 503), true);
        if (!$transient) {
            break;
        }
        usleep(750000 * $i); // 0.75s / 1.5s 退避，避免撞 RPM 限流
        $attempts++;
        $res = mimoocr_http_post($url, $payload, $headers, $timeout, $skip_tls);
    }
    $res['attempts'] = $attempts;
    return $res;
}

function mimoocr_dechunk($raw)
{
    $out = '';
    while ($raw !== '') {
        $pos = strpos($raw, "\r\n");
        if ($pos === false) {
            break;
        }
        $line = substr($raw, 0, $pos);
        $hex = trim($line);
        $pos = strpos($hex, ';');
        if ($pos !== false) {
            $hex = substr($hex, 0, $pos);
        }
        $size = ctype_xdigit($hex) ? (int)hexdec($hex) : -1;
        if ($size < 0) {
            break;
        }
        $raw = (string)substr($raw, $pos + 2);
        if ($size === 0) {
            break;
        }
        $out .= (string)substr($raw, 0, $size);
        $raw = (string)substr($raw, $size + 2);
    }
    return $out;
}

/* ---------------- 字段归一化 ---------------- */

function mimoocr_field($f, $default)
{
    if (is_scalar($f)) {
        return $f;
    }
    if (is_array($f) && isset($f['value']) && is_scalar($f['value'])) {
        return $f['value'];
    }
    return $default;
}

function mimoocr_pick($d, $keys)
{
    if (!is_array($d)) {
        return '';
    }
    foreach ($keys as $k) {
        if (isset($d[$k]) && is_scalar($d[$k])) {
            return (string)$d[$k];
        }
    }
    return '';
}

function mimoocr_str($v, $max)
{
    $s = is_string($v) ? trim($v) : '';
    if ($s === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        $s = mb_substr($s, 0, $max, 'UTF-8');
    } else {
        $s = substr($s, 0, $max);
    }
    return trim($s);
}

function mimoocr_cut($v, $max)
{
    $s = is_string($v) ? $v : (is_scalar($v) ? (string)$v : '');
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
    return substr($s, 0, $max);
}

function mimoocr_qty($v)
{
    $n = null;
    if (is_int($v) || is_float($v)) {
        $n = (float)$v;
    } elseif (is_string($v)) {
        $v = str_replace(array('，', ','), '', trim($v));
        if (preg_match('/\d+(\.\d+)?/', $v, $mm)) {
            $n = (float)$mm[0];
        }
    }
    if ($n === null || !is_finite($n)) {
        return 0;
    }
    $n = max(0, min(1000000, round($n, 2)));
    return (floor($n) == $n) ? (int)$n : $n;
}

function mimoocr_date($s, $today)
{
    $s = trim((string)$s);
    if ($s === '') {
        return '';
    }
    if (preg_match('/(\d{4})[-\/年.](\d{1,2})[-\/月.](\d{1,2})/', $s, $mm)) {
        $y = (int)$mm[1];
        $m = (int)$mm[2];
        $d = (int)$mm[3];
    } elseif (preg_match('/(\d{1,2})[-\/月.](\d{1,2})/', $s, $mm)) {
        $y = (int)substr($today, 0, 4);
        $m = (int)$mm[1];
        $d = (int)$mm[2];
    } else {
        return '';
    }
    if ($m < 1 || $m > 12 || $d < 1 || $d > 31 || !checkdate($m, $d, $y)) {
        return '';
    }
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/** 从模型输出中提取第一个 { ... } JSON 对象（容忍 ```json 围栏） */
function mimoocr_extract_json($content)
{
    $s = trim((string)$content);
    $s = preg_replace('/^```(?:json)?\s*/i', '', $s);
    $s = preg_replace('/\s*```$/', '', $s);
    $s = trim($s);
    $start = strpos($s, '{');
    if ($start === false) {
        return '';
    }
    $len = strlen($s);
    $depth = 0;
    $inStr = false;
    $esc = false;
    for ($i = $start; $i < $len; $i++) {
        $ch = $s[$i];
        if ($inStr) {
            if ($esc) {
                $esc = false;
            } elseif ($ch === '\\') {
                $esc = true;
            } elseif ($ch === '"') {
                $inStr = false;
            }
            continue;
        }
        if ($ch === '"') {
            $inStr = true;
        } elseif ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($s, $start, $i - $start + 1);
            }
        }
    }
    return '';
}

function mimoocr_contains($hay, $needle)
{
    return function_exists('mb_stripos') ? mb_stripos($hay, $needle) !== false : stripos($hay, $needle) !== false;
}

/**
 * 从本行原文中挑出实际写的工序（零件库候选工序里，确实出现在本行的那个）。
 * 关键：不能用零件库「铣1、铣2、钳」整串当工序，否则同一图号的多行会因
 * 工序相同而被去重吞掉，且工序字段本身也是错的。
 * 返回命中的工序；都没命中返回空串。
 */
function mimoocr_pick_process($line, $processStr) {
    $processStr = (string)$processStr;
    if ($processStr === '') {
        return '';
    }
    $cands = preg_split('/[、,，\/|]+/u', $processStr, -1, PREG_SPLIT_NO_EMPTY);
    /* 长候选优先，避免「铣」先命中导致「铣2」被漏 */
    usort($cands, function ($a, $b) {
        return mb_strlen(trim($b), 'UTF-8') - mb_strlen(trim($a), 'UTF-8');
    });
    foreach ($cands as $p) {
        $p = trim($p);
        if ($p !== '' && mimoocr_contains($line, $p)) {
            return $p;
        }
    }
    return '';
}

/**
 * 文本兜底解析：模型没给 JSON 时，按零件库模糊匹配与文本规律提取。
 * 与 qwen35-ocr 的兜底逻辑同源，保证结果风格一致。
 */
function mimoocr_fallback($content, $catalogLines)
{
    $warnings = array();
    $date = '';
    if (preg_match('/(20\d{2})\s*[-\/年.]\s*(\d{1,2})\s*[-\/月.]\s*(\d{1,2})(?:\s*日)?/u', $content, $mm)) {
        $date = sprintf('%04d-%02d-%02d', (int)$mm[1], (int)$mm[2], (int)$mm[3]);
    } elseif (preg_match('/(?:日期|报工日期|填单日期)\D{0,6}(\d{1,2})\s*[-\/月.]\s*(\d{1,2})(?:\s*日)?/u', $content, $mm)) {
        $date = sprintf('%04d-%02d-%02d', (int)substr($GLOBALS['BG_PLUGIN_CTX']['today'], 0, 4), (int)$mm[1], (int)$mm[2]);
    }

    $worker = '';
    $workshop = '';
    $note = '';
    if (preg_match('/(?:报工人|工人|姓名|操作者|加工人)\D{0,6}?([^\s，,;；|：:]{1,20})/u', $content, $mm)) {
        $worker = trim($mm[1]);
    }
    if (preg_match('/(?:车间|工段|班组)\D{0,6}?([^\s，,;；|：:]{1,20})/u', $content, $mm)) {
        $workshop = trim($mm[1]);
    }
    if (preg_match('/(?:备注|说明)\D{0,10}([^\n]{1,60})/u', $content, $mm)) {
        $note = trim($mm[1]);
    }

    // 零件库模糊匹配（含错字容差）
    $matchNo = '';
    $matchName = '';
    $matchProc = '';
    $matchScore = 0;
    $lines = preg_split('/\r\n|\r|\n/', (string)$content);
    foreach ($catalogLines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $parts = explode('|', $line);
        if (count($parts) < 2) {
            continue;
        }
        $no = trim(preg_replace('/^\s*\d+\.\s*/u', '', $parts[0]));
        $name = trim($parts[1]);
        if ($no === '') {
            continue;
        }
        foreach ($lines as $tl) {
            if (mimoocr_contains($tl, $no)) {
                $matchNo = $no;
                $matchName = $name;
                if (count($parts) >= 3 && trim($parts[2]) !== '') {
                    $matchProc = trim($parts[2]);
                }
                $matchScore = 1;
                break 2;
            }
        }
        // 容差 1 处错字的模糊匹配（图号 ≥6 位时）
        if (mb_strlen($no) >= 6) {
            foreach ($lines as $tl) {
                $t = mb_convert_kana($tl, 'as', 'UTF-8');
                $n = mb_convert_kana($no, 'as', 'UTF-8');
                $m = mb_strlen($n);
                $s = max(1, $m - 1);
                $tlLen = mb_strlen($t);
                for ($i = 0; $i + $s <= $tlLen; $i++) {
                    $frag = mb_substr($t, $i, $s);
                    $diff = 0;
                    for ($j = 0; $j < $s; $j++) {
                        if (mb_substr($frag, $j, 1) !== mb_substr($n, $j, 1)) {
                            $diff++;
                        }
                    }
                    if ($diff <= 1) {
                        $matchNo = $no;
                        $matchName = $name;
                        if (count($parts) >= 3 && trim($parts[2]) !== '') {
                            $matchProc = trim($parts[2]);
                        }
                        $matchScore = 0.9;
                        break 3;
                    }
                }
            }
        }
    }

    // 明细行提取：优先「图号 数量」或「名称 数量」
    $items = array();
    $seen = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/合计|总计|小计|签名|班长|日期|报工单|备注|表头/u', $line)) {
            continue;
        }
        if (!preg_match_all('/(\d+(?:\.\d+)?)/', $line, $mm)) {
            continue;
        }
        $qty = null;
        foreach ($mm[1] as $n) {
            $f = (float)$n;
            if ($f > 0 && $f <= 1000000 && strpos($n, '.') === false) {
                $qty = (int)$n;
            }
        }
        if ($qty === null) {
            continue;
        }
        $partNo = $matchNo;
        $partName = $matchName;
        $process = '';
        $procPool = $matchProc; /* 本行候选工序来源：优先本行匹配到的零件，其次全局模糊命中 */
        $matched = false;
        foreach ($catalogLines as $line2) {
            $p2 = explode('|', $line2);
            if (count($p2) < 2) {
                continue;
            }
            $no2 = trim(preg_replace('/^\s*\d+\.\s*/u', '', $p2[0]));
            $name2 = trim($p2[1]);
            if ($no2 !== '' && mimoocr_contains($line, $no2)) {
                $partNo = $no2;
                $partName = $name2;
                if (count($p2) >= 3 && trim($p2[2]) !== '') {
                    $procPool = trim($p2[2]);
                }
                $matched = true;
                break;
            }
            if ($name2 !== '' && mb_strlen($name2, 'UTF-8') >= 3 && mimoocr_contains($line, $name2)) {
                $partNo = $no2;
                $partName = $name2;
                if (count($p2) >= 3 && trim($p2[2]) !== '') {
                    $procPool = trim($p2[2]);
                }
                $matched = true;
                break;
            }
        }
        /* 只取本行实际出现的工序，不塞零件库整串 */
        $process = mimoocr_pick_process($line, $procPool);
        if ($partNo === '' && $partName === '') {
            $clean = preg_replace('/\s+/', ' ', $line);
            $clean = preg_replace('/[0-9.\s×xX*个件台套]+$/', '', $clean);
            $clean = preg_replace('/^[^一-龥A-Za-z0-9]+/', '', $clean);
            if (mb_strlen($clean) < 3) {
                continue;
            }
            $partName = mimoocr_str($clean, 40);
        }
        /* 去重键用「图号 + 本行工序 + 数量」：同一图号不同工序/数量的多行都是合法报工，必须保留 */
        $key = $partNo . '|' . $process . '|' . $qty;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $items[] = array(
            'partNo' => $partNo,
            'partName' => $partName,
            'process' => $process,
            'qty' => $qty,
            'price' => null,
        );
        if (count($items) >= 20) {
            break;
        }
    }
    if (!$items && $matchNo !== '') {
        $items[] = array('partNo' => $matchNo, 'partName' => $matchName, 'process' => $matchProc, 'qty' => 1, 'price' => null);
        $warnings[] = '只识别到零件信息，未取到数量';
    }

    if ($date === '' || !$items) {
        $warnings[] = '未能完整解析识别结果，请核对后手动填写';
    }
    return array(
        'fields' => array(
            'date' => $date,
            'worker' => $worker,
            'workshop' => $workshop,
            'note' => $note,
            'items' => $items,
        ),
        'warnings' => $warnings,
    );
}
