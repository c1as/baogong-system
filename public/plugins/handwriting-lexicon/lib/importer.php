<?php
/**
 * 纠错 → 词条受控入库
 *
 * 四类映射：partNo→drawing_alias、process→process_synonym、
 *           unit→unit_synonym、char→char_confusion。
 * 所有词条仍走既有 bglx_build_entry → bglx_validate_entry → BglxStore::create，
 * source 统一 ocr_feedback，写操作内 bglx_reload() 保证 bridge 下一请求生效。
 *
 * AI 零直入四重保证：
 *  ① resolve_status('ai', ...) 恒 pending，忽略 aiImportStatus；
 *  ② 冲突强制 pending；
 *  ③ 不信任客户端 status；source=ai 时必须 accepted===true，且服务端覆盖为 pending；
 *  ④ AI confidence 上限 0.8 < 自动阈值 0.90，激活唯一途径是既有 review 动作。
 */

if (!defined('BGLX_REV_IMPORTER_LOADED')) {
    define('BGLX_REV_IMPORTER_LOADED', 1);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/lexicon.php';

/**
 * 四类字段 → type 映射表。
 *
 * @return array
 */
function bglx_rev_field_type_map()
{
    return array(
        'partNo' => 'drawing_alias',
        'process' => 'process_synonym',
        'unit' => 'unit_synonym',
        'char' => 'char_confusion',
    );
}

/**
 * type → 纠错字段名 反查（用于 AI 候选转为纠错结构）。
 *
 * @return array
 */
function bglx_rev_type_field_map()
{
    return array(
        'drawing_alias' => 'partNo',
        'process_synonym' => 'process',
        'unit_synonym' => 'unit',
        'char_confusion' => 'char',
    );
}

/**
 * 提取候选的 type / standard / variants（兼容纠错结构与 AI 候选结构）。
 *
 * @param array $c 候选项
 * @return array array('type','standard','variants','field')
 */
function bglx_rev_candidate_parts($c)
{
    $c = is_array($c) ? $c : array();
    $map = bglx_rev_field_type_map();
    $field = isset($c['field']) ? (string)$c['field'] : '';
    $type = isset($c['type']) ? (string)$c['type'] : '';
    if (!in_array($type, BGLX_TYPES, true)) {
        $type = isset($map[$field]) ? $map[$field] : '';
    }
    // 无 type 且字段名未知时保持空 type，交由映射层给出中文错误
    $standard = trim((string)(isset($c['correct_value'])
        ? $c['correct_value'] : (isset($c['standard']) ? $c['standard'] : '')));
    $variants = array();
    if (!empty($c['wrong_value'])) {
        $variants[] = (string)$c['wrong_value'];
    }
    if (isset($c['variants']) && is_array($c['variants'])) {
        $variants = array_merge($variants, $c['variants']);
    } elseif (isset($c['variants']) && is_string($c['variants'])) {
        $variants[] = $c['variants'];
    }
    if (isset($c['extra_variants'])) {
        $variants = array_merge($variants, bglx_variants($c['extra_variants']));
    }
    return array(
        'type' => $type,
        'standard' => $standard,
        'variants' => bglx_variants($variants),
        'field' => $field,
    );
}

/**
 * 冲突检测：同 type 下，候选 standard 命中现有 variant，或候选 variant 命中现有 standard。
 *
 * @param array      $candidates 候选/纠错列表
 * @param BglxStore  $store      既有词库
 * @return array 索引 → array('ids'=>array,'detail'=>string)
 */
function bglx_rev_find_conflicts($candidates, $store)
{
    $out = array();
    if (!is_object($store) || !method_exists($store, 'all')) {
        return $out;
    }
    $grouped = array();
    foreach ($store->all(array('enabled' => '')) as $e) {
        if (!is_array($e) || !isset($e['type'])) {
            continue;
        }
        $type = (string)$e['type'];
        if (!isset($grouped[$type])) {
            $grouped[$type] = array();
        }
        $grouped[$type][] = array(
            'id' => isset($e['id']) ? (string)$e['id'] : '',
            'standard' => bglx_lower((string)(isset($e['standard']) ? $e['standard'] : '')),
            'variants' => array_map(function ($v) {
                return bglx_lower((string)$v);
            }, isset($e['variants']) && is_array($e['variants']) ? $e['variants'] : array()),
        );
    }

    foreach (is_array($candidates) ? $candidates : array() as $i => $c) {
        $parts = bglx_rev_candidate_parts($c);
        if ($parts['type'] === '' || $parts['standard'] === '') {
            continue;
        }
        $standard = bglx_lower($parts['standard']);
        $variantLowers = array_map(function ($v) {
            return bglx_lower((string)$v);
        }, $parts['variants']);
        if (!isset($grouped[$parts['type']])) {
            continue;
        }
        $ids = array();
        foreach ($grouped[$parts['type']] as $e) {
            $hit = ($e['standard'] !== '' && in_array($e['standard'], $variantLowers, true))
                || in_array($standard, $e['variants'], true);
            if ($hit && $e['id'] !== '') {
                $ids[] = $e['id'];
            }
        }
        if ($ids) {
            $out[$i] = array('ids' => array_slice($ids, 0, 10), 'detail' => '与现有词条冲突：' . implode(',', array_slice($ids, 0, 5)));
        }
    }
    return $out;
}

/**
 * 状态决策（AI 零直入第一、二重保证）。
 *
 * @param string $origin   ai 或 manual
 * @param bool   $conflict 是否冲突
 * @param array  $cfg      配置
 * @return string active 或 pending
 */
function bglx_rev_resolve_status($origin, $conflict, $cfg = array())
{
    if ($origin === 'ai') {
        return 'pending';                    // ① 忽略 aiImportStatus，即使被误配为 active
    }
    if ($conflict) {
        return 'pending';                    // ② 冲突强制待审
    }
    $cfg = is_array($cfg) ? $cfg : array();
    $manual = isset($cfg['manual_import_status'])
        ? (string)$cfg['manual_import_status']
        : bglx_rev_setting_enum('manualImportStatus', array('active', 'pending'), 'active');
    return in_array($manual, array('active', 'pending'), true) ? $manual : 'active';
}

/**
 * 形近字等长逐位拆对；不等长返回空数组要求人工/AI 拆分。
 *
 * @param string $standard 正确文本
 * @param array  $variants 错误文本列表
 * @return array array(array(standardChar, array(variantChar)), ...)
 */
function bglx_rev_split_char_pairs($standard, $variants)
{
    $standard = trim((string)$standard);
    $sChars = bglx_str_split($standard);
    $n = count($sChars);
    if ($n < 1) {
        return array();
    }
    $list = bglx_variants($variants);
    if (!$list) {
        return array();
    }
    $pairs = array();
    foreach ($list as $v) {
        $vChars = bglx_str_split((string)$v);
        if (count($vChars) !== $n) {
            return array(); // 不等长：要求人工/AI 拆分，不猜
        }
        if ($n === 1) {
            $pairs[] = array($sChars[0], array($vChars[0]));
            continue;
        }
        for ($i = 0; $i < $n; $i++) {
            if ($sChars[$i] !== $vChars[$i]) {
                $pairs[] = array($sChars[$i], array($vChars[$i]));
            }
        }
    }
    $seen = array();
    $out = array();
    foreach ($pairs as $p) {
        $key = $p[0] . '|' . $p[1][0];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * 四类纠错 → 词条候选（经 build_entry / validate_entry 校验，不落库）。
 *
 * @param array  $corrections 纠错列表
 * @param string $origin      ai 或 manual
 * @param array  $cfg         配置（可含 store / manual_import_status）
 * @return array array('entries','errors')
 */
function bglx_rev_map_corrections($corrections, $origin, $cfg = array())
{
    $cfg = is_array($cfg) ? $cfg : array();
    $store = isset($cfg['store']) && is_object($cfg['store']) ? $cfg['store'] : bglx_store();
    $conflicts = bglx_rev_find_conflicts($corrections, $store);
    $map = bglx_rev_field_type_map();
    $out = array('entries' => array(), 'errors' => array());

    foreach (is_array($corrections) ? $corrections : array() as $i => $c) {
        if (!is_array($c)) {
            $out['errors'][] = '第' . ($i + 1) . '行：数据格式不合法';
            continue;
        }
        $field = isset($c['field']) ? (string)$c['field'] : '';
        $type = isset($c['type']) ? (string)$c['type'] : '';
        if (!in_array($type, BGLX_TYPES, true)) {
            $type = isset($map[$field]) ? $map[$field] : '';
        }
        if ($type === '') {
            $out['errors'][] = '第' . ($i + 1) . '行：未知字段 ' . bglx_cut($field, 40);
            continue;
        }
        $standard = trim((string)(isset($c['correct_value'])
            ? $c['correct_value'] : (isset($c['standard']) ? $c['standard'] : '')));
        if ($standard === '') {
            $out['errors'][] = '第' . ($i + 1) . '行：正确值不能为空';
            continue;
        }
        $rawVariants = array();
        if (isset($c['wrong_value']) && (string)$c['wrong_value'] !== '') {
            $rawVariants[] = (string)$c['wrong_value'];
        }
        if (isset($c['extra_variants'])) {
            $rawVariants = array_merge($rawVariants, bglx_variants($c['extra_variants']));
        }
        if (isset($c['variants'])) {
            $rawVariants = array_merge($rawVariants, bglx_variants($c['variants']));
        }
        $variants = bglx_variants($rawVariants);
        $variants = array_values(array_filter($variants, function ($v) use ($standard) {
            return bglx_lower($v) !== bglx_lower($standard);
        }));
        if (!$variants) {
            $out['errors'][] = '第' . ($i + 1) . '行：缺少错误值/变体';
            continue;
        }

        if ($type === 'char_confusion') {
            $pairs = bglx_rev_split_char_pairs($standard, $variants);
            if (!$pairs) {
                $out['errors'][] = '第' . ($i + 1) . '行：形近字需为等长单字符映射';
                continue;
            }
        } else {
            $pairs = array(array($standard, $variants));
        }

        foreach ($pairs as $pair) {
            $conflict = isset($conflicts[$i]);
            $status = bglx_rev_resolve_status($origin, $conflict, $cfg);
            $conf = ($origin === 'ai')
                ? min(0.8, (float)(isset($c['confidence']) ? $c['confidence'] : 0.8))
                : 1.0;
            $payload = array(
                'type' => $type,
                'standard' => $pair[0],
                'variants' => $pair[1],
                'source' => 'ocr_feedback',
                'status' => $status,
                'confidence' => $conf,
                'enabled' => true,
                'scope' => array('*'),
                'priority' => 10,
                'note' => '审查回流 item=' . (isset($c['item_id']) ? (string)$c['item_id'] : '')
                    . ' invoke=' . (isset($c['invoke_id']) ? (string)$c['invoke_id'] : ''),
            );
            $entry = bglx_build_entry($payload, null);
            $entry['id'] = 'LX-000001';      // 校验器要求合法 id，临时占位
            $errors = bglx_validate_entry($entry);
            unset($entry['id']);             // 真实 id 由 BglxStore::create 分配
            if ($errors) {
                $out['errors'][] = '第' . ($i + 1) . '行：' . implode('；', array_values($errors));
                continue;
            }
            $out['entries'][] = $entry;
        }
    }
    return $out;
}

/**
 * 生成候选签名，用于服务端核对「这条是否来自 AI 候选」，防止客户端伪造 source 绕过审核。
 *
 * @param array $c 候选
 * @return string
 */
function bglx_rev_decision_signature($c)
{
    $parts = bglx_rev_candidate_parts($c);
    $variant = isset($parts['variants'][0]) ? bglx_lower($parts['variants'][0]) : '';
    return $parts['type'] . '|' . bglx_lower($parts['standard']) . '|' . $variant;
}

/**
 * 受控入库：复用 BglxStore::create，按来源/冲突决定状态。
 *
 * @param array  $decisions 决策列表
 * @param string $itemId    审查记录 ID
 * @param array  $cfg       array('origin','store','conflict_policy','ai_signatures',...)
 * @return array array('results','added','pending','skipped','errors','ids')
 */
function bglx_rev_import_decisions($decisions, $itemId, $cfg = array())
{
    $cfg = is_array($cfg) ? $cfg : array();
    $origin = isset($cfg['origin']) ? (string)$cfg['origin'] : 'manual';
    if (!in_array($origin, array('ai', 'manual'), true)) {
        $origin = 'manual';
    }
    $store = isset($cfg['store']) && is_object($cfg['store']) ? $cfg['store'] : bglx_store();
    $policy = isset($cfg['conflict_policy']) ? (string)$cfg['conflict_policy'] : 'ask';
    $aiSignatures = isset($cfg['ai_signatures']) && is_array($cfg['ai_signatures'])
        ? array_map('strval', $cfg['ai_signatures']) : array();

    // R07 第二层闸门：manual 来源只吃判错行；ai 来源仍走既有签名 + accepted 校验，互不误伤
    $onlyWrong = array_key_exists('only_wrong', $cfg)
        ? (bool)$cfg['only_wrong'] : ($origin === 'manual');
    if ($onlyWrong && $origin === 'manual') {
        $decisions = array_values(array_filter(is_array($decisions) ? $decisions : array(), function ($d) {
            if (!is_array($d)) {
                return false;
            }
            $judgment = isset($d['judgment']) ? (string)$d['judgment'] : '';
            if ($judgment !== '' && $judgment !== 'wrong') {
                return false;
            }
            $standard = trim((string)(isset($d['correct_value'])
                ? $d['correct_value'] : (isset($d['standard']) ? $d['standard'] : '')));
            return $standard !== '';
        }));
    }

    $out = array('results' => array(), 'added' => 0, 'pending' => 0,
                 'skipped' => 0, 'errors' => array(), 'ids' => array());
    $conflicts = bglx_rev_find_conflicts($decisions, $store);

    foreach (is_array($decisions) ? $decisions : array() as $i => $d) {
        if (!is_array($d)) {
            $out['errors'][] = '第' . ($i + 1) . '行：决策数据格式不合法';
            $out['skipped']++;
            continue;
        }
        // ③ 服务端根据实际候选签名判定来源，不信任客户端传入的 source/status
        $decisionOrigin = $origin;
        if (in_array(bglx_rev_decision_signature($d), $aiSignatures, true)) {
            $decisionOrigin = 'ai';
        }
        if ($decisionOrigin === 'ai' && empty($d['accepted'])) {
            $out['errors'][] = '第' . ($i + 1) . '行：AI 候选必须勾选采纳后才能导入';
            $out['skipped']++;
            continue;
        }
        $conflict = isset($conflicts[$i]);
        if ($conflict && $policy === 'skip') {
            $out['errors'][] = '第' . ($i + 1) . '行：与现有词条冲突，已按策略跳过';
            $out['skipped']++;
            continue;
        }

        $correction = $d;
        if (!isset($correction['field']) || (string)$correction['field'] === '') {
            $inverse = bglx_rev_type_field_map();
            $type = isset($d['type']) ? (string)$d['type'] : '';
            if (isset($inverse[$type])) {
                $correction['field'] = $inverse[$type];
            }
        }
        if (!isset($correction['item_id'])) {
            $correction['item_id'] = (string)$itemId;
        }
        $mapped = bglx_rev_map_corrections(array($correction), $decisionOrigin, $cfg);
        foreach ($mapped['errors'] as $err) {
            $out['errors'][] = $err;
        }
        foreach ($mapped['entries'] as $entry) {
            if ($decisionOrigin === 'ai') {
                $entry['status'] = 'pending';                 // ③ 服务端覆盖
                $entry['confidence'] = min(0.8, (float)$entry['confidence']); // ④
            }
            try {
                $created = $store->create($entry);
                $out['ids'][] = $created['id'];
                $out['added']++;
                if ((string)$created['status'] === 'pending') {
                    $out['pending']++;
                }
                $out['results'][] = array(
                    'ok' => true,
                    'id' => $created['id'],
                    'type' => $created['type'],
                    'standard' => $created['standard'],
                    'status' => $created['status'],
                    'confidence' => $created['confidence'],
                );
            } catch (Throwable $e) {
                $out['errors'][] = '第' . ($i + 1) . '行：' . bglx_cut($e->getMessage(), 160);
                $out['skipped']++;
            }
        }
        if (!$mapped['entries']) {
            $out['skipped']++;
        }
    }
    if (function_exists('bglx_reload')) {
        bglx_reload(); // 词库写已在 writeDoc 内重载，这里再次显式保证 bridge 生效
    }
    return $out;
}
