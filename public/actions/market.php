<?php
/* 模块: actions/market
   Action: plugin.market.capabilities plugin.source.get plugin.source.save plugin.source.test
           plugin.market.list plugin.market.detail plugin.market.install plugin.market.upgrade
           plugin.market.uninstall plugin.market.rollback
   职责: 插件市场索引、来源配置、安装/升级/卸载/回退
   依赖: lib/plugin_market lib/plugin lib/net
   注意: 双目录策略：必备区 public/plugins 只读，市场区 data/market-plugins 写入 */

function act_plugin_market_capabilities()
{
    bg_json(200, bg_pm_capabilities());
}

function act_plugin_source_get()
{
    $sc = new BG_PM_SourceConfig();
    bg_json(200, array('source' => $sc->publicView()));
}

function act_plugin_source_save()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $sc = new BG_PM_SourceConfig();
    $b = bg_body();
    $name = isset($b['name']) && is_string($b['name']) ? trim($b['name']) : '';
    $url = isset($b['url']) && is_string($b['url']) ? trim($b['url']) : '';
    $token = isset($b['token']) && is_string($b['token']) ? trim($b['token']) : '';
    $keep = ($token === '' || $token === '********');
    $cfg = $sc->save($name, $url, $token, $keep);
    bg_json(200, array('ok' => true, 'source' => $sc->publicView($cfg)));
}

function act_plugin_source_test()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $b = bg_body();
    $url = isset($b['url']) && is_string($b['url']) ? trim($b['url']) : '';
    $token = isset($b['token']) && is_string($b['token']) ? trim($b['token']) : '';
    $ic = new BG_PM_IndexClient();
    bg_json(200, $ic->test($url, $token));
}

function act_plugin_market_list()
{
    $force = isset($_GET['refresh']) && $_GET['refresh'] === '1';
    $ic = new BG_PM_IndexClient();
    $idx = $ic->fetch($force);
    $sc = new BG_PM_SourceConfig();
    $vu = new BG_PM_VersionUtil();
    $scanner = new BG_PM_Scanner();
    $local = $scanner->scanAll();
    $items = array();
    $upgradeCount = 0;
    foreach ($idx['plugins'] as $id => $remote) {
        $item = $remote;
        $loc = isset($local[$id]) ? $local[$id] : null;
        $item['localVersion'] = $loc ? $loc['version'] : null;
        $item['enabled'] = $loc ? bg_plugin_is_enabled($id) : null;
        $item['hasCheck'] = $loc ? ($loc['check'] !== '') : false;
        $item['source'] = $loc ? $loc['source'] : null;
        $item['packageSize'] = $remote['package']['size'];
        if (!$loc) {
            $item['status'] = 'installable';
        } elseif ($vu->compare($loc['version'], $remote['version']) < 0 && $vu->systemAtLeast($remote['minSystemVersion'])) {
            $item['status'] = 'upgradable';
            $upgradeCount++;
        } elseif ($vu->compare($loc['version'], $remote['version']) === 0) {
            $item['status'] = 'installed';
        } else {
            $item['status'] = 'local_newer';
        }
        $items[] = $item;
    }
    // 归并索引中不存在、但本地已装的插件（unmanaged）
    foreach ($local as $id => $loc) {
        if (isset($idx['plugins'][$id])) {
            continue;
        }
        $items[] = array(
            'id' => $id,
            'name' => $loc['name'],
            'version' => $loc['version'],
            'description' => $loc['description'],
            'author' => $loc['author'],
            'capabilities' => $loc['capabilities'],
            'changelog' => '',
            'minSystemVersion' => '',
            'localVersion' => $loc['version'],
            'enabled' => bg_plugin_is_enabled($id),
            'hasCheck' => $loc['check'] !== '',
            'source' => $loc['source'],
            'packageSize' => 0,
            'status' => 'unmanaged',
        );
    }
    bg_json(200, array(
        'source' => $sc->publicView(),
        'items' => $items,
        'upgradeCount' => $upgradeCount,
        'fetchedAt' => $idx['_fetchedAt'],
        'cached' => (bool)$idx['_cached'],
    ));
}

function act_plugin_market_detail()
{
    $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
    if (!bg_pm_valid_id($id)) {
        bg_fail(400, '插件标识不合法');
    }
    $ic = new BG_PM_IndexClient();
    $idx = $ic->fetch(false);
    if (!isset($idx['plugins'][$id])) {
        bg_fail(404, '索引中无此插件');
    }
    $item = $idx['plugins'][$id];
    $scanner = new BG_PM_Scanner();
    $local = $scanner->scanAll();
    $loc = isset($local[$id]) ? $local[$id] : null;
    bg_json(200, array(
        'item' => $item,
        'local' => $loc ? array('version' => $loc['version'], 'enabled' => bg_plugin_is_enabled($id), 'source' => $loc['source']) : null,
        'changelog' => $item['changelog'],
        'minSystemVersion' => $item['minSystemVersion'],
    ));
}

function act_plugin_market_install()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $b = bg_body();
    $id = isset($b['id']) && is_string($b['id']) ? $b['id'] : '';
    $ver = isset($b['version']) && is_string($b['version']) ? trim($b['version']) : '';
    (new BG_PM_Installer())->install($id, $ver);
}

function act_plugin_market_upgrade()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $b = bg_body();
    $id = isset($b['id']) && is_string($b['id']) ? $b['id'] : '';
    $ver = isset($b['version']) && is_string($b['version']) ? trim($b['version']) : '';
    (new BG_PM_Installer())->upgrade($id, $ver);
}

function act_plugin_market_uninstall()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $b = bg_body();
    $id = isset($b['id']) && is_string($b['id']) ? $b['id'] : '';
    if (!isset($b['confirm']) || $b['confirm'] !== true) {
        bg_fail(400, '请先确认卸载操作');
    }
    (new BG_PM_Installer())->uninstall($id);
}

function act_plugin_market_rollback()
{
    global $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $b = bg_body();
    $id = isset($b['id']) && is_string($b['id']) ? $b['id'] : '';
    (new BG_PM_Installer())->rollback($id);
}
