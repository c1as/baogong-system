<?php
/**
 * 腾讯云 OCR 引擎适配层（T02）
 *
 * 复用 tencent-ocr/lib.php 的 TC3-HMAC-SHA256 签名思路，统一改为 bg_tv_ 前缀。
 * 对外暴露：配置归一、请求准备、响应解析、串行调用、TextDetections 行还原。
 * 三子接口：accurate/handwriting/basic，按需带 ConfigID / LanguageType。
 */

if (defined('BG_TV_OCR_LOADED')) {
    return;
}
define('BG_TV_OCR_LOADED', 1);

if (!function_exists('bg_tv_cfg')) {
    require_once __DIR__ . '/util.php';
}

/**
 * 归一腾讯云 OCR 配置。
 *
 * @param array $cfg 插件配置
 * @return array
 */
function bg_tv_ocr_cfg($cfg)
{
    $endpoint = bg_tv_cfg($cfg, 'ocrEndpoint', bg_tv_cfg($cfg, 'endpoint', 'ocr.tencentcloudapi.com'));
    $endpoint = preg_replace('#^https?://#i', '', $endpoint); // 签名用 host，不含协议
    $endpoint = rtrim((string)$endpoint, '/');
    if ($endpoint === '') {
        $endpoint = 'ocr.tencentcloudapi.com';
    }
    $region = bg_tv_cfg($cfg, 'region', 'ap-guangzhou');
    $ocrType = bg_tv_cfg($cfg, 'ocrType', 'accurate');
    if (!in_array($ocrType, array('accurate', 'basic', 'handwriting'), true)) {
        $ocrType = 'accurate';
    }
    return array(
        'secretId'     => bg_tv_cfg($cfg, 'secretId', ''),
        'secretKey'    => bg_tv_cfg($cfg, 'secretKey', ''),
        'endpoint'     => $endpoint,
        'region'       => $region,
        'ocrType'      => $ocrType,
        'languageType' => bg_tv_cfg($cfg, 'languageType', 'zh'),
        'configId'     => bg_tv_cfg($cfg, 'configId', ''),
        'dateFallback' => bg_tv_cfg($cfg, 'dateFallback', '1'),
        'skipTls'      => bg_tv_bool($cfg, 'skipTls', false),
        'timeout'      => bg_tv_int($cfg, 'timeout', 30, 5, 120),
        'retries'      => bg_tv_int($cfg, 'retries', 1, 0, 3),
    );
}

/**
 * 生成 TC3-HMAC-SHA256 Authorization 头。
 * 与 tencent-ocr/lib.php:bg_tc_sign 逐行等价，仅改函数名前缀。
 *
 * @param string $secretId  SecretId
 * @param string $secretKey SecretKey
 * @param string $service   服务名，固定 ocr
 * @param string $host      接口域名（不含协议）
 * @param string $action    接口名，如 GeneralAccurateOCR
 * @param string $payload   请求体 JSON（必须与实际发送完全一致）
 * @param int    $timestamp Unix 时间戳（秒）
 * @return string Authorization 头值
 */
function bg_tv_ocr_sign($secretId, $secretKey, $service, $host, $action, $payload, $timestamp)
{
    $algorithm = 'TC3-HMAC-SHA256';
    $date = gmdate('Y-m-d', $timestamp); // 签名日期必须用 UTC

    // 1) 规范请求串
    $canonicalHeaders = "content-type:application/json; charset=utf-8\n";
    $canonicalHeaders .= 'host:' . $host . "\n";
    $signedHeaders = 'content-type;host';
    $payloadHash = hash('sha256', $payload);
    $canonicalRequest = "POST\n/\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;

    // 2) 待签名字符串
    $credentialScope = $date . '/' . $service . '/tc3_request';
    $stringToSign = $algorithm . "\n" . $timestamp . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);

    // 3) 派生签名密钥并计算签名
    $secretDate = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
    $secretService = hash_hmac('sha256', $service, $secretDate, true);
    $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
    $signature = hash_hmac('sha256', $stringToSign, $secretSigning);

    // 4) 拼接 Authorization
    return $algorithm . ' Credential=' . $secretId . '/' . $credentialScope
        . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
}

/**
 * ocrType → 腾讯云接口名。
 *
 * @param string $ocrType accurate|handwriting|basic
 * @return string
 */
function bg_tv_ocr_action($ocrType)
{
    $actions = array(
        'accurate'    => 'GeneralAccurateOCR',
        'basic'       => 'GeneralBasicOCR',
        'handwriting' => 'GeneralHandwritingOCR',
    );
    return isset($actions[$ocrType]) ? $actions[$ocrType] : 'GeneralAccurateOCR';
}

/**
 * 把 dataURL 图片转成腾讯云 ImageBase64 参数。
 *
 * @param string $image dataURL
 * @return string 纯 base64
 */
function bg_tv_ocr_image_base64($image)
{
    $image = trim((string)$image);
    if ($image === '') {
        throw new RuntimeException('没有收到图片，请重新拍照或选择图片');
    }
    if (!preg_match('#^data:image/(jpeg|pjpeg|jpg|png|webp);base64,#i', $image)) {
        throw new RuntimeException('图片格式不支持，请使用 JPG / PNG / WebP');
    }
    $b64 = preg_replace('#^data:image/[^,]*,\s*#i', '', $image);
    $b64 = trim((string)$b64);
    if ($b64 === '') {
        throw new RuntimeException('图片内容为空，请重新拍照或选择图片');
    }
    if (strlen($b64) > 11000000) {
        throw new RuntimeException('图片过大，请压缩到 8MB 以内再上传');
    }
    return $b64;
}

/**
 * 准备一个腾讯云 OCR 请求 job（含 TC3 签名）。
 *
 * @param array $ctx 插件上下文
 * @param array $cfg 插件配置
 * @return array job(url,headers,payload,timeout,skipTls,action,retries,timestamp)
 */
function bg_tv_ocr_prepare($ctx, $cfg)
{
    $c = bg_tv_ocr_cfg($cfg);
    // 无 curl 时不在此处报错：bg_tv_http_post 会自动走 SSL socket 串行降级。
    if (!function_exists('hash_hmac')) {
        throw new RuntimeException('PHP 未启用 hash 扩展，无法计算 API 签名');
    }
    if ($c['secretId'] === '' || $c['secretKey'] === '') {
        throw new RuntimeException('请先填写 SecretId 与 SecretKey');
    }
    /* 端点由配置决定：必须是公网域名，防止签名与图片被打到内网/本机 */
    if (!bg_tv_host_endpoint_public($c['endpoint'])) {
        throw new RuntimeException('OCR 接口域名不可用：必须是公网域名，不能指向本机或内网地址');
    }
    $ctx = is_array($ctx) ? $ctx : array();
    $b64 = bg_tv_ocr_image_base64(isset($ctx['image']) ? $ctx['image'] : '');

    $action = bg_tv_ocr_action($c['ocrType']);
    $params = array('ImageBase64' => $b64);
    // 高精度版按文档用 ConfigID；另外两个子接口支持 LanguageType。
    if ($action !== 'GeneralAccurateOCR' && $c['languageType'] !== '') {
        $params['LanguageType'] = $c['languageType'];
    }
    if ($c['configId'] !== '') {
        $params['ConfigID'] = $c['configId'];
    }
    $payload = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('请求参数编码失败');
    }
    $timestamp = time();
    $authorization = bg_tv_ocr_sign(
        $c['secretId'],
        $c['secretKey'],
        'ocr',
        $c['endpoint'],
        $action,
        $payload,
        $timestamp
    );
    return array(
        'url' => 'https://' . $c['endpoint'],
        'headers' => array(
            'Authorization: ' . $authorization,
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . $c['endpoint'],
            'X-TC-Action: ' . $action,
            'X-TC-Timestamp: ' . $timestamp,
            'X-TC-Version: 2018-11-19',
            'X-TC-Region: ' . $c['region'],
        ),
        'payload'  => $payload,
        'timeout'  => $c['timeout'],
        'skipTls'  => $c['skipTls'],
        'action'   => $action,
        'retries'  => $c['retries'],
        'timestamp' => $timestamp,
    );
}

/**
 * 把腾讯云 Response.Error / HTTP 状态转中文提示。
 *
 * @param string $code 错误码
 * @param string $msg  原始消息
 * @return string
 */
function bg_tv_ocr_error_text($code, $msg)
{
    $code = (string)$code;
    $msg = trim((string)$msg);
    if (strpos($code, 'AuthFailure') === 0) {
        return '密钥无效或未开通文字识别[' . $code . ']' . ($msg !== '' ? '：' . $msg : '');
    }
    if ($code === 'InvalidParameterValue' || $code === 'MissingParameter') {
        return '参数有误，可换识别类型或关闭多语配置[' . $code . ']' . ($msg !== '' ? '：' . $msg : '');
    }
    return '腾讯云返回错误[' . $code . ']' . ($msg !== '' ? '：' . $msg : '');
}

/**
 * 网络错误转中文提示。
 *
 * @param array $send bg_tv_http_send 结果
 * @return string
 */
function bg_tv_ocr_network_error($send)
{
    $msg = isset($send['err']) && $send['err'] !== '' ? (string)$send['err'] : '无响应';
    if (preg_match('/certificate|SSL|cert/i', $msg)) {
        $msg .= '（HTTPS 证书校验失败，可配置 curl.cainfo 或临时勾选跳过证书校验排查）';
    } elseif (bg_tv_retryable(isset($send['errno']) ? $send['errno'] : 0)) {
        $tries = max(0, (int)(isset($send['attempts']) ? $send['attempts'] : 1) - 1);
        $msg .= '（已重试 ' . $tries . ' 次仍未成功，多为网络瞬时中断）';
    }
    return '网络请求失败：' . $msg;
}

/**
 * 解析腾讯云响应为中间 EngineResult（fields 尚未归一）。
 *
 * @param string $rawBody HTTP 响应体
 * @param int    $status  HTTP 状态码
 * @param array  $cfg     插件配置
 * @param array  $ctx     插件上下文
 * @param float  $t0      起始微秒时间
 * @return array EngineResult
 */
function bg_tv_ocr_parse($rawBody, $status, $cfg, $ctx, $t0 = 0.0)
{
    $data = json_decode((string)$rawBody, true);
    if (!is_array($data) || !isset($data['Response']) || !is_array($data['Response'])) {
        throw new RuntimeException('腾讯云 OCR 返回内容不是预期的 JSON（HTTP ' . (int)$status . '）');
    }
    $resp = $data['Response'];
    if (isset($resp['Error']) && is_array($resp['Error'])) {
        $code = isset($resp['Error']['Code']) ? (string)$resp['Error']['Code'] : 'UnknownError';
        $msg = isset($resp['Error']['Message']) ? (string)$resp['Error']['Message'] : '';
        throw new RuntimeException(bg_tv_ocr_error_text($code, $msg));
    }
    $detections = (isset($resp['TextDetections']) && is_array($resp['TextDetections']))
        ? $resp['TextDetections'] : array();
    $rows = bg_tv_ocr_rows($detections);
    $lines = array();
    foreach ($detections as $d) {
        if (is_array($d) && isset($d['DetectedText']) && trim((string)$d['DetectedText']) !== '') {
            $lines[] = trim((string)$d['DetectedText']);
        }
    }
    // raw 必须保留图号/数量原文片段，供 ocr-ensemble L1 佐证。
    $rawText = implode("\n", $lines);
    $confidence = count($rows) ? min(0.95, 0.70 + 0.02 * count($rows)) : 0.20;
    $elapsed = ($t0 > 0) ? (int)round((microtime(true) - $t0) * 1000) : 0;
    return array(
        'engineId'   => 'tencentOcr',
        'confidence' => round($confidence, 3),
        'fields'     => array('date' => '', 'worker' => '', 'workshop' => '', 'note' => '', 'items' => array()),
        'raw'        => $rawText,
        'warnings'   => array(),
        'usage'      => array('prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0),
        'elapsedMs'  => $elapsed,
        'ok'         => true,
        '_mid'       => array('rows' => $rows, 'detections' => $detections, 'lines' => $lines),
    );
}

/**
 * 串行调用腾讯云 OCR（自检 / 无 curl_multi 降级使用）。
 *
 * @param array $ctx 插件上下文
 * @param array $cfg 插件配置
 * @return array EngineResult
 */
function bg_tv_ocr_call($ctx, $cfg)
{
    $t0 = microtime(true);
    $c = bg_tv_ocr_cfg($cfg);
    $job = bg_tv_ocr_prepare($ctx, $cfg);
    $send = bg_tv_http_send($job, $c['retries']);
    if ($send['err'] !== '') {
        throw new RuntimeException(bg_tv_ocr_network_error($send));
    }
    return bg_tv_ocr_parse($send['body'], $send['code'], $cfg, $ctx, $t0);
}

/**
 * 直接向腾讯云 API 3.0 发起一次调用（自检探针使用，返回 Response 对象）。
 *
 * @param array  $c        归一配置
 * @param string $action   接口名
 * @param array  $params   请求参数
 * @param int    $timeout  超时秒数
 * @param int    $attempts 总尝试次数（含首次）
 * @return array Response
 */
function bg_tv_ocr_post_api($c, $action, $params, $timeout = 30, $attempts = 2)
{
    if ($c['secretId'] === '' || $c['secretKey'] === '') {
        throw new RuntimeException('请先填写 SecretId 与 SecretKey');
    }
    /* 自检探针同样会把签名发到配置端点，出网前统一校验 */
    if (!bg_tv_host_endpoint_public($c['endpoint'])) {
        throw new RuntimeException('OCR 接口域名不可用：必须是公网域名，不能指向本机或内网地址');
    }
    $payload = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('请求参数编码失败');
    }
    $timestamp = time();
    $authorization = bg_tv_ocr_sign(
        $c['secretId'],
        $c['secretKey'],
        'ocr',
        $c['endpoint'],
        $action,
        $payload,
        $timestamp
    );
    $job = array(
        'url' => 'https://' . $c['endpoint'],
        'headers' => array(
            'Authorization: ' . $authorization,
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . $c['endpoint'],
            'X-TC-Action: ' . $action,
            'X-TC-Timestamp: ' . $timestamp,
            'X-TC-Version: 2018-11-19',
            'X-TC-Region: ' . $c['region'],
        ),
        'payload' => $payload,
        'timeout' => $timeout,
        'skipTls' => $c['skipTls'],
    );
    $send = bg_tv_http_send($job, max(0, (int)$attempts - 1));
    if ($send['err'] !== '') {
        throw new RuntimeException(bg_tv_ocr_network_error($send));
    }
    $data = json_decode($send['body'], true);
    if (!is_array($data) || !isset($data['Response']) || !is_array($data['Response'])) {
        throw new RuntimeException('接口返回内容不是预期的 JSON（HTTP ' . $send['code'] . '）');
    }
    $resp = $data['Response'];
    if (isset($resp['Error']) && is_array($resp['Error'])) {
        $code = isset($resp['Error']['Code']) ? (string)$resp['Error']['Code'] : 'UnknownError';
        $msg = isset($resp['Error']['Message']) ? (string)$resp['Error']['Message'] : '';
        throw new RuntimeException(bg_tv_ocr_error_text($code, $msg));
    }
    return $resp;
}

/**
 * 取检测框外接矩形：ItemPolygon 优先，Polygon 四点回退。
 *
 * @param array $d 单条 TextDetection
 * @return array{x:int|null,y:int|null,w:int,h:int}
 */
function bg_tv_poly_box($d)
{
    $x = $y = null;
    $w = $h = 0;
    $d = is_array($d) ? $d : array();
    if (isset($d['ItemPolygon']) && is_array($d['ItemPolygon'])) {
        $ip = $d['ItemPolygon'];
        if (isset($ip['X'], $ip['Y'], $ip['Width'], $ip['Height'])) {
            $x = (int)$ip['X'];
            $y = (int)$ip['Y'];
            $w = (int)$ip['Width'];
            $h = (int)$ip['Height'];
        }
    }
    if ($x === null && isset($d['Polygon']) && is_array($d['Polygon']) && $d['Polygon']) {
        $xs = array();
        $ys = array();
        foreach ($d['Polygon'] as $pt) {
            if (is_array($pt) && isset($pt['X'], $pt['Y'])) {
                $xs[] = (int)$pt['X'];
                $ys[] = (int)$pt['Y'];
            }
        }
        if ($xs) {
            $x = min($xs);
            $y = min($ys);
            $w = max($xs) - $x;
            $h = max($ys) - $y;
        }
    }
    return array('x' => $x, 'y' => $y, 'w' => $w, 'h' => $h);
}

/**
 * 把 TextDetections[] 按中心 Y 聚行、行内按 X 排序还原表格阅读顺序。
 *
 * @param array $detections Response.TextDetections
 * @return array 每行 {text:string,cells:array}
 */
function bg_tv_ocr_rows($detections)
{
    $cells = array();
    foreach ((array)$detections as $d) {
        if (!is_array($d) || !isset($d['DetectedText'])) {
            continue;
        }
        $text = trim((string)$d['DetectedText']);
        if ($text === '') {
            continue;
        }
        $box = bg_tv_poly_box($d);
        if ($box['x'] === null) {
            $cells[] = array('text' => $text, 'x' => 0, 'y' => 0, 'w' => 0, 'h' => 10, 'cy' => 5);
            continue;
        }
        $cells[] = array(
            'text' => $text,
            'x' => $box['x'],
            'y' => $box['y'],
            'w' => $box['w'],
            'h' => $box['h'],
            'cy' => $box['y'] + $box['h'] / 2,
        );
    }
    if (!$cells) {
        return array();
    }

    // 按中心 Y 排序，以「行高中位数 × 0.6」为容差聚行，滚动锚点抗轻微倾斜。
    usort($cells, function ($a, $b) {
        if ($a['cy'] == $b['cy']) {
            return 0;
        }
        return ($a['cy'] < $b['cy']) ? -1 : 1;
    });
    $medianH = bg_tv_median(array_column($cells, 'h'));
    $tol = max(8.0, $medianH * 0.6);
    $rows = array();
    $cur = array();
    $anchor = null;
    foreach ($cells as $c) {
        if ($anchor === null || abs($c['cy'] - $anchor) <= $tol) {
            $cur[] = $c;
            $anchor = ($anchor === null) ? $c['cy'] : ($anchor + $c['cy']) / 2;
        } else {
            $rows[] = $cur;
            $cur = array($c);
            $anchor = $c['cy'];
        }
    }
    if ($cur) {
        $rows[] = $cur;
    }

    // 行内按 X 升序，列间双空格拼文本，保留 cells 供字段定位。
    $out = array();
    foreach ($rows as $row) {
        usort($row, function ($a, $b) {
            if ($a['x'] == $b['x']) {
                return 0;
            }
            return ($a['x'] < $b['x']) ? -1 : 1;
        });
        $texts = array();
        foreach ($row as $cell) {
            $texts[] = $cell['text'];
        }
        $out[] = array('text' => implode('  ', $texts), 'cells' => $row);
    }
    return $out;
}
