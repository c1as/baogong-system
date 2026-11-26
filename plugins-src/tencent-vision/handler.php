<?php
/**
 * 腾讯云多引擎视觉识别插件 · 编排入口
 *
 * 由 api.php?action=plugin.invoke 引入：先校验 BG_PLUGIN_CTX，读 engines 多选，
 * curl_multi 并行调用选中引擎（无 curl 时串行降级），逐路独立容错 → 统一归一化
 * → 字库纠错 → outputMode 收口 → bg_tv_finalize() 施加上限 → return。
 * 全程不输出任何内容、不 exit；单路异常内部消化，全失败时 throw。
 */

if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/tencent_ocr.php';
require_once __DIR__ . '/lib/hy4_vision.php';
require_once __DIR__ . '/lib/normalize.php';
require_once __DIR__ . '/lib/fuse.php';
require_once __DIR__ . '/lib/lex.php';

if (!function_exists('bg_tv_run')) {
    /**
     * 解析 engines 多选配置（支持逗号串或数组）。
     *
     * @param array $cfg 插件配置
     * @return array 引擎 id 列表
     */
    function bg_tv_parse_engines($cfg)
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
            if (!in_array($one, array('tencentOcr', 'hy4Vision'), true)) {
                continue;
            }
            if (!in_array($one, $ids, true)) {
                $ids[] = $one;
            }
        }
        return $ids;
    }

    /**
     * 构造单路失败的 EngineResult（ok=false）。
     *
     * @param string $id    引擎 id
     * @param string $error 错误描述
     * @return array
     */
    function bg_tv_engine_fail($id, $error)
    {
        return array(
            'engineId' => (string)$id,
            'confidence' => 0.0,
            'fields' => array('date' => '', 'worker' => '', 'workshop' => '', 'note' => '', 'items' => array()),
            'raw' => '',
            'warnings' => array(),
            'usage' => array('prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0),
            'elapsedMs' => 0,
            'ok' => false,
            '_error' => (string)$error,
        );
    }

    /**
     * 创建并配置一个 curl 句柄（curl_multi 调度使用）。
     *
     * @param array $job 请求 job
     * @return resource|false
     */
    function bg_tv_curl_handle($job)
    {
        $ch = curl_init(isset($job['url']) ? (string)$job['url'] : '');
        if ($ch === false) {
            return false;
        }
        $skipTls = !empty($job['skipTls']);
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => isset($job['payload']) ? (string)$job['payload'] : '',
            CURLOPT_HTTPHEADER     => isset($job['headers']) && is_array($job['headers']) ? $job['headers'] : array(),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => isset($job['timeout']) ? (int)$job['timeout'] : 30,
            CURLOPT_SSL_VERIFYPEER => !$skipTls,
            CURLOPT_SSL_VERIFYHOST => $skipTls ? 0 : 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
        );
        if (defined('CURLOPT_NOSIGNAL')) {
            $opts[CURLOPT_NOSIGNAL] = 1;
        }
        if (!$skipTls) {
            $ca = bg_tv_ca_file();
            if ($ca !== '') {
                $opts[CURLOPT_CAINFO] = $ca;
            }
        }
        curl_setopt_array($ch, $opts);
        return $ch;
    }

    /**
     * 把一次 HTTP 结果解析为对应引擎的 EngineResult（含 try/catch）。
     *
     * @param string $id    引擎 id
     * @param array  $send  HTTP 结果
     * @param array  $cfg   配置
     * @param array  $ctx   上下文
     * @param float  $t0    起始微秒时间
     * @return array
     */
    function bg_tv_engine_parse_job($id, $send, $cfg, $ctx, $t0)
    {
        try {
            if (!empty($send['err'])) {
                $err = ($id === 'tencentOcr')
                    ? bg_tv_ocr_network_error($send)
                    : bg_tv_hy4_network_error($send);
                throw new RuntimeException($err);
            }
            if ($id === 'tencentOcr') {
                return bg_tv_ocr_parse(
                    isset($send['body']) ? $send['body'] : '',
                    isset($send['code']) ? $send['code'] : 0,
                    $cfg,
                    $ctx,
                    $t0
                );
            }
            return bg_tv_hy4_parse(
                isset($send['body']) ? $send['body'] : '',
                isset($send['code']) ? $send['code'] : 0,
                $cfg,
                $ctx,
                $t0
            );
        } catch (Throwable $e) {
            return bg_tv_engine_fail($id, $e->getMessage());
        }
    }

    /**
     * 并行/串行调度选中引擎，返回 id => EngineResult。
     *
     * @param array $ctx     上下文
     * @param array $cfg     配置
     * @param array $engines 引擎 id 列表
     * @return array
     */
    function bg_tv_invoke_engines($ctx, $cfg, $engines)
    {
        $results = array();
        $jobs = array();
        $t0s = array();
        foreach ($engines as $id) {
            $t0 = microtime(true);
            try {
                if ($id === 'tencentOcr') {
                    $jobs[$id] = bg_tv_ocr_prepare($ctx, $cfg);
                } elseif ($id === 'hy4Vision') {
                    $jobs[$id] = bg_tv_hy4_prepare($ctx, $cfg);
                } else {
                    continue;
                }
                $t0s[$id] = $t0;
            } catch (Throwable $e) {
                $results[$id] = bg_tv_engine_fail($id, $e->getMessage());
            }
        }
        if (!$jobs) {
            return $results;
        }

        if (function_exists('curl_multi_init') && count($jobs) > 1) {
            $mh = curl_multi_init();
            $chs = array();
            foreach ($jobs as $id => $job) {
                $ch = bg_tv_curl_handle($job);
                if ($ch === false) {
                    $results[$id] = bg_tv_engine_fail($id, '无法初始化 curl 句柄');
                    continue;
                }
                curl_multi_add_handle($mh, $ch);
                $chs[$id] = $ch;
            }
            $running = 0;
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running && $status === CURLM_OK) {
                    curl_multi_select($mh, 0.1);
                }
            } while ($running && $status === CURLM_OK);

            foreach ($chs as $id => $ch) {
                $body = curl_multi_getcontent($ch);
                $send = array(
                    'body' => ($body === false) ? '' : (string)$body,
                    'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                    'err' => (string)curl_error($ch),
                    'errno' => (int)curl_errno($ch),
                    'attempts' => 1,
                );
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                // 仅网络类故障与 HTTP 429/5xx 补一次串行重试；4xx 业务错误不重试。
                $retryable = ($send['err'] !== '' && bg_tv_retryable($send['errno']))
                    || $send['code'] === 429 || $send['code'] >= 500;
                $allowed = isset($jobs[$id]['retries']) ? (int)$jobs[$id]['retries'] : 1;
                if ($retryable && $allowed > 0) {
                    $job = $jobs[$id];
                    $r2 = bg_tv_http_post(
                        $job['url'],
                        $job['payload'],
                        $job['headers'],
                        (int)$job['timeout'],
                        !empty($job['skipTls'])
                    );
                    $send = array(
                        'body' => $r2['body'],
                        'code' => $r2['code'],
                        'err' => $r2['err'],
                        'errno' => $r2['errno'],
                        'attempts' => 2,
                    );
                }
                $results[$id] = bg_tv_engine_parse_job(
                    $id,
                    $send,
                    $cfg,
                    $ctx,
                    isset($t0s[$id]) ? $t0s[$id] : microtime(true)
                );
            }
            curl_multi_close($mh);
        } else {
            // 无 curl_multi 或单引擎：串行降级，每路独立重试、独立解析。
            foreach ($jobs as $id => $job) {
                $send = bg_tv_http_send($job, isset($job['retries']) ? $job['retries'] : 1);
                $results[$id] = bg_tv_engine_parse_job(
                    $id,
                    $send,
                    $cfg,
                    $ctx,
                    isset($t0s[$id]) ? $t0s[$id] : microtime(true)
                );
            }
        }
        return $results;
    }

    /**
     * 总编排：ctx 校验已由入口完成，此处负责全流程收口。
     *
     * @param array $ctx 插件上下文
     * @param array $cfg 插件配置
     * @return array FinalResult
     */
    function bg_tv_run($ctx, $cfg)
    {
        $ctx = is_array($ctx) ? $ctx : array();
        $cfg = is_array($cfg) ? $cfg : array();
        $today = (isset($ctx['today']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ctx['today']))
            ? (string)$ctx['today'] : date('Y-m-d');
        $catalog = (isset($ctx['catalog']) && is_array($ctx['catalog'])) ? $ctx['catalog'] : array();

        $engines = bg_tv_parse_engines($cfg);
        if (!$engines) {
            throw new RuntimeException('请至少勾选一个识别引擎');
        }
        $midResults = bg_tv_invoke_engines($ctx, $cfg, $engines);

        // 归一化内部的日期补全统一使用 ctx.today，而非服务器当天。
        $normCfg = is_array($cfg) ? $cfg : array();
        $normCfg['_today'] = $today;

        $globalWarnings = array();
        $errors = array();
        $okResults = array();
        foreach ($midResults as $id => $r) {
            if (empty($r['ok'])) {
                $errors[$id] = isset($r['_error']) ? (string)$r['_error'] : '未知错误';
                continue;
            }
            $r['_today'] = $today;
            $r['_catalog'] = $catalog;
            if ($id === 'tencentOcr') {
                $n = bg_tv_norm_ocr($r, $normCfg);
            } else {
                $mid = isset($r['_mid']) && is_array($r['_mid']) ? $r['_mid'] : array();
                $n = bg_tv_norm_hy4(
                    isset($mid['parsed']) ? $mid['parsed'] : array(),
                    isset($mid['source']) ? $mid['source'] : 'json',
                    $normCfg
                );
                $n['raw'] = isset($r['raw']) ? (string)$r['raw'] : '';
                $n['usage'] = isset($r['usage']) && is_array($r['usage']) ? $r['usage'] : $n['usage'];
                $n['elapsedMs'] = isset($r['elapsedMs']) ? (int)$r['elapsedMs'] : 0;
                $n['warnings'] = array_merge(
                    isset($r['warnings']) && is_array($r['warnings']) ? $r['warnings'] : array(),
                    isset($n['warnings']) && is_array($n['warnings']) ? $n['warnings'] : array()
                );
            }
            // 字库在 fuse 之前逐路施加，保证 alternates[] 也是干净字段。
            $lex = bg_tv_lex_apply($n['fields'], 'tencent-vision');
            $n['fields'] = $lex['fields'];
            $n['warnings'] = array_merge($n['warnings'], $lex['warnings']);
            $okResults[$id] = $n;
        }

        $hasOcr = isset($okResults['tencentOcr']);
        $hasHy4 = isset($okResults['hy4Vision']);
        foreach ($errors as $id => $err) {
            $short = bg_tv_str($err, 60);
            if ($id === 'tencentOcr' && $hasHy4) {
                $globalWarnings[] = '腾讯云 OCR 调用失败：' . $short . '（已由 Hy4 兜底）';
            } elseif ($id === 'hy4Vision' && $hasOcr) {
                $globalWarnings[] = 'Hy4 调用失败：' . $short . '（已由 OCR 兜底）';
            } else {
                $globalWarnings[] = bg_tv_result_label($id) . '调用失败：' . $short;
            }
            // 单一引擎且仅因密钥缺失失败时，抛出更明确的中文提示。
            if (count($engines) === 1 && strpos($err, '请先填写') === 0) {
                throw new RuntimeException($err);
            }
        }
        if (!$okResults) {
            $parts = array();
            foreach ($errors as $id => $err) {
                $parts[] = bg_tv_result_label($id) . '：' . $err;
            }
            throw new RuntimeException('腾讯云 OCR 与 Hy4 均失败：' . implode('；', $parts));
        }

        $primary = bg_tv_cfg($cfg, 'primaryEngine', 'tencentOcr');
        if (!isset($okResults[$primary])) {
            $ids = array_keys($okResults);
            $primary = $ids[0];
            $globalWarnings[] = '主引擎调用失败，已改用' . bg_tv_result_label($primary);
        }

        $mode = bg_tv_cfg($cfg, 'outputMode', 'fuse');
        if (!in_array($mode, array('fuse', 'parallel', 'primary'), true)) {
            $mode = 'fuse';
        }
        if ($mode === 'parallel') {
            $base = bg_tv_parallel($okResults, $cfg);
        } elseif ($mode === 'primary') {
            $base = bg_tv_primary($okResults, $cfg);
        } else {
            $fields = bg_tv_fuse($okResults, $cfg);
            list($a) = bg_tv_order_by_primary($okResults, $primary);
            $base = array(
                'engine' => bg_tv_engine_prefix($a['engineId']),
                'confidence' => bg_tv_conf(isset($a['confidence']) ? $a['confidence'] : 0.0, 0.0),
                'fields' => $fields,
                'warnings' => array(),
                'raw' => '',
            );
        }

        // 汇总两路 raw，保证 fuse 赢家值能在某一路原文中找到片段（L1 佐证）。
        $raws = array();
        foreach ($okResults as $r) {
            $raw = trim((string)(isset($r['raw']) ? $r['raw'] : ''));
            if ($raw !== '' && !in_array($raw, $raws, true)) {
                $raws[] = $raw;
            }
        }
        $base['raw'] = implode("\n", $raws);

        if (bg_tv_bool($cfg, 'compareMode', false) && count($okResults) >= 2) {
            list($a, $b) = bg_tv_order_by_primary($okResults, $primary);
            foreach (bg_tv_diff($a, $b) as $d) {
                $base['warnings'][] = '差异：' . bg_tv_str($d, 76);
            }
        }
        $review = bg_tv_float($cfg, 'reviewThreshold', 0.6, 0.0, 1.0);
        if ($base['confidence'] < $review) {
            $base['warnings'][] = '自报置信度低于阈值，建议人工复核';
        }
        // 汇总各路 warnings（主引擎优先）：引擎/归一化/字库层产生的提示不得在编排层丢失。
        // 顺序：全局兜底提示 → 主引擎 → 备引擎 → base 自身（差异、低置信复核）。
        // 仅汇入原始告警并去重；条数与长度上限仍由 bg_tv_finalize 统一施加。
        $engineWarnings = array();
        $orderedResults = bg_tv_order_by_primary($okResults, $primary);
        foreach ($orderedResults as $r) {
            if (empty($r['ok']) || empty($r['engineId'])) {
                continue;
            }
            $list = isset($r['warnings']) && is_array($r['warnings']) ? $r['warnings'] : array();
            foreach ($list as $w) {
                if (!in_array($w, $engineWarnings, true)) {
                    $engineWarnings[] = $w;
                }
            }
        }
        $mergedWarnings = array_merge(
            $globalWarnings,
            $engineWarnings,
            isset($base['warnings']) && is_array($base['warnings']) ? $base['warnings'] : array()
        );
        $deduped = array();
        foreach ($mergedWarnings as $w) {
            if (!in_array($w, $deduped, true)) {
                $deduped[] = $w;
            }
        }
        $base['warnings'] = $deduped;

        // 旁路可观测性：usage 与耗时已在引擎层写日志，这里补编排摘要。
        $log = array(
            'invokeId' => isset($ctx['invokeId']) ? (string)$ctx['invokeId'] : '',
            'mode' => $mode,
            'primary' => $primary,
            'engines' => implode(',', array_keys($okResults)),
            'confidence' => $base['confidence'],
        );
        foreach ($midResults as $id => $r) {
            $log['elapsed_' . $id] = isset($r['elapsedMs']) ? (int)$r['elapsedMs'] : 0;
        }
        bg_tv_log('run', $log);

        $base['_today'] = $today;
        return bg_tv_finalize($base);
    }
}

$ctx = $GLOBALS['BG_PLUGIN_CTX'];
return bg_tv_run($ctx, isset($ctx['config']) && is_array($ctx['config']) ? $ctx['config'] : array());
