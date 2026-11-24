<?php
/**
 * 人工判断审查子系统 · 纯 PHP CLI 断言测试（零依赖）
 *
 * 运行：php tests/review.test.php
 * 覆盖：穿越防护 / 坏行跳过 / 四级关联 / 四类映射与 schema / AI 围栏解析 /
 *       pending 强制 / 冲突检测 / 信封结构 / 审查存储乐观锁 / bridge 即时生效。
 *
 * 说明：使用系统临时目录隔离数据，不触碰真实词库与审查记录。
 */

$TMP = rtrim(sys_get_temp_dir(), '/') . '/bglx-review-' . getmypid();
$DATA = $TMP . '/data';
@mkdir($DATA . '/data/uploads/2026/06/01', 0775, true);
@mkdir($DATA . '/data/ocr-logs', 0775, true);
@mkdir($DATA . '/review-thumbs', 0775, true);
@mkdir($TMP . '/outside', 0775, true);

$GLOBALS['DATA_DIR'] = $DATA;
$GLOBALS['BG_PLUGIN_CTX'] = array('config' => array(
    'reviewEnabled' => '1',
    'attachmentDirs' => "data/uploads/\ndata/attachments/",
    'attachmentExtensions' => 'jpg,jpeg,png,webp',
    'attachmentMaxBytes' => '8388608',
    'thumbnailSize' => '320',
    'logDir' => 'data/ocr-logs/',
    'logFilePattern' => 'YYYY-MM-DD.jsonl',
    'fusionTracePattern' => 'fusion-trace-YYYY-MM-DD.jsonl',
    'fusionTraceEnabled' => '1',
    'linkStrategy' => 'auto',
    'linkTimeWindowSec' => '300',
    'aiEnabled' => '1',
    'aiKey' => 'SUPERSECRET-KEY-MUST-NOT-LEAK',
    'aiEndpoint' => 'http://127.0.0.1:1/v1/chat/completions',
    'aiModel' => 'hunyuan',
    'aiTimeoutSec' => '1',
    'aiMaxCandidates' => '50',
    'maskSensitive' => '1',
    'manualImportStatus' => 'active',
    'aiImportStatus' => 'pending',
    'reviewPageSize' => '50',
));

require_once dirname(__DIR__) . '/lib/review.php';
bglx_rev_bootstrap();
require_once dirname(__DIR__) . '/lib/bridge.php';

$pass = 0;
$fail = 0;

/**
 * 断言辅助。
 *
 * @param bool   $condition 条件
 * @param string $label     用例名
 * @param string $detail    失败说明
 * @return void
 */
function bglx_rev_assert($condition, $label, $detail = '')
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "[PASS] " . $label . "\n";
    } else {
        $fail++;
        echo "[FAIL] " . $label . ($detail !== '' ? " · " . $detail : '') . "\n";
    }
}

/** 1×1 合法 PNG，供 getimagesize 解析 */
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
$upDir = $DATA . '/data/uploads/2026/06/01/';
@file_put_contents($upDir . '20260601103012-ocrfusion-a1b2.png', $png);
@file_put_contents($upDir . '20260601110500-ocrfusion-c3d4.png', $png);
@file_put_contents($upDir . 'AS-1234_B.png', $png);
@file_put_contents($upDir . 'notes.txt', 'not an image');
@file_put_contents($upDir . 'bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
@file_put_contents($TMP . '/outside/outside.png', $png);
@file_put_contents($DATA . '/data/ocr-logs/2026-06-01.jsonl',
    file_get_contents(dirname(__DIR__) . '/tests/fixtures/sample-ocr-log.jsonl'));
@file_put_contents($DATA . '/data/ocr-logs/fusion-trace-2026-06-01.jsonl',
    file_get_contents(dirname(__DIR__) . '/tests/fixtures/sample-fusion-trace.jsonl'));
@symlink($TMP . '/outside/outside.png', $DATA . '/data/uploads/escape.png');
$logTs = strtotime('2026-06-01 10:30:12');
@touch($upDir . '20260601103012-ocrfusion-a1b2.png', $logTs);

echo "=== 人工判断审查子系统测试 ===\n\n";

/* ---------- A. 配置与默认值 ---------- */
echo "--- A. 配置 ---\n";
$defaults = bglx_rev_defaults();
// v3 基线：原 24 项 + reviewRetentionDays/reviewArchiveEnabled/reviewArchiveDir/wholeImageJudgeEnabled
bglx_rev_assert(count($defaults) === 28, 'A1 默认值表恰好 28 项（原 24 + v3 新增 4）', '实际 ' . count($defaults));
bglx_rev_assert($defaults['reviewRetentionDays'] === '90' && $defaults['reviewArchiveEnabled'] === '1'
    && $defaults['reviewArchiveDir'] === 'review-archive/' && $defaults['wholeImageJudgeEnabled'] === '0',
    'A1b v3 四项默认配置值正确');
bglx_rev_assert(bglx_rev_setting('reviewEnabled') === '1', 'A2 BG_PLUGIN_CTX 配置优先');
$dirs = bglx_rev_setting_list('attachmentDirs');
bglx_rev_assert(isset($dirs[0]) && $dirs[0] === 'data/uploads/', 'A3 目录列表按顺序解析且保序', json_encode($dirs, JSON_UNESCAPED_UNICODE));
bglx_rev_assert(bglx_rev_setting_bool('maskSensitive', false) === true, 'A4 bool 配置解析');
bglx_rev_assert(bglx_rev_setting_int('thumbnailSize') === 320, 'A5 int 配置解析');
bglx_rev_assert(bglx_rev_setting_enum('linkStrategy', array('auto', 'manual'), 'auto') === 'auto', 'A6 enum 配置解析');

/* ---------- B. 目录穿越防护 ---------- */
echo "\n--- B. 穿越防护 ---\n";
$base = realpath($DATA . '/data/uploads');
bglx_rev_assert($base !== false, 'B0 附件基目录存在');
bglx_rev_assert(bglx_rev_safe_path($base, '../etc/passwd') === null, 'B1 ../ 相对穿越被拒');
bglx_rev_assert(bglx_rev_safe_path($base, 'escape.png') === null, 'B2 符号链接越界被拒');
$okPath = bglx_rev_safe_path($base, '2026/06/01/AS-1234_B.png');
bglx_rev_assert($okPath !== null && is_file($okPath), 'B3 合法相对路径解析成功');

/* ---------- C. 日志只读解析 ---------- */
echo "\n--- C. 日志读取 ---\n";
$logs = bglx_rev_read_logs(array('date_from' => '2026-06-01', 'date_to' => '2026-06-01', 'limit' => 10));
bglx_rev_assert($logs['bad_lines'] === 1, 'C1 坏行跳过且计数为 1', '实际 ' . $logs['bad_lines']);
bglx_rev_assert(count($logs['items']) === 2, 'C2 正常行全部返回', '实际 ' . count($logs['items']));
bglx_rev_assert($logs['items'][0]['judgment'] === 'unreviewed', 'C3 未落库日志标注未审');
bglx_rev_assert($logs['items'][0]['fields']['worker'] === '张*', 'C4 工人姓名脱敏为姓+*',
    json_encode($logs['items'][0]['fields'], JSON_UNESCAPED_UNICODE));
$longRow = array('raw' => str_repeat('字', 3000), 'fields' => array(), 'items' => array());
$projected = bglx_rev_project_log($longRow, array());
bglx_rev_assert(bglx_str_len($projected['raw_excerpt']) === 2000, 'C5 raw 截断到 2000 字符');
$traces = bglx_rev_read_traces(array('2026-06-01'), array());
bglx_rev_assert(isset($traces['20260601103012-ocrfusion-a1b2']['fields'][0]['stages']), 'C6 trace 按 invokeId 建立索引');
$eng = bglx_rev_read_logs(array('date_from' => '2026-06-01', 'date_to' => '2026-06-01', 'engine' => 'tencent-ocr'));
bglx_rev_assert(count($eng['items']) === 1 && $eng['items'][0]['engine'] === 'tencent-ocr', 'C7 引擎过滤生效');
$ranges = bglx_rev_date_range('2026-06-01', '2026-08-01', 31);
bglx_rev_assert(count($ranges) === 31, 'C8 跨日期查询被 31 天上限裁剪', '实际 ' . count($ranges));

/* ---------- D. 附件扫描与缩略图降级 ---------- */
echo "\n--- D. 附件扫描 ---\n";
$scan = bglx_rev_scan_images(array());
$rels = array_map(function ($m) { return $m['rel_path']; }, $scan['items']);
bglx_rev_assert(count($scan['items']) === 3, 'D1 三张合法图片被发现（txt/svg 被跳过）', json_encode($rels, JSON_UNESCAPED_UNICODE));
$meta = bglx_rev_image_meta($upDir . 'AS-1234_B.png');
bglx_rev_assert(isset($meta['mime']) && $meta['mime'] === 'image/png' && $meta['width'] === 1, 'D2 PNG 元数据解析成功');
bglx_rev_assert(isset(bglx_rev_image_meta($upDir . 'notes.txt')['skipped_reason']), 'D3 非图片被跳过并记录原因');
bglx_rev_assert(isset(bglx_rev_image_meta($upDir . 'bad.svg')['skipped_reason']), 'D4 SVG 被明确拒绝');
$thumb = bglx_rev_thumbnail($upDir . 'AS-1234_B.png', 320);
bglx_rev_assert(in_array($thumb['fallback'], array('gd', 'original'), true) && strpos($thumb['url'], 'review_image') !== false,
    'D5 缩略图 URL 生成且 GD 缺失时标记 original', $thumb['fallback']);

/* ---------- E. 四级关联 ---------- */
echo "\n--- E. 四级关联 ---\n";
$images = $scan['items'];
$logInvoke = array('invoke_id' => '20260601103012-ocrfusion-a1b2', 'fields' => array(), 'items' => array());
$link = bglx_rev_link_one($logInvoke, $images, 'auto', array());
bglx_rev_assert($link['strategy'] === 'invokeId' && (float)$link['confidence'] === 1.0, 'E1 L1 invokeId 精确命中 1.00', json_encode($link, JSON_UNESCAPED_UNICODE));

$logName = array('invoke_id' => 'NOMATCH', 'fields' => array('partNo' => 'AS-1234'), 'items' => array());
$link = bglx_rev_link_one($logName, $images, 'filename', array());
bglx_rev_assert($link['strategy'] === 'filename' && (float)$link['confidence'] === 0.85, 'E2 L2 文件名 token 命中 0.85', json_encode($link, JSON_UNESCAPED_UNICODE));

$logTime = array('invoke_id' => 'MTIME0001', 'ts' => '2026-06-01 10:30:12', 'fields' => array(), 'items' => array());
$timeImages = array(array('rel_path' => 'x.png', 'mtime' => $logTs + 20));
$link = bglx_rev_link_one($logTime, $timeImages, 'mtime', array());
bglx_rev_assert($link['strategy'] === 'mtime' && (float)$link['confidence'] === 0.60, 'E3 L3 时间窗口命中 0.60', json_encode($link, JSON_UNESCAPED_UNICODE));

$link = bglx_rev_link_one($logInvoke, $images, 'auto', array('manual_image_path' => 'manual/chosen.png'));
bglx_rev_assert($link['strategy'] === 'manual' && (float)$link['confidence'] === 1.0, 'E4 L4 人工绑定覆盖自动结果');

$ambiguousImages = array(
    array('rel_path' => 'x_20260601103012-ocrfusion-a1b2.png', 'mtime' => 0),
    array('rel_path' => 'y_20260601103012-ocrfusion-a1b2.png', 'mtime' => 0),
);
$link = bglx_rev_link_one($logInvoke, $ambiguousImages, 'invokeId', array());
bglx_rev_assert(!empty($link['ambiguous']) && count($link['alternatives']) >= 1, 'E5 同置信度多命中标记歧义', json_encode($link, JSON_UNESCAPED_UNICODE));
bglx_rev_assert(bglx_rev_bind_manual('RV-000001', '../etc/passwd')['ok'] === false, 'E6 人工改绑拒绝穿越路径');

/* ---------- F. AI 围栏解析与降级 ---------- */
echo "\n--- F. AI 整理 ---\n";
$fenced = "以下为整理结果：\n```json\n[{\"type\":\"drawing_alias\",\"standard\":\"AS-1234\",\"variants\":[\"A-123\"],\"confidence\":0.99,\"note\":\"形近\"}]\n```\n以上。";
$cands = bglx_rev_ai_parse($fenced);
bglx_rev_assert(count($cands) === 1, 'F1 markdown 围栏与前后废话容错解析', json_encode($cands, JSON_UNESCAPED_UNICODE));
bglx_rev_assert($cands[0]['status'] === 'pending' && $cands[0]['accepted'] === false, 'F2 AI 候选强制 pending + accepted=false');
bglx_rev_assert((float)$cands[0]['confidence'] <= 0.8, 'F3 置信度被 clamp 到 0.8', (string)$cands[0]['confidence']);
bglx_rev_assert(bglx_rev_ai_parse('不是 JSON') === array(), 'F4 无有效 JSON 返回空数组');
$aiFail = bglx_rev_ai_organize(array(array('field' => 'partNo', 'wrong_value' => 'A-123', 'correct_value' => 'AS-1234')));
bglx_rev_assert($aiFail['ok'] === false && $aiFail['fallback'] === true, 'F5 接口不可达时降级人工', json_encode($aiFail, JSON_UNESCAPED_UNICODE));
$aiCheck = bglx_rev_ai_available(array());
bglx_rev_assert(strpos(json_encode($aiCheck, JSON_UNESCAPED_UNICODE), 'SUPERSECRET-KEY-MUST-NOT-LEAK') === false, 'F6 自检信息不回显 AI Key');

/* ---------- G. 四类映射 / 冲突 / 状态红线 ---------- */
echo "\n--- G. 映射与受控入库 ---\n";
$lexFile = $DATA . '/handwriting_lexicon.json';
@unlink($lexFile);
@unlink($lexFile . '.lock');
$lex = new BglxStore($lexFile);
$corrections = array(
    array('field' => 'partNo', 'wrong_value' => 'A-123', 'correct_value' => 'AS-1234'),
    array('field' => 'process', 'wrong_value' => '焊结', 'correct_value' => '焊接'),
    array('field' => 'unit', 'wrong_value' => '个', 'correct_value' => '件'),
    array('field' => 'char', 'wrong_value' => '未', 'correct_value' => '末'),
);
$mapped = bglx_rev_map_corrections($corrections, 'manual', array('store' => $lex));
$types = array_map(function ($e) { return $e['type']; }, $mapped['entries']);
bglx_rev_assert(count($mapped['entries']) === 4 && !$mapped['errors'], 'G1 四类纠错全部映射成功', json_encode($mapped, JSON_UNESCAPED_UNICODE));
bglx_rev_assert($types === array('drawing_alias', 'process_synonym', 'unit_synonym', 'char_confusion'), 'G2 字段到 type 映射正确', json_encode($types));
$allValid = true;
foreach ($mapped['entries'] as $e) {
    $e['id'] = 'LX-000001';
    if (bglx_validate_entry($e)) { $allValid = false; }
}
bglx_rev_assert($allValid, 'G3 四类词条均通过 bglx_validate_entry');
$badChar = bglx_rev_map_corrections(array(array('field' => 'char', 'wrong_value' => '未来', 'correct_value' => '末')), 'manual', array('store' => $lex));
bglx_rev_assert(count($badChar['entries']) === 0 && count($badChar['errors']) === 1, 'G4 形近字不等长被拒绝');
bglx_rev_assert(bglx_rev_resolve_status('ai', false, array('manual_import_status' => 'active')) === 'pending', 'G5 aiImportStatus 即使误配 active 仍强制 pending');
bglx_rev_assert(bglx_rev_resolve_status('manual', true, array()) === 'pending', 'G6 冲突强制 pending');
bglx_rev_assert(bglx_rev_resolve_status('manual', false, array()) === 'active', 'G7 人工无冲突默认 active');

$conflictLex = new BglxStore($DATA . '/conflict-lexicon.json');
@unlink($DATA . '/conflict-lexicon.json');
@unlink($DATA . '/conflict-lexicon.json.lock');
$conflictLex->create(array('type' => 'drawing_alias', 'standard' => 'A-123', 'variants' => array('OLD'), 'confidence' => 0.95));
$conflicts = bglx_rev_find_conflicts($corrections, $conflictLex);
bglx_rev_assert(isset($conflicts[0]), 'G8 standard 命中候选 variant 判为冲突', json_encode($conflicts, JSON_UNESCAPED_UNICODE));
$conflictMap = bglx_rev_map_corrections(array($corrections[0]), 'manual', array('store' => $conflictLex));
bglx_rev_assert(count($conflictMap['entries']) === 1 && $conflictMap['entries'][0]['status'] === 'pending', 'G9 人工路径冲突项转 pending');

$aiLex = new BglxStore($DATA . '/ai-lexicon.json');
@unlink($DATA . '/ai-lexicon.json');
@unlink($DATA . '/ai-lexicon.json.lock');
$aiCorr = array(
    array('type' => 'drawing_alias', 'standard' => 'AI-STD', 'variants' => array('AI-WRONG'), 'accepted' => false, 'confidence' => 0.99, 'source' => 'ai'),
);
$skipped = bglx_rev_import_decisions($aiCorr, 'RV-000001', array('origin' => 'ai', 'store' => $aiLex));
bglx_rev_assert($skipped['added'] === 0 && $skipped['skipped'] === 1, 'G10 未勾选采纳的 AI 候选被跳过');
$aiCorr[0]['accepted'] = true;
$imported = bglx_rev_import_decisions($aiCorr, 'RV-000001', array('origin' => 'ai', 'store' => $aiLex));
bglx_rev_assert($imported['added'] === 1 && $imported['pending'] === 1, 'G11 已采纳 AI 候选入库恒 pending', json_encode($imported, JSON_UNESCAPED_UNICODE));
$aiEntry = $aiLex->all(array('status' => 'pending'));
bglx_rev_assert(count($aiEntry) === 1 && (float)$aiEntry[0]['confidence'] <= 0.8 && $aiEntry[0]['source'] === 'ocr_feedback',
    'G12 AI 词条置信度 ≤0.8 且来源 ocr_feedback');

$manualLex = new BglxStore($DATA . '/manual-lexicon.json');
@unlink($DATA . '/manual-lexicon.json');
@unlink($DATA . '/manual-lexicon.json.lock');
$manualResult = bglx_rev_import_decisions(array($corrections[0]), 'RV-000002', array('origin' => 'manual', 'store' => $manualLex));
bglx_rev_assert($manualResult['added'] === 1 && $manualResult['pending'] === 0, 'G13 人工无冲突直接 active');
$reviewed = $manualLex->all(array('status' => 'active'));
bglx_rev_assert(count($reviewed) === 1 && $reviewed[0]['source'] === 'ocr_feedback', 'G14 人工回流 source=ocr_feedback');

/* ---------- H. 审查存储与乐观锁 ---------- */
echo "\n--- H. 审查记录存储 ---\n";
$rv = new BglxReviewStore($DATA . '/handwriting_review.json');
@unlink($rv->path());
@unlink($rv->path() . '.lock');
$item = $rv->upsert(array('invoke_id' => '20260601103012-ocrfusion-a1b2', 'date' => '2026-06-01', 'judgment' => 'unreviewed'));
bglx_rev_assert($item['id'] === 'RV-000001' && $item['version'] === 1, 'H1 upsert 分配 RV ID 与初始版本');
bglx_rev_assert($rv->findByInvokeId('20260601103012-ocrfusion-a1b2')['id'] === 'RV-000001', 'H2 invokeIndex/findByInvokeId 可用');
$patched = $rv->patch('RV-000001', array('judgment' => 'wrong', 'version' => 1));
bglx_rev_assert($patched['version'] === 2 && $patched['judgment'] === 'wrong', 'H3 patch 递增版本并落盘');
$conflictThrown = false;
try {
    $rv->patch('RV-000001', array('judgment' => 'correct', 'version' => 1));
} catch (BglxReviewLockException $e) {
    $conflictThrown = true;
}
bglx_rev_assert($conflictThrown, 'H4 过期版本触发 BglxReviewLockException（40901）');
$rv->appendAudit('review_judge', 'RV-000001', 'admin', '判定=wrong');
$stats = $rv->stats();
bglx_rev_assert($stats['wrong'] === 1 && $stats['total'] === 1, 'H5 统计卡计数正确', json_encode($stats));

/* ---------- I. 信封结构与 bridge 即时生效 ---------- */
echo "\n--- I. 信封与闭环 ---\n";
ob_start();
bglx_json(0, array('k' => 'v'), 'ok', false);
$envelopeRaw = ob_get_clean();
$env = json_decode($envelopeRaw, true);
bglx_rev_assert(is_array($env) && $env['code'] === 0 && $env['message'] === 'ok' && $env['data']['k'] === 'v',
    'I1 响应恒为 {code,message,data} 信封');

// 用默认词库文件验证「人工导入后 bridge 下一请求即命中」
@unlink($lexFile);
@unlink($lexFile . '.lock');
$bridgeResult = bglx_rev_import_decisions(array(array(
    'field' => 'partNo', 'wrong_value' => 'ZZCONV001', 'correct_value' => 'ZZSTANDARD',
)), 'RV-000003', array('origin' => 'manual'));
bglx_reload();
bglx_bridge_init('review-test');
$norm = bglx_bridge_norm('partno', 'ZZCONV001');
bglx_rev_assert($bridgeResult['added'] === 1 && $norm['action'] === 'auto' && $norm['value'] === 'ZZSTANDARD',
    'I2 导入后 bridge 下一次调用命中新词条（R10）', json_encode(array($bridgeResult, $norm), JSON_UNESCAPED_UNICODE));

/* ---------- J. 作者 7 项修复保护回归（F1–F7，零回退） ---------- */
echo "\n--- J. 作者 7 项修复保护清单 ---\n";
$srcRoot = dirname(__DIR__);
$readSource = function ($rel) use ($srcRoot) {
    $path = $srcRoot . '/' . $rel;
    return is_file($path) ? (string)@file_get_contents($path) : '';
};
$adminJsSrc = $readSource('assets/admin.js');
$reviewJsSrc = $readSource('assets/review.js');
$apiPhpSrc = $readSource('api.php');
$adminPhpSrc = $readSource('admin.php');
$pluginSrc = $readSource('plugin.json');

// F1 Tab 可切换：admin.js 的 initTabs / bglx:tab 派发 + review.js 的监听
bglx_rev_assert(strpos($adminJsSrc, 'function initTabs') !== false
    && strpos($adminJsSrc, 'bglx:tab') !== false, 'J-F1a admin.js initTabs 与事件派发未回退');
bglx_rev_assert(strpos($reviewJsSrc, "document.addEventListener('bglx:tab'") !== false,
    'J-F1b review.js bglx:tab 监听未回退');

// F2 字段映射：消费归一化结果，no/proc/qty 映射链保持可用
$fusionRow = array(
    'items' => array(array('no' => 'TD90535R184', 'proc' => '铣光2', 'qty' => '204')),
    'fields' => array('date' => '2026-11-24'),
);
$projected = bglx_rev_project_log($fusionRow, array('mask_sensitive' => false));
bglx_rev_assert(isset($projected['items'][0])
    && (string)$projected['items'][0]['partNo'] === 'TD90535R184'
    && (string)$projected['items'][0]['process'] === '铣光2'
    && (string)$projected['items'][0]['qty'] === '204', 'J-F2 no/proc/qty 映射链未回退');

// F3 过滤 match 空记录
bglx_rev_assert(bglx_rev_log_matches(array('kind' => 'match', 'engine' => 'x', 'items' => array()), array()) === false,
    'J-F3 kind=match 空记录仍被过滤');

// F4 稳定键 + 徽标 + invokeLabel
bglx_rev_assert(strpos($apiPhpSrc, "'log-' . substr(sha1(") !== false,
    'J-F4a api.php log-<sha1> 稳定键生成段未回退');
bglx_rev_assert(strpos($reviewJsSrc, "indexOf('log-') === 0 ? '日志记录'") !== false,
    'J-F4b invokeLabel() 未回退');
bglx_rev_assert(strpos($reviewJsSrc, 'JUDGE_LABELS[item.judgment]') !== false,
    'J-F4c 列表判定徽标渲染未回退');

// F5 目录默认值（plugin.json 逐字比对）
$pluginDoc = json_decode($pluginSrc, true);
$pluginSettings = array();
if (is_array($pluginDoc) && isset($pluginDoc['settings']) && is_array($pluginDoc['settings'])) {
    foreach ($pluginDoc['settings'] as $ps) {
        if (is_array($ps) && isset($ps['key'])) {
            $pluginSettings[$ps['key']] = $ps;
        }
    }
}
bglx_rev_assert(isset($pluginSettings['attachmentDirs'], $pluginSettings['logDir'])
    && (string)$pluginSettings['attachmentDirs']['default'] === 'images/attachments/'
    && (string)$pluginSettings['logDir']['default'] === 'ocr-logs/',
    'J-F5 attachmentDirs/logDir 默认值未漂移');
bglx_rev_assert(isset($pluginSettings['reviewRetentionDays'], $pluginSettings['reviewArchiveEnabled'],
    $pluginSettings['reviewArchiveDir'], $pluginSettings['wholeImageJudgeEnabled']),
    'J-F5b v3 四项新配置已追加到 plugin.json');

// F6 脚本缓存版本参数：只递增版本号，不改标签结构
bglx_rev_assert(strpos($adminPhpSrc, 'admin.js?v=1.2.0') !== false
    && strpos($adminPhpSrc, 'review.js?v=1.2.0') !== false,
    'J-F6 admin.php ?v= 已递增到 1.2.0');

// F7 主程序入口兼容：插件侧入口与接口签名保持不变
bglx_rev_assert(isset($pluginDoc['entry']) && (string)$pluginDoc['entry'] === 'handler.php'
    && is_file($srcRoot . '/handler.php'), 'J-F7a 插件入口 handler.php 未变');
bglx_rev_assert(function_exists('bglx_rev_bootstrap') && function_exists('bglx_rev_require_enabled')
    && function_exists('bglx_rev_verify_write') && function_exists('bglx_rev_setting'),
    'J-F7b 插件侧对外函数签名保持兼容');

/* ---------- K. v3 写入闸门无旁路 ---------- */
echo "\n--- K. v3 token / POST / CSRF / 白名单 ---\n";
bglx_rev_assert(strpos($apiPhpSrc, "'review_judge_row'") !== false
    && strpos($apiPhpSrc, "'review_infer_rows'") !== false
    && strpos($apiPhpSrc, "'review_judge_batch'") !== false,
    'K1 三个 v3 新动作已登记到 API 动作清单');
bglx_rev_assert(strpos($apiPhpSrc, '$auth->requireAuth()') !== false
    && strpos($apiPhpSrc, 'bglx_rev_verify_write()') !== false,
    'K2 写动作沿用 token 鉴权 + POST + CSRF 校验');
bglx_rev_assert(strpos((string)@file_get_contents(dirname(__DIR__) . '/lib/review.php'),
    "if (!bglx_is_post())") !== false, 'K3 bglx_rev_verify_write() 强制 POST 未被改写');
bglx_rev_assert(strpos($apiPhpSrc, 'BGLX_REV_VERSION_CONFLICT') !== false
    && strpos($apiPhpSrc, 'BglxReviewLockException') !== false,
    'K4 乐观锁冲突仍映射 40901');
bglx_rev_assert(strpos($apiPhpSrc, 'bglx_rev_api_wrong_rows') !== false
    && strpos($apiPhpSrc, 'bglx_rev_after_write()') !== false,
    'K5 AI 过滤闸门与写后归档收尾已接线');

echo "\n=== 结果：通过 " . $pass . " 项，失败 " . $fail . " 项 ===\n";
exit($fail > 0 ? 1 : 0);
