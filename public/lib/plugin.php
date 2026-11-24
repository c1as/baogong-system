<?php
/* 模块: lib/plugin
   职责: OCR 插件清单解析、配置、调用与结果归一化
   依赖: lib/config lib/http
   注意: plugins/ 目录协议不可改动 */

/* ================================================================ 插件（图像识别） */

/**
 * 插件约定
 *   public/plugins/<id>/plugin.json  清单：id / name / version / description / capabilities / entry / settings[]
 *   public/plugins/<id>/handler.php  处理器：读取 $BG_PLUGIN_CTX（或定义 bg_plugin_run($ctx)），return 数组结果
 *   配置保存在 data/plugins.json
 */

function bg_plugins_dir()
{
    return dirname(__DIR__) . '/plugins';
}

function bg_plugin_state_file()
{
    global $DATA_DIR;
    return $DATA_DIR . '/plugins.json';
}

function bg_plugin_state_load()
{
    $file = bg_plugin_state_file();
    if (!is_file($file)) {
        return array();
    }
    $raw = @file_get_contents($file);
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? $data : array();
}

function bg_plugin_state_save($state)
{
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (@file_put_contents(bg_plugin_state_file(), $json, LOCK_EX) === false) {
        bg_fail(500, '插件配置保存失败，请检查 data 目录的写权限');
    }
}

function bg_plugin_cut($v, $max)
{
    $s = is_string($v) ? trim($v) : '';
    if ($s === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
    return substr($s, 0, $max);
}

function bg_plugin_field($f)
{
    if (!is_array($f)) {
        return null;
    }
    $key = isset($f['key']) && is_string($f['key']) ? $f['key'] : '';
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/', $key)) {
        return null;
    }
    $types = array('text', 'password', 'number', 'select', 'checkbox', 'textarea', 'multiselect');
    $type = isset($f['type']) && in_array($f['type'], $types, true) ? $f['type'] : 'text';
    $out = array(
        'key' => $key,
        'label' => bg_plugin_cut(isset($f['label']) ? $f['label'] : $key, 40),
        'type' => $type,
        'placeholder' => bg_plugin_cut(isset($f['placeholder']) ? $f['placeholder'] : '', 120),
        'hint' => bg_plugin_cut(isset($f['hint']) ? $f['hint'] : '', 120),
        'default' => isset($f['default']) && !is_array($f['default']) ? (string)$f['default'] : '',
    );
    if ($type === 'textarea') {
        $rows = isset($f['rows']) ? (int)$f['rows'] : 4;
        $out['rows'] = $rows < 2 ? 2 : ($rows > 12 ? 12 : $rows);
    }
    if ($type === 'number') {
        if (isset($f['min']) && is_numeric($f['min'])) {
            $out['min'] = (float)$f['min'];
        }
        if (isset($f['max']) && is_numeric($f['max'])) {
            $out['max'] = (float)$f['max'];
        }
        if (isset($f['step']) && is_numeric($f['step']) && (float)$f['step'] > 0) {
            $out['step'] = (float)$f['step'];
        }
    }
    if ($type === 'select' || $type === 'multiselect') {
        $opts = array();
        if (isset($f['options']) && is_array($f['options'])) {
            foreach ($f['options'] as $o) {
                if (is_scalar($o)) {
                    $val = (string)$o;
                    if ($val !== '') {
                        $opts[] = array('value' => bg_plugin_cut($val, 100), 'label' => bg_plugin_cut($val, 40));
                    }
                    continue;
                }
                if (!is_array($o)) {
                    continue;
                }
                $val = isset($o['value']) && !is_array($o['value']) ? (string)$o['value'] : '';
                if ($val === '') {
                    continue;
                }
                $opts[] = array('value' => $val, 'label' => bg_plugin_cut(isset($o['label']) ? $o['label'] : $val, 40));
            }
        }
        $out['options'] = $opts;
        if ($type === 'multiselect') {
            $out['source'] = isset($f['source']) && is_string($f['source']) && preg_match('/^[a-zA-Z][a-zA-Z0-9]{0,31}$/', $f['source'])
                ? $f['source'] : '';
        }
    }
    return $out;
}

function bg_plugin_manifest($file)
{
    $raw = @file_get_contents($file);
    $m = $raw === false ? null : json_decode($raw, true);
    if (!is_array($m)) {
        return null;
    }
    $id = isset($m['id']) && is_string($m['id']) ? $m['id'] : '';
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id) || basename(dirname($file)) !== $id) {
        return null;
    }
    $entry = isset($m['entry']) && is_string($m['entry']) ? $m['entry'] : 'handler.php';
    if (!preg_match('/^[A-Za-z0-9_.-]+\.php$/', $entry) || !is_file(dirname($file) . '/' . $entry)) {
        return null;
    }
    $check = isset($m['check']) && is_string($m['check']) ? $m['check'] : '';
    if ($check !== '' && (!preg_match('/^[A-Za-z0-9_.-]+\.php$/', $check) || !is_file(dirname($file) . '/' . $check))) {
        $check = '';
    }
    $caps = array();
    if (isset($m['capabilities']) && is_array($m['capabilities'])) {
        foreach ($m['capabilities'] as $c) {
            if (is_string($c) && preg_match('/^[a-z][a-z0-9.-]{0,31}$/', $c)) {
                $caps[] = $c;
            }
        }
    }
    $fields = array();
    if (isset($m['settings']) && is_array($m['settings'])) {
        foreach ($m['settings'] as $f) {
            $def = bg_plugin_field($f);
            if ($def) {
                $fields[] = $def;
            }
        }
    }
    return array(
        'id' => $id,
        'name' => bg_plugin_cut(isset($m['name']) ? $m['name'] : $id, 40),
        'version' => bg_plugin_cut(isset($m['version']) ? $m['version'] : '0.0.0', 20),
        'description' => bg_plugin_cut(isset($m['description']) ? $m['description'] : '', 200),
        'author' => bg_plugin_cut(isset($m['author']) ? $m['author'] : '', 40),
        'capabilities' => $caps,
        'settings' => $fields,
        'entry' => $entry,
        'check' => $check,
        'defaultEnabled' => !isset($m['defaultEnabled']) || (bool)$m['defaultEnabled'],
        'dir' => dirname($file),
    );
}

function bg_plugin_all()
{
    // v1.1：合并扫描必备区(public/plugins) + 市场区(data/market-plugins)，同名 market 优先。
    // 市场插件所在目录名 == id，bg_plugin_manifest() 的目录名校验天然满足。
    if (function_exists('bg_pm_all_plugins')) {
        return bg_pm_all_plugins();
    }
    // 兜底：市场模块未加载时保持原行为（只读必备区）
    $list = array();
    $dir = bg_plugins_dir();
    if (!is_dir($dir)) {
        return $list;
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
        return $list;
    }
    sort($items);
    foreach ($items as $name) {
        if ($name === '.' || $name === '..' || $name === '' || $name[0] === '.') {
            continue;
        }
        $file = $dir . '/' . $name . '/plugin.json';
        if (!is_file($file)) {
            continue;
        }
        $m = bg_plugin_manifest($file);
        if ($m) {
            $list[$m['id']] = $m;
        }
    }
    return $list;
}

function bg_plugin_one($id)
{
    if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id)) {
        bg_fail(400, '插件标识不合法');
    }
    $all = bg_plugin_all();
    if (!isset($all[$id])) {
        bg_fail(404, '插件不存在或未安装');
    }
    return $all[$id];
}

function bg_plugin_is_enabled($id)
{
    $state = bg_plugin_state_load();
    if (isset($state[$id]) && is_array($state[$id]) && array_key_exists('enabled', $state[$id])) {
        return (bool)$state[$id]['enabled'];
    }
    $all = bg_plugin_all();
    if (isset($all[$id]) && array_key_exists('defaultEnabled', $all[$id])) {
        return (bool)$all[$id]['defaultEnabled'];
    }
    return true;
}

function bg_plugin_config($m)
{
    $state = bg_plugin_state_load();
    $saved = isset($state[$m['id']]['settings']) && is_array($state[$m['id']]['settings'])
        ? $state[$m['id']]['settings'] : array();
    $cfg = array();
    foreach ($m['settings'] as $f) {
        $v = array_key_exists($f['key'], $saved) ? $saved[$f['key']] : $f['default'];
        $cfg[$f['key']] = is_scalar($v) ? (string)$v : '';
    }
    return $cfg;
}

function bg_plugin_engine_options($selfId)
{
    $opts = array();
    foreach (bg_plugin_all() as $p) {
        if ($p['id'] === $selfId || !in_array('report.ocr', $p['capabilities'], true)) {
            continue;
        }
        $enabled = bg_plugin_is_enabled($p['id']);
        $opts[] = array(
            'value' => $p['id'],
            'label' => bg_plugin_cut($p['name'] . ($enabled ? '' : '（已停用）'), 40),
            'disabled' => !$enabled,
        );
    }
    return $opts;
}

function bg_plugin_public($m)
{
    $cfg = bg_plugin_config($m);
    $fields = array();
    foreach ($m['settings'] as $f) {
        if ($f['type'] === 'multiselect' && isset($f['source']) && $f['source'] === 'ocrEngines') {
            $f['options'] = bg_plugin_engine_options($m['id']);
        }
        $f['value'] = $f['type'] === 'password' && $cfg[$f['key']] !== '' ? '********' : $cfg[$f['key']];
        unset($f['default']);
        $fields[] = $f;
    }
    return array(
        'id' => $m['id'],
        'name' => $m['name'],
        'version' => $m['version'],
        'description' => $m['description'],
        'author' => $m['author'],
        'source' => isset($m['source']) ? $m['source'] : 'builtin',
        'capabilities' => $m['capabilities'],
        'enabled' => bg_plugin_is_enabled($m['id']),
        'hasCheck' => $m['check'] !== '',
        'settings' => $fields,
    );
}

function bg_plugin_normalize_result($out)
{
    if (!is_array($out)) {
        bg_fail(502, '识别插件返回的数据格式不正确');
    }
    $f = isset($out['fields']) && is_array($out['fields']) ? $out['fields'] : array();

    $date = isset($f['date']) ? trim((string)$f['date']) : '';
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = '';
    }
    if ($date !== '') {
        $d = explode('-', $date);
        if (count($d) !== 3 || !checkdate((int)$d[1], (int)$d[2], (int)$d[0])) {
            $date = '';
        }
    }

    $items = array();
    $srcItems = isset($f['items']) && is_array($f['items']) ? $f['items'] : array();
    foreach ($srcItems as $it) {
        if (!is_array($it) || count($items) >= 20) {
            continue;
        }
        $qty = isset($it['qty']) && is_numeric($it['qty']) ? (float)$it['qty'] : 0;
        if ($qty < 0 || $qty > 1000000) {
            $qty = 0;
        }
        $price = isset($it['price']) && is_numeric($it['price']) ? bg_round((float)$it['price']) : null;
        $items[] = array(
            'partNo' => bg_plugin_cut(isset($it['partNo']) ? $it['partNo'] : '', 40),
            'partName' => bg_plugin_cut(isset($it['partName']) ? $it['partName'] : '', 40),
            'process' => bg_plugin_cut(isset($it['process']) ? $it['process'] : '', 40),
            'qty' => bg_round($qty),
            'price' => $price,
        );
    }

    $warnings = array();
    if (isset($out['warnings']) && is_array($out['warnings'])) {
        foreach ($out['warnings'] as $w) {
            if (is_string($w) && trim($w) !== '' && count($warnings) < 5) {
                $warnings[] = bg_plugin_cut($w, 80);
            }
        }
    }

    $confidence = isset($out['confidence']) && is_numeric($out['confidence']) ? (float)$out['confidence'] : 0;
    if ($confidence < 0) {
        $confidence = 0;
    }
    if ($confidence > 1) {
        $confidence = 1;
    }

    return array(
        'engine' => bg_plugin_cut(isset($out['engine']) ? $out['engine'] : '', 40),
        'confidence' => round($confidence, 3),
        'fields' => array(
            'date' => $date,
            'worker' => bg_plugin_cut(isset($f['worker']) ? $f['worker'] : '', 40),
            'workshop' => bg_plugin_cut(isset($f['workshop']) ? $f['workshop'] : '', 40),
            'note' => bg_plugin_cut(isset($f['note']) ? $f['note'] : '', 100),
            'items' => $items,
        ),
        'warnings' => $warnings,
        'raw' => bg_plugin_cut(isset($out['raw']) ? $out['raw'] : '', 4000),
    );
}

function bg_plugin_invoke($id, $dataUrl)
{
    global $BG_PLUGIN_CTX;
    $m = bg_plugin_one($id);
    $bgStart = microtime(true);

    if (!bg_plugin_is_enabled($id)) {
        bg_fail(400, '该识别插件已停用，请先在设置页启用');
    }
    if (!is_string($dataUrl) || !preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $dataUrl, $mt)) {
        bg_fail(400, '请上传 JPG / PNG / WebP 格式的报工单照片');
    }
    $binLen = strlen($mt[2]) * 3 / 4;
    if ($binLen > 8388608) {
        bg_fail(400, '图片太大，请压缩后再识别');
    }

    $db = bg_db_load();
    $catalog = array();
    foreach ($db['parts'] as $p) {
        /* 传全量零件库：各引擎按自己的 catalogLimit 决定塞多少进提示词，
           融合引擎则用全量做图号校正（只取前几百条会让冷门图号永远匹配不上） */
        if (count($catalog) >= 8000) {
            break;
        }
        $names = array();
        foreach (bg_part_processes($p) as $x) {
            $names[] = isset($x['name']) ? (string)$x['name'] : '';
        }
        $catalog[] = array(
            'no' => isset($p['no']) ? (string)$p['no'] : '',
            'name' => isset($p['name']) ? (string)$p['name'] : '',
            'processes' => $names,
        );
    }

    $BG_PLUGIN_CTX = array(
        'id' => $m['id'],
        'image' => $dataUrl,
        'catalog' => $catalog,
        'config' => bg_plugin_config($m),
        'locale' => 'zh-CN',
        'today' => date('Y-m-d'),
    );

    $out = null;
    try {
        $ret = include $m['dir'] . '/' . $m['entry'];
        if (is_array($ret)) {
            $out = $ret;
        } elseif (function_exists('bg_plugin_run')) {
            $out = bg_plugin_run($BG_PLUGIN_CTX);
        }
    } catch (Throwable $e) {
        @error_log('[baogong] 插件 ' . $m['id'] . ' 执行失败：' . $e->getMessage());
        bg_fail(502, '识别失败：' . $e->getMessage());
    }
    if (!is_array($out)) {
        bg_fail(502, '识别插件没有返回结果');
    }

    $result = bg_plugin_normalize_result($out);
    $result['plugin'] = array('id' => $m['id'], 'name' => $m['name'], 'version' => $m['version']);
    if ($m['id'] !== 'ocr-log') {
        bg_log_ocr($result, (int)round((microtime(true) - $bgStart) * 1000), 'ocr', $dataUrl);
    }
    return $result;
}

/**
 * 供融合类插件调用其它识别引擎：单路失败只抛出异常，不中断整体请求。
 * 返回与 bg_plugin_invoke() 相同的归一化结构。
 */
function bg_plugin_invoke_engine($id, $ctx)
{
    global $BG_PLUGIN_CTX, $BG_THROW;
    $bgStart = microtime(true);
    if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id)) {
        throw new RuntimeException('引擎标识不合法');
    }
    $all = bg_plugin_all();
    if (!isset($all[$id])) {
        throw new RuntimeException('插件未安装');
    }
    $m = $all[$id];
    if (!bg_plugin_is_enabled($id)) {
        throw new RuntimeException('插件已停用');
    }
    $child = array(
        'id' => $m['id'],
        'image' => isset($ctx['image']) && is_string($ctx['image']) ? $ctx['image'] : '',
        'catalog' => isset($ctx['catalog']) && is_array($ctx['catalog']) ? $ctx['catalog'] : array(),
        'config' => bg_plugin_config($m),
        'locale' => 'zh-CN',
        'today' => isset($ctx['today']) ? (string)$ctx['today'] : date('Y-m-d'),
    );

    $prevCtx = isset($GLOBALS['BG_PLUGIN_CTX']) ? $GLOBALS['BG_PLUGIN_CTX'] : null;
    $prevThrow = $BG_THROW;
    $GLOBALS['BG_PLUGIN_CTX'] = $child;
    $BG_THROW = true;
    try {
        /* 用闭包隔离被引入脚本的局部变量，避免覆盖本函数的变量 */
        $runner = function ($__bg_file) {
            return include $__bg_file;
        };
        $ret = $runner($m['dir'] . '/' . $m['entry']);
        if (is_array($ret)) {
            $out = $ret;
        } elseif (function_exists('bg_plugin_run')) {
            $out = bg_plugin_run($child);
        } else {
            $out = null;
        }
    } catch (Throwable $e) {
        $GLOBALS['BG_PLUGIN_CTX'] = $prevCtx;
        $BG_THROW = $prevThrow;
        throw new RuntimeException($e->getMessage());
    }
    $GLOBALS['BG_PLUGIN_CTX'] = $prevCtx;
    $BG_THROW = $prevThrow;
    if (!is_array($out)) {
        throw new RuntimeException('没有返回结果');
    }
    $result = bg_plugin_normalize_result($out);
    $result['plugin'] = array('id' => $m['id'], 'name' => $m['name'], 'version' => $m['version']);
    bg_log_ocr(
        $result,
        (int)round((microtime(true) - $bgStart) * 1000),
        'engine',
        isset($child['image']) ? $child['image'] : ''
    );
    return $result;
}

function bg_part_processes($p)
{
    return (isset($p['processes']) && is_array($p['processes'])) ? $p['processes'] : array();
}
