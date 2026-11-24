<?php
/**
 * 手写字词库管理插件 · JSON 接口
 *
 * 入口：GET|POST public/plugins/handwriting-lexicon/api.php
 * 统一响应信封：{code, message, data}
 * 例外：action=export 输出 JSON 附件流；action=review_image 输出受控图片流。
 *
 * 本文件由 Web 直接访问，不使用 BG_PLUGIN_CTX 守卫，改用独立令牌鉴权。
 * 审查动作与既有动作共用同一个 BglxAuth 实例，无任何鉴权旁路。
 */

require_once __DIR__ . '/lib/lexicon.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/review.php';
bglx_rev_bootstrap();

$auth = new BglxAuth();
$auth->requireAuth();

$store = bglx_store();
$action = trim((string)bglx_param('action', ''));

/** 审查动作清单：只读 5 个 + 写 6 个 */
$reviewReadActions = array(
    'review_settings', 'review_list_logs', 'review_list_images',
    'review_image', 'review_stats',
);
$reviewWriteActions = array(
    'review_link', 'review_judge', 'review_save_corrections',
    'review_ai_organize', 'review_import_decisions', 'review_delete_draft',
);
$reviewActions = array_merge($reviewReadActions, $reviewWriteActions);

/** 写操作只允许 POST */
$writeActions = array('create', 'update', 'delete', 'set_enabled', 'review', 'import');
foreach ($reviewWriteActions as $reviewWriteAction) {
    $writeActions[] = $reviewWriteAction;
}
if (in_array($action, $writeActions, true) && !bglx_is_post()) {
    bglx_fail(40001, '写操作必须使用 POST 请求');
}

$allowed = array(
    'list', 'get', 'create', 'update', 'delete', 'set_enabled', 'review',
    'query', 'test', 'stats', 'import', 'export',
);
foreach ($reviewActions as $reviewAction) {
    $allowed[] = $reviewAction;
}
if ($action === '' || !in_array($action, $allowed, true)) {
    bglx_fail(40001, '未知或缺失的 action：' . $action);
}

/* 审查动作统一总开关 + 写动作 CSRF 二次校验（在鉴权之后，无旁路） */
if (in_array($action, $reviewActions, true)) {
    bglx_rev_require_enabled();
}
if (in_array($action, $reviewWriteActions, true)) {
    bglx_rev_verify_write();
}

/**
 * 取得本次审查动作的目标记录：优先 item_id，其次以 invoke_id 懒创建草稿。
 * 懒创建使「日志列表只读」与「判定即落库」两件事互不耦合。
 *
 * @return array 审查记录
 */
function bglx_rev_api_target()
{
    $rv = bglx_rev_store();
    $id = trim((string)bglx_param('item_id', ''));
    if ($id !== '') {
        $item = $rv->get($id);
        if ($item === null) {
            bglx_fail(40401, '审查记录不存在：' . $id);
        }
        return $item;
    }
    $invoke = trim((string)bglx_param('invoke_id', ''));
    if ($invoke === '') {
        bglx_fail(40001, '缺少 item_id 或 invoke_id 参数');
    }
    $item = $rv->findByInvokeId($invoke);
    if ($item !== null) {
        return $item;
    }
    $snapshot = bglx_param('snapshot', array());
    $snapshot = is_array($snapshot) ? $snapshot : array();
    return $rv->upsert(array(
        'invoke_id' => $invoke,
        'date' => isset($snapshot['date']) ? $snapshot['date'] : '',
        'engine' => isset($snapshot['engine']) ? $snapshot['engine'] : '',
        'duration' => isset($snapshot['duration']) ? $snapshot['duration'] : 0,
        'recognized_fields' => isset($snapshot['fields']) ? $snapshot['fields'] : array(),
        'recognized_items' => isset($snapshot['items']) ? $snapshot['items'] : array(),
    ));
}

/**
 * AI 候选签名集合：服务端据此识别「这条决策其实来自 AI」，防止客户端伪造 source。
 *
 * @param array $item 审查记录
 * @return array
 */
function bglx_rev_api_ai_signatures($item)
{
    $candidates = isset($item['ai_candidates']) && is_array($item['ai_candidates'])
        ? $item['ai_candidates'] : array();
    $out = array();
    foreach ($candidates as $c) {
        $sig = bglx_rev_decision_signature($c);
        if (!in_array($sig, $out, true)) {
            $out[] = $sig;
        }
    }
    return $out;
}

try {
    switch ($action) {
        /* ==================== 既有词库动作（零改动） ==================== */
        case 'list':
            $page = max(1, (int)bglx_param('page', 1));
            $pageSize = (int)bglx_param('page_size', 20);
            if ($pageSize < 1) {
                $pageSize = 20;
            }
            if ($pageSize > 100) {
                $pageSize = 100;
            }
            $filters = array(
                'type' => (string)bglx_param('type', ''),
                'status' => (string)bglx_param('status', ''),
                'enabled' => bglx_param('enabled', ''),
                'keyword' => trim((string)bglx_param('keyword', '')),
            );
            $all = $store->all($filters);
            $total = count($all);
            $items = array_slice($all, ($page - 1) * $pageSize, $pageSize);
            $stats = $store->stats();
            $stats['hit_today'] = 0;
            bglx_json(0, array(
                'items' => $items, 'total' => $total, 'page' => $page,
                'page_size' => $pageSize, 'stats' => $stats,
            ), 'ok');
            break;

        case 'get':
            $id = trim((string)bglx_param('id', ''));
            if ($id === '') {
                bglx_fail(40001, '缺少 id 参数');
            }
            $entry = $store->get($id);
            if ($entry === null) {
                bglx_fail(40401, '词条不存在：' . $id);
            }
            bglx_json(0, array('entry' => $entry), 'ok');
            break;

        case 'create':
            $payload = array(
                'type' => bglx_param('type', ''),
                'standard' => bglx_param('standard', ''),
                'variants' => bglx_param('variants', array()),
                'confidence' => bglx_param('confidence', BGLX_AUTO_MIN),
                'enabled' => bglx_param('enabled', 1),
                'status' => bglx_param('status', 'active'),
                'source' => bglx_param('source', 'manual'),
                'scope' => bglx_param('scope', array('*')),
                'priority' => bglx_param('priority', 10),
                'note' => bglx_param('note', ''),
            );
            $entry = $store->create($payload);
            bglx_reload();
            bglx_json(0, array('entry' => $entry), '已创建');
            break;

        case 'update':
            $id = trim((string)bglx_param('id', ''));
            if ($id === '') {
                bglx_fail(40001, '缺少 id 参数');
            }
            $patch = array();
            foreach (array('type', 'standard', 'variants', 'confidence', 'enabled', 'status',
                           'source', 'scope', 'priority', 'note') as $field) {
                $body = bglx_json_body();
                $fromPost = isset($_POST) && array_key_exists($field, $_POST);
                if (array_key_exists($field, $body) || $fromPost) {
                    $patch[$field] = bglx_param($field, '');
                }
            }
            if (!$patch) {
                bglx_fail(40001, '没有可更新的字段');
            }
            $entry = $store->update($id, $patch);
            bglx_reload();
            bglx_json(0, array('entry' => $entry), '已更新');
            break;

        case 'delete':
            $id = trim((string)bglx_param('id', ''));
            if ($id === '') {
                bglx_fail(40001, '缺少 id 参数');
            }
            if (!$store->remove($id)) {
                bglx_fail(40401, '词条不存在：' . $id);
            }
            bglx_reload();
            bglx_json(0, array('id' => $id), '已删除');
            break;

        case 'set_enabled':
            $id = trim((string)bglx_param('id', ''));
            if ($id === '') {
                bglx_fail(40001, '缺少 id 参数');
            }
            $entry = $store->setEnabled($id, bglx_param('enabled', 0));
            bglx_reload();
            bglx_json(0, array('entry' => $entry), '启用状态已更新');
            break;

        case 'review':
            $id = trim((string)bglx_param('id', ''));
            $status = trim((string)bglx_param('status', ''));
            if ($id === '' || !in_array($status, array('active', 'rejected'), true)) {
                bglx_fail(40001, '缺少 id 或审核态不合法（应为 active / rejected）');
            }
            $entry = $store->setStatus($id, $status);
            bglx_reload();
            bglx_json(0, array('entry' => $entry), '审核完成');
            break;

        case 'query':
            $type = trim((string)bglx_param('type', ''));
            $value = (string)bglx_param('value', '');
            if ($type === '' || $value === '') {
                bglx_fail(40001, '缺少 type 或 value 参数');
            }
            $opts = array('plugin' => trim((string)bglx_param('plugin', '')));
            $result = bglx_correct_text($type, $value, $opts);
            bglx_json(0, $result, 'ok');
            break;

        case 'test':
            $type = trim((string)bglx_param('type', ''));
            $value = (string)bglx_param('value', '');
            if ($type === '' || $value === '') {
                bglx_fail(40001, '缺少 type 或 value 参数');
            }
            $opts = array('plugin' => trim((string)bglx_param('plugin', '')));
            $corrector = new BglxCorrector($store, $opts);
            $result = $corrector->correct($type, $value, $opts);
            $trace = $corrector->trace($type, $value, $opts);
            bglx_json(0, array('result' => $result, 'trace' => $trace), 'ok');
            break;

        case 'stats':
            $stats = $store->stats();
            $stats['hit_today'] = 0;
            bglx_json(0, $stats, 'ok');
            break;

        case 'import':
            $format = trim((string)bglx_param('format', 'json'));
            if ($format === 'csv') {
                bglx_fail(50101, 'CSV 导入将在后续版本开放（P1），请使用 JSON 或 procAlias 格式');
            }
            $content = (string)bglx_param('content', '');
            if (trim($content) === '') {
                bglx_fail(40001, '导入内容不能为空');
            }
            $mode = trim((string)bglx_param('mode', 'merge')) === 'replace' ? 'replace' : 'merge';
            $result = $store->import($content, $format, $mode);
            bglx_reload();
            bglx_json(0, $result, '导入完成');
            break;

        case 'export':
            $type = trim((string)bglx_param('type', ''));
            $doc = $store->export($type);
            $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false) {
                bglx_fail(50001, '导出 JSON 编码失败');
            }
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="handwriting-lexicon-' . date('Ymd') . '.json"');
                header('Cache-Control: no-store');
            }
            echo $json;
            exit;

        /* ==================== 审查工作台动作 ==================== */
        case 'review_settings':
            $dirInfo = bglx_rev_attachment_dirs();
            $logBase = bglx_rev_resolve_base(bglx_rev_setting('logDir', 'data/ocr-logs/'));
            $logReal = $logBase !== '' ? realpath($logBase) : false;
            bglx_json(0, array(
                'enabled' => true,
                'capabilities' => array(
                    'gd' => function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled'),
                    'curl' => function_exists('curl_init'),
                    'dirs' => count($dirInfo['dirs']) > 0,
                    'log' => $logReal !== false && is_dir($logReal) && is_readable($logReal),
                    'ai' => bglx_rev_setting_bool('aiEnabled', false),
                ),
                'page_size' => min(BGLX_REV_MAX_PAGE, max(1, bglx_rev_setting_int('reviewPageSize', 50))),
                'aiKeySet' => bglx_rev_setting('aiKey', '') !== '', // 只回布尔，绝不回 Key
                'strategies' => array('auto', 'invokeId', 'filename', 'mtime', 'manual'),
                'statuses' => array('unreviewed', 'correct', 'wrong'),
                'warnings' => $dirInfo['warnings'],
            ), 'ok');
            break;

        case 'review_stats':
            bglx_json(0, bglx_rev_store()->stats(), 'ok');
            break;

        case 'review_list_logs':
            $page = max(1, (int)bglx_param('page', 1));
            $pageSize = min(BGLX_REV_MAX_PAGE, max(1, (int)bglx_param('page_size', bglx_rev_setting_int('reviewPageSize', 50))));
            $opts = array(
                'date_from' => (string)bglx_param('date_from', ''),
                'date_to' => (string)bglx_param('date_to', ''),
                'engine' => trim((string)bglx_param('engine', '')),
                'judgment' => trim((string)bglx_param('judgment', '')),
                'keyword' => trim((string)bglx_param('keyword', '')),
                'page' => $page,
                'limit' => $pageSize,
                'offset' => ($page - 1) * $pageSize,
                'with_trace' => bglx_to_bool(bglx_param('with_trace', 1), true),
            );
            $logs = bglx_rev_read_logs($opts);
            $images = bglx_rev_scan_images(array('limit' => BGLX_REV_MAX_SCAN_FILES));
            // 已落库记录按 invokeId 索引：既供 L4 人工绑定覆盖自动关联，
            // 也把草稿/候选/版本附加到只读日志投影上，前端可断点续审（不回写日志）
            $stored = array();
            foreach (bglx_rev_store()->all() as $rvItem) {
                $inv = isset($rvItem['invoke_id']) ? (string)$rvItem['invoke_id'] : '';
                if ($inv !== '') {
                    $stored[$inv] = $rvItem;
                }
            }
            $linked = bglx_rev_link_all($logs['items'], $images['items'], array(
                'strategy' => bglx_rev_setting_enum('linkStrategy',
                    array('auto', 'invokeId', 'filename', 'mtime', 'manual'), 'auto'),
                'existing' => $stored,
            ));
            foreach ($linked as $k => $log) {
                $inv = isset($log['invoke_id']) ? (string)$log['invoke_id'] : '';
                $saved = ($inv !== '' && isset($stored[$inv])) ? $stored[$inv] : array();
                $linked[$k]['review_id'] = isset($saved['id']) ? $saved['id'] : '';
                $linked[$k]['version'] = isset($saved['version']) ? (int)$saved['version'] : 1;
                $linked[$k]['corrections'] = isset($saved['corrections']) ? $saved['corrections'] : array();
                $linked[$k]['ai_candidates'] = isset($saved['ai_candidates']) ? $saved['ai_candidates'] : array();
                $linked[$k]['import_path'] = isset($saved['import_path']) ? $saved['import_path'] : '';
                $linked[$k]['review_status'] = isset($saved['status']) ? $saved['status'] : '';
                $linked[$k]['manual_image_path'] = isset($saved['manual_image_path']) ? $saved['manual_image_path'] : '';
            }
            bglx_json(0, array(
                'items' => $linked,
                'total' => max(0, (int)$logs['total_estimate']),
                'page' => $page,
                'page_size' => $pageSize,
                'warnings' => array_merge($logs['warnings'], $images['warnings']),
                'bad_lines' => $logs['bad_lines'],
                'truncated' => (bool)($logs['truncated'] || $images['truncated']),
            ), 'ok');
            break;

        case 'review_list_images':
            $images = bglx_rev_scan_images(array(
                'date' => (string)bglx_param('date', ''),
                'keyword' => trim((string)bglx_param('keyword', '')),
            ));
            bglx_json(0, array(
                'items' => $images['items'],
                'warnings' => $images['warnings'],
                'truncated' => (bool)$images['truncated'],
            ), 'ok');
            break;

        case 'review_image':
            bglx_rev_serve_image((string)bglx_param('path', ''),
                bglx_to_bool(bglx_param('thumb', 0), false));
            break;

        case 'review_link':
            $item = bglx_rev_api_target();
            $binding = bglx_rev_bind_manual($item['id'], (string)bglx_param('image_path', ''));
            if (empty($binding['ok'])) {
                bglx_fail(42201, $binding['reason']);
            }
            $patch = array(
                'manual_image_path' => $binding['image_path'],
                'version' => (int)bglx_param('version', isset($item['version']) ? $item['version'] : 1),
            );
            $updated = bglx_rev_store()->patch($item['id'], $patch);
            bglx_rev_store()->appendAudit('review_link', $item['id'], bglx_rev_reviewer(),
                '人工绑定图片：' . $binding['image_path']);
            bglx_json(0, array('item' => $updated, 'version' => $updated['version']), '关联已更新');
            break;

        case 'review_judge':
            $item = bglx_rev_api_target();
            $judgment = trim((string)bglx_param('judgment', ''));
            if (!in_array($judgment, array('correct', 'wrong', 'unreviewed'), true)) {
                bglx_fail(40001, '判定值不合法（应为 correct / wrong / unreviewed）');
            }
            $patch = array(
                'judgment' => $judgment,
                'judged_at' => $judgment === 'unreviewed' ? '' : bglx_now(),
                'status' => $judgment === 'unreviewed' ? 'draft' : 'reviewed',
                'version' => (int)bglx_param('version', isset($item['version']) ? $item['version'] : 1),
            );
            $updated = bglx_rev_store()->patch($item['id'], $patch);
            bglx_rev_store()->appendAudit('review_judge', $item['id'], bglx_rev_reviewer(),
                '判定=' . $judgment);
            bglx_json(0, array('item' => $updated, 'version' => $updated['version']), '判定已保存');
            break;

        case 'review_save_corrections':
            $item = bglx_rev_api_target();
            $corrections = bglx_param('corrections', array());
            if (!is_array($corrections)) {
                bglx_fail(40001, 'corrections 必须为数组');
            }
            $patch = array(
                'corrections' => $corrections,
                'status' => 'draft',
                'version' => (int)bglx_param('version', isset($item['version']) ? $item['version'] : 1),
            );
            $updated = bglx_rev_store()->patch($item['id'], $patch);
            bglx_rev_store()->appendAudit('review_save_corrections', $item['id'], bglx_rev_reviewer(),
                '草稿保存 ' . count($updated['corrections']) . ' 条纠错');
            bglx_json(0, array('item' => $updated, 'version' => $updated['version']), '纠错草稿已保存');
            break;

        case 'review_ai_organize':
            $item = bglx_rev_api_target();
            $corrections = isset($item['corrections']) ? $item['corrections'] : array();
            $result = bglx_rev_ai_organize($corrections, array());
            if (empty($result['ok'])) {
                bglx_fail(BGLX_REV_AI_FAILED, 'AI 整理失败：' . $result['reason'], array(
                    'fallback' => (bool)$result['fallback'],
                    'candidates' => array(),
                ));
            }
            $patch = array(
                'ai_candidates' => $result['candidates'],
                'version' => (int)bglx_param('version', isset($item['version']) ? $item['version'] : 1),
            );
            $updated = bglx_rev_store()->patch($item['id'], $patch);
            bglx_rev_store()->appendAudit('review_ai_organize', $item['id'], bglx_rev_reviewer(),
                'AI 候选 ' . count($result['candidates']) . ' 条，均待人工确认');
            bglx_json(0, array(
                'ok' => true, 'candidates' => $result['candidates'],
                'item' => $updated, 'version' => $updated['version'],
            ), 'AI 整理完成，请人工确认候选');
            break;

        case 'review_import_decisions':
            $item = bglx_rev_api_target();
            $source = trim((string)bglx_param('source', 'manual'));
            if (!in_array($source, array('ai', 'manual'), true)) {
                bglx_fail(40001, '入库来源不合法（应为 ai / manual）');
            }
            $decisions = bglx_param('decisions', array());
            if (!is_array($decisions) || !$decisions) {
                bglx_fail(40001, 'decisions 不能为空');
            }
            $result = bglx_rev_import_decisions($decisions, $item['id'], array(
                'origin' => $source,
                'conflict_policy' => trim((string)bglx_param('conflict_policy', 'ask')),
                'ai_signatures' => bglx_rev_api_ai_signatures($item), // 防 source 伪造
                'manual_import_status' => bglx_rev_setting_enum('manualImportStatus',
                    array('active', 'pending'), 'active'),
            ));
            $updated = bglx_rev_store()->patch($item['id'], array(
                'status' => 'imported',
                'import_path' => $source,
            ));
            bglx_rev_store()->appendAudit('review_import_decisions', $item['id'], bglx_rev_reviewer(),
                'added=' . $result['added'] . ' pending=' . $result['pending']
                . ' skipped=' . $result['skipped'] . ' source=' . $source);
            bglx_json(0, $result, '导入完成');
            break;

        case 'review_delete_draft':
            $id = trim((string)bglx_param('item_id', ''));
            if ($id === '') {
                bglx_fail(40001, '缺少 item_id 参数');
            }
            if (!bglx_rev_store()->remove($id)) {
                bglx_fail(40401, '审查记录不存在：' . $id);
            }
            bglx_rev_store()->appendAudit('review_delete_draft', $id, bglx_rev_reviewer(), '撤销草稿');
            bglx_json(0, array('ok' => true), '草稿已撤销');
            break;
    }
} catch (BglxValidationException $e) {
    bglx_fail(42201, $e->getMessage(), array('errors' => $e->errors));
} catch (BglxReviewLockException $e) {
    bglx_fail(BGLX_REV_VERSION_CONFLICT, $e->getMessage());
} catch (BglxLockException $e) {
    bglx_fail(40901, $e->getMessage());
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
    if (strpos($msg, '词条不存在') === 0 || strpos($msg, '审查记录不存在') === 0) {
        bglx_fail(40401, $msg);
    }
    bglx_fail(50001, $msg);
} catch (Throwable $e) {
    bglx_fail(50001, '接口执行失败：' . $e->getMessage());
}
