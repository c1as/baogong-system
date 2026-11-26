<?php
/**
 * 腾讯云多引擎视觉识别插件 · 双引擎独立自检
 *
 * 由设置页「API 链接测试」引入：仅检测已勾选引擎，两套凭据分别检查、分别提示。
 * 末尾 return {ok,message,details}；details ≤10 条、每条 ≤160 字。
 */

if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/tencent_ocr.php';
require_once __DIR__ . '/lib/hy4_vision.php';

if (!function_exists('bg_tv_check_run')) {
    /**
     * 自检用的 1×1 测试图（PNG dataURL）。
     *
     * @return string
     */
    function bg_tv_check_image()
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    }

    /**
     * 解析已勾选引擎（自检独立实现，不依赖 handler 的执行流程）。
     *
     * @param array $cfg 配置
     * @return array
     */
    function bg_tv_check_engines($cfg)
    {
        $raw = '';
        if (is_array($cfg) && isset($cfg['engines']) && is_array($cfg['engines'])) {
            $raw = implode(',', $cfg['engines']);
        } elseif (is_array($cfg) && array_key_exists('engines', $cfg)) {
            // 显式清空（空串）表示「一个都不勾」，不能回退默认值。
            $raw = (string)$cfg['engines'];
        } else {
            $raw = 'tencentOcr,hy4Vision';
        }
        $ids = array();
        foreach (explode(',', (string)$raw) as $one) {
            $one = trim($one);
            if (in_array($one, array('tencentOcr', 'hy4Vision'), true) && !in_array($one, $ids, true)) {
                $ids[] = $one;
            }
        }
        return $ids;
    }

    /**
     * 收口自检返回：details ≤10、每条 ≤160 字。
     *
     * @param bool   $ok       是否通过
     * @param string $message  结论
     * @param array  $details  明细
     * @return array
     */
    function bg_tv_check_result($ok, $message, $details)
    {
        $out = array();
        foreach ((array)$details as $d) {
            $d = bg_tv_str($d, 160);
            if ($d !== '') {
                $out[] = $d;
            }
        }
        return array(
            'ok' => (bool)$ok,
            'message' => bg_tv_str($message, 160),
            'details' => array_slice($out, 0, 10),
        );
    }

    /**
     * 腾讯云 OCR 自检：配置完整性 + 1×1 图真实探针。
     *
     * @param array $cfg 配置
     * @return array
     */
    function bg_tv_check_ocr($cfg)
    {
        $c = bg_tv_ocr_cfg($cfg);
        $details = array(
            '接口域名：' . $c['endpoint'],
            '地域：' . $c['region'],
            '识别类型：' . bg_tv_ocr_action($c['ocrType']),
        );
        if ($c['secretId'] === '') {
            return bg_tv_check_result(false, '未配置 SecretId，请在设置中填写腾讯云 API 密钥', array_merge($details, array('探针未发起：SecretId 为空')));
        }
        if ($c['secretKey'] === '') {
            return bg_tv_check_result(false, '未配置 SecretKey，请在设置中填写腾讯云 API 密钥', array_merge($details, array('探针未发起：SecretKey 为空')));
        }
        $details[] = 'SecretId：已配置（不回显）';
        $details[] = '签名算法：TC3-HMAC-SHA256';
        if (!bg_tv_host_endpoint_public($c['endpoint'])) {
            return bg_tv_check_result(false, 'OCR 接口域名不可用：必须是公网域名，不能指向本机或内网地址', array_merge($details, array('探针未发起：端点未通过安全检查')));
        }
        try {
            $image = bg_tv_check_image();
            $b64 = bg_tv_ocr_image_base64($image);
            $action = bg_tv_ocr_action($c['ocrType']);
            $resp = bg_tv_ocr_post_api($c, $action, array('ImageBase64' => $b64), 15, 2);
            $detections = (isset($resp['TextDetections']) && is_array($resp['TextDetections']))
                ? $resp['TextDetections'] : array();
            $details[] = '探针接口：' . $action;
            $details[] = '探针返回：成功，文本块 ' . count($detections) . ' 个';
            return bg_tv_check_result(true, '签名与密钥有效，腾讯云 OCR 连通正常', $details);
        } catch (Throwable $e) {
            return bg_tv_check_result(false, '腾讯云 OCR 自检失败：' . $e->getMessage(), array_merge($details, array('排查：确认密钥已开通文字识别、地域正确、服务器出网正常')));
        }
    }

    /**
     * 混元 Hy4 自检：配置完整性 + 文本最小请求探针 + 提示词字段名校验。
     *
     * @param array $cfg 配置
     * @return array
     */
    function bg_tv_check_hy4($cfg)
    {
        $c = bg_tv_hy4_cfg($cfg);
        $details = array(
            '接口地址：' . $c['endpoint'],
            '模型名：' . $c['model'],
            '最大输出 Token：' . $c['maxTokens'],
        );
        if ($c['apiKey'] === '') {
            return bg_tv_check_result(false, '未配置混元 API Key，请在设置中填写', array_merge($details, array('探针未发起：hy4ApiKey 为空')));
        }
        $details[] = '混元 API Key：已配置（不回显）';
        $details[] = '凭据来源：混元控制台（独立于 SecretId/SecretKey）';

        // 提示词字段名校验（R11）：缺失只给提示，不阻断连通性结论。
        foreach (array('"date"', '"items"', '"partNo"', '"qty"') as $needle) {
            if (strpos($c['prompt'], $needle) === false) {
                $details[] = '提示词可能缺少字段 ' . $needle . '，请检查 hy4Prompt 模板';
            }
        }
        if (strpos($c['prompt'], '{TODAY}') === false || strpos($c['prompt'], '{CATALOG}') === false) {
            $details[] = '提示词缺少 {TODAY} 或 {CATALOG} 占位符，日期与零件库将无法注入';
        }

        try {
            if (!bg_tv_url_https_public($c['endpoint'])) {
                throw new RuntimeException('接口地址不可用：必须是公网 https 地址，不能指向本机或内网');
            }
            $image = bg_tv_check_image();
            $body = bg_tv_hy4_build_body($image, '只回复两个字：正常', $c['model'], 64);
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $job = array(
                'url' => $c['endpoint'],
                'headers' => array(
                    'Authorization: Bearer ' . $c['apiKey'],
                    'Content-Type: application/json',
                ),
                'payload' => $payload,
                'timeout' => min(15, $c['timeout']),
                'skipTls' => $c['skipTls'],
            );
            $send = bg_tv_http_send($job, 1);
            if ($send['err'] !== '') {
                throw new RuntimeException(bg_tv_hy4_network_error($send));
            }
            $resp = json_decode($send['body'], true);
            if ($send['code'] >= 400) {
                $msg = '';
                if (is_array($resp) && isset($resp['error']['message'])) {
                    $msg = (string)$resp['error']['message'];
                }
                throw new RuntimeException(bg_tv_hy4_error_text($send['code'], $msg));
            }
            if (!is_array($resp)) {
                throw new RuntimeException('接口返回内容不是预期的 JSON（HTTP ' . $send['code'] . '）');
            }
            $details[] = '探针返回：成功（HTTP ' . $send['code'] . '）';
            if (isset($resp['usage']) && is_array($resp['usage'])) {
                $u = $resp['usage'];
                $details[] = '探针 Token：prompt=' . (isset($u['prompt_tokens']) ? (int)$u['prompt_tokens'] : 0)
                    . ' / completion=' . (isset($u['completion_tokens']) ? (int)$u['completion_tokens'] : 0);
            }
            return bg_tv_check_result(true, '混元 API Key 有效，Hy4 通道连通正常（模型：' . $c['model'] . '）', $details);
        } catch (Throwable $e) {
            return bg_tv_check_result(false, '混元 Hy4 自检失败：' . $e->getMessage(), array_merge($details, array('排查：确认模型名已开通图像输入、API Key 归属正确、出网正常')));
        }
    }

    /**
     * 总自检：仅检查已勾选引擎，汇总为 {ok,message,details}。
     *
     * @param array $cfg 配置
     * @return array
     */
    function bg_tv_check_run($cfg)
    {
        $cfg = is_array($cfg) ? $cfg : array();
        $engines = bg_tv_check_engines($cfg);
        if (!$engines) {
            return bg_tv_check_result(false, '请至少勾选一个识别引擎', array('engines 为空：请在设置页勾选 tencentOcr / hy4Vision'));
        }
        $details = array('已勾选引擎：' . implode('、', $engines));
        $fails = array();
        foreach ($engines as $id) {
            $r = ($id === 'tencentOcr') ? bg_tv_check_ocr($cfg) : bg_tv_check_hy4($cfg);
            $label = ($id === 'tencentOcr') ? '腾讯云 OCR' : '混元 Hy4';
            $details[] = $label . '：' . ($r['ok'] ? '通过' : '未通过') . '——' . $r['message'];
            foreach ($r['details'] as $d) {
                $details[] = '  · ' . $label . ' · ' . $d;
            }
            if (!$r['ok']) {
                $fails[] = $label . '：' . $r['message'];
            }
        }
        if ($fails) {
            return bg_tv_check_result(false, implode('；', $fails), $details);
        }
        return bg_tv_check_result(true, '已勾选引擎全部自检通过', $details);
    }
}

$ctx = $GLOBALS['BG_PLUGIN_CTX'];
return bg_tv_check_run(isset($ctx['config']) && is_array($ctx['config']) ? $ctx['config'] : array());
