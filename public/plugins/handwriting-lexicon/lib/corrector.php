<?php
/**
 * 两级纠错引擎：BglxCorrector
 *
 * 第 1 级：char_confusion 单字符形近归一（如 L→1、O→0、/→7）；
 * 第 2 级：用归一后的字符串精确匹配 drawing_alias / process_synonym / unit_synonym
 *          的 standard 或 variants。
 *
 * 保守原则（硬约束）：归一后没有命中词条，一律原样返回；
 * 绝不因为「字符映射本身」就改写输入。
 */

if (!defined('BGLX_CORRECTOR_LOADED')) {
    define('BGLX_CORRECTOR_LOADED', 1);
}

/**
 * 图号匹配键：大小写不敏感，忽略空格与 -_. 分隔符。
 * 返回值仍使用词条中保存的 standard 原样（如 24971b05 保持小写 b）。
 *
 * @param string $s 原始字符串
 * @return string 归一化键
 */
function bglx_partno_key($s)
{
    $s = (string)$s;
    $s = str_replace(array('－', '—', '–', '‑', '　'), '-', $s);
    $s = preg_replace('/[\s\-_.]/u', '', $s);
    return strtoupper($s === null ? '' : $s);
}

/**
 * 清空纠错引擎的进程内缓存。写操作后由 BglxStore 自动调用。
 */
function bglx_reload()
{
    if (class_exists('BglxCorrector')) {
        BglxCorrector::clearCache();
    }
}

/**
 * 手写字词库纠错器
 */
class BglxCorrector
{
    /** @var array 进程内缓存：文件路径 => array(mtime, size, entries) */
    private static $cache = array();

    /** @var BglxStore 存储实例 */
    private $store;

    /** @var float 自动纠正阈值 */
    private $autoMin;

    /** @var float 人工确认阈值 */
    private $suggestMin;

    /**
     * @param BglxStore|null $store 存储实例；null 时创建默认实例
     * @param array          $opts  auto_min / suggest_min 覆盖
     */
    public function __construct($store = null, $opts = array())
    {
        $this->store = ($store instanceof BglxStore) ? $store : new BglxStore();
        $opts = is_array($opts) ? $opts : array();
        $thresholds = bglx_thresholds();
        $auto = isset($opts['auto_min']) && is_numeric($opts['auto_min'])
            ? (float)$opts['auto_min'] : $thresholds['auto'];
        $suggest = isset($opts['suggest_min']) && is_numeric($opts['suggest_min'])
            ? (float)$opts['suggest_min'] : $thresholds['suggest'];
        if ($auto < 0 || $auto > 1) {
            $auto = BGLX_AUTO_MIN;
        }
        if ($suggest < 0 || $suggest > 1 || $suggest > $auto) {
            $suggest = min($suggest, $auto);
        }
        $this->autoMin = $auto;
        $this->suggestMin = $suggest;
    }

    /** 清空全部进程内缓存 */
    public static function clearCache()
    {
        self::$cache = array();
    }

    /**
     * 读取词条并按 mtime + 文件尺寸做进程内缓存。
     *
     * @return array 全部词条
     */
    private function entries()
    {
        $path = $this->store->path();
        $mtime = is_file($path) ? (int)@filemtime($path) : 0;
        $size = is_file($path) ? (int)@filesize($path) : 0;
        if (isset(self::$cache[$path])
            && self::$cache[$path]['mtime'] === $mtime
            && self::$cache[$path]['size'] === $size) {
            return self::$cache[$path]['entries'];
        }
        $entries = $this->store->all();
        self::$cache[$path] = array(
            'mtime' => $mtime,
            'size' => $size,
            'entries' => $entries,
        );
        return $entries;
    }

    /**
     * 判定词条是否参与查询：仅 active + enabled + scope 命中。
     *
     * @param array  $entry  词条
     * @param string $plugin 插件 id，空表示不限
     * @return bool
     */
    private function participates($entry, $plugin)
    {
        if (!is_array($entry)) {
            return false;
        }
        if (!isset($entry['status']) || (string)$entry['status'] !== 'active') {
            return false;
        }
        if (!isset($entry['enabled']) || !bglx_to_bool($entry['enabled'], false)) {
            return false;
        }
        if ($plugin !== '') {
            $scope = isset($entry['scope']) && is_array($entry['scope']) ? $entry['scope'] : array('*');
            if (!in_array('*', $scope, true) && !in_array($plugin, $scope, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 从 char_confusion 词条构建单字符映射表。
     *
     * @param array  $entries 全部词条
     * @param string $plugin  插件 id
     * @return array 小写字符 => array('to'=>string, 'confidence'=>float, 'id'=>string)
     */
    private function charMap($entries, $plugin)
    {
        $map = array();
        foreach ($entries as $e) {
            if (!isset($e['type']) || (string)$e['type'] !== 'char_confusion') {
                continue;
            }
            if (!$this->participates($e, $plugin)) {
                continue;
            }
            $standard = isset($e['standard']) ? (string)$e['standard'] : '';
            $variants = isset($e['variants']) && is_array($e['variants']) ? $e['variants'] : array();
            if ($standard === '' || bglx_str_len($standard) !== 1) {
                continue;
            }
            $conf = isset($e['confidence']) ? (float)$e['confidence'] : 0.0;
            foreach ($variants as $v) {
                $v = (string)$v;
                if (bglx_str_len($v) !== 1) {
                    continue;
                }
                $key = bglx_lower($v);
                // 冲突时保留置信度更低的映射，保持保守
                if (!isset($map[$key]) || $conf < $map[$key]['confidence']) {
                    $map[$key] = array(
                        'to' => $standard,
                        'confidence' => $conf,
                        'id' => isset($e['id']) ? (string)$e['id'] : '',
                    );
                }
            }
        }
        return $map;
    }

    /**
     * 第 1 级：字符形近归一。
     *
     * @param string $raw 原始输入
     * @param array  $map 字符映射表
     * @return array array('text'=>string, 'min_confidence'=>float, 'used'=>bool, 'replacements'=>array)
     */
    public function charNormalize($raw, $map)
    {
        $raw = (string)$raw;
        $chars = bglx_str_split($raw);
        $out = array();
        $minConf = 1.0;
        $used = false;
        $replacements = array();
        foreach ($chars as $ch) {
            $key = bglx_lower($ch);
            if (isset($map[$key])) {
                $item = $map[$key];
                $out[] = (string)$item['to'];
                $minConf = min($minConf, (float)$item['confidence']);
                $used = true;
                $replacements[] = array(
                    'from' => $ch,
                    'to' => (string)$item['to'],
                    'confidence' => (float)$item['confidence'],
                    'id' => $item['id'],
                );
            } else {
                $out[] = $ch;
            }
        }
        return array(
            'text' => implode('', $out),
            'min_confidence' => $used ? $minConf : 1.0,
            'used' => $used,
            'replacements' => $replacements,
        );
    }

    /**
     * 生成用于匹配的键：先字符归一，再按类型折叠。
     *
     * @param string $s    输入
     * @param string $type 词条类型
     * @param array  $map  字符映射表
     * @return string
     */
    private function matchKey($s, $type, $map)
    {
        $norm = $this->charNormalize($s, $map);
        $key = $norm['text'];
        if ($type === 'drawing_alias') {
            $key = bglx_partno_key($key);
        }
        return $key;
    }

    /**
     * 从长文本中提取可能是图号的字母数字段，作为附加匹配键。
     * 仅在整串未命中时启用，避免短片段误命中。
     *
     * @param string $raw 原始输入
     * @return array 候选键
     */
    private function extraPartnoKeys($raw)
    {
        $keys = array();
        if (!preg_match_all('/[A-Za-z0-9][A-Za-z0-9\-_.]{3,}/', (string)$raw, $m)) {
            return $keys;
        }
        foreach ($m[0] as $run) {
            $key = bglx_partno_key($run);
            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * 第 2 级：词条精确匹配。
     *
     * @param string $type    词条类型
     * @param array  $keys    待匹配键（可能多个）
     * @param array  $entries 全部词条
     * @param string $plugin  插件 id
     * @param array  $map     字符映射表
     * @return array 命中列表，元素 array('entry'=>array, 'confidence'=>float)
     */
    private function matchEntries($type, $keys, $entries, $plugin, $map)
    {
        $hits = array();
        $seen = array();
        foreach ($entries as $e) {
            if (!isset($e['type']) || (string)$e['type'] !== $type) {
                continue;
            }
            if (!$this->participates($e, $plugin)) {
                continue;
            }
            $id = isset($e['id']) ? (string)$e['id'] : '';
            $targets = array();
            if (isset($e['standard'])) {
                $targets[] = $this->matchKey((string)$e['standard'], $type, $map);
            }
            if (isset($e['variants']) && is_array($e['variants'])) {
                foreach ($e['variants'] as $v) {
                    $targets[] = $this->matchKey((string)$v, $type, $map);
                }
            }
            $matched = false;
            foreach ($keys as $key) {
                if ($key === '') {
                    continue;
                }
                if (in_array($key, $targets, true)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $hits[] = array(
                'entry' => $e,
                'confidence' => isset($e['confidence']) ? (float)$e['confidence'] : 0.0,
            );
        }
        return $hits;
    }

    /**
     * 多候选排序：confidence DESC → priority DESC → updated_at DESC。
     *
     * @param array $hits 命中列表
     * @return array 排序后的命中列表
     */
    private function sortHits($hits)
    {
        usort($hits, function ($a, $b) {
            $ca = (float)$a['confidence'];
            $cb = (float)$b['confidence'];
            if (abs($ca - $cb) > 0.0001) {
                return $ca > $cb ? -1 : 1;
            }
            $ea = $a['entry'];
            $eb = $b['entry'];
            $pa = isset($ea['priority']) ? (int)$ea['priority'] : 0;
            $pb = isset($eb['priority']) ? (int)$eb['priority'] : 0;
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }
            $ta = isset($ea['updated_at']) ? (string)$ea['updated_at'] : '';
            $tb = isset($eb['updated_at']) ? (string)$eb['updated_at'] : '';
            return strcmp($tb, $ta);
        });
        return $hits;
    }

    /**
     * 构造未命中结果对象。
     *
     * @param string $original 原始值
     * @param string $type     类型
     * @param string $reason   原因
     * @return array
     */
    private function noneResult($original, $type, $reason = '')
    {
        return array(
            'original' => (string)$original,
            'corrected' => (string)$original,
            'type' => (string)$type,
            'confidence' => 0.0,
            'action' => 'none',
            'matched_id' => '',
            'strategy' => 'none',
            'candidates' => array(),
            'reason' => (string)$reason,
        );
    }

    /**
     * 统一查询入口：两级纠错 + 置信度分级。
     *
     * @param string $type 词条类型
     * @param string $raw  原始值
     * @param array  $opts plugin / auto_min / suggest_min / store
     * @return array §3.5 查询结果对象；任何异常都降级为 none，不抛出到 OCR 主流程
     */
    public function correct($type, $raw, $opts = array())
    {
        $original = (string)$raw;
        $type = (string)$type;
        $opts = is_array($opts) ? $opts : array();
        if (!in_array($type, BGLX_TYPES, true)) {
            return $this->noneResult($original, $type, '未知词条类型');
        }
        if ($original === '') {
            return $this->noneResult($original, $type, '原始值为空');
        }
        try {
            $plugin = isset($opts['plugin']) ? trim((string)$opts['plugin']) : '';
            $entries = $this->entries();
            $map = $this->charMap($entries, $plugin);
            $norm = $this->charNormalize($original, $map);
            $key = $type === 'drawing_alias' ? bglx_partno_key($norm['text']) : $norm['text'];

            $keys = array($key);
            if ($type === 'drawing_alias') {
                foreach ($this->extraPartnoKeys($norm['text']) as $extra) {
                    if (!in_array($extra, $keys, true)) {
                        $keys[] = $extra;
                    }
                }
            }
            $hits = $this->sortHits($this->matchEntries($type, $keys, $entries, $plugin, $map));
            if (!$hits) {
                // 归一后没命中词条 → 原样返回（保守原则）
                return $this->noneResult($original, $type, '归一后未命中任何启用词条');
            }

            $best = $hits[0];
            $bestEntry = $best['entry'];
            $effective = min((float)$best['confidence'], (float)$norm['min_confidence']);
            $strategy = $norm['used'] ? 'char_norm' : 'exact';

            $candidates = array();
            foreach (array_slice($hits, 0, 5) as $h) {
                $e = $h['entry'];
                $candidates[] = array(
                    'id' => isset($e['id']) ? (string)$e['id'] : '',
                    'standard' => isset($e['standard']) ? (string)$e['standard'] : '',
                    'confidence' => (float)$h['confidence'],
                );
            }

            // 多候选且标准值不同 → 强制降级 suggest
            $ambiguous = false;
            if (count($hits) > 1) {
                $second = $hits[1]['entry'];
                $s1 = isset($bestEntry['standard']) ? bglx_lower((string)$bestEntry['standard']) : '';
                $s2 = isset($second['standard']) ? bglx_lower((string)$second['standard']) : '';
                $ambiguous = ($s1 !== $s2);
            }

            $auto = $this->autoMin;
            $suggest = $this->suggestMin;
            if (array_key_exists('auto_min', $opts) && is_numeric($opts['auto_min'])) {
                $auto = (float)$opts['auto_min'];
            }
            if (array_key_exists('suggest_min', $opts) && is_numeric($opts['suggest_min'])) {
                $suggest = (float)$opts['suggest_min'];
            }
            if ($suggest > $auto) {
                $suggest = $auto;
            }

            if ($effective < $suggest) {
                return array(
                    'original' => $original,
                    'corrected' => $original,
                    'type' => $type,
                    'confidence' => round($effective, 2),
                    'action' => 'none',
                    'matched_id' => isset($bestEntry['id']) ? (string)$bestEntry['id'] : '',
                    'strategy' => $strategy,
                    'candidates' => $candidates,
                    'reason' => '置信度低于人工确认阈值',
                );
            }

            $action = 'suggest';
            $reason = '置信度处于人工确认区间';
            if (!$ambiguous && $effective >= $auto) {
                $action = 'auto';
                $reason = '唯一命中且置信度达到自动纠正阈值';
            } elseif ($ambiguous) {
                $reason = '多候选标准值不同，强制降级为人工确认';
            }

            return array(
                'original' => $original,
                'corrected' => $action === 'auto'
                    ? (isset($bestEntry['standard']) ? (string)$bestEntry['standard'] : $original)
                    : $original,
                'type' => $type,
                'confidence' => round($effective, 2),
                'action' => $action,
                'matched_id' => isset($bestEntry['id']) ? (string)$bestEntry['id'] : '',
                'strategy' => $strategy,
                'candidates' => $candidates,
                'reason' => $reason,
            );
        } catch (Throwable $e) {
            error_log('[handwriting-lexicon] 纠错查询降级：' . $e->getMessage());
            return $this->noneResult($original, $type, '词库读取失败，已按未命中处理');
        }
    }

    /**
     * 调试用：返回所有命中条目的逐条判定，不用于生产替换。
     *
     * @param string $type 词条类型
     * @param string $raw  原始值
     * @param array  $opts 选项
     * @return array 结果对象数组
     */
    public function queryAll($type, $raw, $opts = array())
    {
        $original = (string)$raw;
        $type = (string)$type;
        $opts = is_array($opts) ? $opts : array();
        if (!in_array($type, BGLX_TYPES, true) || $original === '') {
            return array($this->noneResult($original, $type, '参数不合法'));
        }
        try {
            $plugin = isset($opts['plugin']) ? trim((string)$opts['plugin']) : '';
            $entries = $this->entries();
            $map = $this->charMap($entries, $plugin);
            $norm = $this->charNormalize($original, $map);
            $key = $type === 'drawing_alias' ? bglx_partno_key($norm['text']) : $norm['text'];
            $keys = array($key);
            if ($type === 'drawing_alias') {
                foreach ($this->extraPartnoKeys($norm['text']) as $extra) {
                    if (!in_array($extra, $keys, true)) {
                        $keys[] = $extra;
                    }
                }
            }
            $hits = $this->sortHits($this->matchEntries($type, $keys, $entries, $plugin, $map));
            if (!$hits) {
                return array($this->noneResult($original, $type, '归一后未命中任何启用词条'));
            }
            $out = array();
            foreach ($hits as $h) {
                $e = $h['entry'];
                $eff = min((float)$h['confidence'], (float)$norm['min_confidence']);
                $out[] = array(
                    'original' => $original,
                    'corrected' => isset($e['standard']) ? (string)$e['standard'] : $original,
                    'type' => $type,
                    'confidence' => round($eff, 2),
                    'action' => $eff >= $this->autoMin ? 'auto' : ($eff >= $this->suggestMin ? 'suggest' : 'none'),
                    'matched_id' => isset($e['id']) ? (string)$e['id'] : '',
                    'strategy' => $norm['used'] ? 'char_norm' : 'exact',
                    'candidates' => array(),
                    'reason' => '逐条判定（调试用）',
                );
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[handwriting-lexicon] queryAll 降级：' . $e->getMessage());
            return array($this->noneResult($original, $type, '词库读取失败'));
        }
    }

    /**
     * 管理页测试区用：返回决策过程轨迹。
     *
     * @param string $type 词条类型
     * @param string $raw  原始值
     * @param array  $opts 选项
     * @return array trace 对象
     */
    public function trace($type, $raw, $opts = array())
    {
        $original = (string)$raw;
        $type = (string)$type;
        $opts = is_array($opts) ? $opts : array();
        $empty = array(
            'raw' => $original,
            'char_normalized' => $original,
            'replacements' => array(),
            'matched_entries' => array(),
            'reason' => '参数不合法或未命中',
        );
        if (!in_array($type, BGLX_TYPES, true) || $original === '') {
            return $empty;
        }
        try {
            $plugin = isset($opts['plugin']) ? trim((string)$opts['plugin']) : '';
            $entries = $this->entries();
            $map = $this->charMap($entries, $plugin);
            $norm = $this->charNormalize($original, $map);
            $key = $type === 'drawing_alias' ? bglx_partno_key($norm['text']) : $norm['text'];
            $keys = array($key);
            if ($type === 'drawing_alias') {
                foreach ($this->extraPartnoKeys($norm['text']) as $extra) {
                    if (!in_array($extra, $keys, true)) {
                        $keys[] = $extra;
                    }
                }
            }
            $hits = $this->sortHits($this->matchEntries($type, $keys, $entries, $plugin, $map));
            $matched = array();
            foreach ($hits as $h) {
                $e = $h['entry'];
                $matched[] = array(
                    'id' => isset($e['id']) ? (string)$e['id'] : '',
                    'standard' => isset($e['standard']) ? (string)$e['standard'] : '',
                    'confidence' => (float)$h['confidence'],
                );
            }
            return array(
                'raw' => $original,
                'char_normalized' => $norm['text'],
                'match_key' => $key,
                'replacements' => $norm['replacements'],
                'matched_entries' => $matched,
                'reason' => $hits ? '命中 ' . count($hits) . ' 条词条' : '归一后未命中任何启用词条',
            );
        } catch (Throwable $e) {
            $empty['reason'] = '词库读取失败：' . $e->getMessage();
            return $empty;
        }
    }
}
