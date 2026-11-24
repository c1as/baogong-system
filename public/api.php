<?php
/**
 * 报工管理系统 · PHP 后端（群晖 Web Station · SQLite 分支）
 *
 * 数据保存在 data/db/baogong.sqlite，图片在 data/images/{parts,attachments}，快照在 data/snapshots，配置在 app.env。
 * 接口：api.php?action=xxx
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$BG_ROOT = dirname(__DIR__);
require_once __DIR__ . '/lib/net.php';
$BG_THROW = false;

/** 系统版本号（供插件市场 minSystemVersion 判定，与前端缓存号一致） */
if (!function_exists('bg_app_version')) {
    function bg_app_version()
    {
        return '3.1.0';
    }
}

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/repo.php';
require_once __DIR__ . '/lib/backup.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/image.php';
require_once __DIR__ . '/lib/validate.php';
require_once __DIR__ . '/lib/csv.php';
require_once __DIR__ . '/lib/plugin.php';
require_once __DIR__ . '/lib/log.php';
require_once __DIR__ . '/lib/query.php';
require_once __DIR__ . '/lib/match.php';
require_once __DIR__ . '/lib/plugin_market.php';

$BG_CFG = bg_load_env($BG_ROOT . '/app.env');
$APP_PASSWORD = isset($BG_CFG['APP_PASSWORD']) ? $BG_CFG['APP_PASSWORD'] : '';
$DATA_DIR = isset($BG_CFG['DATA_DIR']) && $BG_CFG['DATA_DIR'] !== '' ? $BG_CFG['DATA_DIR'] : $BG_ROOT . '/data';
$DB_DIR = $DATA_DIR . '/db';
$SNAP_DIR = $DATA_DIR . '/snapshots';
$IMG_ROOT = $DATA_DIR . '/images';
$PART_IMG_DIR = $IMG_ROOT . '/parts';
$ATT_IMG_DIR = $IMG_ROOT . '/attachments';
$DRAW_DIR = $IMG_ROOT . '/drawings';
$IMG_DIR = $IMG_ROOT;
$BACKUP_DIR = $SNAP_DIR;
$DB_FILE = $DB_DIR . '/baogong.sqlite';
$SECRET_FILE = $DATA_DIR . '/.secret';
$BG_SNAP = null;
$BG_PDO = null;

foreach (array($DATA_DIR, $DB_DIR, $SNAP_DIR, $PART_IMG_DIR, $ATT_IMG_DIR, $DRAW_DIR) as $bgDir) {
    if (!is_dir($bgDir)) {
        @mkdir($bgDir, 0775, true);
    }
}
$BG_WRITABLE = false;
if (is_dir($DATA_DIR)) {
    $probe = $DATA_DIR . '/.write-test';
    if (@file_put_contents($probe, '1') !== false) {
        $BG_WRITABLE = true;
        @unlink($probe);
    }
}
if (!$BG_WRITABLE) {
    bg_fail(500, '数据目录不可写：' . $DATA_DIR . '。请给 Web Station 的 http 用户读写权限，并确认 PHP 的 open_basedir 未限制该目录。');
}

date_default_timezone_set('Asia/Shanghai');

/* 把旧版扁平目录（db 文件 / backups / images 根目录）迁移到新结构，只执行一次 */
bg_storage_migrate();

set_exception_handler(function ($e) {
    @error_log('[baogong] ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => '服务器内部错误'), JSON_UNESCAPED_UNICODE);
    }
});

/* ================================================================ 路由表（新增 action 必须同时登记） */

$ACTIONS = array(
    'health' => array('auth', 'health'),
    'login' => array('auth', 'login'),
    'state' => array('auth', 'state'),
    'bootstrap' => array('auth', 'bootstrap'),
    'logout' => array('auth', 'logout'),
    'parts.list' => array('parts', 'parts_list'),
    'parts.search' => array('parts', 'parts_search'),
    'parts.get' => array('parts', 'parts_get'),
    'parts.match' => array('parts', 'parts_match'),
    'part.create' => array('parts', 'part_create'),
    'part.update' => array('parts', 'part_update'),
    'part.delete' => array('parts', 'part_delete'),
    'part.drawing.remove' => array('parts', 'part_drawing_remove'),
    'part.stock' => array('parts', 'part_stock'),
    'part.import' => array('parts', 'part_import'),
    'orders.list' => array('orders', 'orders_list'),
    'orders.search' => array('orders', 'orders_search'),
    'orders.get' => array('orders', 'orders_get'),
    'order.create' => array('orders', 'order_create'),
    'order.update' => array('orders', 'order_update'),
    'order.delete' => array('orders', 'order_delete'),
    'reports.list' => array('reports', 'reports_list'),
    'reports.get' => array('reports', 'reports_get'),
    'report.create' => array('reports', 'report_create'),
    'report.update' => array('reports', 'report_update'),
    'report.delete' => array('reports', 'report_delete'),
    'stats.list' => array('stats', 'stats_list'),
    'stats.overview' => array('stats', 'stats_overview'),
    'data.stats' => array('data', 'data_stats'),
    'data.clear' => array('data', 'data_clear'),
    'backup.list' => array('data', 'backup_list'),
    'backup.create' => array('data', 'backup_create'),
    'backup.restore' => array('data', 'backup_restore'),
    'backup.delete' => array('data', 'backup_delete'),
    'backup.download' => array('data', 'backup_download'),
    'export.parts' => array('data', 'export_parts'),
    'export.stats' => array('data', 'export_stats'),
    'export.csv' => array('data', 'export_csv'),
    'export.json' => array('data', 'export_json'),
    'restore' => array('data', 'restore'),
    'plugins.list' => array('plugins', 'plugins_list'),
    'plugin.save' => array('plugins', 'plugin_save'),
    'plugin.invoke' => array('plugins', 'plugin_invoke'),
    'plugin.check' => array('plugins', 'plugin_check'),
    'net.check' => array('plugins', 'net_check'),
    'plugin.market.capabilities' => array('market', 'plugin_market_capabilities'),
    'plugin.source.get' => array('market', 'plugin_source_get'),
    'plugin.source.save' => array('market', 'plugin_source_save'),
    'plugin.source.test' => array('market', 'plugin_source_test'),
    'plugin.market.list' => array('market', 'plugin_market_list'),
    'plugin.market.detail' => array('market', 'plugin_market_detail'),
    'plugin.market.install' => array('market', 'plugin_market_install'),
    'plugin.market.upgrade' => array('market', 'plugin_market_upgrade'),
    'plugin.market.uninstall' => array('market', 'plugin_market_uninstall'),
    'plugin.market.rollback' => array('market', 'plugin_market_rollback'),
    'image' => array('images', 'image'),
);

function bg_dispatch($action)
{
    global $ACTIONS;
    $mod = $ACTIONS[$action][0];
    require_once __DIR__ . '/actions/' . $mod . '.php';
    $fn = 'act_' . $ACTIONS[$action][1];
    $fn();
}

$action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';

bg_backup_once();

/* 不需要登录的接口 */
if ($action === 'health' || $action === 'login') {
    bg_dispatch($action);
}

if (!bg_authed()) {
    bg_fail(401, '未登录');
}

if (isset($ACTIONS[$action])) {
    bg_dispatch($action);
    bg_fail(500, '接口未返回响应');
}

bg_fail(404, '接口不存在');

