<?php
/**
 * 词库持久层：BglxStore
 *
 * 所有读写集中在本类，上层只依赖其接口。将来迁移到数据库时，
 * 只需替换本文件而不用改动纠错引擎与 HTTP 接口。
 *
 * 并发策略：独立 .lock 伴生文件 flock + 临时文件 rename 原子替换；
 * ID 分配与导入都在写锁内完成。
 */

if (!defined('BGLX_STORE_LOADED')) {
    define('BGLX_STORE_LOADED', 1);
}

/** 字段校验失败：携带字段名 → 中文提示的映射 */
if (!class_exists('BglxValidationException')) {
    class BglxValidationException extends RuntimeException
    {
        /** @var array 字段名 → 中文提示 */
        public $errors = array();

        /**
         * @param array  $errors  校验错误映射
         * @param string $message 摘要信息
         */
        public function __construct($errors, $message = '词条字段校验失败')
        {
            $this->errors = is_array($errors) ? $errors : array('entry' => $message);
            parent::__construct($message);
        }
    }
}

/** 文件锁超时，接口层映射为 40901 */
if (!class_exists('BglxLockException')) {
    class BglxLockException extends RuntimeException
    {
    }
}

/**
 * 手写字词库存储（JSON 文件实现）
 */
class BglxStore
{
    /** @var string 词库文件绝对路径 */
    private $file;

    /** @var string 锁文件绝对路径 */
    private $lockFile;

    /**
     * @param string|null $file 词库文件路径；null 时按配置解析
     */
    public function __construct($file = null)
    {
        $this->file = ($file !== null && (string)$file !== '') ? (string)$file : bglx_store_file();
        $this->lockFile = $this->file . '.lock';
    }

    /**
     * 返回当前词库文件路径（自检与调试用）。
     *
     * @return string
     */
    public function path()
    {
        return $this->file;
    }

    /**
     * 空词库文档。next_id 持久化，保证删除后不复用 ID。
     *
     * @return array
     */
    private function emptyDoc()
    {
        return array(
            'schema' => BGLX_SCHEMA_VERSION,
            'updated_at' => bglx_now(),
            'next_id' => 1,
            'entries' => array(),
        );
    }

    /**
     * 获取文件锁，最多等待 BGLX_LOCK_TIMEOUT 秒。
     *
     * @param bool $exclusive true 为写锁 LOCK_EX，false 为读锁 LOCK_SH
     * @return resource 锁文件句柄
     * @throws BglxLockException 锁超时
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
            throw new RuntimeException('无法打开词库锁文件：' . $this->lockFile);
        }
        $op = $exclusive ? LOCK_EX : LOCK_SH;
        $deadline = microtime(true) + (float)BGLX_LOCK_TIMEOUT;
        while (microtime(true) < $deadline) {
            if (@flock($fp, $op | LOCK_NB)) {
                return $fp;
            }
            usleep(20000);
        }
        fclose($fp);
        throw new BglxLockException('词库文件锁超时（' . BGLX_LOCK_TIMEOUT . ' 秒），请稍后重试');
    }

    /**
     * @param resource $fp 锁文件句柄
     */
    private function unlock($fp)
    {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }

    /**
     * 读取文档（调用方需持有锁）。
     *
     * @return array schema / updated_at / next_id / entries
     * @throws RuntimeException JSON 损坏
     */
    private function readDoc()
    {
        if (!is_file($this->file)) {
            return $this->emptyDoc();
        }
        $raw = @file_get_contents($this->file);
        if ($raw === false) {
            throw new RuntimeException('无法读取词库文件：' . $this->file);
        }
        if (trim($raw) === '') {
            return $this->emptyDoc();
        }
        $doc = json_decode($raw, true);
        if (!is_array($doc) || !isset($doc['entries']) || !is_array($doc['entries'])) {
            throw new RuntimeException('词库 JSON 格式损坏，请检查 ' . $this->file);
        }
        if (!isset($doc['schema'])) {
            $doc['schema'] = BGLX_SCHEMA_VERSION;
        }
        if (!isset($doc['updated_at']) || !is_string($doc['updated_at'])) {
            $doc['updated_at'] = bglx_now();
        }
        if (!isset($doc['next_id']) || !is_numeric($doc['next_id'])) {
            $doc['next_id'] = $this->maxId($doc['entries']) + 1;
        }
        $doc['next_id'] = max(1, (int)$doc['next_id']);
        return $doc;
    }

    /**
     * 扫描现有词条求最大序号（兼容 next_id 缺失的旧文件）。
     *
     * @param array $entries 词条列表
     * @return int
     */
    private function maxId($entries)
    {
        $max = 0;
        foreach ($entries as $e) {
            if (!is_array($e) || !isset($e['id'])) {
                continue;
            }
            if (preg_match('/^LX-(\d{6})$/', (string)$e['id'], $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return $max;
    }

    /**
     * 原子写回文档（调用方需持有写锁）。
     *
     * @param array $doc 完整文档
     * @throws RuntimeException 写失败
     */
    private function writeDoc($doc)
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建数据目录：' . $dir);
        }
        $doc['updated_at'] = bglx_now();
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('词库 JSON 编码失败：' . json_last_error_msg());
        }
        $tmp = $this->file . '.tmp.' . getmypid() . '.' . mt_rand(10000, 99999);
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('无法写入临时文件：' . $tmp);
        }
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new RuntimeException('无法原子替换词库文件：' . $this->file);
        }
        @chmod($this->file, 0664);
        // 写操作后清空纠错引擎的进程内缓存
        if (function_exists('bglx_reload')) {
            bglx_reload();
        }
    }

    /**
     * 查询词条列表（支持类型 / 状态 / 启用 / 关键词 / 作用范围过滤）。
     *
     * @param array $filters 过滤条件
     * @return array 词条数组，按优先级降序、更新时间降序
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

        $type = isset($filters['type']) ? trim((string)$filters['type']) : '';
        $status = isset($filters['status']) ? trim((string)$filters['status']) : '';
        $keyword = isset($filters['keyword']) ? trim((string)$filters['keyword']) : '';
        $plugin = isset($filters['plugin']) ? trim((string)$filters['plugin']) : '';
        $hasEnabled = array_key_exists('enabled', $filters) && $filters['enabled'] !== '' && $filters['enabled'] !== null;
        $enabled = $hasEnabled ? bglx_to_bool($filters['enabled'], false) : false;

        $out = array();
        foreach ($doc['entries'] as $e) {
            if (!is_array($e)) {
                continue;
            }
            if ($type !== '' && (!isset($e['type']) || (string)$e['type'] !== $type)) {
                continue;
            }
            if ($status !== '' && (!isset($e['status']) || (string)$e['status'] !== $status)) {
                continue;
            }
            if ($hasEnabled && (isset($e['enabled']) ? bglx_to_bool($e['enabled'], false) : false) !== $enabled) {
                continue;
            }
            if ($plugin !== '') {
                $scope = isset($e['scope']) && is_array($e['scope']) ? $e['scope'] : array('*');
                if (!in_array('*', $scope, true) && !in_array($plugin, $scope, true)) {
                    continue;
                }
            }
            if ($keyword !== '') {
                $hay = isset($e['standard']) ? (string)$e['standard'] : '';
                if (isset($e['variants']) && is_array($e['variants'])) {
                    $hay .= ' ' . implode(' ', $e['variants']);
                }
                if (stripos($hay, $keyword) === false && strpos($hay, $keyword) === false) {
                    continue;
                }
            }
            $out[] = $e;
        }

        usort($out, function ($a, $b) {
            $pa = isset($a['priority']) ? (int)$a['priority'] : 0;
            $pb = isset($b['priority']) ? (int)$b['priority'] : 0;
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }
            $ta = isset($a['updated_at']) ? (string)$a['updated_at'] : '';
            $tb = isset($b['updated_at']) ? (string)$b['updated_at'] : '';
            return strcmp($tb, $ta);
        });
        return $out;
    }

    /**
     * 按 ID 读取单条词条。
     *
     * @param string $id 词条 ID
     * @return array|null 未找到返回 null
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
        foreach ($doc['entries'] as $e) {
            if (is_array($e) && isset($e['id']) && (string)$e['id'] === $id) {
                return $e;
            }
        }
        return null;
    }

    /**
     * 分配下一个 ID（调用方持有写锁）。
     *
     * @param array $doc 当前文档
     * @return string 形如 LX-000009
     */
    private function nextId(&$doc)
    {
        $n = max((int)$doc['next_id'], $this->maxId($doc['entries']) + 1);
        $doc['next_id'] = $n + 1;
        return sprintf('LX-%06d', $n);
    }

    /**
     * 新建词条：在锁内分配 ID、校验并写回。
     *
     * @param array $data 用户输入
     * @return array 新词条
     * @throws BglxValidationException 字段校验失败
     */
    public function create($data)
    {
        $fp = $this->lock(true);
        try {
            $doc = $this->readDoc();
            $entry = bglx_build_entry($data, null);
            $entry['id'] = $this->nextId($doc);
            $errors = bglx_validate_entry($entry);
            if ($errors) {
                throw new BglxValidationException($errors);
            }
            $doc['entries'][] = $entry;
            $this->writeDoc($doc);
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        return $entry;
    }

    /**
     * 更新词条补丁；id / type / created_at 不允许修改。
     *
     * @param string $id    词条 ID
     * @param array  $patch 可改字段
     * @return array 更新后的词条
     * @throws BglxValidationException 字段校验失败
     * @throws RuntimeException 词条不存在
     */
    public function update($id, $patch)
    {
        $id = (string)$id;
        $patch = is_array($patch) ? $patch : array();
        foreach (array('id', 'type', 'created_at') as $locked) {
            unset($patch[$locked]);
        }
        $fp = $this->lock(true);
        try {
            $doc = $this->readDoc();
            $found = -1;
            foreach ($doc['entries'] as $i => $e) {
                if (is_array($e) && isset($e['id']) && (string)$e['id'] === $id) {
                    $found = $i;
                    break;
                }
            }
            if ($found < 0) {
                throw new RuntimeException('词条不存在：' . $id);
            }
            $entry = bglx_build_entry($patch, $doc['entries'][$found]);
            $entry['id'] = $id;
            $entry['type'] = (string)$doc['entries'][$found]['type'];
            $entry['created_at'] = (string)$doc['entries'][$found]['created_at'];
            $errors = bglx_validate_entry($entry);
            if ($errors) {
                throw new BglxValidationException($errors);
            }
            $doc['entries'][$found] = $entry;
            $this->writeDoc($doc);
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        return $entry;
    }

    /**
     * 删除词条（ID 不复用）。
     *
     * @param string $id 词条 ID
     * @return bool 是否删除成功
     */
    public function remove($id)
    {
        $id = (string)$id;
        $fp = $this->lock(true);
        try {
            $doc = $this->readDoc();
            $found = -1;
            foreach ($doc['entries'] as $i => $e) {
                if (is_array($e) && isset($e['id']) && (string)$e['id'] === $id) {
                    $found = $i;
                    break;
                }
            }
            if ($found < 0) {
                $this->unlock($fp);
                return false;
            }
            array_splice($doc['entries'], $found, 1);
            $this->writeDoc($doc);
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        return true;
    }

    /**
     * 快捷设置启用状态。
     *
     * @param string $id 词条 ID
     * @param mixed  $on 是否启用
     * @return array 更新后词条
     */
    public function setEnabled($id, $on)
    {
        return $this->update($id, array('enabled' => bglx_to_bool($on, false)));
    }

    /**
     * 快捷设置审核态；审核通过时同时启用词条。
     *
     * @param string $id     词条 ID
     * @param string $status active 或 rejected
     * @return array 更新后词条
     */
    public function setStatus($id, $status)
    {
        $status = (string)$status;
        if ($status === 'active') {
            return $this->update($id, array('status' => 'active', 'enabled' => true));
        }
        return $this->update($id, array('status' => $status));
    }

    /**
     * 全库统计。
     *
     * @return array total / enabled / pending / rejected / active_enabled
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

        $out = array('total' => 0, 'enabled' => 0, 'pending' => 0, 'rejected' => 0, 'active_enabled' => 0);
        foreach ($doc['entries'] as $e) {
            if (!is_array($e)) {
                continue;
            }
            $out['total']++;
            $on = isset($e['enabled']) && bglx_to_bool($e['enabled'], false);
            $st = isset($e['status']) ? (string)$e['status'] : '';
            if ($on) {
                $out['enabled']++;
            }
            if ($st === 'pending') {
                $out['pending']++;
            } elseif ($st === 'rejected') {
                $out['rejected']++;
            } elseif ($st === 'active' && $on) {
                $out['active_enabled']++;
            }
        }
        return $out;
    }

    /**
     * 批量导入。整个导入在单个写锁内完成，避免中途被其他进程插入。
     *
     * @param string $content 导入内容
     * @param string $format  json 或 procAlias；csv 未开放
     * @param string $mode    merge 或 replace
     * @return array added / updated / skipped / errors
     * @throws RuntimeException 格式不支持或内容解析失败
     */
    public function import($content, $format, $mode = 'merge')
    {
        $format = strtolower(trim((string)$format));
        if ($format === 'csv') {
            throw new RuntimeException('CSV 导入将在后续版本开放（P1），请使用 JSON 或 procAlias 格式');
        }
        if (!in_array($format, array('json', 'procalias'), true)) {
            throw new RuntimeException('不支持的导入格式：' . $format);
        }
        $mode = ($mode === 'replace') ? 'replace' : 'merge';
        $rows = array();
        $errors = array();

        if ($format === 'json') {
            $doc = json_decode((string)$content, true);
            if (is_array($doc) && isset($doc['entries']) && is_array($doc['entries'])) {
                $rows = $doc['entries'];
            } elseif (is_array($doc)) {
                $rows = $doc;
            } else {
                throw new RuntimeException('JSON 内容无法解析，需为词条数组或含 entries 的对象');
            }
        } else {
            $lines = preg_split('/\r\n|\r|\n/', (string)$content);
            foreach ($lines as $i => $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^[#;]/', $line)) {
                    continue;
                }
                if (!preg_match('/^([^:：=]+)[:：=](.+)$/u', $line, $m)) {
                    $errors[] = array('line' => $i + 1, 'message' => '无法解析，应为「工序 = 别名1, 别名2」');
                    continue;
                }
                $base = trim($m[1]);
                $words = preg_split('/[,，、\s]+/u', trim($m[2]));
                $words[] = $base;
                $rows[] = array(
                    'type' => 'process_synonym',
                    'standard' => $base,
                    'variants' => $words,
                    'confidence' => 0.90,
                    'source' => 'import',
                    'status' => 'active',
                    'enabled' => true,
                    'note' => '从 procAlias 迁移',
                );
            }
        }

        $result = array('added' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => $errors);
        $fp = $this->lock(true);
        try {
            $doc = $this->readDoc();
            if ($mode === 'replace') {
                $doc['entries'] = array();
            }
            $signatures = array();
            foreach ($doc['entries'] as $e) {
                if (is_array($e)) {
                    $signatures[$this->signature($e)] = true;
                }
            }
            foreach ($rows as $i => $row) {
                if (!is_array($row)) {
                    $result['errors'][] = array('line' => $i + 1, 'message' => '词条不是对象');
                    $result['skipped']++;
                    continue;
                }
                $entry = bglx_build_entry($row, null);
                $entry['id'] = $this->nextId($doc);
                $errs = bglx_validate_entry($entry);
                if ($errs) {
                    $result['errors'][] = array('line' => $i + 1, 'message' => implode('；', $errs));
                    $result['skipped']++;
                    continue;
                }
                $sig = $this->signature($entry);
                if (isset($signatures[$sig])) {
                    // merge 模式下同签名视为已存在，不重复入库
                    $result['skipped']++;
                    continue;
                }
                $signatures[$sig] = true;
                $doc['entries'][] = $entry;
                $result['added']++;
            }
            $this->writeDoc($doc);
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        return $result;
    }

    /**
     * 导入去重签名：类型 + 标准值 + 排序后的变体集合。
     *
     * @param array $entry 词条
     * @return string
     */
    private function signature($entry)
    {
        $type = isset($entry['type']) ? (string)$entry['type'] : '';
        $standard = isset($entry['standard']) ? bglx_lower((string)$entry['standard']) : '';
        $variants = isset($entry['variants']) && is_array($entry['variants']) ? $entry['variants'] : array();
        $variants = array_map(function ($v) {
            return bglx_lower((string)$v);
        }, $variants);
        sort($variants, SORT_STRING);
        return md5($type . '|' . $standard . '|' . implode('|', $variants));
    }

    /**
     * 全量导出（可按类型过滤）。
     *
     * @param string $type 类型过滤，空表示全部
     * @return array schema / updated_at / entries
     */
    public function export($type = '')
    {
        $fp = $this->lock(false);
        try {
            $doc = $this->readDoc();
        } catch (Throwable $e) {
            $this->unlock($fp);
            throw $e;
        }
        $this->unlock($fp);
        $entries = array();
        foreach ($doc['entries'] as $e) {
            if (is_array($e) && ($type === '' || (isset($e['type']) && (string)$e['type'] === $type))) {
                $entries[] = $e;
            }
        }
        return array(
            'schema' => $doc['schema'],
            'updated_at' => $doc['updated_at'],
            'entries' => $entries,
        );
    }
}
