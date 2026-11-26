<?php
/**
 * 腾讯云多引擎视觉识别插件 · 公共底座（T01）
 *
 * 职责：配置读取、整型/布尔/字符串/日期/数量归一、字符串相似度、原文佐证、
 *      统一上限收口、旁路日志，以及 mb_* 缺失时的 UTF-8 polyfill。
 *
 * 本文件是纯函数库，不含任何引擎细节；全部函数统一 bg_tv_ 前缀，
 * 通过常量守卫防止同一 PHP 进程内被重复引入导致函数重定义。
 */

if (defined('BG_TV_UTIL_LOADED')) {
    return;
}
define('BG_TV_UTIL_LOADED', 1);

/* ------------------------------------------------------------------------- */
/* mbstring polyfill：缺失时用 preg_.../u 逐字符回退，保证 UTF-8 安全          */
/* ------------------------------------------------------------------------- */

if (!function_exists('mb_strlen')) {
    /**
     * 计算 UTF-8 字符数（polyfill）。
     *
     * @param string $s   输入字符串
     * @param string $enc 编码，仅兼容参数
     * @return int
     */
    function mb_strlen($s, $enc = 'UTF-8')
    {
        $s = (string)$s;
        if ($s === '') {
            return 0;
        }
        $n = preg_match_all('/./us', $s);
        return $n === false ? strlen($s) : (int)$n;
    }
}

if (!function_exists('mb_substr')) {
    /**
     * 按 UTF-8 字符截取子串（polyfill）。
     *
     * @param string   $s     输入字符串
     * @param int      $start 起始字符位置，负数从尾部计
     * @param int|null $len   截取长度，null 表示到结尾
     * @param string   $enc   编码，仅兼容参数
     * @return string
     */
    function mb_substr($s, $start, $len = null, $enc = 'UTF-8')
    {
        $s = (string)$s;
        if ($s === '') {
            return '';
        }
        preg_match_all('/./us', $s, $m);
        $chars = isset($m[0]) ? $m[0] : array();
        $total = count($chars);
        $start = (int)$start;
        if ($start < 0) {
            $start = max(0, $total + $start);
        }
        if ($len === null) {
            $len = $total - $start;
        } else {
            $len = (int)$len;
            if ($len < 0) {
                $len = max(0, ($total + $len) - $start);
            }
        }
        return implode('', array_slice($chars, $start, $len));
    }
}

if (!function_exists('mb_strtolower')) {
    /**
     * 转小写（polyfill）：ASCII 用 strtolower，多字节尽力回退。
     *
     * @param string $s   输入字符串
     * @param string $enc 编码，仅兼容参数
     * @return string
     */
    function mb_strtolower($s, $enc = 'UTF-8')
    {
        return strtolower((string)$s);
    }
}

if (!function_exists('mb_stripos')) {
    /**
     * 大小写不敏感查找（polyfill），返回字符偏移。
     *
     * @param string $hay     haystack
     * @param string $needle  子串
     * @param int    $offset  起始字符偏移
     * @param string $enc     编码，仅兼容参数
     * @return int|false
     */
    function mb_stripos($hay, $needle, $offset = 0, $enc = 'UTF-8')
    {
        $hay = (string)$hay;
        $needle = (string)$needle;
        if ($needle === '') {
            return 0;
        }
        $offset = max(0, (int)$offset);
        $tail = mb_substr($hay, $offset);
        $pos = stripos($tail, $needle);
        if ($pos === false) {
            return false;
        }
        return $offset + mb_strlen(substr($tail, 0, $pos));
    }
}

/* ------------------------------------------------------------------------- */
/* 配置读取                                                                   */
/* ------------------------------------------------------------------------- */

/**
 * 读取字符串配置；值缺失或为空串时返回默认值。
 *
 * @param array  $cfg      配置数组
 * @param string $key      配置键
 * @param string $default  默认值
 * @return string
 */
function bg_tv_cfg($cfg, $key, $default = '')
{
    if (!is_array($cfg) || !isset($cfg[$key])) {
        return $default;
    }
    $v = $cfg[$key];
    if (is_array($v) || is_object($v)) {
        return $default;
    }
    $v = trim((string)$v);
    return ($v === '') ? $default : $v;
}

/**
 * 读取整数配置并夹逼到 [min, max]。
 *
 * @param array $cfg      配置数组
 * @param string $key     配置键
 * @param int $default    默认值
 * @param int $min        下限
 * @param int $max        上限
 * @return int
 */
function bg_tv_int($cfg, $key, $default, $min, $max)
{
    $raw = bg_tv_cfg($cfg, $key, '');
    if ($raw === '' || !is_numeric($raw)) {
        return (int)$default;
    }
    $v = (int)$raw;
    if ($v < $min) {
        $v = (int)$min;
    }
    if ($v > $max) {
        $v = (int)$max;
    }
    return $v;
}

/**
 * 读取浮点配置并夹逼到 [min, max]。
 *
 * @param array $cfg      配置数组
 * @param string $key     配置键
 * @param float $default  默认值
 * @param float $min      下限
 * @param float $max      上限
 * @return float
 */
function bg_tv_float($cfg, $key, $default, $min, $max)
{
    $raw = bg_tv_cfg($cfg, $key, '');
    if ($raw === '' || !is_numeric($raw)) {
        return (float)$default;
    }
    $v = (float)$raw;
    if ($v < $min) {
        $v = (float)$min;
    }
    if ($v > $max) {
        $v = (float)$max;
    }
    return $v;
}

/**
 * 读取布尔配置（checkbox/on/true/1）。
 *
 * @param array $cfg      配置数组
 * @param string $key     配置键
 * @param bool $default   默认值
 * @return bool
 */
function bg_tv_bool($cfg, $key, $default = false)
{
    if (!is_array($cfg) || !array_key_exists($key, $cfg)) {
        return (bool)$default;
    }
    $v = trim((string)$cfg[$key]);
    if ($v === '') {
        return false;
    }
    return ($v === '1' || $v === '1.0' || strtolower($v) === 'true' || strtolower($v) === 'on');
}

/* ------------------------------------------------------------------------- */
/* 字符串 / 日期 / 数量归一                                                    */
/* ------------------------------------------------------------------------- */

/**
 * 压缩空白并按字符数截断；过滤 null/none 等空值文本。
 *
 * @param mixed $value 输入值
 * @param int   $max   最大字符数
 * @return string
 */
function bg_tv_str($value, $max)
{
    if (is_array($value) || is_object($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $s = trim(preg_replace('/\s+/u', ' ', (string)$value));
    if (in_array(strtolower($s), array('null', 'none', 'undefined', 'nan'), true)) {
        return '';
    }
    $max = max(1, (int)$max);
    if (mb_strlen($s, 'UTF-8') > $max) {
        $s = mb_substr($s, 0, $max, 'UTF-8');
    }
    return $s;
}

/**
 * 保留换行的原文截断，超长时末尾加省略号。
 *
 * @param mixed $value 输入值
 * @param int   $max   最大字符数
 * @return string
 */
function bg_tv_cut($value, $max)
{
    $s = (string)$value;
    $max = max(1, (int)$max);
    if (mb_strlen($s, 'UTF-8') > $max) {
        return mb_substr($s, 0, $max - 1, 'UTF-8') . '…';
    }
    return $s;
}

/**
 * 严格日期归一：支持 YYYY-MM-DD / YYYY/MM/DD / YYYY年M月D日 / M-D（补当前年）。
 * 非法日期一律返回空串。
 *
 * @param mixed  $value 输入日期文本
 * @param string $today 当天日期 YYYY-MM-DD，用于补年份
 * @return string YYYY-MM-DD 或空串
 */
function bg_tv_date($value, $today)
{
    $s = trim((string)$value);
    if ($s === '') {
        return '';
    }
    if (preg_match('/(\d{4})\s*[-\/.年]\s*(\d{1,2})\s*[-\/.月]\s*(\d{1,2})/u', $s, $m)) {
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
    }
    if (preg_match('/^(\d{1,2})\s*[-\/.月]\s*(\d{1,2})\s*日?$/u', $s, $m)) {
        $y = (int)substr((string)$today, 0, 4);
        if ($y > 1900 && checkdate((int)$m[1], (int)$m[2], $y)) {
            return sprintf('%04d-%02d-%02d', $y, (int)$m[1], (int)$m[2]);
        }
    }
    return '';
}

/**
 * 数量 / 单价归一：从 "12件" "1,200" "￥3.5" 中取数，超范围或非数字返回 null。
 *
 * @param mixed $value 输入值
 * @return float|null
 */
function bg_tv_qty($value)
{
    if ($value === null || $value === '' || is_bool($value)) {
        return null;
    }
    if (is_numeric($value)) {
        $n = (float)$value;
    } else {
        if (is_array($value) || is_object($value)) {
            return null;
        }
        $s = str_replace(array(',', '，', ' ', '　'), '', (string)$value);
        if (!preg_match('/-?\d+(?:\.\d+)?/', $s, $m)) {
            return null;
        }
        $n = (float)$m[0];
    }
    if (!is_finite($n) || $n < 0 || $n > 1000000) {
        return null;
    }
    return round($n, 2);
}

/**
 * 置信度归一：夹逼到 0~1 并保留 3 位小数。
 *
 * @param mixed $value   输入值
 * @param float $default 无法解析时的默认值
 * @return float
 */
function bg_tv_conf($value, $default = 0.0)
{
    $n = bg_tv_qty($value);
    if ($n === null) {
        $n = (float)$default;
    }
    // 越界值按 clamp 处理（架构 §2.2：confidence = round(clamp, 3)）。
    return round(min(1.0, max(0.0, $n)), 3);
}

/**
 * 空值判定：仅 null 与空串视为空（0 是有效数量）。
 *
 * @param mixed $v 输入值
 * @return bool
 */
function bg_tv_empty($v)
{
    return ($v === null || $v === '');
}

/* ------------------------------------------------------------------------- */
/* 相似度与原文佐证                                                            */
/* ------------------------------------------------------------------------- */

/**
 * 生成 uni-gram + bi-gram 集合（按 UTF-8 字符）。
 *
 * @param string $s 输入文本
 * @return array
 */
function bg_tv_ngrams($s)
{
    $chars = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars)) {
        $chars = array();
    }
    $grams = array();
    foreach ($chars as $c) {
        $grams[] = $c;
    }
    $n = count($chars);
    for ($i = 0; $i + 1 < $n; $i++) {
        $grams[] = $chars[$i] . $chars[$i + 1];
    }
    return $grams;
}

/**
 * Dice 相似度（uni+bi-gram），返回 0~1。
 *
 * @param string $a 文本 A
 * @param string $b 文本 B
 * @return float
 */
function bg_tv_dice($a, $b)
{
    $a = trim((string)$a);
    $b = trim((string)$b);
    if ($a === '' || $b === '') {
        return ($a === $b) ? 1.0 : 0.0;
    }
    $ga = bg_tv_ngrams($a);
    $gb = bg_tv_ngrams($b);
    if (!$ga || !$gb) {
        return 0.0;
    }
    $pool = array();
    foreach ($ga as $g) {
        $pool[$g] = isset($pool[$g]) ? $pool[$g] + 1 : 1;
    }
    $hit = 0;
    foreach ($gb as $g) {
        if (isset($pool[$g]) && $pool[$g] > 0) {
            $hit++;
            $pool[$g]--;
        }
    }
    return (float)(2 * $hit) / (count($ga) + count($gb));
}

/**
 * 判断两值是否一致：数值用相对误差，字符串用 Dice。
 *
 * @param mixed $a         值 A
 * @param mixed $b         值 B
 * @param float $threshold 一致阈值
 * @return bool
 */
function bg_tv_same($a, $b, $threshold)
{
    if (bg_tv_empty($a) || bg_tv_empty($b)) {
        return ($a === $b);
    }
    $threshold = min(1.0, max(0.0, (float)$threshold));
    if (is_numeric($a) && is_numeric($b)) {
        $den = max(abs((float)$a), abs((float)$b), 1.0);
        return (1.0 - abs((float)$a - (float)$b) / $den) >= $threshold;
    }
    return bg_tv_dice($a, $b) >= $threshold;
}

/**
 * 大小写不敏感包含判断，不依赖 mbstring。
 *
 * @param string $hay     原文
 * @param string $needle  片段
 * @return bool
 */
function bg_tv_contains($hay, $needle)
{
    $needle = (string)$needle;
    if ($needle === '') {
        return false;
    }
    return mb_stripos((string)$hay, $needle, 0, 'UTF-8') !== false;
}

/**
 * 轻量原文佐证：从候选值取图号片段或数字片段，在原文中做包含判断。
 * 无佐证不直接剔除，只用于择优降权。
 *
 * @param mixed  $value 候选值
 * @param string $raw   原文
 * @return bool
 */
function bg_tv_raw_support($value, $raw)
{
    $s = trim((string)$value);
    $raw = (string)$raw;
    if ($s === '' || $raw === '') {
        return false;
    }
    $needles = array();
    if (preg_match('/[A-Za-z][A-Za-z0-9\-\/\.]{3,}/', $s, $m)) {
        $needles[] = $m[0];
    }
    if (preg_match('/\d+(?:\.\d+)?/', $s, $m)) {
        $needles[] = $m[0];
    }
    if (!$needles) {
        $needles[] = $s;
    }
    foreach ($needles as $n) {
        if (bg_tv_contains($raw, $n)) {
            return true;
        }
    }
    return false;
}

/**
 * 求中位数（用于 OCR 行高容差）。
 *
 * @param array $nums 数值数组
 * @return float
 */
function bg_tv_median($nums)
{
    $nums = array_values(array_filter((array)$nums, 'is_numeric'));
    if (!$nums) {
        return 0.0;
    }
    sort($nums, SORT_NUMERIC);
    $n = count($nums);
    $mid = (int)floor($n / 2);
    if ($n % 2 === 1) {
        return (float)$nums[$mid];
    }
    return ((float)$nums[$mid - 1] + (float)$nums[$mid]) / 2;
}

/* ------------------------------------------------------------------------- */
/* 出网与日志                                                                 */
/* ------------------------------------------------------------------------- */

/**
 * 是否公网 IP：排除私有、保留、回环、链路本地（含云元数据 169.254.169.254）。
 *
 * @param string $ip IP 字面量
 * @return bool
 */
function bg_tv_ip_public($ip)
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * 主机名是否可安全外访：localhost/.local、IP 字面量、DNS 解析结果都必须是公网。
 *
 * @param string $host 主机名（可带方括号的 IPv6）
 * @return bool
 */
function bg_tv_host_public($host)
{
    $host = strtolower(trim((string)$host, " \t\n\r\0\x0B[]"));
    if ($host === '' || $host === 'localhost' || substr($host, -6) === '.local') {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return bg_tv_ip_public($host);
    }
    $ips = @gethostbynamel($host);
    if (!is_array($ips) || !count($ips)) {
        return false;
    }
    foreach ($ips as $ip) {
        if (!bg_tv_ip_public($ip)) {
            return false;
        }
    }
    return true;
}

/**
 * 是否可安全外访的 HTTPS 地址：https + 无 userinfo + 主机解析后全部为公网 IP。
 * 自建代理域名只要解析到公网即可通过，内网与本机地址一律拒绝。
 *
 * @param string $url 完整 URL
 * @return bool
 */
function bg_tv_url_https_public($url)
{
    $p = @parse_url((string)$url);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
        return false;
    }
    if (strtolower($p['scheme']) !== 'https') {
        return false;
    }
    if (isset($p['user']) || isset($p['pass'])) {
        return false;
    }
    if (isset($p['port']) && ((int)$p['port'] < 1 || (int)$p['port'] > 65535)) {
        return false;
    }
    return bg_tv_host_public($p['host']);
}

/**
 * OCR 端点只存 host（可带端口/路径，不含协议），补 https:// 后复用同一套校验。
 *
 * @param string $endpoint 形如 ocr.tencentcloudapi.com 或 proxy.example.com/ocr
 * @return bool
 */
function bg_tv_host_endpoint_public($endpoint)
{
    $endpoint = ltrim(trim((string)$endpoint), '/');
    if ($endpoint === '' || preg_match('/[\s]/', $endpoint)) {
        return false;
    }
    return bg_tv_url_https_public('https://' . $endpoint);
}

/**
 * 解析 CA 证书路径：优先 php.ini，其次系统包。
 *
 * @return string 证书文件绝对路径，找不到返回空串
 */
function bg_tv_ca_file()
{
    foreach (array('curl.cainfo', 'openssl.cafile') as $k) {
        $v = ini_get($k);
        if (is_string($v) && $v !== '' && is_file($v)) {
            return $v;
        }
    }
    foreach (array(
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/cert.pem',
        dirname(__DIR__, 3) . '/lib/cacert.pem',
    ) as $f) {
        $r = @realpath($f);
        if ($r !== false && is_file($r)) {
            return $r;
        }
    }
    return '';
}

/**
 * 单次 POST JSON。返回 code/body/err/errno；HTTP 4xx/5xx 记入 code 不算传输错误。
 *
 * @param string $url      完整 URL
 * @param string $payload  请求体
 * @param array  $headers  请求头
 * @param int    $timeout  超时秒数
 * @param bool   $skipTls  是否跳过证书校验
 * @return array{code:int,body:string,err:string,errno:int}
 */
function bg_tv_http_post($url, $payload, $headers, $timeout = 30, $skipTls = false)
{
    $out = array('code' => 0, 'body' => '', 'err' => '', 'errno' => 0);
    $timeout = max(1, (int)$timeout);
    $headers = is_array($headers) ? $headers : array();

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
            CURLOPT_CONNECTTIMEOUT => max(3, min(10, $timeout)),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => !$skipTls,
            CURLOPT_SSL_VERIFYHOST => $skipTls ? 0 : 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
        );
        if (defined('CURLOPT_NOSIGNAL')) {
            $opts[CURLOPT_NOSIGNAL] = 1;
        }
        curl_setopt_array($ch, $opts);
        if (!$skipTls) {
            $ca = bg_tv_ca_file();
            if ($ca !== '') {
                curl_setopt($ch, CURLOPT_CAINFO, $ca);
            }
        }
        $body = curl_exec($ch);
        if ($body === false) {
            $out['err'] = curl_error($ch);
            $out['errno'] = (int)curl_errno($ch);
            if ($out['err'] === '') {
                $out['err'] = 'curl 请求失败';
            }
            curl_close($ch);
            return $out;
        }
        $out['code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $out['body'] = (string)$body;
        return $out;
    }

    /* 无 curl 时退回 SSL socket，仍走 https */
    $p = parse_url($url);
    $host = isset($p['host']) ? (string)$p['host'] : '';
    $port = isset($p['port']) ? (int)$p['port'] : 443;
    $path = (isset($p['path']) && $p['path'] !== '') ? (string)$p['path'] : '/';
    if (isset($p['query']) && $p['query'] !== '') {
        $path .= '?' . $p['query'];
    }
    if ($host === '') {
        $out['err'] = '接口地址无法解析';
        return $out;
    }
    $ssl = array(
        'verify_peer'       => !$skipTls,
        'verify_peer_name'  => !$skipTls,
        'allow_self_signed' => $skipTls,
    );
    if (!$skipTls) {
        $ca = bg_tv_ca_file();
        if ($ca !== '') {
            $ssl['cafile'] = $ca;
        }
    }
    $ctx = stream_context_create(array(
        'ssl'  => $ssl,
        'http' => array('ignore_errors' => true, 'timeout' => $timeout),
    ));
    $line = @stream_socket_client(
        'ssl://' . $host . ':' . $port,
        $errno,
        $errstr,
        max(3, min(15, $timeout)),
        STREAM_CLIENT_CONNECT,
        $ctx
    );
    if (!$line) {
        $out['err'] = ($errstr !== '' ? (string)$errstr : 'SSL 连接失败') . '(#' . (int)$errno . ')';
        $out['errno'] = (int)$errno;
        return $out;
    }
    @stream_set_timeout($line, $timeout);
    $req = 'POST ' . $path . " HTTP/1.1\r\n"
        . 'Host: ' . $host . "\r\n"
        . 'Accept: application/json\r\n'
        . 'Connection: close\r\n';
    foreach ($headers as $h) {
        $req .= (string)$h . "\r\n";
    }
    $req .= 'Content-Length: ' . strlen((string)$payload) . "\r\n\r\n" . $payload;
    $deadline = microtime(true) + $timeout;
    $written = 0;
    $len = strlen($req);
    while ($written < $len && microtime(true) < $deadline) {
        $n = @fwrite($line, substr($req, $written));
        if ($n === false || $n === 0) {
            break;
        }
        $written += $n;
    }
    if ($written < $len) {
        fclose($line);
        $out['err'] = '请求发送不完整（可能超时）';
        $out['errno'] = 28;
        return $out;
    }
    $raw = '';
    while (!feof($line)) {
        if (microtime(true) > $deadline) {
            break;
        }
        $chunk = fread($line, 65535);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    $meta = @stream_get_meta_data($line);
    fclose($line);
    if ($raw === '') {
        $out['err'] = (isset($meta['timed_out']) && $meta['timed_out']) ? '等待响应超时' : '接口没有返回内容';
        $out['errno'] = 28;
        return $out;
    }
    $split = strpos($raw, "\r\n\r\n");
    $head = ($split === false) ? $raw : substr($raw, 0, $split);
    $body = ($split === false) ? '' : substr($raw, $split + 4);
    if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', ltrim($head), $m)) {
        $out['code'] = (int)$m[1];
    }
    if (stripos($head, 'Transfer-Encoding: chunked') !== false) {
        $body = bg_tv_dechunk($body);
    }
    $out['body'] = $body;
    return $out;
}

/**
 * 解析 HTTP 分块传输编码。
 *
 * @param string $body 原始响应体
 * @return string
 */
function bg_tv_dechunk($body)
{
    $out = '';
    $i = 0;
    $n = strlen($body);
    while ($i < $n) {
        $eol = strpos($body, "\r\n", $i);
        if ($eol === false) {
            break;
        }
        $size = hexdec(trim(substr($body, $i, $eol - $i)));
        if ($size <= 0) {
            break;
        }
        $out .= substr($body, $eol + 2, (int)$size);
        $i = $eol + 2 + (int)$size + 2;
    }
    return ($out === '') ? $body : $out;
}

/**
 * 判断 curl errno 是否属于可重试的网络瞬时故障。
 *
 * @param int $errno curl errno
 * @return bool
 */
function bg_tv_retryable($errno)
{
    return in_array((int)$errno, array(7, 28, 52, 55, 56), true);
}

/**
 * 带重试的 POST（串行降级路径与自检使用）。
 * 仅网络类故障与 HTTP 429/5xx 重试；4xx 业务错误不重试。
 *
 * @param array $job     array(url,payload,headers,timeout,skipTls)
 * @param int   $retries 额外重试次数
 * @return array{code:int,body:string,err:string,errno:int,attempts:int}
 */
function bg_tv_http_send($job, $retries = 1)
{
    $url = isset($job['url']) ? (string)$job['url'] : '';
    $payload = isset($job['payload']) ? (string)$job['payload'] : '';
    $headers = isset($job['headers']) && is_array($job['headers']) ? $job['headers'] : array();
    $timeout = isset($job['timeout']) ? (int)$job['timeout'] : 30;
    $skipTls = !empty($job['skipTls']);
    $attempts = max(1, (int)$retries) + 1;
    $last = array('code' => 0, 'body' => '', 'err' => '', 'errno' => 0, 'attempts' => 0);
    for ($i = 1; $i <= $attempts; $i++) {
        $r = bg_tv_http_post($url, $payload, $headers, $timeout, $skipTls);
        $last = array(
            'code' => $r['code'],
            'body' => $r['body'],
            'err' => $r['err'],
            'errno' => $r['errno'],
            'attempts' => $i,
        );
        $retryableNet = ($r['err'] !== '' && bg_tv_retryable($r['errno']));
        $retryableHttp = ($r['code'] === 429 || $r['code'] >= 500);
        if (($retryableNet || $retryableHttp) && $i < $attempts) {
            usleep(300000);
            continue;
        }
        break;
    }
    return $last;
}

/**
 * 旁路日志（不进返回结构，不突破上限）。
 *
 * @param string $tag     日志标签
 * @param array  $payload 结构化载荷
 * @return void
 */
function bg_tv_log($tag, $payload)
{
    $payload = is_array($payload) ? $payload : array('value' => $payload);
    error_log('[baogong] tencent-vision: ' . $tag . ' ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/* ------------------------------------------------------------------------- */
/* 统一上限收口                                                               */
/* ------------------------------------------------------------------------- */

/**
 * 把单条明细规整为标准结构。
 *
 * @param mixed $row 输入明细
 * @return array
 */
function bg_tv_finalize_item($row)
{
    $row = is_array($row) ? $row : array();
    $qty = bg_tv_qty(isset($row['qty']) ? $row['qty'] : null);
    $price = bg_tv_qty(isset($row['price']) ? $row['price'] : null);
    return array(
        'partNo'   => bg_tv_str(isset($row['partNo']) ? $row['partNo'] : '', 40),
        'partName' => bg_tv_str(isset($row['partName']) ? $row['partName'] : '', 40),
        'process'  => bg_tv_str(isset($row['process']) ? $row['process'] : '', 40),
        'qty'      => ($qty === null) ? 0 : $qty,
        'price'    => $price,
    );
}

/**
 * 把字段规整为标准 Fields（items≤20、日期严格、字符串限长）。
 *
 * @param array  $fields 输入字段
 * @param string $today  当天日期
 * @return array
 */
function bg_tv_finalize_fields($fields, $today)
{
    $fields = is_array($fields) ? $fields : array();
    $items = isset($fields['items']) && is_array($fields['items']) ? $fields['items'] : array();
    $outItems = array();
    foreach ($items as $row) {
        if (count($outItems) >= 20) {
            break;
        }
        $item = bg_tv_finalize_item($row);
        if ($item['partNo'] === '' && $item['partName'] === '' && $item['process'] === '' && $item['qty'] <= 0) {
            continue;
        }
        $outItems[] = $item;
    }
    return array(
        'date'     => bg_tv_date(isset($fields['date']) ? $fields['date'] : '', $today),
        'worker'   => bg_tv_str(isset($fields['worker']) ? $fields['worker'] : '', 40),
        'workshop' => bg_tv_str(isset($fields['workshop']) ? $fields['workshop'] : '', 40),
        'note'     => bg_tv_str(isset($fields['note']) ? $fields['note'] : '', 100),
        'items'    => $outItems,
    );
}

/**
 * 告警收口：去重、每条≤80字、最多5条；复核/失败类优先保留。
 *
 * @param array $warnings 输入告警
 * @return array
 */
function bg_tv_finalize_warnings($warnings)
{
    $important = array();
    $normal = array();
    foreach ((array)$warnings as $w) {
        $w = bg_tv_str($w, 80);
        if ($w === '') {
            continue;
        }
        if (preg_match('/复核|失败|不可用|兜底|未识别|请先|无效|有误|限流/u', $w)) {
            if (!in_array($w, $important, true)) {
                $important[] = $w;
            }
        } elseif (!in_array($w, $normal, true)) {
            $normal[] = $w;
        }
    }
    return array_slice(array_merge($important, $normal), 0, 5);
}

/**
 * 最终收口：所有分支返回前必须调用，施加全部归一化上限。
 * 以 _ 开头的内部键（_mid/_today 等）不会出现在返回结果中。
 *
 * @param array $result 引擎/编排结果
 * @return array FinalResult
 */
function bg_tv_finalize($result)
{
    $r = is_array($result) ? $result : array();
    $today = isset($r['_today']) ? (string)$r['_today'] : date('Y-m-d');
    $fields = bg_tv_finalize_fields(isset($r['fields']) ? $r['fields'] : array(), $today);
    $warnings = bg_tv_finalize_warnings(isset($r['warnings']) ? $r['warnings'] : array());

    $engine = bg_tv_str(isset($r['engine']) ? $r['engine'] : 'tencent-vision', 40);
    if ($engine === '') {
        $engine = 'tencent-vision';
    }
    $out = array(
        'engine'     => $engine,
        'confidence' => bg_tv_conf(isset($r['confidence']) ? $r['confidence'] : 0.0, 0.0),
        'plugin'     => array('id' => 'tencent-vision'),
        'strengths'  => array('partNo', 'qty', 'process'),
        'fields'     => $fields,
        'warnings'   => $warnings,
        'raw'        => bg_tv_cut(isset($r['raw']) ? $r['raw'] : '', 4000),
    );
    if (isset($r['alternates']) && is_array($r['alternates']) && $r['alternates']) {
        $alts = array();
        foreach ($r['alternates'] as $alt) {
            if (!is_array($alt)) {
                continue;
            }
            $alts[] = array(
                'engine'     => bg_tv_str(isset($alt['engine']) ? $alt['engine'] : '', 40),
                'confidence' => bg_tv_conf(isset($alt['confidence']) ? $alt['confidence'] : 0.0, 0.0),
                'fields'     => bg_tv_finalize_fields(isset($alt['fields']) ? $alt['fields'] : array(), $today),
                'raw'        => bg_tv_cut(isset($alt['raw']) ? $alt['raw'] : '', 4000),
                'warnings'   => bg_tv_finalize_warnings(isset($alt['warnings']) ? $alt['warnings'] : array()),
            );
        }
        if ($alts) {
            $out['alternates'] = $alts;
        }
    }
    return $out;
}
