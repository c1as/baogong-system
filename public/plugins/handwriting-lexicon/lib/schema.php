<?php
/**
 * 词条数据结构定义与校验
 *
 * 四类词条共用同一结构，通过 type 区分。所有写入路径必须先经过
 * bglx_build_entry() 规范化，再经过 bglx_validate_entry() 校验。
 */

if (!defined('BGLX_SCHEMA_LOADED')) {
    define('BGLX_SCHEMA_LOADED', 1);
}

/* 枚举常量：大小写严格，禁止同义词 */
if (!defined('BGLX_TYPES')) {
    define('BGLX_TYPES', array('drawing_alias', 'process_synonym', 'unit_synonym', 'char_confusion'));
}
if (!defined('BGLX_STATUS')) {
    define('BGLX_STATUS', array('active', 'pending', 'rejected'));
}
if (!defined('BGLX_SOURCES')) {
    define('BGLX_SOURCES', array('manual', 'ocr_feedback', 'import', 'learning'));
}

/** 中文安全的字符长度：优先 mbstring，缺失时按 UTF-8 正则计数 */
function bglx_str_len($s)
{
    $s = (string)$s;
    if (function_exists('mb_strlen')) {
        return (int)mb_strlen($s, 'UTF-8');
    }
    return (int)strlen($s);
}

/** 大小写折叠：ASCII 用 strtolower，UTF-8 多字节在有 mbstring 时折叠 */
function bglx_lower($s)
{
    $s = (string)$s;
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    return strtolower($s);
}

/** 按 UTF-8 拆成字符数组（无 mbstring 时逐字节，ASCII 场景等价） */
function bglx_str_split($s)
{
    $s = (string)$s;
    if ($s === '') {
        return array();
    }
    if (function_exists('mb_str_split')) {
        return mb_str_split($s, 1, 'UTF-8');
    }
    if (preg_match_all('/./us', $s, $m)) {
        return $m[0];
    }
    return str_split($s);
}

/**
 * 把多种输入形态统一为变体数组。
 *
 * 接受 array 或「逗号 / 中文逗号 / 顿号 / 空白 / 换行」分隔的字符串。
 *
 * @param mixed $value 原始值
 * @return array 去空去重后的字符串数组
 */
function bglx_variants($value)
{
    if (is_array($value)) {
        $parts = $value;
    } elseif (is_string($value) && trim($value) !== '') {
        $parts = preg_split('/[,，、\s]+/u', trim($value));
    } else {
        $parts = array();
    }
    $out = array();
    foreach ($parts as $p) {
        if (is_array($p) || is_object($p)) {
            continue;
        }
        $p = trim((string)$p);
        if ($p === '' || in_array($p, $out, true)) {
            continue;
        }
        $out[] = $p;
    }
    return array_values($out);
}

/**
 * 生成一条默认词条（未被持久化，无 id）。
 *
 * @return array 完整字段的默认词条
 */
function bglx_default_entry()
{
    $now = bglx_now();
    return array(
        'id' => '',
        'type' => 'drawing_alias',
        'standard' => '',
        'variants' => array(),
        'enabled' => true,
        'status' => 'active',
        'source' => 'manual',
        'confidence' => BGLX_AUTO_MIN,
        'scope' => array('*'),
        'priority' => 10,
        'note' => '',
        'stats' => array('hit' => 0, 'auto_corrected' => 0, 'manual_rewritten' => 0),
        'created_at' => $now,
        'updated_at' => $now,
        'updated_by' => 'admin',
    );
}

/**
 * 由用户输入（新建或补丁）构造规范化词条。
 *
 * @param array      $data 用户输入字段
 * @param array|null $base 已有词条；提供时视为更新，保留 id/created_at/stats
 * @return array 规范化后的完整词条（尚未校验）
 */
function bglx_build_entry($data, $base = null)
{
    $data = is_array($data) ? $data : array();
    $entry = is_array($base) ? $base : bglx_default_entry();

    if (array_key_exists('type', $data)) {
        $entry['type'] = trim((string)$data['type']);
    }
    if (array_key_exists('standard', $data)) {
        $entry['standard'] = trim((string)$data['standard']);
    }
    if (array_key_exists('variants', $data)) {
        $entry['variants'] = bglx_variants($data['variants']);
    }
    if (array_key_exists('enabled', $data)) {
        $entry['enabled'] = bglx_to_bool($data['enabled'], (bool)$entry['enabled']);
    }
    if (array_key_exists('status', $data)) {
        $entry['status'] = trim((string)$data['status']);
    }
    if (array_key_exists('source', $data)) {
        $entry['source'] = trim((string)$data['source']);
    }
    if (array_key_exists('confidence', $data)) {
        $entry['confidence'] = round((float)$data['confidence'], 2);
    }
    if (array_key_exists('scope', $data)) {
        $entry['scope'] = bglx_variants($data['scope']);
        if (!$entry['scope']) {
            $entry['scope'] = array('*');
        }
    }
    if (array_key_exists('priority', $data)) {
        $entry['priority'] = (int)$data['priority'];
    }
    if (array_key_exists('note', $data)) {
        $entry['note'] = bglx_cut((string)$data['note'], 500);
    }
    if (!isset($entry['stats']) || !is_array($entry['stats'])) {
        $entry['stats'] = array('hit' => 0, 'auto_corrected' => 0, 'manual_rewritten' => 0);
    }
    if (array_key_exists('updated_by', $data) && trim((string)$data['updated_by']) !== '') {
        $entry['updated_by'] = bglx_cut(trim((string)$data['updated_by']), 60);
    }

    $now = bglx_now();
    if ($base === null) {
        $entry['id'] = '';
        $entry['created_at'] = $now;
    }
    $entry['updated_at'] = $now;
    return $entry;
}

/** 兼容 1/true/'1'/'on'/'yes' 与 0/false/'0'/'off'/'' 的布尔解析 */
function bglx_to_bool($value, $default = false)
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return $value != 0;
    }
    $s = strtolower(trim((string)$value));
    if ($s === '') {
        return $default;
    }
    if (in_array($s, array('1', 'true', 'on', 'yes', 'y'), true)) {
        return true;
    }
    if (in_array($s, array('0', 'false', 'off', 'no', 'n'), true)) {
        return false;
    }
    return $default;
}

/** 按字符数截断（中文安全） */
function bglx_cut($s, $max)
{
    $s = (string)$s;
    $len = bglx_str_len($s);
    if ($len <= $max) {
        return $s;
    }
    $chars = bglx_str_split($s);
    return implode('', array_slice($chars, 0, $max));
}

/**
 * 全量校验词条。
 *
 * @param array $entry 规范化后的词条
 * @return array 字段名 → 中文错误提示；空数组表示通过
 */
function bglx_validate_entry($entry)
{
    $errors = array();
    if (!is_array($entry)) {
        return array('entry' => '词条必须是对象');
    }

    $id = isset($entry['id']) ? (string)$entry['id'] : '';
    if (!preg_match('/^LX-\d{6}$/', $id)) {
        $errors['id'] = '词条 ID 格式不合法（应为 LX-000001）';
    }

    $type = isset($entry['type']) ? (string)$entry['type'] : '';
    if (!in_array($type, BGLX_TYPES, true)) {
        $errors['type'] = '类型必须是 drawing_alias / process_synonym / unit_synonym / char_confusion 之一';
    }

    $standard = isset($entry['standard']) ? trim((string)$entry['standard']) : '';
    if ($standard === '') {
        $errors['standard'] = '标准值不能为空';
    } elseif (bglx_str_len($standard) > 120) {
        $errors['standard'] = '标准值不能超过 120 个字符';
    }

    $variants = isset($entry['variants']) && is_array($entry['variants']) ? $entry['variants'] : array();
    if (!$variants) {
        $errors['variants'] = '变体至少需要 1 个';
    } else {
        foreach ($variants as $v) {
            $v = trim((string)$v);
            if ($v === '') {
                $errors['variants'] = '变体不能为空字符串';
                break;
            }
            if (bglx_str_len($v) > 120) {
                $errors['variants'] = '单个变体不能超过 120 个字符';
                break;
            }
        }
    }

    // char_confusion 的 standard 与所有 variant 必须是单个字符
    if ($type === 'char_confusion') {
        if ($standard !== '' && bglx_str_len($standard) !== 1) {
            $errors['standard'] = '形近字符映射的标准值必须是单个字符';
        }
        foreach ($variants as $v) {
            if (bglx_str_len((string)$v) !== 1) {
                $errors['variants'] = '形近字符映射的每个变体必须是单个字符';
                break;
            }
        }
    }

    if (!isset($entry['enabled']) || !is_bool($entry['enabled'])) {
        $errors['enabled'] = '启用状态必须是布尔值';
    }

    $status = isset($entry['status']) ? (string)$entry['status'] : '';
    if (!in_array($status, BGLX_STATUS, true)) {
        $errors['status'] = '审核态必须是 active / pending / rejected 之一';
    }

    $source = isset($entry['source']) ? (string)$entry['source'] : '';
    if (!in_array($source, BGLX_SOURCES, true)) {
        $errors['source'] = '来源必须是 manual / ocr_feedback / import / learning 之一';
    }

    $confidence = isset($entry['confidence']) ? $entry['confidence'] : null;
    if (!is_numeric($confidence) || (float)$confidence < 0 || (float)$confidence > 1) {
        $errors['confidence'] = '置信度必须是 0 到 1 之间的数字';
    }

    $scope = isset($entry['scope']) ? $entry['scope'] : null;
    if (!is_array($scope) || !$scope) {
        $errors['scope'] = '作用范围不能为空';
    }

    if (!isset($entry['priority']) || !is_numeric($entry['priority'])) {
        $errors['priority'] = '优先级必须是整数';
    } else {
        $priority = (int)$entry['priority'];
        if ($priority < 0 || $priority > 100) {
            $errors['priority'] = '优先级必须在 0 到 100 之间';
        }
    }

    if (isset($entry['note']) && bglx_str_len((string)$entry['note']) > 500) {
        $errors['note'] = '备注不能超过 500 个字符';
    }

    foreach (array('created_at', 'updated_at') as $k) {
        $v = isset($entry[$k]) ? (string)$entry[$k] : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) {
            $errors[$k] = '时间格式必须为 Y-m-d H:i:s';
        }
    }

    return $errors;
}
