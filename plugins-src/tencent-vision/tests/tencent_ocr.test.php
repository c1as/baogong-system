<?php
/**
 * T02 单元测试：TC3 签名向量 / 三子接口映射 / TextDetections 行还原 / 中文错误映射
 * 运行：php tests/tencent_ocr.test.php
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
require_once __DIR__ . '/../lib/tencent_ocr.php';

echo "== T02 腾讯云 OCR ==\n";

/* 1. 签名向量：与基线 lib.php:bg_tc_sign 对同一输入逐字节一致 */
$secretId = 'AKIDTEST123456789';
$secretKey = 'testSecretKey123456';
$host = 'ocr.tencentcloudapi.com';
$action = 'GeneralAccurateOCR';
$payload = '{"ImageBase64":"iVBORw0KGgo="}';
$ts = 1700000000;
$mine = bg_tv_ocr_sign($secretId, $secretKey, 'ocr', $host, $action, $payload, $ts);
$baseline = (is_file('/workspace/tencent-ocr/lib.php') && !function_exists('bg_tc_sign'))
    ? (require_once '/workspace/tencent-ocr/lib.php') : null;
if (function_exists('bg_tc_sign')) {
    $ref = bg_tc_sign($secretId, $secretKey, 'ocr', $host, $action, $payload, $ts);
    bg_tv_test_assert($mine === $ref, '签名向量与 lib.php 完全一致');
} else {
    bg_tv_test_assert(strpos($mine, 'TC3-HMAC-SHA256 Credential=') === 0, '签名格式以 TC3-HMAC-SHA256 开头');
}
bg_tv_test_assert(strpos($mine, 'Credential=' . $secretId . '/') !== false, 'Authorization 含 Credential');
bg_tv_test_assert(strpos($mine, 'SignedHeaders=content-type;host') !== false, 'Authorization 含 SignedHeaders');
bg_tv_test_assert(strpos($mine, 'Signature=') !== false, 'Authorization 含 Signature');
bg_tv_test_assert(strpos($mine, gmdate('Y-m-d', $ts)) !== false, '签名 scope 使用 UTC 日期');

/* 2. 三子接口映射 */
bg_tv_test_assert(bg_tv_ocr_action('accurate') === 'GeneralAccurateOCR', 'accurate 映射高精度接口');
bg_tv_test_assert(bg_tv_ocr_action('handwriting') === 'GeneralHandwritingOCR', 'handwriting 映射手写接口');
bg_tv_test_assert(bg_tv_ocr_action('basic') === 'GeneralBasicOCR', 'basic 映射标准接口');

/* 3. TextDetections 行还原（fixture：三行明细 80/50/50） */
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/ocr_text_detections.json'), true);
bg_tv_test_assert(is_array($fixture) && count($fixture) > 10, 'fixture 可解析且含多条检测');
$rows = bg_tv_ocr_rows($fixture);
bg_tv_test_assert(count($rows) === 6, '按 Y 聚行得到 6 行（日期/人员/3明细/合计），实际 ' . count($rows));

$detailRows = array();
foreach ($rows as $row) {
    if (preg_match('/TD24510R04|AB123456|ZZ998877/', $row['text'])) {
        $detailRows[] = $row;
    }
}
bg_tv_test_assert(count($detailRows) === 3, '识别到 3 行明细');
if (count($detailRows) === 3) {
    bg_tv_test_assert(strpos($detailRows[0]['text'], 'TD24510R04') !== false && strpos($detailRows[0]['text'], '80') !== false, '明细行1含图号与数量 80');
    bg_tv_test_assert(strpos($detailRows[1]['text'], 'AB123456') !== false && strpos($detailRows[1]['text'], '50') !== false, '明细行2含图号与数量 50');
    bg_tv_test_assert(strpos($detailRows[2]['text'], 'ZZ998877') !== false && strpos($detailRows[2]['text'], '50') !== false, '明细行3含图号与数量 50');
    $xs = array_column($detailRows[0]['cells'], 'x');
    $sorted = $xs;
    sort($sorted);
    bg_tv_test_assert($xs === $sorted, '行内单元格按 X 升序排列');
}

/* 4. Polygon 回退外接矩形 */
$box = bg_tv_poly_box(array('Polygon' => array(
    array('X' => 10, 'Y' => 20), array('X' => 50, 'Y' => 20),
    array('X' => 50, 'Y' => 40), array('X' => 10, 'Y' => 40),
)));
bg_tv_test_assert($box['x'] === 10 && $box['y'] === 20 && $box['w'] === 40 && $box['h'] === 20, 'Polygon 外接矩形回退正确');

/* 5. 中文错误映射与重试判定 */
bg_tv_test_assert(strpos(bg_tv_ocr_error_text('AuthFailure.InvalidSecretId', ''), '密钥无效或未开通文字识别') !== false, '鉴权错误映射中文提示');
bg_tv_test_assert(strpos(bg_tv_ocr_error_text('InvalidParameterValue', ''), '参数有误') !== false, '参数错误映射中文提示');
bg_tv_test_assert(bg_tv_retryable(28) && bg_tv_retryable(7) && !bg_tv_retryable(60), '仅网络类 errno 可重试');

echo "结果：{$bg_tv_test_pass}/{$bg_tv_test_total} 通过\n";
if ($bg_tv_test_fail) {
    exit(1);
}
