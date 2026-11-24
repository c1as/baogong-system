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

/** 审查动作清单：只读 5 个 + 写 9 个（v3 新增逐条判定 / 行推断 / 批量快捷） */
$reviewReadActions = array(
    'review_settings', 'review_list_logs', 'review_list_images',
    'review_image', 'review_stats',
);
$reviewWriteActions = array(
    'review_link', 'review_judge', 'review_judge_batch', 'review_judge_row',
    'review_infer_rows', 'review_save_corrections',
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
    $fields = isset($snapshot['fields']) && is_array($snapshot['fields']) ? $snapshot['fields'] : array();
    $details = isset($snapshot['items']) && is_array($snapshot['items']) ? $snapshot['items'] : array();
    return $rv->upsert(array(
        'invoke_id' => $invoke,
        'date' => isset($snapshot['date']) ? $snapshot['date'] : '',
        'engine' => isset($snapshot['engine']) ? $snapshot['engine'] : '',
        'duration' => isset($snapshot['duration']) ? $snapshot['duration'] : 0,
        'recognized_fields' => $fields,
        'recognized_items' => $details,
        // v3：首次判定即按四类规则落行，row_key 与前端推断一致，避免 40001
        'corrections' => bglx_rev_infer_rows($details, $fields, array()),
    ));
}

/**
 * AI 输入闸门：仅取 judgment=wrong 且正确值非空、未超长的行（G5 红线）。
 *
 * 判对行 / 未审行根本不进 prompt；ai_organizer 内部还有一层同构断言。
 *
 * @param array $item 审查记录
 * @return array 判错行列表
 */
function bglx_rev_api_wrong_rows($item)
{
    $rows = isset($item['corrections']) && is_array($item['corrections'])
        ? $item['corrections'] : array();
    $out = array();
    foreach ($rows as $row) {
        if (!is_array($row) || (string)(isset($row['judgment']) ? $row['judgment'] : '') !== 'wrong') {
            continue;
        }
        $correct = trim((string)(isset($row['correct_value']) ? $row['correct_value'] : ''));
        if ($correct === '' || bglx_str_len($correct) > 200) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
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
                // 主程序日志没有 invokeId 时，用日志内容生成稳定键，保证判定/纠错可落库并回显
                if ($inv === '') {
                    $seed = (isset($log['date']) ? $log['date'] : '') . '|'
                        . (isset($log['ts']) ? $log['ts'] : '') . '|'
                        . (isset($log['engine']) ? $log['engine'] : '') . '|'
                        . json_encode(array(
                            isset($log['fields']) ? $log['fields'] : array(),
                            isset($log['items']) ? $log['items'] : array(),
                        ), JSON_UNESCAPED_UNICODE);
                    $inv = 'log-' . substr(sha1($seed), 0, 20);
                    $linked[$k]['invoke_id'] = $inv;
                }
                $saved = ($inv !== '' && isset($stored[$inv])) ? $stored[$inv] : array();
                $linked[$k]['review_id'] = isset($saved['id']) ? $saved['id'] : '';
                $linked[$k]['version'] = isset($saved['version']) ? (int)$saved['version'] : 1;
                $linked[$k]['judgment'] = (isset($saved['judgment']) && $saved['judgment'] !== '')
                    ? $saved['judgment'] : 'unreviewed';
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
            $tail = bglx_rev_after_write();
            bglx_json(0, array(
                'item' => $updated, 'version' => $updated['version'], 'warnings' => $tail['warnings'],
            ), '关联已更新');
            break;

        case 'review_judge_row':
            $item = bglx_rev_api_target();
            $ctx = array('item' => $item, 'reviewer' => bglx_rev_reviewer());
            $body = bglx_json_body();
            $fromPost = is_array($_POST);
            if (array_key_exists('note', $body) || ($fromPost && array_key_exists('note', $_POST))) {
                $ctx['note'] = (string)bglx_param('note', '');
            }
            if (array_key_exists('extra_variants', $body)
                || ($fromPost && array_key_exists('extra_variants', $_POST))) {
                $ctx['extra_variants'] = bglx_param('extra_variants', array());
            }
            $data = bglx_rev_judge_row(
                $item['id'],
                (string)bglx_param('row_key', ''),
                (string)bglx_param('judgment', ''),
                (string)bglx_param('correct_value', ''),
                (int)bglx_param('version', isset($item['version']) ? $item['version'] : 1),
                $ctx
            );
            $tail = bglx_rev_after_write();
            $data['warnings'] = $tail['warnings'];
            bglx_json(0, $data, '逐条判定已保存');
            break;

        case 'review_infer_rows':
            $item = bglx_rev_api_target();
            $rows = bglx_rev_infer_rows(
                isset($item['recognized_items']) ? $item['recognized_items'] : array(),
                isset($item['recognized_fields']) ? $item['recognized_fields'] : array(),
                isset($item['corrections']) ? $item['corrections'] : array()
            );
            bglx_json(0, array(
                'rows' => $rows,
                'item_id' => isset($item['id']) ? $item['id'] : '',
                'version' => isset($item['version']) ? (int)$item['version'] : 1,
            ), 'ok');
            break;

        /* 整图批量快捷：默认关闭，开启后本质逐行写 judgment（R14） */
        case 'review_judge':
        case 'review_judge_batch':
            $item = bglx_rev_api_target();
            if (!bglx_rev_setting_bool('wholeImageJudgeEnabled', false)) {
                bglx_fail(BGLX_REV_IMMUTABLE, '整图批量判定已关闭，请在逐条纠错区判定');
            }
            $rows = bglx_param('rows', array());
            if (!is_array($rows) || !$rows) {
                // 兼容旧版单条 judgment 入参：展开到全部纠错行
                $judgment = trim((string)bglx_param('judgment', ''));
                $correct = (string)bglx_param('correct_value', '');
                $rows = array();
                $existing = isset($item['corrections']) && is_array($item['corrections'])
                    ? $item['corrections'] : array();
                foreach ($existing as $c) {
                    if (!is_array($c) || !isset($c['row_key'])) {
                        continue;
                    }
                    $rows[] = array(
                        'row_key' => (string)$c['row_key'],
                        'judgment' => $judgment,
                        'correct_value' => $correct,
                    );
                }
                if (!$rows) {
                    bglx_fail(40001, '缺少 rows 参数或没有可判定的纠错行');
                }
            }
            $data = bglx_rev_judge_batch(
                $item['id'], $rows,
                (int)bglx_param('version', isset($item['version']) ? $item['version'] : 1),
                array('item' => $item, 'reviewer' => bglx_rev_reviewer())
            );
            $tail = bglx_rev_after_write();
            $data['warnings'] = $tail['warnings'];
            bglx_json(0, $data, '批量判定已保存');
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
            $tail = bglx_rev_after_write();
            bglx_json(0, array(
                'item' => $updated,
                'version' => $updated['version'],
                'projection' => bglx_rev_derive_projection($updated['corrections'], $updated),
                'warnings' => $tail['warnings'],
            ), '纠错草稿已保存');
            break;

        case 'review_ai_organize':
            $item = bglx_rev_api_target();
            // G5 红线：服务端只把判错且填了正确值的行送进 AI，判对行根本不进 prompt
            $wrongRows = bglx_rev_api_wrong_rows($item);
            if (!$wrongRows) {
                $tail = bglx_rev_after_write();
                bglx_json(0, array(
                    'ok' => true, 'candidates' => array(), 'wrong_rows' => 0,
                    'item' => $item,
                    'version' => isset($item['version']) ? (int)$item['version'] : 1,
                    'warnings' => $tail['warnings'],
                ), '无判错行，未生成 AI 候选');
            }
            $result = bglx_rev_ai_organize($wrongRows, array());
            if (empty($result['ok'])) {
                bglx_fail(BGLX_REV_AI_FAILED, 'AI 整理失败：' . $result['reason'], array(
                    'fallback' => (bool)$result['fallback'],
                    'candidates' => array(),
                    'wrong_rows' => count($wrongRows),
                ));
            }
            $patch = array(
                'ai_candidates' => $result['candidates'],
                'version' => (int)bglx_param('version', isset($item['version']) ? $item['version'] : 1),
            );
            $updated = bglx_rev_store()->patch($item['id'], $patch);
            bglx_rev_store()->appendAudit('review_ai_organize', $item['id'], bglx_rev_reviewer(),
                'AI 候选 ' . count($result['candidates']) . ' 条（判错行 ' . count($wrongRows) . '），均待人工确认');
            $tail = bglx_rev_after_write();
            bglx_json(0, array(
                'ok' => true, 'candidates' => $result['candidates'],
                'wrong_rows' => count($wrongRows),
                'item' => $updated, 'version' => $updated['version'],
                'warnings' => $tail['warnings'],
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
            if ($source === 'manual') {
                // R07：人工路径仅判错行入库；判对行 / 未审行 / 空正确值一律不产生词条
                $decisions = array_values(array_filter($decisions, function ($d) {
                    if (!is_array($d)) {
                        return false;
                    }
                    if (isset($d['judgment']) && (string)$d['judgment'] !== 'wrong') {
                        return false;
                    }
                    $correct = isset($d['correct_value']) ? $d['correct_value']
                        : (isset($d['standard']) ? $d['standard'] : '');
                    return trim((string)$correct) !== '';
                }));
                if (!$decisions) {
                    $tail = bglx_rev_after_write();
                    bglx_json(0, array(
                        'added' => 0, 'pending' => 0, 'skipped' => 0,
                        'errors' => array(), 'ids' => array(), 'warnings' => $tail['warnings'],
                    ), '无判错行，未新增词条');
                }
            }
            $result = bglx_rev_import_decisions($decisions, $item['id'], array(
                'origin' => $source,
                'only_wrong' => ($source === 'manual'), // importer 内再做一层 defense-in-depth
                'conflict_policy' => trim((string)bglx_param('conflict_policy', 'ask')),
                'ai_signatures' => bglx_rev_api_ai_signatures($item), // 防 source 伪造
                'manual_import_status' => bglx_rev_setting_enum('manualImportStatus',
                    array('active', 'pending'), 'active'),
            ));
            $patch = array('version' => (int)bglx_param('version', isset($item['version']) ? $item['version'] : 1));
            // 无词条入库不置 imported（架构 §8.3）：全判正确时仍保持 reviewed
            if ((int)$result['added'] > 0) {
                $patch['status'] = 'imported';
                $patch['import_path'] = $source;
            }
            $updated = bglx_rev_store()->patch($item['id'], $patch);
            bglx_rev_store()->appendAudit('review_import_decisions', $item['id'], bglx_rev_reviewer(),
                'added=' . $result['added'] . ' pending=' . $result['pending']
                . ' skipped=' . $result['skipped'] . ' source=' . $source);
            $tail = bglx_rev_after_write();
            $result['warnings'] = $tail['warnings'];
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
            $tail = bglx_rev_after_write();
            bglx_json(0, array('ok' => true, 'warnings' => $tail['warnings']), '草稿已撤销');
            break;
    }
} catch (BglxReviewImmutableException $e) {
    bglx_fail(BGLX_REV_IMMUTABLE, $e->getMessage());
} catch (InvalidArgumentException $e) {
    bglx_fail(40001, $e->getMessage());
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
