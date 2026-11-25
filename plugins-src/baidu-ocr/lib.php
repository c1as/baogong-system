<?php
/**
 * 百度云 OCR 公共函数（token 缓存、接口调用）
 */

if (!defined('BG_BAIDU_OCR_LIB')) {
    define('BG_BAIDU_OCR_LIB', 1);
}

/* 仅允许由 api.php 加载，禁止直接访问 */
if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

function bg_baidu_cfg($cfg)
{
    $cfg = is_array($cfg) ? $cfg : array();
    $endpoint = isset($cfg['endpoint']) ? trim((string)$cfg['endpoint']) : '';
    if ($endpoint === '') {
        $endpoint = 'https://aip.baidubce.com';
    }
    $type = isset($cfg['ocrType']) ? (string)$cfg['ocrType'] : '';
    if (!in_array($type, array('accurate_location', 'accurate_basic', 'general_basic'), true)) {
        $type = 'accurate_location';
    }
    return array(
        'apiKey' => isset($cfg['apiKey']) ? trim((string)$cfg['apiKey']) : '',
        'secretKey' => isset($cfg['secretKey']) ? trim((string)$cfg['secretKey']) : '',
        'endpoint' => rtrim($endpoint, '/'),
        'ocrType' => $type,
        'procAlias' => isset($cfg['procAlias']) ? (string)$cfg['procAlias'] : '',
        'dateFallback' => isset($cfg['dateFallback']) ? (string)$cfg['dateFallback'] : '1',
        'insecure' => !empty($cfg['insecure']) && $cfg['insecure'] !== '0',
    );
}

function bg_baidu_cache_file()
{
    $dir = isset($GLOBALS['DATA_DIR']) && is_string($GLOBALS['DATA_DIR']) && $GLOBALS['DATA_DIR'] !== ''
        ? $GLOBALS['DATA_DIR']
        : dirname(__DIR__, 3) . '/data';
    $dir = rtrim(str_replace('\\', '/', $dir), '/') . '/plugin-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/baidu-ocr-token.json';
}

/**
 * 解析 CA 证书：优先 php.ini 的 curl.cainfo / openssl.cafile，其次使用内置 public/lib/cacert.pem
 *
 * 插件既可能位于 public/plugins/<id>（内置），也可能被市场安装到 data/market-plugins/<id>，
 * 因此不能只用固定相对层级，需按项目根与逐级向上查找 lib/cacert.pem。
 */
function bg_baidu_ca_file()
{
    foreach (array('curl.cainfo', 'openssl.cafile') as $k) {
        $v = ini_get($k);
        if (is_string($v) && $v !== '' && is_file($v)) {
            return $v;
        }
    }
    $cands = array();
    $root = isset($GLOBALS['BG_ROOT']) && is_string($GLOBALS['BG_ROOT']) ? $GLOBALS['BG_ROOT'] : '';
    if ($root !== '') {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $cands[] = $root . '/public/lib/cacert.pem';
        $cands[] = $root . '/lib/cacert.pem';
    }
    $dir = __DIR__;
    for ($i = 0; $i < 4; $i++) {
        $dir = dirname($dir);
        $cands[] = $dir . '/lib/cacert.pem';
        $cands[] = $dir . '/public/lib/cacert.pem';
    }
    foreach ($cands as $f) {
        if (is_file($f)) {
            return $f;
        }
    }
    return '';
}

/**
 * 向百度云发起 JSON 表单请求
 *
 * $attempts > 1 时，仅对「超时 / 连接中断」这类瞬时故障自动重试，
 * HTTP 错误（4xx/5xx）与百度业务错误不重试，避免重复计费。
 */
function bg_baidu_post_json($url, $body, $timeout = 20, $insecure = false, $attempts = 1)
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP 未启用 curl 扩展，无法访问百度云接口');
    }
    $attempts = max(1, (int)$attempts);
    $retryable = array(7, 28, 52, 55, 56); /* 连接失败、超时、无响应、发送/接收失败 */
    $raw = false;
    $err = '';
    $errno = 0;
    $status = 0;
    for ($try = 1; $try <= $attempts; $try++) {
        $ch = curl_init($url);
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => !$insecure,
            CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
            /* 去掉 Expect: 100-continue，省掉大请求体前的一次往返等待 */
            CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded', 'Expect:'),
        );
        $ca = $insecure ? '' : bg_baidu_ca_file();
        if ($ca !== '') {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw !== false || $try >= $attempts || !in_array($errno, $retryable, true)) {
            break;
        }
        usleep(300000);
    }
    if ($raw === false) {
        $msg = $err !== '' ? $err : '无响应';
        if (preg_match('/certificate|SSL|cert/i', $msg)) {
            $msg .= '（HTTPS 证书校验失败，请在服务器 php.ini 配置 curl.cainfo 指向 cacert.pem）';
        } elseif (in_array($errno, $retryable, true)) {
            $msg .= '（已自动重试 ' . ($attempts - 1) . ' 次仍未成功，多为网络瞬时中断或百度接口繁忙，请稍后重试）';
        }
        throw new RuntimeException('网络请求失败：' . $msg);
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('接口返回内容不是 JSON（HTTP ' . $status . '）');
    }
    if (isset($data['error'])) {
        $msg = (string)$data['error'];
        if (isset($data['error_description']) && $data['error_description'] !== '') {
            $msg .= ' · ' . (string)$data['error_description'];
        }
        throw new RuntimeException('百度云返回错误：' . $msg);
    }
    return $data;
}

function bg_baidu_token($cfg, $force = false)
{
    $c = bg_baidu_cfg($cfg);
    if ($c['apiKey'] === '' || $c['secretKey'] === '') {
        throw new RuntimeException('请先在识别插件设置中填写并保存 API Key 与 Secret Key');
    }
    $cacheFile = bg_baidu_cache_file();
    if (!$force && is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        $cache = $raw === false ? null : json_decode($raw, true);
        if (is_array($cache) && isset($cache['token'], $cache['expires'], $cache['key'])
            && (int)$cache['expires'] > time() + 60
            && $cache['key'] === md5($c['apiKey'])) {
            return (string)$cache['token'];
        }
    }
    $data = bg_baidu_post_json($c['endpoint'] . '/oauth/2.0/token', http_build_query(array(
        'grant_type' => 'client_credentials',
        'client_id' => $c['apiKey'],
        'client_secret' => $c['secretKey'],
    )), 15, $c['insecure']);
    if (empty($data['access_token'])) {
        throw new RuntimeException('未取得 access_token，请检查密钥是否正确');
    }
    $token = (string)$data['access_token'];
    $ttl = isset($data['expires_in']) && is_numeric($data['expires_in']) ? (int)$data['expires_in'] : 2592000;
    @file_put_contents($cacheFile, json_encode(array(
        'token' => $token,
        'expires' => time() + $ttl,
        'key' => md5($c['apiKey']),
    )), LOCK_EX);
    return $token;
}

function bg_baidu_ocr($cfg, $binary)
{
    $c = bg_baidu_cfg($cfg);
    $token = bg_baidu_token($cfg);
    /* accurate_location 对应「高精度含位置版」，返回 location 才能把文字块还原成表格行 */
    $api = $c['ocrType'] === 'accurate_location' ? 'accurate' : $c['ocrType'];
    $url = $c['endpoint'] . '/rest/2.0/ocr/v1/' . $api . '?access_token=' . urlencode($token);
    /* 识别接口偶发 30 秒无响应，单次 25 秒 + 自动重试 1 次，避免一次网络抖动就整单失败 */
    $data = bg_baidu_post_json($url, http_build_query(array(
        'image' => base64_encode($binary),
        'language_type' => 'CHN_ENG',
        'detect_direction' => 'true',
        'paragraph' => 'false',
    )), 25, $c['insecure'], 2);
    if (!isset($data['words_result']) || !is_array($data['words_result'])) {
        throw new RuntimeException('识别接口未返回文字结果');
    }
    $lines = array();
    $cells = array();
    foreach ($data['words_result'] as $w) {
        if (!is_array($w) || !isset($w['words']) || !is_string($w['words']) || trim($w['words']) === '') {
            continue;
        }
        $line = trim($w['words']);
        $lines[] = $line;
        if (isset($w['location']) && is_array($w['location'])
            && isset($w['location']['left'], $w['location']['top'], $w['location']['width'], $w['location']['height'])) {
            $cells[] = array(
                'words' => $line,
                'left' => (int)$w['location']['left'],
                'top' => (int)$w['location']['top'],
                'width' => (int)$w['location']['width'],
                'height' => (int)$w['location']['height'],
            );
        }
    }
    return array('lines' => $lines, 'cells' => $cells, 'data' => $data);
}
