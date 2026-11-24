<?php
/**
 * 手写字词库审查工作台 v3 · CLI 断言测试（零依赖）
 *
 * 运行：php tests/review_v3.test.php
 * 覆盖：四类全覆盖 / row_key 幂等 / 派生判定表 / 逐条事务与 40901 /
 *       存量迁移 / 归档先写后移 / AI 仅 wrong / 审计守恒 / 归档目录穿越。
 *
 * 使用系统临时目录隔离数据，不触碰真实词库与审查记录。
 */

$TMP3 = rtrim(sys_get_temp_dir(), '/') . '/bglx-review-v3-' . getmypid();
$DATA3 = $TMP3 . '/data';
@mkdir($DATA3 . '/review-archive', 0775, true);

$GLOBALS['DATA_DIR'] = $DATA3;
$GLOBALS['BG_PLUGIN_CTX'] = array('config' => array(
    'reviewEnabled' => '1',
    'reviewRetentionDays' => '90',
    'reviewArchiveEnabled' => '1',
    'reviewArchiveDir' => 'review-archive/',
    'wholeImageJudgeEnabled' => '0',
    'aiEnabled' => '0',
    'manualImportStatus' => 'active',
    'aiImportStatus' => 'pending',
));

require_once dirname(__DIR__) . '/lib/review.php';
bglx_rev_bootstrap();

$pass3 = 0;
$fail3 = 0;

/**
 * v3 断言辅助。
 *
 * @param bool   $condition 条件
 * @param string $label     用例名
 * @param string $detail    失败说明
 * @return void
 */
function bglx_rev3_assert($condition, $label, $detail = '')
{
    global $pass3, $fail3;
    if ($condition) {
        $pass3++;
        echo "[PASS] " . $label . "\n";
    } else {
        $fail3++;
        echo "[FAIL] " . $label . ($detail !== '' ? " · " . $detail : '') . "\n";
    }
}

/** 递归清理临时目录 */
function bglx_rev3_cleanup($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($dir);
}
register_shutdown_function('bglx_rev3_cleanup', $TMP3);

/** 构造一条判定行 */
function bglx_rev3_row($judgment, $time = '2026-11-25 10:00:00')
{
    return array(
        'row_key' => '0:partNo', 'detail_index' => 0, 'field' => 'partNo',
        'type' => 'drawing_alias', 'wrong_value' => 'A-123',
        'judgment' => $judgment, 'judged_at' => $time,
        'correct_value' => ($judgment === 'wrong' ? 'AS-1234' : ''),
        'extra_variants' => array(), 'confirmed' => false, 'note' => '',
    );
}

/** 从纠错行列表中按 row_key 找行 */
function bglx_rev3_find_row($rows, $key)
{
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['row_key']) && (string)$row['row_key'] === $key) {
            return $row;
        }
    }
    return array();
}

$items3 = array(
    array('partNo' => 'TD90535R184', 'process' => '铣光2', 'unit' => '个', 'qty' => '204'),
    array('partNo' => 'TD90523R301', 'process' => '铣光1', 'unit' => '件', 'qty' => '404'),
    array('partNo' => 'TD90523R302', 'process' => '铣光2', 'qty' => '404'),
);
$fields3 = array('date' => '2026-11-24', 'note' => '末');

echo "=== 手写字词库 v3 逐条判定改造测试 ===\n\n";

/* ---------- A. 四类全覆盖 ---------- */
echo "--- A. 四类全覆盖与 row_key 幂等 ---\n";
$rows3 = bglx_rev_infer_rows($items3, $fields3, array());
bglx_rev3_assert(count($rows3) === 9, 'A1 三条明细 × 有值字段 + 形近字 = 9 行', '实际 ' . count($rows3));
$keys3 = array();
foreach ($rows3 as $r) {
    $keys3[] = $r['row_key'];
}
foreach (array('0:partNo', '1:process', '2:partNo', '0:unit', '1:unit', 'note:char') as $need) {
    bglx_rev3_assert(in_array($need, $keys3, true), 'A2 生成行 ' . $need);
}
$qtyPolluted = false;
foreach ($rows3 as $r) {
    if ($r['field'] === 'qty' || strpos($r['row_key'], 'qty') !== false) {
        $qtyPolluted = true;
    }
}
bglx_rev3_assert(!$qtyPolluted, 'A3 qty 不生成判定行（仅在对照区只读展示）');
$types3 = array_values(array_unique(array_map(function ($r) {
    return $r['type'];
}, $rows3)));
sort($types3);
bglx_rev3_assert($types3 === array('char_confusion', 'drawing_alias', 'process_synonym', 'unit_synonym'),
    'A4 四类词条齐全且无第五类', json_encode($types3, JSON_UNESCAPED_UNICODE));

$saved3 = array(array(
    'row_key' => '0:partNo', 'detail_index' => 0, 'field' => 'partNo', 'type' => 'drawing_alias',
    'wrong_value' => 'TD90535R1S4', 'judgment' => 'wrong', 'judged_at' => '2026-11-25 09:00:00',
    'correct_value' => 'TD90535R184', 'extra_variants' => array('TD90535R18'),
    'confirmed' => true, 'note' => '已人工判定',
));
$merged3 = bglx_rev_infer_rows($items3, $fields3, $saved3);
$kept3 = bglx_rev3_find_row($merged3, '0:partNo');
bglx_rev3_assert(count($merged3) === count($rows3), 'A5 row_key 幂等合并不产生重复行', '实际 ' . count($merged3));
bglx_rev3_assert(!empty($kept3) && $kept3['judgment'] === 'wrong'
    && $kept3['correct_value'] === 'TD90535R184' && $kept3['note'] === '已人工判定',
    'A6 存量已判结果整行保留，绝不覆盖', json_encode($kept3, JSON_UNESCAPED_UNICODE));

/* ---------- B. 派生判定表 ---------- */
echo "\n--- B. 状态派生判定表 ---\n";
$p1 = bglx_rev_derive_projection(array(), array('status' => 'draft', 'judgment' => 'unreviewed'));
bglx_rev3_assert($p1['status'] === 'draft' && $p1['judgment'] === 'unreviewed', 'B1 无行 → draft/unreviewed');
$p2 = bglx_rev_derive_projection(array(bglx_rev3_row('correct'), bglx_rev3_row('unreviewed')), array());
bglx_rev3_assert($p2['status'] === 'draft' && $p2['judgment'] === 'unreviewed', 'B2 存在未审行 → draft/unreviewed');
$p3 = bglx_rev_derive_projection(array(
    bglx_rev3_row('correct', '2026-11-25 10:00:00'),
    array_merge(bglx_rev3_row('correct', '2026-11-25 11:30:00'), array('row_key' => '1:process')),
), array());
bglx_rev3_assert($p3['status'] === 'reviewed' && $p3['judgment'] === 'correct'
    && $p3['judged_at'] === '2026-11-25 11:30:00', 'B3 全正确 → reviewed/correct，时间取最大', json_encode($p3));
$p4 = bglx_rev_derive_projection(array(
    bglx_rev3_row('correct'),
    array_merge(bglx_rev3_row('wrong', '2026-11-25 12:00:00'), array('row_key' => '1:process')),
), array());
bglx_rev3_assert($p4['status'] === 'reviewed' && $p4['judgment'] === 'wrong', 'B4 有判错且全已判 → wrong 优先聚合');
$p5 = bglx_rev_derive_projection(array(bglx_rev3_row('correct')), array(
    'status' => 'imported', 'judgment' => 'wrong', 'judged_at' => '2026-11-20 00:00:00',
));
bglx_rev3_assert($p5['status'] === 'imported' && $p5['judgment'] === 'wrong', 'B5 imported 保持终态投影');
$p6 = bglx_rev_derive(array(bglx_rev3_row('wrong')), array());
bglx_rev3_assert($p6 === bglx_rev_derive_projection(array(bglx_rev3_row('wrong')), array()),
    'B6 短名 bglx_rev_derive() 与全称一致');

/* ---------- C. 逐条事务 / 乐观锁 ---------- */
echo "\n--- C. 逐条判定事务与 40901 ---\n";
$rv3 = bglx_rev_store();
@unlink($rv3->path());
@unlink($rv3->path() . '.lock');
$base3 = $rv3->upsert(array(
    'invoke_id' => 'v3-001', 'date' => '2026-11-24',
    'recognized_items' => $items3, 'recognized_fields' => $fields3,
    'corrections' => bglx_rev_infer_rows($items3, $fields3, array()),
));
bglx_rev3_assert(count($base3['corrections']) === 9 && $base3['version'] === 1, 'C1 upsert 写入 9 条逐条行，版本 1');
$judged3 = bglx_rev_judge_row($base3['id'], '0:partNo', 'wrong', 'TD90535R184-OK', $base3['version'],
    array('item' => $base3, 'reviewer' => 'unit-test'));
$row0 = bglx_rev3_find_row($judged3['item']['corrections'], '0:partNo');
bglx_rev3_assert($judged3['version'] === 2 && $judged3['item']['version'] === 2, 'C2 判定后 version +1');
bglx_rev3_assert(!empty($row0) && $row0['judgment'] === 'wrong'
    && $row0['correct_value'] === 'TD90535R184-OK' && $row0['judged_at'] !== '',
    'C3 行级事实源与判定时间已落盘', json_encode($row0, JSON_UNESCAPED_UNICODE));
bglx_rev3_assert($judged3['item']['status'] === 'draft' && $judged3['item']['judgment'] === 'unreviewed',
    'C4 仍有未审行时顶层回落 draft（派生回写）');
$conflict = false;
try {
    bglx_rev_judge_row($base3['id'], '0:partNo', 'correct', '', $base3['version'], array('item' => $base3));
} catch (BglxReviewLockException $e) {
    $conflict = true;
}
bglx_rev3_assert($conflict, 'C5 过期 version 抛 BglxReviewLockException（API 映射 40901）');
$noValue = false;
try {
    bglx_rev_judge_row($base3['id'], '0:partNo', 'wrong', '', 2, array('item' => $rv3->get($base3['id'])));
} catch (BglxValidationException $e) {
    $noValue = true;
}
bglx_rev3_assert($noValue, 'C6 判错未填正确值被 42201 拒绝');
$cleared = bglx_rev_judge_row($base3['id'], '0:partNo', 'correct', '残留值', 2,
    array('item' => $rv3->get($base3['id'])));
$row0b = bglx_rev3_find_row($cleared['item']['corrections'], '0:partNo');
bglx_rev3_assert(!empty($row0b) && $row0b['correct_value'] === '', 'C7 判正确强制清空正确值');

/* 全判完 → reviewed */
$cur3 = $rv3->get($base3['id']);
foreach ($cur3['corrections'] as $r) {
    $cur3b = $rv3->get($base3['id']);
    if ($r['judgment'] === 'unreviewed') {
        bglx_rev_judge_row($base3['id'], $r['row_key'], 'correct', '', $cur3b['version'],
            array('item' => $cur3b, 'reviewer' => 'unit-test'));
    }
}
$final3 = $rv3->get($base3['id']);
bglx_rev3_assert($final3['status'] === 'reviewed' && $final3['judgment'] === 'correct',
    'C8 全部判完自动派生 reviewed/correct（无需人工提交）',
    json_encode(array($final3['status'], $final3['judgment'])));

/* imported 终态守卫 */
$imported3 = $rv3->patch($base3['id'], array('status' => 'imported'));
$immutable = false;
try {
    bglx_rev_judge_row($base3['id'], '0:partNo', 'wrong', 'X', $imported3['version'],
        array('item' => $rv3->get($base3['id'])));
} catch (BglxReviewImmutableException $e) {
    $immutable = true;
}
bglx_rev3_assert($immutable, 'C9 imported 终态拒绝改判（41005）');

/* ---------- D. 存量迁移 ---------- */
echo "\n--- D. schema 1 存量迁移 ---\n";
$legacyFile = $DATA3 . '/legacy_review.json';
$legacyDoc = array(
    'schema' => 1, 'updated_at' => '2026-01-02 00:00:00', 'next_id' => 2,
    'items' => array(array(
        'id' => 'RV-000001', 'invoke_id' => 'legacy-1', 'date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00',
        'status' => 'reviewed', 'judgment' => 'correct', 'judged_at' => '', 'version' => 1,
        'recognized_items' => array(array('partNo' => 'A-123', 'process' => '焊结')),
        'corrections' => array(
            array('field' => 'partNo', 'type' => 'drawing_alias', 'wrong_value' => 'A-123',
                  'correct_value' => 'AS-1234', 'extra_variants' => array(), 'confirmed' => true, 'note' => ''),
            array('field' => 'process', 'type' => 'process_synonym', 'wrong_value' => '焊结',
                  'correct_value' => '', 'extra_variants' => array(), 'confirmed' => false, 'note' => ''),
        ),
    )),
    'audit' => array(),
);
@file_put_contents($legacyFile, json_encode($legacyDoc, JSON_UNESCAPED_UNICODE));
$rvLegacy = new BglxReviewStore($legacyFile);
$legacyItem = $rvLegacy->get('RV-000001');
$legacyRows = $legacyItem['corrections'];
bglx_rev3_assert(isset($legacyRows[0]['row_key']) && $legacyRows[0]['row_key'] === '0:partNo'
    && $legacyRows[0]['judgment'] === 'wrong' && $legacyRows[0]['correct_value'] === 'AS-1234'
    && $legacyRows[0]['judged_at'] !== '', 'D1 旧行补 row_key + correct_value 非空判 wrong',
    json_encode($legacyRows[0], JSON_UNESCAPED_UNICODE));
bglx_rev3_assert(isset($legacyRows[1]['row_key']) && $legacyRows[1]['row_key'] === '0:process'
    && $legacyRows[1]['judgment'] === 'unreviewed' && $legacyRows[1]['correct_value'] === '',
    'D2 旧行 correct_value 为空判 unreviewed');
bglx_rev3_assert($legacyItem['status'] === 'draft' && $legacyItem['judgment'] === 'unreviewed',
    'D3 迁移后顶层按派生回落 draft（预期行为）');
$rvLegacy->patch('RV-000001', array('reviewer' => 'migrated'));
$legacyOnDisk = json_decode((string)@file_get_contents($legacyFile), true);
bglx_rev3_assert(isset($legacyOnDisk['schema']) && $legacyOnDisk['schema'] === 2,
    'D4 写回统一 schema=2');

// 旧记录完全没有 corrections 时，首次逐条判定在同一次锁内补齐四类行
// 服务层固定使用 bglx_rev_store() 单例路径，测试也须写该文件
$emptyFile = bglx_rev_store()->path();
@unlink($emptyFile);
@unlink($emptyFile . '.lock');
$emptyDoc = array(
    'schema' => 1, 'updated_at' => bglx_now(), 'next_id' => 2,
    'items' => array(array(
        'id' => 'RV-000001', 'invoke_id' => 'empty-1', 'date' => '2026-01-01',
        'created_at' => bglx_now(), 'updated_at' => bglx_now(),
        'status' => 'draft', 'judgment' => 'unreviewed', 'version' => 1,
        'recognized_items' => array(array('partNo' => 'A-123', 'process' => '焊结', 'unit' => '个')),
        'recognized_fields' => array('note' => '末'), 'corrections' => array(),
    )),
    'audit' => array(),
);
@file_put_contents($emptyFile, json_encode($emptyDoc, JSON_UNESCAPED_UNICODE));
$rvEmpty = bglx_rev_store();
$emptyJudged = bglx_rev_judge_row('RV-000001', '0:partNo', 'correct', '', 1,
    array('item' => $rvEmpty->get('RV-000001'), 'reviewer' => 'unit-test'));
$emptyRow = bglx_rev3_find_row($emptyJudged['item']['corrections'], '0:partNo');
bglx_rev3_assert(count($emptyJudged['item']['corrections']) === 4 && !empty($emptyRow)
    && $emptyRow['judgment'] === 'correct',
    'D5 无纠错行的旧记录首次判定自动补齐四类行（同锁）',
    json_encode($emptyJudged['item']['corrections'], JSON_UNESCAPED_UNICODE));

/* ---------- E. 归档：先写后移 + 审计守恒 ---------- */
echo "\n--- E. 90 天归档与审计守恒 ---\n";
$archiveFile3 = $DATA3 . '/archive_test.json';
@unlink($archiveFile3);
@unlink($archiveFile3 . '.lock');
$oldTime = date('Y-m-d H:i:s', time() - 91 * 86400);
$oldMonth = substr($oldTime, 0, 7);
$oldDoc3 = array(
    'schema' => 2, 'updated_at' => bglx_now(), 'next_id' => 2,
    'items' => array(array(
        'id' => 'RV-000001', 'invoke_id' => 'old-1', 'date' => substr($oldTime, 0, 10),
        'created_at' => $oldTime, 'updated_at' => $oldTime,
        'status' => 'reviewed', 'judgment' => 'wrong', 'judged_at' => $oldTime, 'version' => 2,
        'corrections' => array(bglx_rev3_row('wrong', $oldTime)),
    )),
    'audit' => array(array(
        'ts' => $oldTime, 'action' => 'review_judge_row', 'item_id' => 'RV-000001',
        'reviewer' => 'unit-test', 'detail' => 'row=0:partNo → wrong',
    )),
);
@file_put_contents($archiveFile3, json_encode($oldDoc3, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$rvArchive = new BglxReviewStore($archiveFile3);

// 归档目录不可用时：不移除主文档
@file_put_contents($DATA3 . '/blocked-dir', 'not a directory');
$blocked = $rvArchive->pruneExpired(90, bglx_rev_safe_archive_dir('blocked-dir/'));
bglx_rev3_assert(!empty($blocked['error']) && $rvArchive->get('RV-000001') !== null,
    'E1 归档写失败时主文档不移除（先写后移）', json_encode($blocked, JSON_UNESCAPED_UNICODE));

$archiveResult = $rvArchive->pruneExpired(90, bglx_rev_safe_archive_dir('review-archive/'));
bglx_rev3_assert((int)$archiveResult['archived'] === 1, 'E2 91 天前的记录被归档',
    json_encode($archiveResult, JSON_UNESCAPED_UNICODE));
$archPath = $DATA3 . '/review-archive/review-' . $oldMonth . '.json';
bglx_rev3_assert(is_file($archPath), 'E3 归档文件按 created_at 月份命名');
$archDoc = is_file($archPath) ? json_decode((string)@file_get_contents($archPath), true) : array();
bglx_rev3_assert(isset($archDoc['items'][0]['id']) && $archDoc['items'][0]['id'] === 'RV-000001',
    'E4 归档文件含完整条目原文');
bglx_rev3_assert(isset($archDoc['audit'][0]['action'])
    && $archDoc['audit'][0]['action'] === 'review_judge_row',
    'E5 关联审计随条目搬入归档，未硬删');
bglx_rev3_assert($rvArchive->get('RV-000001') === null, 'E6 主文档已无该超期记录');
$mainAfter = json_decode((string)@file_get_contents($archiveFile3), true);
$mainActions = array();
foreach ($mainAfter['audit'] as $a) {
    $mainActions[] = $a['action'];
}
bglx_rev3_assert(in_array('retention_archive', $mainActions, true),
    'E7 主文档写入 retention_archive 审计');
$archAuditCount = isset($archDoc['audit']) ? count($archDoc['audit']) : 0;
bglx_rev3_assert($archAuditCount === count($oldDoc3['audit'])
    && count($mainAfter['items']) === 0,
    'E8 归档前后审计条数守恒、条目只存一份',
    '归档审计 ' . $archAuditCount . ' / 原 ' . count($oldDoc3['audit']));
$disabled = $rvArchive->pruneExpired(0, bglx_rev_safe_archive_dir('review-archive/'));
bglx_rev3_assert(!empty($disabled['skipped']), 'E9 retentionDays=0 禁用到期归档（Q5）');

/* ---------- F. 归档目录穿越 ---------- */
echo "\n--- F. 归档目录穿越防护 ---\n";
bglx_rev3_assert(bglx_rev_safe_archive_dir('../escape/') === null, 'F1 .. 相对穿越被拒');
bglx_rev3_assert(bglx_rev_safe_archive_dir('/etc') === null, 'F2 data 目录外绝对路径被拒');
bglx_rev3_assert(bglx_rev_safe_archive_dir('review-archive/') === $DATA3 . '/review-archive',
    'F3 默认相对目录解析正确');

/* ---------- G. AI / 入库仅判错行 ---------- */
echo "\n--- G. AI 与人工入库闸门 ---\n";
$promptRows = array(
    array('field' => 'partNo', 'wrong_value' => 'KEEP-WRONG-VALUE', 'correct_value' => 'KEEP-CORRECT-VALUE',
          'judgment' => 'correct'),
    array('field' => 'process', 'wrong_value' => '焊结', 'correct_value' => '焊接', 'judgment' => 'wrong'),
);
$messages = bglx_rev_ai_prompt($promptRows, 10);
$userContent = $messages[1]['content'];
bglx_rev3_assert(strpos($userContent, 'KEEP-CORRECT-VALUE') === false
    && strpos($userContent, 'KEEP-WRONG-VALUE') === false,
    'G1 判对行的正确值/错误值都不进 AI prompt');
bglx_rev3_assert(strpos($userContent, '焊接') !== false && strpos($userContent, '焊结') !== false,
    'G2 仅判错行进入 AI prompt');
$wrongOnly = bglx_rev_ai_wrong_rows($promptRows);
bglx_rev3_assert(count($wrongOnly) === 1 && $wrongOnly[0]['field'] === 'process',
    'G3 ai_organizer 内部二次断言只留判错行');

$lexV3 = $DATA3 . '/v3-lexicon.json';
@unlink($lexV3);
@unlink($lexV3 . '.lock');
$storeV3 = new BglxStore($lexV3);
$importV3 = bglx_rev_import_decisions(array(
    array('field' => 'partNo', 'wrong_value' => 'SKIP-ME', 'correct_value' => 'NOT-ADDED', 'judgment' => 'correct'),
    array('field' => 'process', 'wrong_value' => '焊结', 'correct_value' => '焊接', 'judgment' => 'wrong'),
), 'RV-000001', array('origin' => 'manual', 'store' => $storeV3, 'only_wrong' => true));
bglx_rev3_assert((int)$importV3['added'] === 1, 'G4 人工路径仅判错行入库，added=1',
    json_encode($importV3, JSON_UNESCAPED_UNICODE));
$lexAll = $storeV3->all(array('enabled' => ''));
$standards = array_map(function ($e) {
    return $e['standard'];
}, $lexAll);
bglx_rev3_assert(!in_array('NOT-ADDED', $standards, true) && in_array('焊接', $standards, true),
    'G5 判对行不产生词条');
$noneWrong = bglx_rev_import_decisions(array(array(
    'field' => 'partNo', 'wrong_value' => 'A', 'correct_value' => 'B', 'judgment' => 'correct',
)), 'RV-000001', array('origin' => 'manual', 'store' => $storeV3, 'only_wrong' => true));
bglx_rev3_assert((int)$noneWrong['added'] === 0, 'G6 全判正确时新增词条为 0');

/* ---------- H. 写后收尾 ---------- */
echo "\n--- H. 写后归档收尾 ---\n";
$tail3 = bglx_rev_after_write();
bglx_rev3_assert(is_array($tail3) && isset($tail3['archive'], $tail3['warnings'])
    && empty($tail3['warnings']), 'H1 after_write 返回归档结果与告警，正常路径无告警',
    json_encode($tail3, JSON_UNESCAPED_UNICODE));
bglx_rev3_assert(method_exists(bglx_rev_store(), 'capacityTrim')
    && method_exists(bglx_rev_store(), 'pruneExpired'), 'H2 writeDoc 容量裁剪已拆出为独立方法');

echo "\n=== 结果：通过 " . $pass3 . " 项，失败 " . $fail3 . " 项 ===\n";
exit($fail3 > 0 ? 1 : 0);
