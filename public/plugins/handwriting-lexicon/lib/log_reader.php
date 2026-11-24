<?php
/**
 * 识别日志只读流式解析
 *
 * 硬约束：只以 'rb' 打开，绝不写回原日志；单行 fgets 限长、扫描行数/天数/返回条数
 * 三重上限防 OOM；坏行跳过并计数；raw 只保留截断摘要；敏感字段按配置掩码。
 */

if (!defined('BGLX_REV_LOG_READER_LOADED')) {
    define('BGLX_REV_LOG_READER_LOADED', 1);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/attachment.php';

if (!defined('BGLX_REV_MAX_SCAN_LINES')) {
    define('BGLX_REV_MAX_SCAN_LINES', 200000);
    define('BGLX_REV_MAX_DAYS', 31);
    define('BGLX_REV_LINE_BYTES', 131072);
    define('BGLX_REV_MAX_PAGE', 200);
}

/**
 * 组装日志读取配置（键名标准化，便于测试注入）。
 *
 * @param array $cfg 外部配置
 * @return array
 */
function bglx_rev_log_config($cfg = array())
{
    $cfg = is_array($cfg) ? $cfg : array();
    return array(
        'log_dir' => isset($cfg['log_dir']) ? $cfg['log_dir']
            : (isset($cfg['logDir']) ? $cfg['logDir'] : bglx_rev_setting('logDir', 'data/ocr-logs/')),
        'log_file_pattern' => isset($cfg['log_file_pattern']) ? $cfg['log_file_pattern']
            : (isset($cfg['logFilePattern']) ? $cfg['logFilePattern'] : bglx_rev_setting('logFilePattern', 'YYYY-MM-DD.jsonl')),
        'fusion_trace_pattern' => isset($cfg['fusion_trace_pattern']) ? $cfg['fusion_trace_pattern']
            : (isset($cfg['fusionTracePattern']) ? $cfg['fusionTracePattern'] : bglx_rev_setting('fusionTracePattern', 'fusion-trace-YYYY-MM-DD.jsonl')),
        'fusion_trace_enabled' => isset($cfg['fusion_trace_enabled']) ? (bool)$cfg['fusion_trace_enabled']
            : bglx_rev_setting_bool('fusionTraceEnabled', true),
        'mask_sensitive' => isset($cfg['mask_sensitive']) ? (bool)$cfg['mask_sensitive']
            : bglx_rev_setting_bool('maskSensitive', true),
    );
}

/**
 * 日期 → 日志文件绝对路径；realpath + base 前缀双重校验。
 *
 * @param string $date Y-m-d
 * @param array  $cfg  日志配置
 * @return string 绝对路径或空串
 */
function bglx_rev_log_file($date, $cfg = array())
{
    $cfg = bglx_rev_log_config($cfg);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) {
        return '';
    }
    $pattern = $cfg['log_file_pattern'] !== '' ? $cfg['log_file_pattern'] : 'YYYY-MM-DD.jsonl';
    $name = str_replace(
        array('YYYY-MM-DD', 'YYYYMMDD', 'YYYY', 'MM', 'DD'),
        array((string)$date, str_replace('-', '', (string)$date), substr((string)$date, 0, 4),
              substr((string)$date, 5, 2), substr((string)$date, 8, 2)),
        $pattern
    );
    if ($name === '' || preg_match('#(^|/)\.\.(/|$)#', $name)) {
        return '';
    }
    $base = bglx_rev_resolve_base($cfg['log_dir']);
    $real = $base !== '' ? realpath($base) : false;
    if ($real === false || !is_dir($real)) {
        return '';
    }
    $abs = bglx_rev_safe_path($real, $name);
    return $abs === null ? '' : $abs;
}

/**
 * 生成日期范围（升序，受 maxDays 硬顶）。
 *
 * @param string $from    起始日期 Y-m-d
 * @param string $to      结束日期 Y-m-d；空表示今天
 * @param int    $maxDays 最大天数
 * @return array Y-m-d 列表
 */
function bglx_rev_date_range($from, $to, $maxDays = 31)
{
    $from = trim((string)$from);
    $to = trim((string)$to);
    if ($to === '') {
        $to = date('Y-m-d');
    }
    if ($from === '') {
        $from = $to;
    }
    $valid = function ($d) {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
            return false;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    };
    if (!$valid($from) || !$valid($to)) {
        return array();
    }
    $t1 = strtotime($from . ' 00:00:00');
    $t2 = strtotime($to . ' 00:00:00');
    if ($t1 === false || $t2 === false) {
        return array();
    }
    if ($t1 > $t2) {
        $tmp = $t1;
        $t1 = $t2;
        $t2 = $tmp;
    }
    $maxDays = max(1, min(366, (int)$maxDays));
    $days = (int)floor(($t2 - $t1) / 86400) + 1;
    if ($days > $maxDays) {
        $t2 = $t1 + ($maxDays - 1) * 86400;
    }
    $out = array();
    for ($t = $t1; $t <= $t2; $t += 86400) {
        $out[] = date('Y-m-d', $t);
    }
    return $out;
}

/**
 * 单行 JSONL 解析：非数组或空数组一律视为坏行。
 *
 * @param string $line 原始行
 * @return array 成功返回关联数组，坏行返回 array()
 */
function bglx_rev_parse_line($line)
{
    $line = trim((string)$line);
    if ($line === '') {
        return array();
    }
    $row = json_decode($line, true);
    return (is_array($row) && $row) ? $row : array();
}

/**
 * 姓名掩码：保留首字符，其余以 * 替代（中文安全）。
 *
 * @param string $name 原始姓名
 * @return string
 */
function bglx_rev_mask_name($name)
{
    $chars = bglx_str_split((string)$name);
    $n = count($chars);
    if ($n <= 1) {
        return $n === 1 ? $chars[0] : '';
    }
    return $chars[0] . str_repeat('*', $n - 1);
}

/**
 * 日志字段投影 + 脱敏 + raw 截断，只保留界面展示所需字段。
 *
 * @param array $row 原始日志行
 * @param array $cfg 日志配置
 * @return array LogEntry
 */
function bglx_rev_project_log($row, $cfg = array())
{
    $cfg = bglx_rev_log_config($cfg);
    $row = is_array($row) ? $row : array();
    $invoke = '';
    foreach (array('invoke_id', 'invokeId', 'invokeID', 'trace_id', 'traceId') as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
            $invoke = bglx_cut(trim((string)$row[$k]), 180);
            break;
        }
    }
    $fields = isset($row['fields']) && is_array($row['fields']) ? $row['fields'] : array();
    $items = isset($row['items']) && is_array($row['items']) ? array_slice($row['items'], 0, 200) : array();
    // 主程序日志把日期/人员等平铺在行上，明细用 no/proc/qty；这里统一成界面字段名
    foreach (array('date', 'worker', 'workshop', 'note') as $k) {
        if (!isset($fields[$k]) && isset($row[$k]) && is_scalar($row[$k])) {
            $fields[$k] = $row[$k];
        }
    }
    foreach ($items as $i => $item) {
        if (!is_array($item)) {
            continue;
        }
        if (!isset($item['partNo'])) {
            foreach (array('no', 'drawingNo', 'part_no', 'drawing') as $k) {
                if (isset($item[$k]) && is_scalar($item[$k]) && trim((string)$item[$k]) !== '') {
                    $item['partNo'] = $item[$k];
                    break;
                }
            }
        }
        if (!isset($item['process'])) {
            foreach (array('proc', 'procedure', 'processName') as $k) {
                if (isset($item[$k]) && is_scalar($item[$k]) && trim((string)$item[$k]) !== '') {
                    $item['process'] = $item[$k];
                    break;
                }
            }
        }
        if (!isset($item['qty'])) {
            foreach (array('quantity', 'count', 'num') as $k) {
                if (isset($item[$k]) && is_scalar($item[$k]) && trim((string)$item[$k]) !== '') {
                    $item['qty'] = $item[$k];
                    break;
                }
            }
        }
        $items[$i] = $item;
    }
    $warnings = isset($row['warnings']) && is_array($row['warnings'])
        ? array_slice($row['warnings'], 0, 50) : array();
    $raw = isset($row['raw']) ? (string)$row['raw'] : '';
    $ts = '';
    foreach (array('ts', 'timestamp', 'time') as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
            $ts = bglx_cut(trim((string)$row[$k]), 60);
            break;
        }
    }

    if ($cfg['mask_sensitive']) {
        foreach (array('worker', 'name', 'workerName', 'person') as $k) {
            if (isset($fields[$k]) && is_scalar($fields[$k])) {
                $fields[$k] = bglx_rev_mask_name((string)$fields[$k]);
            }
        }
        foreach ($items as $i => $item) {
            if (is_array($item)) {
                foreach (array('worker', 'name', 'workerName') as $k) {
                    if (isset($item[$k]) && is_scalar($item[$k])) {
                        $items[$i][$k] = bglx_rev_mask_name((string)$item[$k]);
                    }
                }
            }
        }
    }
    $date = isset($row['date']) && is_scalar($row['date']) ? trim((string)$row['date']) : '';
    if ($date === '' && isset($fields['date']) && is_scalar($fields['date'])) {
        $date = trim((string)$fields['date']);
    }
    return array(
        'date' => bglx_cut($date, 20),
        'invoke_id' => $invoke,
        'engine' => bglx_cut(trim((string)(isset($row['engine']) ? $row['engine'] : '')), 80),
        'duration' => max(0, (int)(isset($row['duration']) ? $row['duration'] : 0)),
        'fields' => $fields,
        'items' => $items,
        'warnings' => $warnings,
        'raw_excerpt' => bglx_cut($raw, 2000),
        'judgment' => 'unreviewed',
        'image' => null,
        'trace' => null,
        'ts' => $ts,
    );
}

/**
 * 引擎与关键词过滤（关键词在字段/明细/raw 的有限摘要上匹配）。
 *
 * @param array $row 原始日志行
 * @param array $opts 查询选项
 * @return bool
 */
function bglx_rev_log_matches($row, $opts)
{
    $opts = is_array($opts) ? $opts : array();
    // 审查工作台只展示识别结果，跳过 kind=match 的零件库匹配辅助日志
    $kind = trim((string)(isset($row['kind']) ? $row['kind'] : ''));
    if ($kind !== '' && !in_array($kind, array('engine', 'ocr', 'fusion'), true)) {
        return false;
    }
    if ($kind === ''
        && trim((string)(isset($row['engine']) ? $row['engine'] : '')) === ''
        && empty($row['items'])) {
        return false;
    }
    $engine = trim((string)(isset($opts['engine']) ? $opts['engine'] : ''));
    if ($engine !== ''
        && (string)(isset($row['engine']) ? $row['engine'] : '') !== $engine) {
        return false;
    }
    $keyword = trim((string)(isset($opts['keyword']) ? $opts['keyword'] : ''));
    if ($keyword === '') {
        return true;
    }
    $hay = json_encode(array(
        isset($row['fields']) ? $row['fields'] : array(),
        isset($row['items']) ? $row['items'] : array(),
        isset($row['raw']) ? $row['raw'] : '',
    ), JSON_UNESCAPED_UNICODE);
    $hay = $hay === false ? '' : bglx_cut($hay, 4000);
    return stripos($hay, $keyword) !== false;
}

/**
 * 流式读取日志：坏行跳过、条数上限、invokeId 索引标注判定与 trace。
 *
 * @param array $opts array('date_from','date_to','engine','judgment','keyword',
 *                          'limit','offset','with_trace','cfg')
 * @return array array('items','total_estimate','scanned','bad_lines','truncated','warnings')
 */
function bglx_rev_read_logs($opts = array())
{
    $opts = is_array($opts) ? $opts : array();
    $cfg = bglx_rev_log_config(isset($opts['cfg']) ? $opts['cfg'] : array());
    $dates = bglx_rev_date_range(
        isset($opts['date_from']) ? $opts['date_from'] : '',
        isset($opts['date_to']) ? $opts['date_to'] : '',
        BGLX_REV_MAX_DAYS
    );
    $limit = (int)(isset($opts['limit']) ? $opts['limit'] : BGLX_REV_MAX_PAGE);
    $limit = max(1, min(BGLX_REV_MAX_PAGE, $limit));
    $offset = max(0, (int)(isset($opts['offset']) ? $opts['offset'] : 0));
    $judgment = trim((string)(isset($opts['judgment']) ? $opts['judgment'] : ''));
    $withTrace = !empty($opts['with_trace']) && $cfg['fusion_trace_enabled'];

    $index = bglx_rev_store()->invokeIndex();
    $traces = $withTrace ? bglx_rev_read_traces($dates, $cfg) : array();

    $collected = array();
    $scanned = 0;
    $bad = 0;
    $truncated = false;
    $warnings = array();

    foreach ($dates as $date) {
        $path = bglx_rev_log_file($date, $cfg);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            $warnings[] = '日志不可读：' . $date;
            continue;
        }
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            $warnings[] = '日志打开失败：' . $date;
            continue;
        }
        $lineNo = 0;
        while (($line = fgets($fp, BGLX_REV_LINE_BYTES)) !== false) {
            $lineNo++;
            $scanned++;
            if ($lineNo > BGLX_REV_MAX_SCAN_LINES) {
                $truncated = true;
                break 2;
            }
            if (trim($line) === '') {
                continue;
            }
            $row = bglx_rev_parse_line($line);
            if (!$row) {
                $bad++;
                continue;
            }
            if (!bglx_rev_log_matches($row, $opts)) {
                continue;
            }
            $item = bglx_rev_project_log($row, $cfg);
            $item['date'] = $date;
            $invoke = $item['invoke_id'];
            $item['judgment'] = ($invoke !== '' && isset($index[$invoke]))
                ? $index[$invoke]['judgment'] : 'unreviewed';
            if ($judgment !== '' && $item['judgment'] !== $judgment) {
                continue;
            }
            $item['trace'] = ($invoke !== '' && isset($traces[$invoke])) ? $traces[$invoke] : null;
            $collected[] = $item;
            if (count($collected) >= $offset + $limit) {
                break 2;
            }
        }
        @fclose($fp);
    }
    return array(
        'items' => array_slice($collected, $offset, $limit),
        'total_estimate' => count($collected) + ($truncated ? -1 : 0),
        'scanned' => $scanned,
        'bad_lines' => $bad,
        'truncated' => $truncated,
        'warnings' => $warnings,
    );
}

/**
 * 按 invokeId 建 trace 哈希索引，一次流式扫描避免 O(n²)。
 *
 * @param array $dates 日期列表
 * @param array $cfg   日志配置
 * @return array invokeId → trace
 */
function bglx_rev_read_traces($dates, $cfg = array())
{
    $cfg = bglx_rev_log_config($cfg);
    $out = array();
    if (!$cfg['fusion_trace_enabled']) {
        return $out;
    }
    $dates = is_array($dates) ? $dates : array();
    $traceCfg = $cfg;
    $traceCfg['log_file_pattern'] = $cfg['fusion_trace_pattern'];
    foreach ($dates as $date) {
        $path = bglx_rev_log_file($date, $traceCfg);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            continue;
        }
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            continue;
        }
        $lineNo = 0;
        while (($line = fgets($fp, BGLX_REV_LINE_BYTES)) !== false) {
            $lineNo++;
            if ($lineNo > BGLX_REV_MAX_SCAN_LINES) {
                break 2;
            }
            if (trim($line) === '') {
                continue;
            }
            $row = bglx_rev_parse_line($line);
            if (!$row) {
                continue;
            }
            $invoke = '';
            foreach (array('invoke_id', 'invokeId', 'invokeID') as $k) {
                if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
                    $invoke = bglx_cut(trim((string)$row[$k]), 180);
                    break;
                }
            }
            if ($invoke === '') {
                continue;
            }
            $fields = isset($row['fields']) && is_array($row['fields'])
                ? array_slice($row['fields'], 0, 100) : array();
            $out[$invoke] = array(
                'invoke_id' => $invoke,
                'date' => $date,
                'fields' => $fields,
            );
        }
        @fclose($fp);
    }
    return $out;
}
