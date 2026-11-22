<?php
/*
 * 通义千问 Qwen3.5-OCR 插件 · 公共函数
 * 由 handler.php 与 check.php 引入，本身不是入口文件。
 */

if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

if (!defined('QWENOCR_DEFAULT_ENDPOINT')) {
    define('QWENOCR_DEFAULT_ENDPOINT', 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions');
    define('QWENOCR_DEFAULT_MODEL', 'qwen3.5-ocr');
}

/* 默认识别指令，可在设置页 textarea 里覆盖，支持 {TODAY} / {CATALOG} 占位符 */
function qwenocr_default_prompt() {
    return <<<TXT
你是工厂车间的「报工单」识别引擎，请识别图片中的报工单/生产流转单，只输出一个 JSON 对象，不要解释、不要 markdown 代码块。

输出结构：
{"date":"YYYY-MM-DD","worker":"报工人","workshop":"车间","note":"备注","confidence":0.9,"items":[{"partNo":"图号","partName":"零件名称","process":"工序","qty":12,"price":null}]}

识别规则：
1. date：取报工日期/填单日期，必须补全成 YYYY-MM-DD；只写月日时按当前年份补全年份；识别不到填空字符串。
2. worker / workshop / note：分别取报工人（或签名、班组长）、车间（或工段、班组）、备注，没有则填空字符串。
3. items：每行一条报工明细，最多 20 行；表头行、合计行、小计行、签名行不算明细。
4. partNo / partName / process 必须优先采用下方「零件库」中完全一致的写法；零件库里没有的，照单据原文填写，不得改写、缩写或编造。
5. process 只能是工序名（如车、铣、钻、磨、钳、热处理、电镀、装配），无法判断填空字符串。
6. qty：本行报工数量，纯数字，单位不要写进去；看不清或无法确认填 null，不要猜。
7. price：该件单价，纯数字；单据没有价格填 null。
8. confidence：0~1 之间，表示整单识别的把握程度。
9. 模糊、遮挡、无法辨认的单字用英文问号 ? 代替；宁可留空也不要编造内容。

当前日期：{TODAY}
零件库（图号 | 零件名称 | 可选工序）：
{CATALOG}
TXT;
}

/* ---------- 配置读取 ---------- */

function qwenocr_cfg($cfg, $key, $default = '') {
    if (!is_array($cfg) || !isset($cfg[$key])) return $default;
    $v = $cfg[$key];
    if (is_array($v) || is_object($v)) return $default;
    $v = trim((string)$v);
    return ($v === '') ? $default : $v;
}

function qwenocr_int($cfg, $key, $default, $min, $max) {
    $raw = qwenocr_cfg($cfg, $key, '');
    if ($raw === '' || !is_numeric($raw)) return $default;
    $v = (int)$raw;
    if ($v < $min) $v = $min;
    if ($v > $max) $v = $max;
    return $v;
}

function qwenocr_bool($cfg, $key, $default = false) {
    if (!is_array($cfg) || !array_key_exists($key, $cfg)) return $default;
    $v = trim((string)$cfg[$key]);
    if ($v === '') return false;
    return ($v === '1' || $v === '1.0' || strtolower($v) === 'true' || strtolower($v) === 'on');
}

function qwenocr_fail($code, $message) {
    if (function_exists('bg_fail')) {
        bg_fail($code, $message);
    }
    throw new RuntimeException($message);
}

/* ---------- 出网 ---------- */

/* 优先用系统自带的 lib/cacert.pem，其次系统 CA 包 */
function qwenocr_ca() {
    $candidates = array(
        __DIR__ . '/../../lib/cacert.pem',
        __DIR__ . '/../../../lib/cacert.pem',
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/cert.pem',
    );
    foreach ($candidates as $c) {
        $r = @realpath($c);
        if ($r !== false && is_file($r)) return $r;
    }
    return '';
}

/**
 * POST JSON。返回 array('code'=>int,'body'=>string,'err'=>string)
 * err 非空表示传输层失败（DNS / 连接 / 超时），HTTP 4xx 5xx 不算 err。
 */
function qwenocr_http_post($url, $payload, $headers, $timeout = 30, $skip_tls = false) {
    $out = array('code' => 0, 'body' => '', 'err' => '');

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            $out['err'] = '无法初始化 curl';
            return $out;
        }
        $opts = array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(3, min(10, (int)$timeout)),
            CURLOPT_TIMEOUT        => max(1, (int)$timeout),
            CURLOPT_SSL_VERIFYPEER => !$skip_tls,
            CURLOPT_SSL_VERIFYHOST => $skip_tls ? 0 : 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
        );
        if (defined('CURLOPT_NOSIGNAL')) {
            $opts[CURLOPT_NOSIGNAL] = 1;
        }
        curl_setopt_array($ch, $opts);
        if (!$skip_tls) {
            $ca = qwenocr_ca();
            if ($ca !== '') curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        $body = curl_exec($ch);
        if ($body === false) {
            $out['err'] = curl_error($ch);
            if ($out['err'] === '') $out['err'] = 'curl 请求失败';
            curl_close($ch);
            return $out;
        }
        $out['code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $out['body'] = (string)$body;
        return $out;
    }

    /* 没有 curl 扩展时退回 ssl socket，仍走 https 并校验证书 */
    $p = parse_url($url);
    $host = isset($p['host']) ? $p['host'] : '';
    $port = isset($p['port']) ? (int)$p['port'] : 443;
    $path = (isset($p['path']) && $p['path'] !== '') ? $p['path'] : '/';
    if (isset($p['query']) && $p['query'] !== '') $path .= '?' . $p['query'];
    if ($host === '') {
        $out['err'] = '接口地址无法解析';
        return $out;
    }

    $ssl = array(
        'verify_peer'      => !$skip_tls,
        'verify_peer_name' => !$skip_tls,
        'allow_self_signed'=> $skip_tls,
    );
    if (!$skip_tls) {
        $ca = qwenocr_ca();
        if ($ca !== '') $ssl['cafile'] = $ca;
    }
    $context = stream_context_create(array('ssl' => $ssl, 'http' => array('ignore_errors' => true, 'timeout' => max(1, (int)$timeout))));
    $line = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, max(3, min(15, (int)$timeout)), STREAM_CLIENT_CONNECT, $context);
    if (!$line) {
        $out['err'] = ($errstr !== '' ? $errstr : 'SSL 连接失败') . '(#' . $errno . ')';
        return $out;
    }
    @stream_set_timeout($line, max(1, (int)$timeout));

    $req = 'POST ' . $path . " HTTP/1.1\r\n"
         . 'Host: ' . $host . "\r\n"
         . 'Accept: application/json\r\n'
         . 'Connection: close\r\n';
    foreach ($headers as $h) {
        $req .= $h . "\r\n";
    }
    $req .= 'Content-Length: ' . strlen($payload) . "\r\n\r\n" . $payload;

    $deadline = microtime(true) + max(1, (int)$timeout);
    $written = 0;
    $len = strlen($req);
    while ($written < $len && microtime(true) < $deadline) {
        $n = @fwrite($line, substr($req, $written));
        if ($n === false || $n === 0) break;
        $written += $n;
    }
    if ($written < $len) {
        fclose($line);
        $out['err'] = '请求发送不完整（可能超时）';
        return $out;
    }

    $raw = '';
    while (!feof($line)) {
        if (microtime(true) > $deadline) break;
        $chunk = fread($line, 65535);
        if ($chunk === false || $chunk === '') break;
        $raw .= $chunk;
    }
    $meta = @stream_get_meta_data($line);
    fclose($line);
    if ($raw === '') {
        $out['err'] = (isset($meta['timed_out']) && $meta['timed_out']) ? '等待响应超时' : '接口没有返回内容';
        return $out;
    }

    $split = strpos($raw, "\r\n\r\n");
    $head = ($split === false) ? $raw : substr($raw, 0, $split);
    $body = ($split === false) ? '' : substr($raw, $split + 4);
    if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', ltrim($head), $m)) {
        $out['code'] = (int)$m[1];
    }
    /* 分块传输时把 chunk 长度行去掉 */
    if (stripos($head, 'Transfer-Encoding: chunked') !== false) {
        $body = qwenocr_dechunk($body);
    }
    $out['body'] = $body;
    return $out;
}

function qwenocr_dechunk($body) {
    $out = '';
    $i = 0;
    $n = strlen($body);
    while ($i < $n) {
        $eol = strpos($body, "\r\n", $i);
        if ($eol === false) break;
        $size = hexdec(trim(substr($body, $i, $eol - $i)));
        if ($size <= 0) break;
        $out .= substr($body, $eol + 2, $size);
        $i = $eol + 2 + $size + 2;
    }
    return $out === '' ? $body : $out;
}

/* ---------- 归一化小工具 ---------- */

/* 按候选键取原始值（保留数字类型），找不到返回 '' */
function qwenocr_field($arr, $keys) {
    if (!is_array($arr)) return '';
    foreach ($keys as $k) {
        if (array_key_exists($k, $arr) && $arr[$k] !== null && $arr[$k] !== '') return $arr[$k];
    }
    return '';
}

function qwenocr_pick($arr, $keys) {
    $v = qwenocr_field($arr, $keys);
    if (is_array($v) || is_object($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
    return trim(preg_replace('/\s+/u', ' ', (string)$v));
}

/* 压掉换行并按字数截断（后端也有截断，这里避免超长） */
function qwenocr_str($v, $max) {
    if (is_array($v) || is_object($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
    $s = trim(preg_replace('/\s+/u', ' ', (string)$v));
    if (in_array(strtolower($s), array('null', 'none', 'undefined', 'nan'), true)) $s = '';
    if (function_exists('mb_substr')) {
        if (mb_strlen($s, 'UTF-8') > $max) $s = mb_substr($s, 0, $max, 'UTF-8');
    } elseif (preg_match('/^.{0,' . (int)$max . '}/us', $s, $m)) {
        $s = $m[0];
    }
    return $s;
}

/* 保留换行的原文截断，用于 raw */
function qwenocr_cut($s, $max) {
    $s = (string)$s;
    if (function_exists('mb_substr')) {
        if (mb_strlen($s, 'UTF-8') > $max) return mb_substr($s, 0, $max - 1, 'UTF-8') . '…';
        return $s;
    }
    if (strlen($s) > $max * 3 && preg_match('/^.{0,' . ((int)$max - 1) . '}/us', $s, $m)) {
        return $m[0] . '…';
    }
    return $s;
}

/* 数量 / 单价：从 "12件" "1,200" "￥3.5" 里取数，超范围或非数字返回 null */
function qwenocr_qty($v) {
    if ($v === null || $v === '' || is_bool($v)) return null;
    if (is_numeric($v)) {
        $n = (float)$v;
    } else {
        if (is_array($v) || is_object($v)) return null;
        $s = str_replace(array(',', '，', ' ', '　'), '', (string)$v);
        if (!preg_match('/-?\d+(?:\.\d+)?/', $s, $m)) return null;
        $n = (float)$m[0];
    }
    if (!is_finite($n) || $n < 0 || $n > 1000000) return null;
    return round($n, 2);
}

/* 严格日期：支持 2026-11-20 / 2026/11/20 / 2026年11月20日 / 11-20（补当前年） */
function qwenocr_date($v, $today) {
    $s = trim((string)$v);
    if ($s === '') return '';
    if (preg_match('/(\d{4})\s*[-\/.年]\s*(\d{1,2})\s*[-\/.月]\s*(\d{1,2})/u', $s, $m)) {
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
    }
    if (preg_match('/^(\d{1,2})\s*[-\/.月]\s*(\d{1,2})\s*日?$/u', $s, $m)) {
        $y = (int)substr($today, 0, 4);
        if ($y > 1900 && checkdate((int)$m[1], (int)$m[2], $y)) {
            return sprintf('%04d-%02d-%02d', $y, (int)$m[1], (int)$m[2]);
        }
    }
    return '';
}

/* 从模型输出里抠出 JSON 对象（兼容 ```json 围栏、前后带说明文字） */
function qwenocr_extract_json($text) {
    $t = trim((string)$text);
    if ($t === '') return null;
    if (preg_match('/^```[a-zA-Z0-9_-]*\s*(.*?)\s*```$/s', $t, $m)) $t = trim($m[1]);

    $d = json_decode($t, true);
    if (is_array($d)) return $d;

    $s = strpos($t, '{');
    $e = strrpos($t, '}');
    if ($s !== false && $e !== false && $e > $s) {
        $d = json_decode(substr($t, $s, $e - $s + 1), true);
        if (is_array($d)) return $d;
    }
    $s = strpos($t, '[');
    $e = strrpos($t, ']');
    if ($s !== false && $e !== false && $e > $s) {
        $d = json_decode(substr($t, $s, $e - $s + 1), true);
        if (is_array($d)) return $d;
    }
    return null;
}

/* 大小写不敏感的包含判断，不依赖 mbstring */
function qwenocr_contains($haystack, $needle) {
    if ($needle === '' || $needle === null) return false;
    if (function_exists('mb_stripos')) return mb_stripos($haystack, $needle) !== false;
    return stripos($haystack, $needle) !== false;
}

/*
 * 模型没有给出合法 JSON 时的兜底：只从原文里挑「确实存在」的信息，
 * 图号必须命中零件库或形如图号的字符串，避免臆造明细。
 */
function qwenocr_fallback($text, $catalog, $today) {
    $res = array('date' => '', 'worker' => '', 'workshop' => '', 'note' => '', 'items' => array());
    $text = (string)$text;

    if (preg_match('/(\d{4})\s*[-\/.年]\s*(\d{1,2})\s*[-\/.月]\s*(\d{1,2})/u', $text, $m)) {
        $res['date'] = qwenocr_date($m[0], $today);
    }
    /* 车间/报工人必须带冒号等显式分隔，避免把「车间报工单」这类标题当成车间名 */
    if (preg_match('/(报工人|报工员|记录人|操作员)\s*[:：]\s*([^\s,，、;：:]{1,12})/u', $text, $m)) {
        $res['worker'] = $m[2];
    } elseif (preg_match('/签\s*名\s*[:：]\s*([^\s,，、;：:]{1,12})/u', $text, $m)) {
        $res['worker'] = $m[1];
    }
    if (preg_match_all('/(车间|工段|班组)\s*[:：]\s*([^\s,，、;：:]{1,12})/u', $text, $mw, PREG_SET_ORDER)) {
        $last = $mw[count($mw) - 1];
        if (!preg_match('/(单|表|栏|名称|号)$/u', $last[2])) $res['workshop'] = $last[2];
    }

    foreach (preg_split('/\r\n|\r|\n/u', $text) as $line) {
        if (count($res['items']) >= 20) break;
        $line = trim($line);
        if ($line === '' || strlen($line) < 4) continue;
        if (preg_match('/(序号|零件名|零件名称|图\s*号|工序|单价|合计|小计|总计|金额|页码|客户名称)/u', $line)
            && !preg_match('/\d{3,}/u', $line)) continue;

        $no = '';
        $name = '';
        $proc = '';
        foreach ($catalog as $p) {
            if (!is_array($p)) continue;
            $pn = isset($p['no']) ? trim((string)$p['no']) : '';
            if ($pn !== '' && qwenocr_contains($line, $pn)) {
                $no = $pn;
                $name = isset($p['name']) ? trim((string)$p['name']) : '';
                /* 工序：该零件的可选工序里，确实在本行原文出现的才算识别到 */
                if (isset($p['processes']) && is_array($p['processes'])) {
                    foreach ($p['processes'] as $ps) {
                        $ps = trim((string)$ps);
                        if ($ps !== '' && qwenocr_contains($line, $ps)) { $proc = $ps; break; }
                    }
                }
                break;
            }
        }
        if ($no === '' && preg_match('/(?<![A-Za-z0-9])([A-Za-z][A-Za-z0-9\-\/\.]{4,29})(?![A-Za-z0-9])/u', $line, $m)) {
            $no = $m[1];
        }
        if ($no === '') continue;

        $bare = preg_replace('/[A-Za-z0-9][A-Za-z0-9\-\/\.]{4,}/u', ' ', $line);
        $qty = null;
        if (preg_match_all('/(\d+(?:\.\d+)?)/u', $bare, $mm) && $mm[1]) {
            $qty = qwenocr_qty(end($mm[1]));
        }
        $res['items'][] = array(
            'partNo' => $no,
            'partName' => $name,
            'process' => $proc,
            'qty' => ($qty === null) ? null : $qty,
            'price' => null,
        );
    }
    return $res;
}
