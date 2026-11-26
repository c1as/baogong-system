<?php
/**
 * T03 单元测试：JSON 容错抠取 / 文本兜底 / 请求体红线 / 占位符 / 中文错误映射
 * 运行：php tests/hy4_vision.test.php
 */

$bg_tv_test_total = 0;
$bg_tv_test_pass = 0;
$bg_tv_test_fail = array();

/**
 * 断言辅助。
 *
 * @param bool   $cond 条件
 * @param string $name 用例名
 * @return void
 */
function bg_tv_test_assert($cond, $name)
{
    global $bg_tv_test_total, $bg_tv_test_pass, $bg_tv_test_fail;
    $bg_tv_test_total++;
    if ($cond) {
        $bg_tv_test_pass++;
        echo "  [OK] " . $name . "\n";
    } else {
        $bg_tv_test_fail[] = $name;
        echo "  [FAIL] " . $name . "\n";
    }
}

require_once __DIR__ . '/../lib/util.php';
require_once __DIR__ . '/../lib/hy4_vision.php';

echo "== T03 混元 Hy4 ==\n";

$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures/hy4_contents.json'), true);
bg_tv_test_assert(is_array($fixtures), 'fixture 可解析');

/* 1. 纯 JSON */
$p1 = bg_tv_hy4_extract_json($fixtures['pure_json']);
bg_tv_test_assert(is_array($p1) && $p1['date'] === '2026-11-20', '纯 JSON 抠取日期');
bg_tv_test_assert(isset($p1['items'][1]['qty']) && $p1['items'][1]['qty'] === 50, '纯 JSON 抠取明细数量');

/* 2. ```json 围栏 */
$p2 = bg_tv_hy4_extract_json($fixtures['fenced']);
bg_tv_test_assert(is_array($p2) && $p2['worker'] === '李四', '围栏 JSON 抠取报工人');

/* 3. 前后带说明文字 */
$p3 = bg_tv_hy4_extract_json($fixtures['with_text']);
bg_tv_test_assert(is_array($p3) && $p3['worker'] === '王五', '说明文字中抠取 JSON');

/* 4. 非 JSON → null + 文本兜底 */
bg_tv_test_assert(bg_tv_hy4_extract_json('这里没有结构化结果') === null, '无 JSON 返回 null');
$fallback = bg_tv_hy4_fallback($fixtures['plain_text'], array(), '2026-11-20');
bg_tv_test_assert($fallback['date'] === '2026-11-20', '文本兜底解析日期');
bg_tv_test_assert($fallback['worker'] === '张三', '文本兜底解析报工人');
bg_tv_test_assert(count($fallback['items']) === 2, '文本兜底解析 2 行明细');
bg_tv_test_assert($fallback['items'][0]['qty'] == 80 && $fallback['items'][1]['qty'] == 50, '文本兜底数量 80/50');

/* 5. 请求体红线：无 ImageQuestion，图片 dataURL 英文逗号 */
$image = 'data:image/png;base64,iVBORw0KGgo=';
$body = bg_tv_hy4_build_body($image, '提示词', 'hunyuan-vision', 4096);
$encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
bg_tv_test_assert(strpos($encoded, 'ImageQuestion') === false, '请求体不含 ImageQuestion');
bg_tv_test_assert(array_keys($body) === array('model', 'messages', 'max_tokens', 'stream'), '请求体仅含四个允许键');
bg_tv_test_assert($body['messages'][0]['content'][0]['image_url']['url'] === $image, '图片以 dataURL 原样放入 image_url.url');
bg_tv_test_assert(strpos($body['messages'][0]['content'][1]['text'], '提示词') !== false, '提示词作为 text 内容');
bg_tv_test_assert($body['stream'] === false, 'stream=false');

/* 6. 占位符替换与提示词字段约束 */
$prompt = bg_tv_hy4_render_prompt('日期{TODAY} 库{CATALOG}', '2026-11-20', 'A|B');
bg_tv_test_assert($prompt === '日期2026-11-20 库A|B', '{TODAY}/{CATALOG} 被替换');
$defaultPrompt = bg_tv_hy4_default_prompt();
bg_tv_test_assert(strpos($defaultPrompt, '{"date"') !== false && strpos($defaultPrompt, '"items"') !== false
    && strpos($defaultPrompt, '"partNo"') !== false && strpos($defaultPrompt, '"qty"') !== false, '默认提示词含契约字段名');

/* 7. 模型名可配 + Bearer 头 */
$cfg = array('hy4ApiKey' => 'sk-test', 'hy4Model' => 'hy4-custom', 'hy4Prompt' => '日期{TODAY}库{CATALOG}');
$ctx = array('image' => $image, 'today' => '2026-11-20', 'catalog' => array(array('no' => 'TD1', 'name' => '块')));
$job = bg_tv_hy4_prepare($ctx, $cfg);
$jobBody = json_decode($job['payload'], true);
bg_tv_test_assert($jobBody['model'] === 'hy4-custom', 'hy4Model 生效');
bg_tv_test_assert(strpos($jobBody['messages'][0]['content'][1]['text'], '2026-11-20') !== false, 'prepare 已替换占位符');
bg_tv_test_assert($job['headers'][0] === 'Authorization: Bearer sk-test', 'Bearer 头正确');

/* 8. 中文错误映射 */
bg_tv_test_assert(strpos(bg_tv_hy4_error_text(401, 'bad'), '密钥无效或地域不匹配') !== false, '401 中文提示');
bg_tv_test_assert(strpos(bg_tv_hy4_error_text(404, 'no model'), '接口地址或模型名有误') !== false, '404 中文提示');
bg_tv_test_assert(strpos(bg_tv_hy4_error_text(429, 'limit'), '触发限流') !== false, '429 中文提示');
bg_tv_test_assert(strpos(bg_tv_hy4_error_text(500, 'oops'), '服务端异常') !== false, '5xx 中文提示');

echo "结果：{$bg_tv_test_pass}/{$bg_tv_test_total} 通过\n";
if ($bg_tv_test_fail) {
    exit(1);
}
