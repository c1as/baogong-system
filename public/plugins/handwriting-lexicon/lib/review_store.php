<?php
/**
 * 审查记录持久层：BglxReviewStore
 *
 * 与既有词库 BglxStore 完全隔离的独立文档 data/handwriting_review.json：
 *  - 独立 .lock 伴生锁，flock + tmp rename 原子写；
 *  - version 乐观锁，防止两名审查员并发改判互相覆盖；
 *  - audit 上限 5000，超出按月归档到 data/review-archive/。
 *
 * 任何审查数据的读写都必须经过本类，禁止直接操作 JSON 文件。
 */

if (!defined('BGLX_REVIEW_STORE_LOADED')) {
    define('BGLX_REVIEW_STORE_LOADED', 1);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';

if (!class_exists('BglxReviewLockException')) {
    /** 审查记录锁/版本冲突，API 层映射为 40901 */
    class BglxReviewLockException extends RuntimeException
    {
    }
}
if (!defined('BGLX_REV_AUDIT_LIMIT')) {
    define('BGLX_REV_AUDIT_LIMIT', 5000);
    define('BGLX_REV_ITEM_LIMIT', 20000);
    define('BGLX_REV_ARCHIVE_BATCH', 1000);
}

/**
 * 规范化一条审查记录。
 *
 * @param array      $data 输入字段
 * @param array|null $base 已有记录；提供时视为更新，保留 id/created_at
 * @return array 完整审查记录
 */
function bglx_rev_normalize_item($data, $base = null)
{
    $data = is_array($data) ? $data : array();
    $now = bglx_now();
    $item = is_array($base) ? $base : array(
        'id' => '',
        'date' => '',
        'invoke_id' => '',
        'image_path' => '',
        'manual_image_path' => '',
        'engine' => '',
        'duration' => 0,
        'recognized_fields' => array(),
        'recognized_items' => array(),
        'trace' => array(),
        'judgment' => 'unreviewed',
        'judged_at' => '',
        'corrections' => array(),
        'import_path' => '',
        'ai_candidates' => array(),
        'status' => 'draft',
        'reviewer' => '',
        'created_at' => $now,
        'updated_at' => $now,
        'version' => 1,
    );

    $simple = array('date', 'invoke_id', 'image_path', 'manual_image_path', 'engine',
                    'judged_at', 'import_path', 'status', 'reviewer');
    foreach ($simple as $k) {
        if (array_key_exists($k, $data)) {
            $item[$k] = bglx_cut(trim((string)$data[$k]), 300);
        }
    }
    if (array_key_exists('duration', $data)) {
        $item['duration'] = max(0, (int)$data['duration']);
    }
    foreach (array('recognized_fields', 'trace') as $k) {
        if (array_key_exists($k, $data)) {
            $item[$k] = is_array($data[$k]) ? $data[$k] : array();
        }
    }
    if (array_key_exists('recognized_items', $data)) {
        $list = is_array($data['recognized_items']) ? $data['recognized_items'] : array();
        $item['recognized_items'] = array_slice($list, 0, 200);
    }
    if (array_key_exists('corrections', $data)) {
        $item['corrections'] = bglx_rev_normalize_corrections($data['corrections']);
    }
    if (array_key_exists('ai_candidates', $data)) {
        $candidates = is_array($data['ai_candidates']) ? $data['ai_candidates'] : array();
        $item['ai_candidates'] = array_slice($candidates, 0, 200);
    }
    if (array_key_exists('judgment', $data)) {
        $judgment = (string)$data['judgment'];
        $item['judgment'] = in_array($judgment, array('correct', 'wrong', 'unreviewed'), true)
            ? $judgment : 'unreviewed';
    }
    if (!isset($item['status']) || !in_array((string)$item['status'], array('draft', 'reviewed', 'imported'), true)) {
        $item['status'] = 'draft';
    }
    $item['updated_at'] = $now;
    return $item;
}

/**
 * 规范化纠错数组：只保留白名单字段并截断文本，防止存储型 XSS 与超大载荷。
 *
 * @param mixed $corrections 输入纠错列表
 * @return array
 */
function bglx_rev_normalize_corrections($corrections)
{
    $list = is_array($corrections) ? $corrections : array();
    $out = array();
    foreach (array_slice($list, 0, 200) as $c) {
        if (!is_array($c)) {
            continue;
        }
        $extra = isset($c['extra_variants']) ? bglx_variants($c['extra_variants']) : array();
        $out[] = array(
            'field' => bglx_cut(trim((string)(isset($c['field']) ? $c['field'] : '')), 40),
            'wrong_value' => bglx_cut(trim((string)(isset($c['wrong_value']) ? $c['wrong_value'] : '')), 200),
            'correct_value' => bglx_cut(trim((string)(isset($c['correct_value']) ? $c['correct_value'] : '')), 200),
            'extra_variants' => array_slice(array_map(function ($v) {
                return bglx_cut((string)$v, 120);
            }, $extra), 0, 20),
            'type' => bglx_cut(trim((string)(isset($c['type']) ? $c['type'] : '')), 40),
            'confirmed' => !empty($c['confirmed']),
            'note' => bglx_cut(trim((string)(isset($c['note']) ? $c['note'] : '')), 300),
        );
    }
    return $out;
}

/**
 * 审查记录存储（JSON 文件实现）。
 */
class BglxReviewStore
{
    /** 允许通过 patch 修改的字段；id / created_at / version 永不可直接写 */
    const PATCH_FIELDS = array(
        'date', 'invoke_id', 'image_path', 'manual_image_path', 'engine', 'duration',
        'recognized_fields', 'recognized_items', 'trace', 'judgment', 'judged_at',
        'corrections', 'import_path', 'ai_candidates', 'status', 'reviewer',
    );

    /** @var string 审查记录文件绝对路径 */
    private $file;

    /** @var string 锁文件绝对路径 */
    private $lockFile;

    /**
     * @param string|null $file 文件路径；null 时按 data 目录解析
     */
    public function __construct($file = null)
    {
        $this->file = ($file !== null && (string)$file !== '')
            ? (string)$file : bglx_rev_data_path('handwriting_review.json');
        $this->lockFile = $this->file . '.lock';
    }

    /**
     * 返回当前审查记录文件路径。
     *
     * @return string
     */
    public function path()
    {
        return $this->file;
    }

    /**
     * 空文档。
     *
     * @return array
     */
    private function emptyDoc()
    {
        return array(
            'schema' => 1,
            'updated_at' => bglx_now(),
            'next_id' => 1,
            'items' => array(),
            'audit' => array(),
        );
    }

    /**
     * 获取文件锁。
     *
     * @param bool $exclusive 是否写锁
     * @return resource
     * @throws BglxReviewLockException 锁超时
     * @throws RuntimeException 无法打开锁文件
     */
    private function lock($exclusive)
    {
        $dir = dirname($this->lockFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fp = @fopen($this->lockFile, 'c');
        if ($fp === false) {
            throw new RuntimeException('无法打开审查记录锁文件：' . $this->lockFile);
        }
        $deadline = microtime(true) + (float)BGLX_LOCK_TIMEOUT;
        while (microtime(true) < $deadline) {
            if (@flock($fp, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
                return $fp;
            }
            usleep(20000);
        }
        @fclose($fp);
        throw new BglxReviewLockException('审查记录文件锁超时，请稍后重试');
    }

    /**
     * @param resource $fp 锁句柄
     * @return void
     */
    private function unlock($fp)
    {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }

    /**
     * 读取完整文档（调用方持锁）。
     *
     * @return array
     * @throws RuntimeException JSON 损坏
     */
    private function readDoc()
    {
        if (!is_file($this->file)) {
            return $this->emptyDoc();
        }
        $raw = @file_get_contents($this->file);
        if ($raw === false) {
            throw new RuntimeException('无法读取审查记录文件：' . $this->file);
        }
        if (trim($raw) === '') {
            return $this->emptyDoc();
        }
        $doc = json_decode($raw, true);
        if (!is_array($doc) || !isset($doc['items']) || !is_array($doc['items'])) {
            throw new RuntimeException('审查记录 JSON 格式损坏，请检查 ' . $this->file);
        }
        if (!isset($doc['schema'])) {
            $doc['schema'] = 1;
        }
        if (!isset($doc['updated_at']) || !is_string($doc['updated_at'])) {
            $doc['updated_at'] = bglx_now();
        }
        if (!isset($doc['next_id']) || !is_numeric($doc['next_id'])) {
            $max = 0;
            foreach ($doc['items'] as $it) {
                if (is_array($it) && isset($it['id'])
                    && preg_match('/^RV-(\d{6})$/', (string)$it['id'], $m)) {
                    $max = max($max, (int)$m[1]);
                }
            }
            $doc['next_id'] = $max + 1;
        }
        $doc['next_id'] = max(1, (int)$doc['next_id']);
        if (!isset($doc['audit']) || !is_array($doc['audit'])) {
            $doc['audit'] = array();
        }
        return $doc;
    }

    /**
     * 原子写回（调用方持写锁）。内含条目容量裁剪。
     *
     * @param array $doc 完整文档
     * @return void
     * @throws RuntimeException 写失败
     */
    private function writeDoc($doc)
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建审查数据目录：' . $dir);
        }
        $count = count($doc['items']);
        if ($count > BGLX_REV_ITEM_LIMIT) {
            $doc['items'] = array_slice($doc['items'], $count - BGLX_REV_ITEM_LIMIT);
            $doc['audit'][] = array(
                'ts' => bglx_now(), 'action' => 'capacity_trim', 'item_id' => '',
                'reviewer' => 'system', 'detail' => '审查记录超过 ' . BGLX_REV_ITEM_LIMIT . ' 条，已裁剪最早条目',
            );
        }
        $doc['updated_at'] = bglx_now();
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('审查记录 JSON 编码失败：' . json_last_error_msg());
        }
        $tmp = $this->file . '.tmp.' . getmypid() . '.' . mt_rand(10000, 99999);
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('无法写入审查记录临时文件');
        }
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new RuntimeException('无法原子替换审查记录文件：' . $this->file);
        }
        @chmod($this->file, 0664);
    }

    /**
     * 分配下一条审查 ID（调用方持写锁）。
     *
     * @param array $doc 当前文档
     * @return string
     */
    private function nextId(&$doc)
    {
        $n = max((int)$doc['next_id'], 1);
        $doc['next_id'] = $n + 1;
        return sprintf('RV-%06d', $n);
    }

    /**
     * 查询审查记录。
     *
     * @param array $filters 支持 judgment / status / date / keyword
     * @return array
     */
    public function all($filters = array())
    {
        $filters = is_array($filters) ? $filters : array();
        $fp = $this->lock(false);
        try {
            $doc = $this->readDoc();
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);

        $judgment = trim((string)(isset($filters['judgment']) ? $filters['judgment'] : ''));
        $status = trim((string)(isset($filters['status']) ? $filters['status'] : ''));
        $date = trim((string)(isset($filters['date']) ? $filters['date'] : ''));
        $keyword = trim((string)(isset($filters['keyword']) ? $filters['keyword'] : ''));

        $out = array();
        foreach ($doc['items'] as $it) {
            if (!is_array($it)) {
                continue;
            }
            if ($judgment !== '' && (string)(isset($it['judgment']) ? $it['judgment'] : '') !== $judgment) {
                continue;
            }
            if ($status !== '' && (string)(isset($it['status']) ? $it['status'] : '') !== $status) {
                continue;
            }
            if ($date !== '' && (string)(isset($it['date']) ? $it['date'] : '') !== $date) {
                continue;
            }
            if ($keyword !== '') {
                $hay = implode(' ', array(
                    (string)(isset($it['id']) ? $it['id'] : ''),
                    (string)(isset($it['invoke_id']) ? $it['invoke_id'] : ''),
                    (string)(isset($it['reviewer']) ? $it['reviewer'] : ''),
                ));
                if (stripos($hay, $keyword) === false) {
                    continue;
                }
            }
            $out[] = $it;
        }
        usort($out, function ($a, $b) {
            return strcmp(
                (string)(isset($b['updated_at']) ? $b['updated_at'] : ''),
                (string)(isset($a['updated_at']) ? $a['updated_at'] : '')
            );
        });
        return $out;
    }

    /**
     * 按 ID 读取单条。
     *
     * @param string $id 审查 ID
     * @return array|null
     */
    public function get($id)
    {
        $id = (string)$id;
        $fp = $this->lock(false);
        try {
            $doc = $this->readDoc();
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        foreach ($doc['items'] as $it) {
            if (is_array($it) && isset($it['id']) && (string)$it['id'] === $id) {
                return $it;
            }
        }
        return null;
    }

    /**
     * 按 invoke_id 查找记录。
     *
     * @param string $invokeId 调用 ID
     * @return array|null
     */
    public function findByInvokeId($invokeId)
    {
        $invokeId = (string)$invokeId;
        if ($invokeId === '') {
            return null;
        }
        $fp = $this->lock(false);
        try {
            $doc = $this->readDoc();
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        foreach ($doc['items'] as $it) {
            if (is_array($it) && isset($it['invoke_id']) && (string)$it['invoke_id'] === $invokeId) {
                return $it;
            }
        }
        return null;
    }

    /**
     * 构建 invoke_id → 判定摘要 索引，供日志只读投影时标注状态。
     *
     * @return array
     */
    public function invokeIndex()
    {
        $fp = $this->lock(false);
        try {
            $doc = $this->readDoc();
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        $index = array();
        foreach ($doc['items'] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $invoke = isset($it['invoke_id']) ? (string)$it['invoke_id'] : '';
            if ($invoke === '') {
                continue;
            }
            $index[$invoke] = array(
                'id' => isset($it['id']) ? (string)$it['id'] : '',
                'judgment' => isset($it['judgment']) ? (string)$it['judgment'] : 'unreviewed',
                'status' => isset($it['status']) ? (string)$it['status'] : 'draft',
                'version' => isset($it['version']) ? (int)$it['version'] : 1,
            );
        }
        return $index;
    }

    /**
     * 新增或更新审查记录（存在 id 时更新并递增 version）。
     *
     * @param array $data 记录字段；可带 id / version
     * @return array 落盘后的记录
     * @throws BglxReviewLockException 版本冲突
     */
    public function upsert($data)
    {
        $data = is_array($data) ? $data : array();
        $id = isset($data['id']) ? trim((string)$data['id']) : '';
        $fp = $this->lock(true);
        try {
            $doc = $this->readDoc();
            $found = -1;
            if ($id !== '') {
                foreach ($doc['items'] as $i => $it) {
                    if (is_array($it) && isset($it['id']) && (string)$it['id'] === $id) {
                        $found = $i;
                        break;
                    }
                }
            }
            $base = $found >= 0 ? $doc['items'][$found] : null;
            if ($base !== null && array_key_exists('version', $data)) {
                $expected = (int)$data['version'];
                $actual = isset($base['version']) ? (int)$base['version'] : 1;
                if ($expected !== $actual) {
                    throw new BglxReviewLockException('审查记录已被更新（版本冲突），请刷新后重试');
                }
            }
            $item = bglx_rev_normalize_item($data, $base);
            $item['version'] = $base === null ? 1 : ((int)(isset($base['version']) ? $base['version'] : 1) + 1);
            if ($base !== null) {
                $item['id'] = (string)$base['id'];
                $item['created_at'] = isset($base['created_at']) ? (string)$base['created_at'] : bglx_now();
            } else {
                $item['id'] = $this->nextId($doc);
                $item['created_at'] = bglx_now();
            }
            if ($found >= 0) {
                $doc['items'][$found] = $item;
            } else {
                $doc['items'][] = $item;
            }
            $this->writeDoc($doc);
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        return $item;
    }

    /**
     * 局部更新：带 version 时执行乐观锁比对。
     *
     * @param string $id    审查 ID
     * @param array  $patch 补丁字段
     * @return array 更新后的记录
     * @throws BglxReviewLockException 版本冲突
     * @throws RuntimeException 记录不存在
     */
    public function patch($id, $patch)
    {
        $id = (string)$id;
        $patch = is_array($patch) ? $patch : array();
        $fp = $this->lock(true);
        try {
            $doc = $this->readDoc();
            $found = -1;
            foreach ($doc['items'] as $i => $it) {
                if (is_array($it) && isset($it['id']) && (string)$it['id'] === $id) {
                    $found = $i;
                    break;
                }
            }
            if ($found < 0) {
                throw new RuntimeException('审查记录不存在：' . $id);
            }
            $base = $doc['items'][$found];
            if (array_key_exists('version', $patch)) {
                $expected = (int)$patch['version'];
                $actual = isset($base['version']) ? (int)$base['version'] : 1;
                if ($expected !== $actual) {
                    throw new BglxReviewLockException('审查记录已被其他审查员更新（版本冲突），请刷新后重试');
                }
            }
            $allowed = array();
            foreach (self::PATCH_FIELDS as $field) {
                if (array_key_exists($field, $patch)) {
                    $allowed[$field] = $patch[$field];
                }
            }
            $item = bglx_rev_normalize_item($allowed, $base);
            $item['id'] = (string)$base['id'];
            $item['created_at'] = isset($base['created_at']) ? (string)$base['created_at'] : bglx_now();
            $item['version'] = (int)(isset($base['version']) ? $base['version'] : 1) + 1;
            $doc['items'][$found] = $item;
            $this->writeDoc($doc);
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        return $item;
    }

    /**
     * 删除审查记录（草稿撤销）。
     *
     * @param string $id 审查 ID
     * @return bool
     */
    public function remove($id)
    {
        $id = (string)$id;
        $fp = $this->lock(true);
        try {
            $doc = $this->readDoc();
            $found = -1;
            foreach ($doc['items'] as $i => $it) {
                if (is_array($it) && isset($it['id']) && (string)$it['id'] === $id) {
                    $found = $i;
                    break;
                }
            }
            if ($found < 0) {
                $this->unlock($fp);
                return false;
            }
            array_splice($doc['items'], $found, 1);
            $this->writeDoc($doc);
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        return true;
    }

    /**
     * 审查统计卡数据。
     *
     * @return array
     */
    public function stats()
    {
        $fp = $this->lock(false);
        try {
            $doc = $this->readDoc();
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        $out = array(
            'unreviewed' => 0, 'correct' => 0, 'wrong' => 0,
            'draft' => 0, 'reviewed' => 0, 'imported' => 0, 'total' => 0,
        );
        foreach ($doc['items'] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $out['total']++;
            $j = isset($it['judgment']) ? (string)$it['judgment'] : 'unreviewed';
            if (isset($out[$j])) {
                $out[$j]++;
            }
            $s = isset($it['status']) ? (string)$it['status'] : 'draft';
            if (isset($out[$s])) {
                $out[$s]++;
            }
        }
        return $out;
    }

    /**
     * 追加一条审计记录，并在超限时按月归档。
     *
     * @param string $action   动作名
     * @param string $itemId   审查 ID
     * @param string $reviewer 脱敏操作人
     * @param string $detail   摘要（自动截断到 160 字）
     * @return void
     */
    public function appendAudit($action, $itemId, $reviewer, $detail)
    {
        $entry = array(
            'ts' => bglx_now(),
            'action' => bglx_cut((string)$action, 60),
            'item_id' => bglx_cut((string)$itemId, 40),
            'reviewer' => bglx_cut((string)$reviewer, 60),
            'detail' => bglx_cut((string)$detail, 160),
        );
        $fp = $this->lock(true);
        try {
            $doc = $this->readDoc();
            $doc['audit'][] = $entry;
            $this->writeDoc($doc);
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        $this->archiveIfNeeded();
    }

    /**
     * audit 超过 5000 条时，把最早 1000 条按月写入归档文件并移出主文档。
     *
     * @return void
     */
    public function archiveIfNeeded()
    {
        $fp = $this->lock(true);
        $old = array();
        try {
            $doc = $this->readDoc();
            if (count($doc['audit']) > BGLX_REV_AUDIT_LIMIT) {
                $old = array_splice($doc['audit'], 0, BGLX_REV_ARCHIVE_BATCH);
                $this->writeDoc($doc);
            }
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        if (!$old) {
            return;
        }

        $groups = array();
        foreach ($old as $a) {
            $month = isset($a['ts']) ? substr((string)$a['ts'], 0, 7) : '';
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                $month = 'unknown';
            }
            if (!isset($groups[$month])) {
                $groups[$month] = array();
            }
            $groups[$month][] = $a;
        }
        $dir = bglx_rev_data_path('review-archive');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        foreach ($groups as $month => $rows) {
            $file = $dir . '/review-' . $month . '.json';
            $lck = @fopen($file . '.lock', 'c');
            if ($lck === false) {
                continue;
            }
            @flock($lck, LOCK_EX);
            $existing = array();
            if (is_file($file)) {
                $decoded = json_decode((string)@file_get_contents($file), true);
                if (is_array($decoded)) {
                    $existing = $decoded;
                }
            }
            $merged = array_merge($existing, $rows);
            $json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json !== false) {
                $tmp = $file . '.tmp.' . getmypid();
                if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
                    @rename($tmp, $file);
                } else {
                    @unlink($tmp);
                }
            }
            @flock($lck, LOCK_UN);
            @fclose($lck);
        }
    }
}

/**
 * BglxReviewStore 单例。
 *
 * @return BglxReviewStore
 */
function bglx_rev_store()
{
    static $store = null;
    if ($store === null) {
        $store = new BglxReviewStore();
    }
    return $store;
}
