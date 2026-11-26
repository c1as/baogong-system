<?php
/* 模块: actions/plugins
   Action: plugins.list plugin.save plugin.invoke plugin.check net.check
   职责: OCR 插件清单、配置保存、试别调用与连通性检查
   依赖: lib/plugin lib/net
   注意: plugin.json 协议不可改动 */

function act_plugins_list()
{
    $list = array();
    foreach (bg_plugin_all() as $m) {
        $list[] = bg_plugin_public($m);
    }
    bg_json(200, array('plugins' => $list, 'dir' => 'public/plugins/'));
    return;
}

function act_plugin_save()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $m = bg_plugin_one(isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '');
    $body = bg_body();
    $state = bg_plugin_state_load();
    $prev = isset($state[$m['id']]) && is_array($state[$m['id']]) ? $state[$m['id']] : array();
    $next = array(
        'enabled' => isset($body['enabled']) ? (bool)$body['enabled'] : bg_plugin_is_enabled($m['id']),
        'settings' => isset($prev['settings']) && is_array($prev['settings']) ? $prev['settings'] : array(),
    );
    $input = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : array();
    foreach ($m['settings'] as $f) {
        if (!array_key_exists($f['key'], $input)) {
            continue;
        }
        $v = $input[$f['key']];
        if ($f['type'] === 'password') {
            if (!is_string($v)) {
                continue;
            }
            $v = trim($v);
            if ($v === '********') {
                continue;
            }
            if (strlen($v) > 500) {
                bg_fail(400, $f['label'] . '最多 500 个字符');
            }
            $next['settings'][$f['key']] = $v;
        } elseif ($f['type'] === 'checkbox') {
            $next['settings'][$f['key']] = $v ? '1' : '';
        } elseif ($f['type'] === 'number') {
            if ($v === '' || $v === null) {
                $next['settings'][$f['key']] = '';
            } elseif (!is_numeric($v)) {
                bg_fail(400, $f['label'] . '必须是数字');
            } else {
                $num = (float)$v;
                if (isset($f['min']) && $num < (float)$f['min']) {
                    bg_fail(400, $f['label'] . '不能小于 ' . $f['min']);
                }
                if (isset($f['max']) && $num > (float)$f['max']) {
                    bg_fail(400, $f['label'] . '不能大于 ' . $f['max']);
                }
                $next['settings'][$f['key']] = (string)$num;
            }
        } elseif ($f['type'] === 'multiselect') {
            $arr = is_array($v) ? $v : preg_split('/[\s,，、;；]+/u', is_scalar($v) ? (string)$v : '');
            $allowed = array();
            if (isset($f['source']) && $f['source'] === 'ocrEngines') {
                /* 动态来源：只允许选择其它已安装的 report.ocr 插件 */
                foreach (bg_plugin_all() as $p) {
                    if ($p['id'] === $m['id'] || !in_array('report.ocr', $p['capabilities'], true)) {
                        continue;
                    }
                    $allowed[$p['id']] = true;
                }
            } else {
                /* 自定义选项：按清单中声明的 value 白名单保存 */
                $opts = isset($f['options']) && is_array($f['options']) ? $f['options'] : array();
                foreach ($opts as $o) {
                    if (is_array($o) && isset($o['value'])) {
                        $allowed[(string)$o['value']] = true;
                    }
                }
            }
            $picked = array();
            foreach ($arr as $x) {
                if (is_string($x) && isset($allowed[$x]) && !in_array($x, $picked, true)) {
                    $picked[] = $x;
                }
            }
            $next['settings'][$f['key']] = implode(',', $picked);
        } else {
            $s = is_scalar($v) ? trim((string)$v) : '';
            $limit = $f['type'] === 'textarea' ? 2000 : 500;
            if (bg_len($s) > $limit) {
                bg_fail(400, $f['label'] . '最多 ' . $limit . ' 个字符');
            }
            $next['settings'][$f['key']] = $s;
        }
    }
    $state[$m['id']] = $next;
    bg_plugin_state_save($state);
    bg_json(200, array('plugin' => bg_plugin_public(bg_plugin_one($m['id']))));
    return;
}

function act_plugin_invoke()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $body = bg_body();
    $image = isset($body['image']) && is_string($body['image']) ? $body['image'] : '';
    $res = bg_plugin_invoke(isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '', $image);
    bg_json(200, $res);
    return;
}

function act_plugin_check()
{
    $m = bg_plugin_one(isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '');
    $res = bg_plugin_check($m['id']);
    $res['plugin'] = array('id' => $m['id'], 'name' => $m['name'], 'version' => $m['version']);
    bg_json(200, $res);
    return;
}

function act_net_check()
{
    bg_json(200, bg_net_check());
    return;
}
