<?php
/**
 * 逐条判定服务（v3 核心）
 *
 * 设计不变量（架构 §1.1 / §5）：
 *  - corrections[].judgment 是唯一事实源；顶层 judgment / status / judged_at 只是服务端派生投影；
 *  - 所有写操作走「行级改判 → 重算派生 → 顶层回写 → version++ → 原子写」的单锁事务；
 *  - 归档是写后收尾，失败只告警，不回滚本次判定。
 *
 * 依赖：review_store.php（bootstrap 中必须先加载本文件的上一行）。
 * 纯 PHP 零依赖，函数前缀统一 bglx_rev_*。
 */

if (!defined('BGLX_REV_JUDGE_LOADED')) {
    define('BGLX_REV_JUDGE_LOADED', 1);
}

if (!class_exists('BglxReviewImmutableException')) {
    /** 已入库终态 / 整图批量关闭：API 层映射为 41005 */
    class BglxReviewImmutableException extends RuntimeException
    {
    }
}

/**
 * 顶层投影派生（判定表，架构 §5.1，按优先级短路）。
 *
 * 1) imported 且未显式重审 → 保持 imported 及入库前投影；
 * 2) 无行或存在 unreviewed → draft / unreviewed；
 * 3) 全部 correct（≥1 行） → reviewed / correct；
 * 4) 全部已判且存在 wrong → reviewed / wrong（wrong 优先聚合）。
 *
 * judged_at 取所有非空行 judged_at 的最大值。兜底回退旧顶层值（PRD §7.5）。
 *
 * @param array $corrections 纠错行列表（容忍 schema 1 旧行）
 * @param array $current     当前记录（提供旧顶层值与 imported 状态）
 * @return array array('status','judgment','judged_at')
 */
function bglx_rev_derive_projection($corrections, $current)
{
    $current = is_array($current) ? $current : array();
    $reopened = !empty($current['reopened']);
    if (!$reopened && isset($current['status']) && (string)$current['status'] === 'imported') {
        return array(
            'status' => 'imported',
            'judgment' => (isset($current['judgment']) && (string)$current['judgment'] !== '')
                ? (string)$current['judgment'] : 'wrong',
            'judged_at' => isset($current['judged_at']) ? (string)$current['judged_at'] : '',
        );
    }

    $rows = is_array($corrections) ? $corrections : array();
    $total = count($rows);
    $unreviewed = 0;
    $wrong = 0;
    $judgedAt = '';
    foreach ($rows as $row) {
        $row = is_array($row) ? $row : array();
        $j = isset($row['judgment']) ? (string)$row['judgment'] : 'unreviewed';
        if (!in_array($j, array('correct', 'wrong', 'unreviewed'), true)) {
            $j = 'unreviewed'; // 容忍 schema 1 旧行
        }
        if ($j === 'unreviewed') {
            $unreviewed++;
        } elseif ($j === 'wrong') {
            $wrong++;
        }
        $t = isset($row['judged_at']) ? (string)$row['judged_at'] : '';
        if ($t !== '' && strcmp($t, $judgedAt) > 0) {
            $judgedAt = $t;
        }
    }

    if ($total === 0 || $unreviewed > 0) {
        $status = 'draft';
        $judgment = 'unreviewed';
    } elseif ($wrong > 0) {
        $status = 'reviewed';
        $judgment = 'wrong';
    } else {
        $status = 'reviewed';
        $judgment = 'correct';
    }
    if ($status === null || $status === '') {
        $status = isset($current['status']) && $current['status'] !== '' ? (string)$current['status'] : 'draft';
    }
    if ($judgment === null || $judgment === '') {
        $judgment = isset($current['judgment']) && $current['judgment'] !== ''
            ? (string)$current['judgment'] : 'unreviewed';
    }
    return array('status' => $status, 'judgment' => $judgment, 'judged_at' => $judgedAt);
}

/**
 * 派生函数短名（PRD §7.3 / 架构 §4.3 约定的对外别名）。
 *
 * @param array $corrections 纠错行列表
 * @param array $current     当前记录
 * @return array
 */
function bglx_rev_derive($corrections, $current = array())
{
    return bglx_rev_derive_projection($corrections, $current);
}

/**
 * 归档目录穿越校验：仅接受相对 data 目录的路径，或 data 目录前缀下的绝对路径。
 *
 * 禁止 .. 段、空字节；不做存在性校验（存在性由 pruneExpired / check.php 处理）。
 *
 * @param string $dir 配置中的目录
 * @return string|null 规范化绝对路径；非法返回 null
 */
function bglx_rev_safe_archive_dir($dir)
{
    $dir = str_replace('\\', '/', trim((string)$dir));
    if ($dir === '') {
        $dir = 'review-archive/';
    }
    if (strpos($dir, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $dir)) {
        return null;
    }
    $data = rtrim(str_replace('\\', '/', bglx_data_dir()), '/');
    if ($dir[0] === '/' || preg_match('#^[A-Za-z]:/#', $dir)) {
        $abs = rtrim($dir, '/');
        $prefix = $data . '/';
        return (strpos($abs . '/', $prefix) === 0) ? $abs : null;
    }
    return rtrim($data . '/' . ltrim($dir, '/'), '/');
}

/**
 * row_key 合法性：形如 <段>:<段>（0:partNo / note:char / manual:1）。
 *
 * 段内仅允许字母数字、下划线与连字符，杜绝 DOM/路径注入。
 * 手工新增纠错行使用 manual:N 前缀，避免与明细自动行键冲突。
 *
 * @param string $key 行键
 * @return bool
 */
function bglx_rev_row_key_valid($key)
{
    $key = (string)$key;
    return preg_match('/^[A-Za-z0-9_-]{1,40}:[A-Za-z0-9_-]{1,40}$/', $key) === 1;
}

/**
 * 构造一条未审纠错行（四类共用，前后端同构）。
 *
 * @param string $key    行键
 * @param int    $index  明细下标，形近字为 -1
 * @param string $field  字段名
 * @param string $type   词条 type
 * @param string $value  识别错误值
 * @return array
 */
function bglx_rev_new_row($key, $index, $field, $type, $value)
{
    return array(
        'row_key' => (string)$key,
        'detail_index' => (int)$index,
        'field' => (string)$field,
        'type' => (string)$type,
        'wrong_value' => bglx_cut(trim((string)$value), 200),
        'judgment' => 'unreviewed',
        'judged_at' => '',
        'correct_value' => '',
        'extra_variants' => array(),
        'confirmed' => false,
        'note' => '',
    );
}

/**
 * 服务端四类全覆盖行推断（与前端 inferCorrectionRows 同构，共享 row_key 契约）。
 *
 * 遍历全部明细行，按 partNo/process/unit 生成；形近字取记录级 note 单行（Q2 方案 A）；
 * qty 明确不生成。按 row_key 幂等合并 saved，绝不覆盖已判结果；保留手工行。
 *
 * @param array $items  已归一化的识别明细
 * @param array $fields 记录级字段
 * @param array $saved  已保存纠错行
 * @return array 最多 200 行
 */
function bglx_rev_infer_rows($items, $fields, $saved = array())
{
    $items = is_array($items) ? array_slice($items, 0, 200) : array();
    $fields = is_array($fields) ? $fields : array();
    $saved = is_array($saved) ? $saved : array();

    $seeds = array();
    foreach ($saved as $s) {
        if (is_array($s) && isset($s['row_key'])) {
            $seeds[(string)$s['row_key']] = $s;
        }
    }
    $used = array();
    $out = array();
    $defs = array(
        array('partNo', 'drawing_alias'),
        array('process', 'process_synonym'),
        array('unit', 'unit_synonym'),
    );
    foreach ($items as $i => $detail) {
        if (!is_array($detail)) {
            continue;
        }
        foreach ($defs as $def) {
            $field = $def[0];
            $type = $def[1];
            $value = isset($detail[$field]) ? (string)$detail[$field] : '';
            if (trim($value) === '' && isset($fields[$field])) {
                $value = (string)$fields[$field];
            }
            if (trim($value) === '') {
                continue; // 空值跳过该行（G1 按「有值字段」计）
            }
            $key = $i . ':' . $field;
            $out[] = isset($seeds[$key]) ? $seeds[$key] : bglx_rev_new_row($key, $i, $field, $type, $value);
            $used[$key] = true;
        }
    }
    $note = trim((string)(isset($fields['note']) ? $fields['note'] : ''));
    if ($note !== '') {
        $out[] = isset($seeds['note:char']) ? $seeds['note:char']
            : bglx_rev_new_row('note:char', -1, 'char', 'char_confusion', $note);
        $used['note:char'] = true;
    }
    // 保留手工添加行与「明细已删但已判」的历史行，不丢数据
    foreach ($saved as $s) {
        if (!is_array($s) || !isset($s['row_key'])) {
            continue;
        }
        $key = (string)$s['row_key'];
        if (!isset($used[$key])) {
            $out[] = $s;
            $used[$key] = true;
        }
    }
    return array_slice(bglx_rev_normalize_corrections($out, count($items)), 0, 200);
}

/**
 * 逐条判定服务：守卫 → 单锁事务 → 返回新投影。
 *
 * @param string $id           审查 ID
 * @param string $rowKey       行键
 * @param string $judgment     correct / wrong / unreviewed
 * @param string $correctValue 判错时的正确值
 * @param int    $version      乐观锁版本
 * @param array  $ctx          array('item','reviewer','note','extra_variants')
 * @return array array('item','version','projection')
 * @throws InvalidArgumentException         参数/行键非法 → 40001
 * @throws BglxValidationException          判错未填值 → 42201
 * @throws BglxReviewImmutableException     已入库终态 → 41005
 * @throws BglxReviewLockException          版本冲突 → 40901
 */
function bglx_rev_judge_row($id, $rowKey, $judgment, $correctValue = '', $version = 0, $ctx = array())
{
    $ctx = is_array($ctx) ? $ctx : array();
    $judgment = (string)$judgment;
    if (!in_array($judgment, array('correct', 'wrong', 'unreviewed'), true)) {
        throw new InvalidArgumentException('判定值不合法（应为 correct / wrong / unreviewed）');
    }
    $key = trim((string)$rowKey);
    if (!bglx_rev_row_key_valid($key)) {
        throw new InvalidArgumentException('row_key 不合法：' . bglx_cut($key, 80));
    }
    $correct = trim((string)$correctValue);
    if ($judgment === 'wrong' && $correct === '') {
        throw new BglxValidationException(array('correct_value' => '判错必须填写正确值'), '判错必须填写正确值');
    }
    if ($judgment !== 'wrong') {
        $correct = ''; // 服务端强清空，杜绝判对行残留正确值污染 AI/入库
    }

    $item = isset($ctx['item']) && is_array($ctx['item']) ? $ctx['item'] : bglx_rev_store()->get($id);
    if (!is_array($item)) {
        throw new RuntimeException('审查记录不存在：' . (string)$id);
    }
    if (isset($item['status']) && (string)$item['status'] === 'imported') {
        throw new BglxReviewImmutableException('已入库记录为只读终态，不能直接改判');
    }
    $found = false;
    $rows = isset($item['corrections']) && is_array($item['corrections']) ? $item['corrections'] : array();
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['row_key']) && (string)$row['row_key'] === $key) {
            $found = true;
            break;
        }
    }
    // 空纠错行的存量记录由 store::judgeRow 在同一次锁内补齐，此处只拒绝「有行但键不存在」
    if ($rows && !$found) {
        throw new InvalidArgumentException('row_key 不存在：' . bglx_cut($key, 80));
    }

    $patch = array(
        'judgment' => $judgment,
        'correct_value' => $correct,
        'reviewer' => isset($ctx['reviewer']) ? (string)$ctx['reviewer'] : bglx_rev_reviewer(),
    );
    if (array_key_exists('note', $ctx)) {
        $patch['note'] = $ctx['note'];
    }
    if (array_key_exists('extra_variants', $ctx)) {
        $patch['extra_variants'] = $ctx['extra_variants'];
    }
    $updated = bglx_rev_store()->judgeRow($item['id'], $key, $patch, $version);
    return array(
        'item' => $updated,
        'version' => (int)$updated['version'],
        'projection' => bglx_rev_derive_projection($updated['corrections'], $updated),
    );
}

/**
 * 批量快捷判定（整图降级入口，默认关闭）：逐行守卫后一次锁内写入。
 *
 * @param string $id      审查 ID
 * @param array  $rows    行补丁列表
 * @param int    $version 乐观锁版本
 * @param array  $ctx     array('item','reviewer')
 * @return array array('item','version','projection','applied')
 * @throws InvalidArgumentException     参数非法
 * @throws BglxValidationException      判错未填值
 * @throws BglxReviewImmutableException 已入库终态
 * @throws BglxReviewLockException      版本冲突
 */
function bglx_rev_judge_batch($id, $rows, $version, $ctx = array())
{
    $ctx = is_array($ctx) ? $ctx : array();
    $rows = is_array($rows) ? $rows : array();
    if (!$rows) {
        throw new InvalidArgumentException('rows 不能为空');
    }
    $item = isset($ctx['item']) && is_array($ctx['item']) ? $ctx['item'] : bglx_rev_store()->get($id);
    if (!is_array($item)) {
        throw new RuntimeException('审查记录不存在：' . (string)$id);
    }
    if (isset($item['status']) && (string)$item['status'] === 'imported') {
        throw new BglxReviewImmutableException('已入库记录为只读终态，不能批量判定');
    }
    $prepared = array();
    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException('rows 元素必须为对象');
        }
        $key = trim((string)(isset($row['row_key']) ? $row['row_key'] : ''));
        if (!bglx_rev_row_key_valid($key)) {
            throw new InvalidArgumentException('row_key 不合法：' . bglx_cut($key, 80));
        }
        $j = isset($row['judgment']) ? (string)$row['judgment'] : '';
        if (!in_array($j, array('correct', 'wrong', 'unreviewed'), true)) {
            throw new InvalidArgumentException('判定值不合法（应为 correct / wrong / unreviewed）');
        }
        $correct = trim((string)(isset($row['correct_value']) ? $row['correct_value'] : ''));
        if ($j === 'wrong' && $correct === '') {
            throw new BglxValidationException(array('correct_value' => '判错必须填写正确值'), '判错必须填写正确值');
        }
        if ($j !== 'wrong') {
            $correct = '';
        }
        $one = array('row_key' => $key, 'judgment' => $j, 'correct_value' => $correct);
        if (array_key_exists('note', $row)) {
            $one['note'] = $row['note'];
        }
        if (array_key_exists('extra_variants', $row)) {
            $one['extra_variants'] = $row['extra_variants'];
        }
        $prepared[] = $one;
    }
    $reviewer = isset($ctx['reviewer']) ? (string)$ctx['reviewer'] : bglx_rev_reviewer();
    $result = bglx_rev_store()->judgeBatch($item['id'], $prepared, $version, $reviewer);
    return array(
        'item' => $result['item'],
        'version' => (int)$result['item']['version'],
        'projection' => bglx_rev_derive_projection($result['item']['corrections'], $result['item']),
        'applied' => (int)$result['applied'],
    );
}

/**
 * 写后收尾：先到期归档 → 再 audit 按月归档 → 最后条数裁剪（顺序不可颠倒）。
 *
 * 归档失败只收集 warnings，不改变主流程 code=0；retentionDays=0 / 开关关闭时跳过到期归档。
 *
 * @return array array('archive'=>归档结果, 'warnings'=>告警字符串列表)
 */
function bglx_rev_after_write()
{
    $cfg = bglx_rev_archive_config();
    $warnings = array();
    $archive = array('skipped' => true, 'archived' => 0);
    if ($cfg['enabled'] && $cfg['retention_days'] > 0) {
        $dir = bglx_rev_safe_archive_dir($cfg['dir']);
        if ($dir === null) {
            $warnings[] = '归档目录非法，已跳得到期归档';
        } else {
            $archive = bglx_rev_store()->pruneExpired($cfg['retention_days'], $dir);
            if (!empty($archive['error'])) {
                $warnings[] = (string)$archive['error'];
            }
            if (!empty($archive['warnings'])) {
                $warnings = array_merge($warnings, $archive['warnings']);
            }
        }
    }
    bglx_rev_store()->archiveIfNeeded();
    bglx_rev_store()->capacityTrim();
    return array('archive' => $archive, 'warnings' => $warnings);
}
