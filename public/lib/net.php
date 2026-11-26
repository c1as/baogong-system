<?php
/**
 * 插件自检与网络连通性检测
 *
 * 由 api.php 引入：
 *   - bg_http()         服务器端 HTTP 请求
 *   - bg_plugin_check() 加载插件的 check.php（API 链接测试）
 *   - bg_net_check()    互联网连接测试（百度首页 / 百度云 OCR 接口）
 */

/**
 * 解析 CA 证书：优先 php.ini 的 curl.cainfo / openssl.cafile，其次使用内置 lib/cacert.pem
 */
function bg_ca_file()
{
    foreach (array('curl.cainfo', 'openssl.cafile') as $k) {
        $v = ini_get($k);
        if (is_string($v) && $v !== '' && is_file($v)) {
            return $v;
        }
    }
    $local = __DIR__ . '/cacert.pem';
    return is_file($local) ? $local : '';
}

/**
 * 是否为公网 IP（拒绝私网/回环/链路本地/保留地址）
 */
function bg_ip_public($ip)
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * 是否可安全外访的 HTTPS 地址：https + 无 userinfo + 主机解析后全部为公网 IP。
 * 用于插件市场等"由配置决定目标"的请求，防止被打成内网探测跳板。
 */
function bg_url_https_public($url)
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
    $host = strtolower($p['host']);
    if ($host === 'localhost' || substr($host, -6) === '.local') {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return bg_ip_public($host);
    }
    $ips = @gethostbynamel($host);
    if (!is_array($ips) || !count($ips)) {
        return false;
    }
    foreach ($ips as $ip) {
        if (!bg_ip_public($ip)) {
            return false;
        }
    }
    return true;
}

/**
 * 服务器端 HTTP 请求
 * 返回：ok(是否拿到响应) / status / ms / body / error / redirect
 * $follow=false 时不自动跟随跳转，由调用方校验每一跳后再继续。
 */
function bg_http($method, $url, $headers, $body, $timeout = 10, $insecure = false, $follow = true)
{
    $start = microtime(true);
    $res = array('ok' => false, 'status' => 0, 'ms' => 0, 'body' => '', 'error' => '', 'redirect' => '');
    $method = strtoupper($method) === 'POST' ? 'POST' : 'GET';
    $headers = is_array($headers) ? $headers : array();

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => $follow ? true : false,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => !$insecure,
            CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
            CURLOPT_HTTPHEADER => $headers,
        );
        $ca = $insecure ? '' : bg_ca_file();
        if ($ca !== '') {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$body);
        }
        $out = curl_exec($ch);
        $res['status'] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $res['redirect'] = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        if ($out === false) {
            $res['error'] = curl_error($ch) !== '' ? curl_error($ch) : '请求失败';
        } else {
            $res['body'] = (string)$out;
            $res['ok'] = $res['status'] > 0;
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create(array(
            'http' => array(
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $method === 'POST' ? (string)$body : '',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $out = @file_get_contents($url, false, $ctx);
        if ($out === false) {
            $err = error_get_last();
            $res['error'] = isset($err['message']) ? $err['message'] : '请求失败';
        } else {
            $res['body'] = (string)$out;
            $res['ok'] = true;
            $hdrs = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : array();
            if (isset($hdrs[0]) && preg_match('/\s(\d{3})\s/', $hdrs[0], $mm)) {
                $res['status'] = (int)$mm[1];
            }
        }
    }
    $res['ms'] = (int)round((microtime(true) - $start) * 1000);
    return $res;
}

/**
 * 插件自检（API 链接测试）：加载插件 check 脚本，不受启用状态影响
 * check 脚本 return array('ok'=>bool, 'message'=>'', 'details'=>[['label'=>'','ok'=>bool,'detail'=>'']])
 */
function bg_plugin_check($id)
{
    global $BG_PLUGIN_CTX;
    $m = bg_plugin_one($id);
    if ($m['check'] === '') {
        bg_fail(400, '该插件没有提供自检脚本（plugin.json 中的 check）');
    }
    $BG_PLUGIN_CTX = array(
        'id' => $m['id'],
        'image' => '',
        'catalog' => array(),
        'config' => bg_plugin_config($m),
        'locale' => 'zh-CN',
        'today' => date('Y-m-d'),
        'action' => 'check',
    );

    $out = null;
    try {
        $ret = include $m['dir'] . '/' . $m['check'];
        if (is_array($ret)) {
            $out = $ret;
        }
    } catch (Throwable $e) {
        @error_log('[baogong] 插件 ' . $m['id'] . ' 自检失败：' . $e->getMessage());
        return array(
            'ok' => false,
            'message' => bg_plugin_cut($e->getMessage(), 160),
            'details' => array(),
        );
    }
    if (!is_array($out)) {
        bg_fail(502, '插件自检脚本没有返回结果');
    }

    $details = array();
    if (isset($out['details']) && is_array($out['details'])) {
        foreach ($out['details'] as $d) {
            if (!is_array($d) || count($details) >= 10) {
                continue;
            }
            $details[] = array(
                'label' => bg_plugin_cut(isset($d['label']) ? $d['label'] : '', 40),
                'ok' => !empty($d['ok']),
                'detail' => bg_plugin_cut(isset($d['detail']) ? $d['detail'] : '', 160),
            );
        }
    }
    return array(
        'ok' => !empty($out['ok']),
        'message' => bg_plugin_cut(isset($out['message']) ? $out['message'] : '', 160),
        'details' => $details,
    );
}

/**
 * 互联网连接测试：由服务器出网访问百度与百度云 OCR 接口
 */
function bg_net_check()
{
    $targets = array(
        array(
            'name' => '百度首页',
            'url' => 'https://www.baidu.com/',
            'note' => '通用外网连通性',
        ),
        array(
            'name' => '百度云 OCR 接口',
            'url' => 'https://aip.baidubce.com/oauth/2.0/token',
            'note' => '未带密钥，返回 invalid_client 即表示接口可达',
        ),
    );

    $results = array();
    $all = true;
    foreach ($targets as $t) {
        $r = bg_http('GET', $t['url'], array('User-Agent: baogong-net-check/1.0'), '', 10);
        $certOk = true;
        /* 证书校验失败但网络可达时重试一次，仅用于诊断连通性 */
        if (!$r['ok'] && preg_match('/SSL|certificate|cert/i', $r['error'])) {
            $r2 = bg_http('GET', $t['url'], array('User-Agent: baogong-net-check/1.0'), '', 10, true);
            if ($r2['ok']) {
                $r = $r2;
                $certOk = false;
            }
        }
        $ok = $r['ok'] && $r['status'] > 0 && $r['status'] < 500;
        $detail = $ok ? 'HTTP ' . $r['status'] : ($r['error'] !== '' ? $r['error'] : '无响应');
        if ($ok && $t['url'] === 'https://aip.baidubce.com/oauth/2.0/token') {
            $j = json_decode($r['body'], true);
            if (is_array($j) && isset($j['error'])) {
                $detail = 'HTTP ' . $r['status'] . ' · ' . (string)$j['error'] . '（接口可达）';
            }
        }
        if (!$certOk) {
            $detail .= ' · 证书未校验（推荐在 php.ini 配置 curl.cainfo 指向 cacert.pem）';
        }
        $results[] = array(
            'name' => $t['name'],
            'url' => $t['url'],
            'note' => $t['note'],
            'ok' => $ok,
            'status' => $r['status'],
            'ms' => $r['ms'],
            'detail' => bg_plugin_cut($detail, 160),
        );
        if (!$ok) {
            $all = false;
        }
    }

    return array(
        'ok' => $all,
        'curl' => function_exists('curl_init'),
        'time' => date('Y-m-d H:i:s'),
        'results' => $results,
    );
}
