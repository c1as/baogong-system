<?php
/**
 * HTTP 辅助：统一响应信封、请求参数读取、客户端 IP
 *
 * 同时兼容 application/x-www-form-urlencoded 与 application/json 请求体。
 */

if (!defined('BGLX_HTTP_LOADED')) {
    define('BGLX_HTTP_LOADED', 1);
}

/** 缓存解析后的 JSON 请求体，避免重复读取 php://input */
function bglx_json_body()
{
    static $parsed = null;
    if ($parsed !== null) {
        return $parsed;
    }
    $parsed = array();
    $raw = @file_get_contents('php://input');
    if ($raw === false || trim((string)$raw) === '') {
        return $parsed;
    }
    $data = json_decode((string)$raw, true);
    if (is_array($data)) {
        $parsed = $data;
    }
    return $parsed;
}

/**
 * 读取请求参数：JSON body 优先，其次 POST，最后 GET。
 *
 * @param string $key     参数名
 * @param mixed  $default 默认值
 * @return mixed
 */
function bglx_param($key, $default = '')
{
    $body = bglx_json_body();
    if (array_key_exists($key, $body)) {
        return $body[$key];
    }
    if (isset($_POST) && array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }
    if (isset($_GET) && array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }
    return $default;
}

/** 当前是否为 POST 请求 */
function bglx_is_post()
{
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
    return $method === 'POST';
}

/**
 * 直连对等体 IP：只读取 REMOTE_ADDR，是 Web 服务器记录的 TCP 对端，
 * 客户端无法通过普通请求头伪造。鉴权与回环判定必须使用本函数。
 *
 * @return string 无法取得时返回空字符串
 */
function bglx_peer_ip()
{
    if (!isset($_SERVER['REMOTE_ADDR'])) {
        return '';
    }
    return trim((string)$_SERVER['REMOTE_ADDR']);
}

/**
 * 请求头「声明」的客户端 IP：X-Forwarded-For 第一跳，其次 X-Real-IP。
 * 这些值完全由客户端提供，默认不可信；只有在显式配置了可信反向代理、
 * 且直连对等体命中该代理名单时才可被鉴权逻辑采信。
 *
 * @return string 无声明值时返回空字符串
 */
function bglx_forwarded_ip()
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim((string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        if (strpos($ip, ',') !== false) {
            $parts = explode(',', $ip);
            $ip = trim($parts[0]);
        }
        if ($ip !== '') {
            return $ip;
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = trim((string)$_SERVER['HTTP_X_REAL_IP']);
        if ($ip !== '') {
            return $ip;
        }
    }
    return '';
}

/**
 * 客户端 IP（声明值优先）：优先 X-Forwarded-For / X-Real-IP，最后 REMOTE_ADDR。
 *
 * 注意：本函数保留用于日志、统计等非安全用途；由于 XFF / X-Real-IP 可被
 * 客户端任意伪造，**不得**用于鉴权或回环判定，那些场景请用 bglx_peer_ip()。
 *
 * @return string
 */
function bglx_client_ip()
{
    foreach (array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR') as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $ip = trim((string)$_SERVER[$key]);
        if ($key === 'HTTP_X_FORWARDED_FOR' && strpos($ip, ',') !== false) {
            $parts = explode(',', $ip);
            $ip = trim($parts[0]);
        }
        if ($ip !== '') {
            return $ip;
        }
    }
    return '';
}

/**
 * 输出统一 JSON 信封。
 *
 * @param int    $code    业务错误码，0 表示成功
 * @param mixed  $data    响应数据
 * @param string $message 提示信息
 * @param bool   $exit    是否立即结束脚本
 * @return string JSON 文本
 */
function bglx_json($code, $data = array(), $message = 'ok', $exit = true)
{
    $envelope = array(
        'code' => (int)$code,
        'message' => (string)$message,
        'data' => $data,
    );
    $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{"code":50001,"message":"JSON 编码失败","data":{}}';
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo $json;
    if ($exit) {
        exit;
    }
    return $json;
}

/** 常见错误响应快捷方法 */
function bglx_fail($code, $message, $data = array())
{
    bglx_json($code, $data, $message, true);
}

/**
 * 审查子系统专用 HTTP POST：curl 优先，缺失时降级 stream_context_create。
 *
 * 约定：
 *  - 永不把 headers（含 Authorization / AI Key）写入返回结构或错误信息；
 *  - 超时/网络失败只返回 ok=false，不抛异常到主流程，便于 AI 降级人工；
 *  - SSL 校验默认开启，仅当显式设置 aiSslVerify=0 时关闭（高级运维项）。
 *
 * @param string $url     完整接口地址
 * @param array  $headers 请求头键值数组
 * @param string $body    请求体
 * @param int    $timeout 超时秒数
 * @return array array('ok'=>bool,'status'=>int,'body'=>string,'error'=>string)
 */
function bglx_rev_http_post($url, $headers, $body, $timeout)
{
    $result = array('ok' => false, 'status' => 0, 'body' => '', 'error' => '');
    $url = trim((string)$url);
    if ($url === '') {
        $result['error'] = '接口地址为空';
        return $result;
    }
    $timeout = max(1, min(300, (int)$timeout));
    $headers = is_array($headers) ? $headers : array();
    $body = (string)$body;
    $verify = bglx_rev_setting_bool('aiSslVerify', true);

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
        ));
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string)curl_error($ch);
        curl_close($ch);
        $result['status'] = $status;
        if ($resp === false) {
            $result['error'] = $err === '' ? '网络请求失败' : '网络请求失败：' . bglx_cut($err, 120);
            return $result;
        }
        $result['body'] = (string)$resp;
        if ($status >= 200 && $status < 300) {
            $result['ok'] = true;
        } else {
            $result['error'] = '接口返回 HTTP ' . $status;
        }
        return $result;
    }

    // stream 通道：无 curl 扩展时的保底路径
    $headerLines = array();
    foreach ($headers as $k => $v) {
        $headerLines[] = (string)$k . ': ' . (string)$v;
    }
    $context = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ),
        'ssl' => array(
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
        ),
    ));
    $fp = @fopen($url, 'rb', false, $context);
    if ($fp === false) {
        $result['error'] = '无法连接 AI 接口（stream 通道）';
        return $result;
    }
    $resp = @stream_get_contents($fp);
    $meta = @stream_get_meta_data($fp);
    @fclose($fp);
    if (is_array($meta) && isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) {
        foreach ($meta['wrapper_data'] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$line, $m) === 1) {
                $result['status'] = (int)$m[1];
                break;
            }
        }
    }
    $result['body'] = $resp === false ? '' : (string)$resp;
    if ($result['status'] >= 200 && $result['status'] < 300) {
        $result['ok'] = true;
    } else {
        $result['error'] = $result['status'] > 0
            ? '接口返回 HTTP ' . $result['status'] : '接口无有效响应状态';
    }
    return $result;
}

