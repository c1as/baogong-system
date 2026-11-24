<?php
/**
 * AI 纠错整理（OpenAI 兼容接口）
 *
 * 红线：AI 只做「归纳」，不做「判定」，也不直接入库。
 *  - 候选强制 status=pending、accepted=false、confidence≤0.8；
 *  - 请求失败/超时/解析无有效候选一律降级（由 API 返回 41003），人工路径不受影响；
 *  - AI Key 只在服务端读取，任何返回与错误信息都不回显 Key。
 */

if (!defined('BGLX_REV_AI_LOADED')) {
    define('BGLX_REV_AI_LOADED', 1);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/schema.php';

/**
 * AI 输入二次闸门（defense-in-depth）：只保留判错且带正确值的行。
 *
 * 兼容 schema 1 旧行（无 judgment 字段）：只要 correct_value 非空即视为可整理；
 * 明确标为 correct / unreviewed 的行一律剔除，杜绝判对值污染词库。
 *
 * @param array $corrections 纠错列表
 * @return array 判错行列表
 */
function bglx_rev_ai_wrong_rows($corrections)
{
    $rows = is_array($corrections) ? $corrections : array();
    $out = array();
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $judgment = isset($row['judgment']) ? (string)$row['judgment'] : '';
        if ($judgment !== '' && $judgment !== 'wrong') {
            continue;
        }
        $standard = trim((string)(isset($row['correct_value'])
            ? $row['correct_value'] : (isset($row['standard']) ? $row['standard'] : '')));
        if ($standard === '') {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * 构造 AI 整理提示词（system + user 两条 messages）。
 *
 * @param array $corrections 纠错列表（内部只取判错行）
 * @param int   $max         候选上限
 * @return array
 */
function bglx_rev_ai_prompt($corrections, $max)
{
    $max = max(1, min(200, (int)$max));
    $system = '你是中文手写识别纠错归纳助手。只输出一个 JSON 数组，不要任何解释、标题或 markdown 围栏。'
        . '每个元素字段：type（只能是 drawing_alias/process_synonym/unit_synonym/char_confusion）、'
        . 'standard（正确值）、variants（识别错误值及合理变体数组）、confidence（0~1）、note（简短原因）。'
        . '将相同 standard 的纠错合并；形近字 standard/variants 必须是单个字符。最多 ' . $max . ' 条。';
    $json = json_encode(bglx_rev_ai_wrong_rows($corrections), JSON_UNESCAPED_UNICODE);
    return array(
        array('role' => 'system', 'content' => $system),
        array('role' => 'user', 'content' => $json === false ? '[]' : $json),
    );
}

/**
 * 容错解析 AI 返回文本：剥离 ```json 围栏、截取首个 [ 到最后一个 ]。
 *
 * 所有输出强制 pending / accepted=false / confidence≤0.8。
 *
 * @param string $text AI 原始文本
 * @return array 候选数组
 */
function bglx_rev_ai_parse($text)
{
    $text = trim((string)$text);
    if ($text === '') {
        return array();
    }
    if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', $text, $m)) {
        $text = trim($m[1]);
    }
    $a = strpos($text, '[');
    $b = strrpos($text, ']');
    if ($a !== false && $b !== false && $b > $a) {
        $text = substr($text, $a, $b - $a + 1);
    }
    $arr = json_decode($text, true);
    if (!is_array($arr)) {
        return array();
    }
    if (isset($arr['candidates']) && is_array($arr['candidates'])) {
        $arr = $arr['candidates'];
    }
    $out = array();
    foreach ($arr as $row) {
        if (!is_array($row)) {
            continue;
        }
        $type = isset($row['type']) ? (string)$row['type'] : '';
        if (!in_array($type, BGLX_TYPES, true)) {
            continue;
        }
        $standard = trim((string)(isset($row['standard']) ? $row['standard'] : ''));
        $variants = bglx_variants(isset($row['variants']) ? $row['variants'] : array());
        if ($standard === '' || !$variants) {
            continue;
        }
        $conf = isset($row['confidence']) ? (float)$row['confidence'] : 0.8;
        if (!is_finite($conf)) {
            $conf = 0.8;
        }
        $out[] = array(
            'type' => $type,
            'standard' => $standard,
            'variants' => $variants,
            'confidence' => max(0.0, min(0.8, $conf)),   // ④ 上限 0.8 < 自动阈值 0.90
            'status' => 'pending',                       // ① 红线：强制待审
            'accepted' => false,                         // ② 红线：必须人工勾选
            'source' => 'ocr_feedback',
            'note' => bglx_cut(isset($row['note']) ? (string)$row['note'] : '', 200),
        );
    }
    $max = bglx_rev_setting_int('aiMaxCandidates', 50);
    return array_slice($out, 0, max(1, min(200, $max)));
}

/**
 * 组装 AI 请求公共配置；不回显 Key。
 *
 * @param array $cfg 外部配置
 * @return array
 */
function bglx_rev_ai_config($cfg = array())
{
    $cfg = is_array($cfg) ? $cfg : array();
    return array(
        'enabled' => array_key_exists('enabled', $cfg) ? (bool)$cfg['enabled']
            : bglx_rev_setting_bool('aiEnabled', false),
        'endpoint' => trim((string)(isset($cfg['endpoint']) ? $cfg['endpoint']
            : bglx_rev_setting('aiEndpoint', 'https://api.hunyuan.cloud.tencent.com/v1/chat/completions'))),
        'key' => trim((string)(isset($cfg['key']) ? $cfg['key'] : bglx_rev_setting('aiKey', ''))),
        'model' => trim((string)(isset($cfg['model']) ? $cfg['model'] : bglx_rev_setting('aiModel', 'hunyuan'))),
        'timeout' => (int)(isset($cfg['timeout']) ? $cfg['timeout'] : bglx_rev_setting_int('aiTimeoutSec', 30)),
        'max' => (int)(isset($cfg['max']) ? $cfg['max'] : bglx_rev_setting_int('aiMaxCandidates', 50)),
        'fallback' => array_key_exists('fallback', $cfg) ? (bool)$cfg['fallback']
            : bglx_rev_setting_bool('aiFallbackToManual', true),
    );
}

/**
 * AI 连通性探测（默认不发起网络请求；cfg['probe']=true 时探测）。
 *
 * @param array $cfg 配置
 * @return array array('ok'=>bool,'detail'=>string)，detail 不含 Key
 */
function bglx_rev_ai_available($cfg = array())
{
    $c = bglx_rev_ai_config($cfg);
    if (!$c['enabled']) {
        return array('ok' => false, 'detail' => 'AI 整理未启用');
    }
    if ($c['endpoint'] === '') {
        return array('ok' => false, 'detail' => '未配置 AI 接口地址');
    }
    if ($c['key'] === '') {
        return array('ok' => false, 'detail' => '未配置 AI Key（服务端读取，不回显）');
    }
    $cfg = is_array($cfg) ? $cfg : array();
    if (empty($cfg['probe'])) {
        return array('ok' => true, 'detail' => 'AI 配置完整（未执行连通性探测）');
    }
    $messages = array(array('role' => 'user', 'content' => '请只回复 ok 两个字符。'));
    $body = json_encode(array(
        'model' => $c['model'], 'messages' => $messages, 'max_tokens' => 16,
    ), JSON_UNESCAPED_UNICODE);
    $resp = bglx_rev_http_post($c['endpoint'], array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $c['key'],
    ), $body === false ? '{}' : $body, min(10, $c['timeout']));
    if (!$resp['ok']) {
        return array('ok' => false, 'detail' => 'AI 接口不可达：' . bglx_cut($resp['error'], 120));
    }
    return array('ok' => true, 'detail' => 'AI 接口连通（HTTP ' . $resp['status'] . '）');
}

/**
 * AI 整理主流程：构造提示词 → HTTP POST → 解析 → 降级。
 *
 * @param array $corrections 纠错列表
 * @param array $cfg         配置
 * @return array array('ok','candidates','reason','fallback')
 */
function bglx_rev_ai_organize($corrections, $cfg = array())
{
    $c = bglx_rev_ai_config($cfg);
    $fallback = array(
        'ok' => false, 'candidates' => array(),
        'reason' => '', 'fallback' => $c['fallback'],
    );
    $fail = function ($reason) use ($fallback) {
        $fallback['reason'] = bglx_cut((string)$reason, 200);
        return $fallback;
    };

    if (!$c['enabled']) {
        return $fail('AI 整理未启用');
    }
    if ($c['endpoint'] === '') {
        return $fail('未配置 AI 接口地址');
    }
    if ($c['key'] === '') {
        return $fail('未配置 AI Key（请在服务端配置）');
    }
    $corrections = bglx_rev_ai_wrong_rows($corrections); // 断言：判对行不进 prompt
    if (!$corrections) {
        return $fail('没有可整理的判错明细');
    }

    $messages = bglx_rev_ai_prompt($corrections, $c['max']);
    $payload = array(
        'model' => $c['model'],
        'messages' => $messages,
        'temperature' => 0.2,
        'max_tokens' => 2048,
    );
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return $fail('AI 请求体编码失败');
    }
    $resp = bglx_rev_http_post($c['endpoint'], array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $c['key'],
    ), $body, $c['timeout']);
    if (!$resp['ok']) {
        return $fail($resp['error'] !== '' ? $resp['error'] : 'AI 接口请求失败');
    }
    $data = json_decode($resp['body'], true);
    $content = '';
    if (is_array($data) && isset($data['choices'][0]['message']['content'])) {
        $content = (string)$data['choices'][0]['message']['content'];
    } elseif (is_array($data) && isset($data['content'])) {
        $content = (string)$data['content'];
    } elseif (is_array($data) && isset($data['data']) && is_string($data['data'])) {
        $content = $data['data'];
    } else {
        return $fail('AI 响应结构无法识别');
    }
    $candidates = bglx_rev_ai_parse($content);
    if (!$candidates) {
        return $fail('AI 未返回有效候选，已降级为人工导入');
    }
    return array(
        'ok' => true, 'candidates' => $candidates,
        'reason' => '', 'fallback' => false,
    );
}
