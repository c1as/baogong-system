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
        $detailCount = isset($item['recognized_items']) && is_array($item['recognized_items'])
            ? count($item['recognized_items']) : 0;
        $legacyTime = isset($base['updated_at']) ? (string)$base['updated_at'] : $now;
        $item['corrections'] = bglx_rev_normalize_corrections($data['corrections'], $detailCount, $legacyTime);
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
 * 规范化纠错数组：白名单字段 + 文本截断 + v3 行级事实字段 + 存量迁移。
 *
 * 存量规则（schema 1 → 2，架构 §5.4）：
 *  ① 无 row_key 时按「明细序号:字段」补齐，char 行固定 note:char；
 *  ② 无合法 judgment 时按 correct_value 是否非空推断 wrong / unreviewed；
 *  ③ 无 judged_at 的旧 wrong 行回填记录 updated_at；
 *  ④ 按 row_key 去重（保留首次出现），防止旧数据重复键。
 * correct_value 是事实字段：只有 wrong 行允许非空，correct/unreviewed 强制清空。
 *
 * @param mixed  $corrections 输入纠错列表
 * @param int    $detailCount 关联明细条数（仅用于上限提示，不参与生成）
 * @param string $updatedAt   所属记录的 updated_at，供旧行 judged_at 回填
 * @return array
 */
function bglx_rev_normalize_corrections($corrections, $detailCount = 0, $updatedAt = '')
{
    $list = is_array($corrections) ? $corrections : array();
    $updatedAt = trim((string)$updatedAt);
    $fallbackTime = $updatedAt !== '' ? $updatedAt : bglx_now();
    $out = array();
    $seen = array();

    foreach (array_slice($list, 0, 200) as $c) {
        if (!is_array($c)) {
            continue;
        }
        $field = bglx_cut(trim((string)(isset($c['field']) ? $c['field'] : '')), 40);
        $rawKey = trim((string)(isset($c['row_key']) ? $c['row_key'] : ''));
        $rawIndex = isset($c['detail_index']) && is_numeric($c['detail_index'])
            ? (int)$c['detail_index'] : null;

        if ($field === 'char' || $rawKey === 'note:char') {
            $rowKey = 'note:char';
            $detailIndex = -1;
        } elseif ($rawKey !== '') {
            $rowKey = bglx_cut($rawKey, 80);
            if ($rawIndex !== null) {
                $detailIndex = $rawIndex;
            } elseif (preg_match('/^(-?\d+):/', $rowKey, $m)) {
                $detailIndex = (int)$m[1];
            } else {
                $detailIndex = -1;
            }
        } else {
            $detailIndex = ($rawIndex !== null && $rawIndex >= 0) ? $rawIndex : 0;
            $rowKey = $detailIndex . ':' . ($field !== '' ? $field : 'partNo');
        }
        if (isset($seen[$rowKey])) {
            continue;
        }
        $seen[$rowKey] = true;

        $correct = bglx_cut(trim((string)(isset($c['correct_value']) ? $c['correct_value'] : '')), 200);
        $judgment = isset($c['judgment']) ? (string)$c['judgment'] : '';
        if (!in_array($judgment, array('correct', 'wrong', 'unreviewed'), true)) {
            $judgment = $correct !== '' ? 'wrong' : 'unreviewed';
        }
        if ($judgment !== 'wrong') {
            $correct = '';
        }
        $judgedAt = trim((string)(isset($c['judged_at']) ? $c['judged_at'] : ''));
        if ($judgedAt === '' && $judgment === 'wrong') {
            $judgedAt = $fallbackTime;
        }

        $extra = isset($c['extra_variants']) ? bglx_variants($c['extra_variants']) : array();
        $out[] = array(
            'row_key' => $rowKey,
            'detail_index' => $detailIndex,
            'field' => $field,
            'wrong_value' => bglx_cut(trim((string)(isset($c['wrong_value']) ? $c['wrong_value'] : '')), 200),
            'judgment' => $judgment,
            'judged_at' => bglx_cut($judgedAt, 40),
            'correct_value' => $correct,
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
            'schema' => 2,
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
        // 缺失 / schema=1 一律在内存中迁移到 2，写回时 writeDoc 统一落 schema=2
        if (!isset($doc['schema']) || (int)$doc['schema'] < 2) {
            $doc['schema'] = 2;
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
        $this->migrateDocItems($doc);
        return $doc;
    }

    /**
     * 内存态迁移纠错行并刷新顶层派生投影（schema 1 → 2）。
     *
     * 只对已有 corrections 键且非空行的记录派生：无纠错行的旧记录保留原顶层值，
     * 避免纯顶层 patch（如 review_link / 老判定）被派生意外改写。
     *
     * @param array $doc 文档引用
     * @return void
     */
    private function migrateDocItems(&$doc)
    {
        foreach ($doc['items'] as $i => $item) {
            if (!is_array($item) || !array_key_exists('corrections', $item)) {
                continue;
            }
            $details = isset($item['recognized_items']) && is_array($item['recognized_items'])
                ? $item['recognized_items'] : array();
            $legacyTime = isset($item['updated_at']) ? (string)$item['updated_at'] : '';
            $item['corrections'] = bglx_rev_normalize_corrections($item['corrections'], count($details), $legacyTime);
            if (!empty($item['corrections']) && function_exists('bglx_rev_derive_projection')) {
                $this->writeProjection($item, $item['corrections']);
            }
            $doc['items'][$i] = $item;
        }
    }

    /**
     * 按行级事实源重算顶层投影（status / judgment / judged_at）。
     *
     * @param array $item        审查记录引用
     * @param array $corrections 纠错行列表
     * @return void
     */
    private function writeProjection(&$item, $corrections)
    {
        if (!function_exists('bglx_rev_derive_projection')) {
            return;
        }
        $projection = bglx_rev_derive_projection($corrections, $item);
        $item['judgment'] = $projection['judgment'];
        $item['status'] = $projection['status'];
        $item['judged_at'] = $projection['judged_at'];
    }

    /**
     * 纯原子写回（调用方持写锁）：tmp + rename + chmod，不再夹带容量裁剪。
     *
     * 容量裁剪外移到 capacityTrim()，由 bglx_rev_after_write() 在到期归档之后调用，
     * 保证「先按天归档 → 后条数裁剪」的顺序（架构 §7.1）。
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
        $doc['schema'] = 2;
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
            if (!empty($item['corrections'])) {
                $this->writeProjection($item, $item['corrections']);
            }
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
            if (!empty($item['corrections'])) {
                $this->writeProjection($item, $item['corrections']);
            }
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
     * 逐条判定单锁事务：改行 → 重算派生 → 顶层回写 → version++ → 原子写，全在同一把 LOCK_EX 内。
     *
     * row_key 不参与版本冲突判定；version 不匹配抛 BglxReviewLockException（API 映射 40901）。
     * 审计与记录在同一锁内写入，避免「判了行但审计丢了」的中间态。
     *
     * @param string $id              审查 ID
     * @param string $rowKey          命中行键
     * @param array  $patch           array('judgment','correct_value','note','extra_variants','reviewer')
     * @param int    $expectedVersion 乐观锁版本
     * @return array 更新后的记录
     * @throws BglxReviewLockException 版本冲突
     * @throws RuntimeException         记录/行不存在
     */
    public function judgeRow($id, $rowKey, $patch, $expectedVersion)
    {
        $id = (string)$id;
        $rowKey = (string)$rowKey;
        $patch = is_array($patch) ? $patch : array();
        $fp = $this->lock(true);
        try {
            $doc = $this->readDoc();
            $idx = -1;
            foreach ($doc['items'] as $i => $it) {
                if (is_array($it) && isset($it['id']) && (string)$it['id'] === $id) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx < 0) {
                throw new RuntimeException('审查记录不存在：' . $id);
            }
            $base = $doc['items'][$idx];
            $actual = isset($base['version']) ? (int)$base['version'] : 1;
            if ((int)$expectedVersion !== $actual) {
                throw new BglxReviewLockException('审查记录已被其他审查员更新（版本冲突），请刷新后重试');
            }
            $rowIdx = -1;
            $rows = isset($base['corrections']) && is_array($base['corrections']) ? $base['corrections'] : array();
            // 存量记录没有纠错行时，在同一次锁内按四类规则补齐，保证 row_key 可用且不丢版本一致性
            if (!$rows && function_exists('bglx_rev_infer_rows')) {
                $details = isset($base['recognized_items']) && is_array($base['recognized_items'])
                    ? $base['recognized_items'] : array();
                $fields = isset($base['recognized_fields']) && is_array($base['recognized_fields'])
                    ? $base['recognized_fields'] : array();
                $rows = bglx_rev_infer_rows($details, $fields, array());
                $base['corrections'] = $rows;
            }
            foreach ($rows as $i => $row) {
                if (is_array($row) && isset($row['row_key']) && (string)$row['row_key'] === $rowKey) {
                    $rowIdx = $i;
                    break;
                }
            }
            if ($rowIdx < 0) {
                throw new InvalidArgumentException('row_key 不存在：' . bglx_cut($rowKey, 80));
            }
            $judgment = isset($patch['judgment']) ? (string)$patch['judgment'] : 'unreviewed';
            $base['corrections'][$rowIdx] = $this->applyRowPatch($base['corrections'][$rowIdx], $judgment, $patch);
            $this->writeProjection($base, $base['corrections']);
            $base['version'] = $actual + 1;
            $base['updated_at'] = bglx_now();
            $doc['items'][$idx] = $base;
            $doc['audit'][] = $this->makeAudit(
                'review_judge_row', $id,
                isset($patch['reviewer']) ? (string)$patch['reviewer'] : 'unknown',
                'row=' . $rowKey . ' → ' . $judgment
                    . (isset($patch['correct_value']) && trim((string)$patch['correct_value']) !== ''
                        ? '（正确值：' . bglx_cut(trim((string)$patch['correct_value']), 60) . '）' : '')
            );
            $this->writeDoc($doc);
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        return $base;
    }

    /**
     * 批量快捷判定：一次锁内改所有行、派生一次、version++ 一次（架构 §5.3）。
     *
     * 语义等价于逐条，但避免 N 次锁导致版本抖动；每行都必须先通过服务层守卫。
     *
     * @param string $id              审查 ID
     * @param array  $rows            行补丁列表 array('row_key','judgment','correct_value',...)
     * @param int    $expectedVersion 乐观锁版本
     * @param string $reviewer        脱敏操作人
     * @return array array('item'=>记录, 'applied'=>实际写入行数)
     * @throws BglxReviewLockException 版本冲突
     * @throws RuntimeException         记录不存在
     */
    public function judgeBatch($id, $rows, $expectedVersion, $reviewer = 'unknown')
    {
        $id = (string)$id;
        $rows = is_array($rows) ? $rows : array();
        $fp = $this->lock(true);
        try {
            $doc = $this->readDoc();
            $idx = -1;
            foreach ($doc['items'] as $i => $it) {
                if (is_array($it) && isset($it['id']) && (string)$it['id'] === $id) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx < 0) {
                throw new RuntimeException('审查记录不存在：' . $id);
            }
            $base = $doc['items'][$idx];
            $actual = isset($base['version']) ? (int)$base['version'] : 1;
            if ((int)$expectedVersion !== $actual) {
                throw new BglxReviewLockException('审查记录已被其他审查员更新（版本冲突），请刷新后重试');
            }
            $index = array();
            foreach ($base['corrections'] as $i => $row) {
                if (is_array($row) && isset($row['row_key'])) {
                    $index[(string)$row['row_key']] = $i;
                }
            }
            $applied = 0;
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['row_key'])) {
                    continue;
                }
                $key = (string)$row['row_key'];
                if (!isset($index[$key])) {
                    continue;
                }
                $j = isset($row['judgment']) ? (string)$row['judgment'] : 'unreviewed';
                $base['corrections'][$index[$key]] = $this->applyRowPatch($base['corrections'][$index[$key]], $j, $row);
                $applied++;
            }
            $this->writeProjection($base, $base['corrections']);
            $base['version'] = $actual + 1;
            $base['updated_at'] = bglx_now();
            $doc['items'][$idx] = $base;
            $doc['audit'][] = $this->makeAudit('review_judge_batch', $id, (string)$reviewer,
                '批量判定 ' . $applied . ' 行');
            $this->writeDoc($doc);
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        return array('item' => $base, 'applied' => $applied);
    }

    /**
     * 把一次行判定补丁写到指定纠错行（correct/unreviewed 强制清空正确值）。
     *
     * @param array  $row      原行
     * @param string $judgment 判定枚举
     * @param array  $patch    补丁
     * @return array 新行
     */
    private function applyRowPatch($row, $judgment, $patch)
    {
        $row = is_array($row) ? $row : array();
        $row['judgment'] = $judgment;
        $row['judged_at'] = ($judgment === 'unreviewed') ? '' : bglx_now();
        $correct = ($judgment === 'wrong')
            ? bglx_cut(trim((string)(isset($patch['correct_value']) ? $patch['correct_value'] : '')), 200)
            : '';
        $row['correct_value'] = $correct;
        if (array_key_exists('note', $patch)) {
            $row['note'] = bglx_cut(trim((string)$patch['note']), 300);
        }
        if (array_key_exists('extra_variants', $patch)) {
            $row['extra_variants'] = array_slice(array_map(function ($v) {
                return bglx_cut((string)$v, 120);
            }, bglx_variants($patch['extra_variants'])), 0, 20);
        }
        return $row;
    }

    /**
     * 生成统一审计条目。
     *
     * @param string $action   动作
     * @param string $itemId   记录 ID
     * @param string $reviewer 操作人
     * @param string $detail   摘要
     * @return array
     */
    private function makeAudit($action, $itemId, $reviewer, $detail)
    {
        return array(
            'ts' => bglx_now(),
            'action' => bglx_cut((string)$action, 60),
            'item_id' => bglx_cut((string)$itemId, 40),
            'reviewer' => bglx_cut((string)$reviewer, 60),
            'detail' => bglx_cut((string)$detail, 160),
        );
    }

    /**
     * 到期归档：先写归档文件成功，再从主文档移除；两阶段之间二次加锁复核。
     *
     * 基准取 max(created_at, updated_at)；按 created_at 月份分组；单批 ≤ BGLX_REV_ARCHIVE_BATCH。
     * 归档写入失败只返回 warnings，绝不移除主文档、绝不回滚已完成的判定。
     *
     * @param int    $retentionDays 保留天数；≤0 时禁用
     * @param string $archiveDir    已通过穿越校验的归档目录绝对路径
     * @return array
     */
    public function pruneExpired($retentionDays, $archiveDir)
    {
        $retentionDays = (int)$retentionDays;
        if ($retentionDays <= 0 || !bglx_rev_setting_bool('reviewArchiveEnabled', true)) {
            return array('skipped' => true, 'archived' => 0);
        }
        $dir = function_exists('bglx_rev_safe_archive_dir')
            ? bglx_rev_safe_archive_dir($archiveDir) : null;
        if ($dir === null || $dir === '') {
            return array('error' => '归档目录非法', 'archived' => 0);
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return array('error' => '归档目录不可写', 'archived' => 0);
        }
        $cutoff = strtotime('-' . $retentionDays . ' days');
        if ($cutoff === false) {
            $cutoff = time() - $retentionDays * 86400;
        }

        /* 阶段 1：共享锁只读挑候选 */
        $fp = $this->lock(false);
        try {
            $doc = $this->readDoc();
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);

        $expired = array();
        foreach ($doc['items'] as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }
            $t1 = strtotime((string)(isset($item['created_at']) ? $item['created_at'] : ''));
            $t2 = strtotime((string)(isset($item['updated_at']) ? $item['updated_at'] : ''));
            $stamp = max($t1 === false ? 0 : $t1, $t2 === false ? 0 : $t2);
            if ($stamp > 0 && $stamp < $cutoff) {
                $expired[] = $item;
            }
        }
        if (!$expired) {
            return array('archived' => 0);
        }
        $expired = array_slice($expired, 0, BGLX_REV_ARCHIVE_BATCH);
        $ids = array();
        $groups = array();
        foreach ($expired as $row) {
            $ids[(string)$row['id']] = true;
            $month = substr((string)(isset($row['created_at']) ? $row['created_at'] : ''), 0, 7);
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                $month = 'unknown';
            }
            if (!isset($groups[$month])) {
                $groups[$month] = array();
            }
            $groups[$month][] = $row;
        }

        /* 阶段 2：先写归档文件（含关联审计），失败即止、主文档不动 */
        foreach ($groups as $month => $rows) {
            $auditRows = array();
            foreach ($doc['audit'] as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $aid = isset($a['item_id']) ? (string)$a['item_id'] : '';
                $am = substr((string)(isset($a['ts']) ? $a['ts'] : ''), 0, 7);
                if (isset($ids[$aid]) && $am === $month) {
                    $auditRows[] = $a;
                }
            }
            $payload = array(
                'schema' => 2, 'updated_at' => bglx_now(),
                'items' => array_values($rows), 'audit' => $auditRows,
            );
            if (!$this->archiveWrite($dir . '/review-' . $month . '.json', $payload)) {
                error_log('[bglx-rev] 归档失败：' . $dir . '/review-' . $month . '.json');
                return array(
                    'error' => '归档写入失败', 'archived' => 0,
                    'warnings' => array('归档失败，判定已保存，稍后重试'),
                );
            }
        }

        /* 阶段 3：二次加写锁，复核仍超期后移除并记录归档审计 */
        $fp = $this->lock(true);
        $removed = 0;
        $movedAudit = 0;
        try {
            $doc = $this->readDoc();
            foreach ($expired as $row) {
                $rid = (string)$row['id'];
                $idx = -1;
                foreach ($doc['items'] as $i => $it) {
                    if (is_array($it) && isset($it['id']) && (string)$it['id'] === $rid) {
                        $idx = $i;
                        break;
                    }
                }
                if ($idx < 0) {
                    continue;
                }
                $cur = $doc['items'][$idx];
                $t1 = strtotime((string)(isset($cur['created_at']) ? $cur['created_at'] : ''));
                $t2 = strtotime((string)(isset($cur['updated_at']) ? $cur['updated_at'] : ''));
                $stamp = max($t1 === false ? 0 : $t1, $t2 === false ? 0 : $t2);
                if ($stamp === 0 || $stamp >= $cutoff) {
                    continue; // 已被他人改动，放弃归档
                }
                $month = substr((string)(isset($cur['created_at']) ? $cur['created_at'] : ''), 0, 7);
                if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                    $month = 'unknown';
                }
                $aRows = array();
                $kept = array();
                foreach ($doc['audit'] as $a) {
                    if (is_array($a) && isset($a['item_id']) && (string)$a['item_id'] === $rid) {
                        $aRows[] = $a;
                    } else {
                        $kept[] = $a;
                    }
                }
                if ($aRows) {
                    $file = $dir . '/review-' . $month . '.json';
                    $payload = $this->archiveRead($file);
                    $payload['audit'] = array_merge($payload['audit'], $aRows);
                    $this->archiveWrite($file, $payload);
                    $movedAudit += count($aRows);
                }
                $doc['audit'] = $kept;
                array_splice($doc['items'], $idx, 1);
                $removed++;
            }
            $doc['audit'][] = $this->makeAudit('retention_archive', '', 'system',
                '归档 ' . $removed . ' 条，月份 ' . implode(',', array_keys($groups)));
            $doc['schema'] = 2;
            $this->writeDoc($doc);
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        return array(
            'archived' => $removed,
            'audit_moved' => $movedAudit,
            'months' => array_keys($groups),
        );
    }

    /**
     * 读取归档文件：兼容 audit 纯数组（archiveIfNeeded 旧格式）与 {items,audit} 新格式。
     *
     * @param string $file 归档文件绝对路径
     * @return array array('schema','updated_at','items','audit')
     */
    private function archiveRead($file)
    {
        $base = array('schema' => 2, 'updated_at' => bglx_now(), 'items' => array(), 'audit' => array());
        if (!is_file($file)) {
            return $base;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return $base;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $base;
        }
        if ($decoded === array_values($decoded)) {
            $base['schema'] = 1;      // 旧的纯 audit 列表
            $base['audit'] = $decoded;
            return $base;
        }
        $base['schema'] = isset($decoded['schema']) ? (int)$decoded['schema'] : 1;
        if (isset($decoded['updated_at']) && is_string($decoded['updated_at'])) {
            $base['updated_at'] = $decoded['updated_at'];
        }
        $base['items'] = isset($decoded['items']) && is_array($decoded['items']) ? $decoded['items'] : array();
        $base['audit'] = isset($decoded['audit']) && is_array($decoded['audit']) ? $decoded['audit'] : array();
        return $base;
    }

    /**
     * 写归档文件：独立 .lock + 按 item.id 去重合并 items + audit 全量追加 + tmp rename。
     *
     * @param string $file    归档文件绝对路径
     * @param array  $payload {schema,updated_at,items,audit}
     * @return bool 成功 true
     */
    private function archiveWrite($file, $payload)
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $lck = @fopen($file . '.lock', 'c');
        if ($lck === false || !@flock($lck, LOCK_EX)) {
            if ($lck !== false) {
                @fclose($lck);
            }
            return false;
        }
        $ok = false;
        try {
            $old = $this->archiveRead($file);
            $byId = array();
            foreach ($old['items'] as $it) {
                if (is_array($it) && isset($it['id'])) {
                    $byId[(string)$it['id']] = $it;
                }
            }
            $payload = is_array($payload) ? $payload : array();
            $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : array();
            foreach ($items as $it) {
                if (is_array($it) && isset($it['id'])) {
                    $byId[(string)$it['id']] = $it; // 后写覆盖，重复归档幂等
                }
            }
            $audit = array_merge($old['audit'],
                isset($payload['audit']) && is_array($payload['audit']) ? $payload['audit'] : array());
            // 审计按内容去重：阶段 2 已写入关联审计时，阶段 3 的补齐不会重复计数（幂等）
            $seenAudit = array();
            $uniqueAudit = array();
            foreach ($audit as $a) {
                $sig = is_array($a) ? json_encode($a, JSON_UNESCAPED_UNICODE) : (string)$a;
                if (!isset($seenAudit[$sig])) {
                    $seenAudit[$sig] = true;
                    $uniqueAudit[] = $a;
                }
            }
            $audit = $uniqueAudit;
            $out = array(
                'schema' => 2,
                'updated_at' => bglx_now(),
                'items' => array_values($byId),
                'audit' => $audit,
            );
            $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json !== false) {
                $tmp = $file . '.tmp.' . getmypid() . '.' . mt_rand(10000, 99999);
                if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
                    if (@rename($tmp, $file)) {
                        $ok = true;
                    } else {
                        @unlink($tmp);
                    }
                } else {
                    @unlink($tmp);
                }
            }
        } catch (Throwable $e) {
            $ok = false;
        }
        @flock($lck, LOCK_UN);
        @fclose($lck);
        return $ok;
    }

    /**
     * 条目容量裁剪：items > 20000 时硬删最早条目并写审计（最后兜底）。
     *
     * 由 bglx_rev_after_write() 在到期归档之后显式调用，口径与原 writeDoc 内逻辑一致。
     *
     * @return void
     */
    public function capacityTrim()
    {
        $fp = $this->lock(true);
        try {
            $doc = $this->readDoc();
            $count = count($doc['items']);
            if ($count > BGLX_REV_ITEM_LIMIT) {
                $doc['items'] = array_slice($doc['items'], $count - BGLX_REV_ITEM_LIMIT);
                $doc['audit'][] = $this->makeAudit('capacity_trim', '', 'system',
                    '审查记录超过 ' . BGLX_REV_ITEM_LIMIT . ' 条，已裁剪最早条目');
                $this->writeDoc($doc);
            }
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
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
