<?php
/**
 * 手写字词库管理插件 · 自检脚本
 *
 * 由 api.php?action=plugin.check 加载，返回：
 *   array('ok' => bool, 'message' => string,
 *         'details' => [['label'=>string, 'ok'=>bool, 'detail'=>string]])
 */

/* 仅允许由主程序加载，禁止直接访问 */
if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/lib/lexicon.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/review.php';
bglx_rev_bootstrap();

$details = array();
$allOk = true;

/** 追加一条自检明细 */
$bglxAddDetail = function ($label, $ok, $detail) use (&$details, &$allOk) {
    $details[] = array(
        'label' => (string)$label,
        'ok' => (bool)$ok,
        'detail' => (string)$detail,
    );
    if (!$ok) {
        $allOk = false;
    }
};

/* 1. PHP 版本 */
$phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
$bglxAddDetail('PHP 版本', $phpOk, '当前 ' . PHP_VERSION . ($phpOk ? '（满足 7.4+）' : '（需升级到 7.4 或更高）'));

/* 2. json 扩展 */
$jsonOk = function_exists('json_encode') && function_exists('json_decode');
$bglxAddDetail('JSON 扩展', $jsonOk, $jsonOk ? '已启用' : '未启用，无法读写词库');

/* 3. 数据目录 */
$dataDir = bglx_data_dir();
$dirOk = is_dir($dataDir) || (@mkdir($dataDir, 0775, true) || is_dir($dataDir));
$writable = $dirOk && is_writable($dataDir);
$bglxAddDetail('数据目录', $writable, $dataDir . ($writable ? '（可写）' : '（不可写，请检查权限）'));

/* 4. 词库文件与 JSON 合法性 */
$store = bglx_store();
$storeFile = $store->path();
$jsonValid = true;
$jsonDetail = '';
try {
    $doc = $store->export();
    $entries = isset($doc['entries']) && is_array($doc['entries']) ? $doc['entries'] : array();
    $jsonDetail = is_file($storeFile)
        ? '文件存在，共 ' . count($entries) . ' 条词条'
        : '文件尚未创建，首次写入时自动生成（当前按空词库运行）';
} catch (Throwable $e) {
    $jsonValid = false;
    $jsonDetail = $e->getMessage();
}
$bglxAddDetail('词库 JSON', $jsonValid, $storeFile . ' · ' . $jsonDetail);

/* 5. 四类词条计数 */
$counts = array('drawing_alias' => 0, 'process_synonym' => 0, 'unit_synonym' => 0, 'char_confusion' => 0);
if ($jsonValid) {
    foreach ($entries as $e) {
        if (is_array($e) && isset($e['type']) && isset($counts[$e['type']])) {
            $counts[$e['type']]++;
        }
    }
}
$bglxAddDetail(
    '四类词条统计',
    true,
    '图号 ' . $counts['drawing_alias'] . ' · 工序 ' . $counts['process_synonym']
        . ' · 单位 ' . $counts['unit_synonym'] . ' · 形近字符 ' . $counts['char_confusion']
);

/* 6. 阈值 */
$thresholds = bglx_thresholds();
$thrOk = $thresholds['auto'] >= $thresholds['suggest'];
$bglxAddDetail(
    '纠错阈值',
    $thrOk,
    '自动纠正 ≥ ' . number_format($thresholds['auto'], 2)
        . ' · 人工确认 ≥ ' . number_format($thresholds['suggest'], 2)
);

/* 7. 鉴权模式 */
$auth = new BglxAuth();
$bglxAddDetail(
    '鉴权模式',
    true,
    $auth->mode() === 'token'
        ? '管理令牌已配置（X-Bglx-Token / 独立 Session BGLXSESSID）'
        : '未配置令牌，仅允许回环地址 127.0.0.1 / ::1 访问'
);

/* 8. 示例查询结构 */
$sampleOk = false;
$sampleDetail = '查询未执行';
try {
    $result = bglx_correct_partno('2L59711305');
    $required = array('original', 'corrected', 'type', 'confidence', 'action', 'matched_id', 'strategy', 'candidates');
    $missing = array();
    foreach ($required as $k) {
        if (!array_key_exists($k, $result)) {
            $missing[] = $k;
        }
    }
    $sampleOk = !$missing && in_array($result['action'], array('auto', 'suggest', 'none'), true);
    $sampleDetail = $sampleOk
        ? '示例查询返回结构合法（action=' . $result['action'] . '，corrected=' . $result['corrected'] . '）'
        : '缺少字段：' . implode(', ', $missing);
} catch (Throwable $e) {
    $sampleDetail = '示例查询异常：' . $e->getMessage();
}
$bglxAddDetail('示例查询结构', $sampleOk, $sampleDetail);

/* 9. 文件锁能力 */
$lockOk = function_exists('flock');
$bglxAddDetail('并发文件锁', $lockOk, $lockOk ? 'flock 可用（写操作使用 LOCK_EX + rename 原子替换）' : 'flock 不可用');

/* 10. 审查环境（合并为恰好 1 条，总数维持 10 条上限） */
$revEnabled = bglx_rev_setting_bool('reviewEnabled', false);
$revOk = true;
$revParts = array();
if ($revEnabled) {
    $revDirs = bglx_rev_attachment_dirs();
    $revParts[] = '附件目录' . (count($revDirs['dirs']) > 0 ? count($revDirs['dirs']) . ' 个可读' : '不可读');
    $revLogBase = bglx_rev_resolve_base(bglx_rev_setting('logDir', 'data/ocr-logs/'));
    $revLogReal = $revLogBase !== '' ? realpath($revLogBase) : false;
    $revLogOk = $revLogReal !== false && is_dir($revLogReal) && is_readable($revLogReal);
    $revParts[] = '日志目录' . ($revLogOk ? '可读' : '不可读');
    $revParts[] = 'GD ' . (function_exists('imagecreatetruecolor') ? '可用' : '缺失（降级原图）');
    $revParts[] = 'AI ' . (bglx_rev_setting_bool('aiEnabled', false) ? '已启用' : '未启用（人工路径可用）');
    $revAiStatus = bglx_rev_setting('aiImportStatus', 'pending');
    if ($revAiStatus !== 'pending') {
        $revOk = false;
        $revParts[] = 'aiImportStatus 须为 pending';
    } else {
        $revParts[] = 'aiImportStatus=pending';
    }
} else {
    $revParts[] = '审查未启用';
}
$bglxAddDetail('审查环境', $revOk, bglx_cut(implode('；', $revParts), 160));

/* 11. v3 逐条判定与归档（三段：服务函数 / 归档目录 / 配置与 F5 默认值） */
$v3Ok = true;
$v3Parts = array();

// ① 判定服务与派生函数存在性（I/U/D 三键 + 归档入口）
$v3Functions = array(
    'bglx_rev_judge_row', 'bglx_rev_judge_batch', 'bglx_rev_derive_projection',
    'bglx_rev_derive', 'bglx_rev_infer_rows', 'bglx_rev_after_write',
    'bglx_rev_safe_archive_dir',
);
$v3Methods = array('judgeRow', 'judgeBatch', 'pruneExpired', 'capacityTrim');
$v3Missing = array();
foreach ($v3Functions as $v3Function) {
    if (!function_exists($v3Function)) {
        $v3Missing[] = $v3Function;
    }
}
$v3Store = bglx_rev_store();
foreach ($v3Methods as $v3Method) {
    if (!method_exists($v3Store, $v3Method)) {
        $v3Missing[] = 'BglxReviewStore::' . $v3Method;
    }
}
if ($v3Missing) {
    $v3Ok = false;
    $v3Parts[] = '缺失：' . implode(',', $v3Missing);
} else {
    $v3Parts[] = '判定/派生/归档入口齐备';
}

// ② 归档目录可写（含穿越校验）
$v3Cfg = bglx_rev_archive_config();
$v3Dir = bglx_rev_safe_archive_dir($v3Cfg['dir']);
if ($v3Dir === null) {
    $v3Ok = false;
    $v3Parts[] = '归档目录非法';
} else {
    $v3DirOk = is_dir($v3Dir) || (@mkdir($v3Dir, 0775, true) || is_dir($v3Dir));
    $v3DirOk = $v3DirOk && is_writable($v3Dir);
    if (!$v3DirOk) {
        $v3Ok = false;
        $v3Parts[] = '归档目录不可写：' . bglx_cut($v3Dir, 80);
    } else {
        $v3Parts[] = '归档目录可写';
    }
}

// ③ 4 项配置值合法 + F5 目录默认值不漂移（Q5：retentionDays=0 不判 fail）
if ($v3Cfg['retention_days'] < 0) {
    $v3Ok = false;
    $v3Parts[] = 'reviewRetentionDays 非法';
} elseif ($v3Cfg['retention_days'] === 0 || !$v3Cfg['enabled']) {
    $v3Parts[] = '到期归档已禁用（仅条数裁剪）';
} else {
    $v3Parts[] = '到期归档 ' . $v3Cfg['retention_days'] . ' 天';
}
$v3Parts[] = '整图快捷' . ($v3Cfg['whole_image_judge'] ? '已开启' : '已关闭（逐条判定）');
$v3Settings = array();
$v3PluginFile = __DIR__ . '/plugin.json';
if (is_file($v3PluginFile)) {
    $v3Plugin = json_decode((string)@file_get_contents($v3PluginFile), true);
    if (is_array($v3Plugin) && isset($v3Plugin['settings']) && is_array($v3Plugin['settings'])) {
        foreach ($v3Plugin['settings'] as $v3Setting) {
            if (is_array($v3Setting) && isset($v3Setting['key'])) {
                $v3Settings[$v3Setting['key']] = $v3Setting;
            }
        }
    }
}
$v3F5Ok = isset($v3Settings['attachmentDirs'], $v3Settings['logDir'])
    && isset($v3Settings['attachmentDirs']['default'], $v3Settings['logDir']['default'])
    && (string)$v3Settings['attachmentDirs']['default'] === 'images/attachments/'
    && (string)$v3Settings['logDir']['default'] === 'ocr-logs/';
if (!$v3F5Ok) {
    $v3Ok = false;
    $v3Parts[] = 'F5 目录默认值漂移';
} else {
    $v3Parts[] = 'F5 默认值未漂移';
}
$bglxAddDetail('v3 逐条判定与归档', $v3Ok, bglx_cut(implode('；', $v3Parts), 160));

return array(
    'ok' => $allOk,
    'message' => $allOk ? '手写字词库插件自检通过' : '手写字词库插件自检未通过，请查看明细',
    'details' => $details,
);
