<?php
/**
 * 报工系统 · 插件市场后端（v1.1 · 双目录策略）
 *
 * 设计依据：docs/插件市场-架构设计.md v1.1（A.1.4 / A.3 / A.5 / B.5）。
 * 本文件由 api.php 在定义好 bg_* 基础函数与 $DATA_DIR 之后 require_once 引入，
 * 因此可直接复用 bg_json()/bg_fail()/bg_http()/bg_plugin_manifest()/bg_plugin_public() 等。
 *
 * 双目录策略（用户拍板，D7/U1）：
 *   - 必备插件：public/plugins/<id>/   —— 市场【只读】，随 web 包整目录覆盖，绝不写入/删除
 *   - 市场插件：data/market-plugins/<id>/ —— 市场【唯一写根】，App 热更只写 public/，天然零覆盖
 *   - 同名 id：market 优先；卸载市场副本后自动回落必备基线；纯必备插件不可卸载（409）
 *
 * 安全：https 来源强制；令牌只存配置不写日志；zip-slip/扩展名/限额/路径越界全校验；
 *       data/ 除 market-plugins/ 与 plugin-market.json 外一律不写；data/plugins.json 全程只读。
 */

if (!defined('BG_PM_LOADED')) {

define('BG_PM_LOADED', true);

/** 索引 schema（公共口径，不得改） */
if (!defined('BG_PM_SCHEMA')) {
    define('BG_PM_SCHEMA', 'baogong-plugin-index/1');
}
/** 包大小上限 5MB */
if (!defined('BG_PM_MAX_BYTES')) {
    define('BG_PM_MAX_BYTES', 5242880);
}
/** 解压后总量上限 20MB */
if (!defined('BG_PM_MAX_UNCOMPRESSED')) {
    define('BG_PM_MAX_UNCOMPRESSED', 20971520);
}
/** 文件数上限 */
if (!defined('BG_PM_MAX_FILES')) {
    define('BG_PM_MAX_FILES', 100);
}
/** 目录深度上限 */
if (!defined('BG_PM_MAX_DEPTH')) {
    define('BG_PM_MAX_DEPTH', 3);
}
/** 索引缓存 TTL（秒） */
if (!defined('BG_PM_TTL')) {
    define('BG_PM_TTL', 300);
}
/** zip 允许的扩展名白名单 */
if (!defined('BG_PM_EXTS')) {
    define('BG_PM_EXTS', 'php,json,md,txt,css,js,png,jpg,jpeg,webp,svg');
}
/** 能力标签白名单 */
if (!defined('BG_PM_CAPS')) {
    define('BG_PM_CAPS', 'report.ocr,report.ocr.fusion,report.ocr.log');
}

/* ============================================================ 目录与基础工具 */

/** 必备插件区（只读） */
function bg_pm_builtin_dir()
{
    return __DIR__ . '/../plugins';
}

/** 市场插件区（唯一写根） */
function bg_pm_market_dir()
{
    global $DATA_DIR;
    return $DATA_DIR . '/market-plugins';
}

/** 来源配置文件（唯一允许的其它 data 写入） */
function bg_pm_source_file()
{
    global $DATA_DIR;
    return $DATA_DIR . '/plugin-market.json';
}

/** 索引缓存目录（public/.cache，系统更新替换 public/ 后自然重建） */
function bg_pm_cache_dir()
{
    $dir = __DIR__ . '/../.cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function bg_pm_cache_file()
{
    return bg_pm_cache_dir() . '/plugins-index.json';
}

/** id 合法性 */
function bg_pm_valid_id($id)
{
    return is_string($id) && preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $id) ? true : false;
}

/** 令牌脱敏回显 */
function bg_pm_mask_token($token)
{
    $t = is_string($token) ? $token : '';
    if ($t === '') {
        return '';
    }
    if (strlen($t) <= 8) {
        return '********';
    }
    return substr($t, 0, 3) . '****' . substr($t, -4);
}

/** 确保目录可写（必要时创建），返回 bool */
function bg_pm_ensure_dir($dir)
{
    if (is_dir($dir)) {
        return is_writable($dir);
    }
    return @mkdir($dir, 0755, true) && is_writable($dir);
}

/**
 * 路径越界断言：realpath 后必须位于 $root 内；拒绝 .. / 软链 / 绝对路径越界。
 * 用于市场写根的强制边界（A.5）。
 */
function bg_pm_assert_safe_path($path, $root)
{
    $rootReal = realpath($root);
    if ($rootReal === false) {
        return false;
    }
    $rootReal = rtrim($rootReal, '/\\') . DIRECTORY_SEPARATOR;
    // 目标可能尚不存在（如 .tmp 目录），对其父目录做 realpath
    $targetReal = realpath($path);
    if ($targetReal === false) {
        $parent = realpath(dirname($path));
        if ($parent === false) {
            return false;
        }
        $targetReal = rtrim($parent, '/\\') . DIRECTORY_SEPARATOR . basename($path);
    }
    if (stripos($targetReal, "\0") !== false) {
        return false;
    }
    return strpos($targetReal, $rootReal) === 0;
}

/* ============================================================ T04 · SourceConfig */

class BG_PM_SourceConfig
{
    private $file;

    public function __construct()
    {
        $this->file = bg_pm_source_file();
    }

    public function load()
    {
        $default = array(
            'name' => '官方源',
            'url' => 'https://api.github.com/repos/c1as/baogong-system/contents/plugins-index.json?ref=plugin',
            'token' => '',
            'updatedAt' => '',
        );
        if (!is_file($this->file)) {
            return $default;
        }
        $raw = @file_get_contents($this->file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            return $default;
        }
        foreach ($default as $k => $v) {
            if (array_key_exists($k, $data)) {
                $default[$k] = $data[$k];
            }
        }
        return $default;
    }

    public function save($name, $url, $token, $keepToken = false)
    {
        $cfg = $this->load();
        if (!$this->assertHttps($url)) {
            bg_fail(400, '来源地址必须是 https:// 开头');
        }
        if (bg_len($name) > 40) {
            bg_fail(400, '来源名称最多 40 字');
        }
        if ($keepToken || $token === '********' || $token === '') {
            $token = $cfg['token'];
        }
        $cfg['name'] = $name === '' ? '自定义源' : $name;
        $cfg['url'] = $url;
        $cfg['token'] = is_string($token) ? $token : '';
        $cfg['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (@file_put_contents($this->file, $json, LOCK_EX) === false) {
            bg_fail(403, '来源配置保存失败，请检查 data 目录写权限');
        }
        return $cfg;
    }

    public function assertHttps($url)
    {
        return is_string($url) && strpos($url, 'https://') === 0;
    }

    public function publicView($cfg = null)
    {
        $cfg = $cfg === null ? $this->load() : $cfg;
        return array(
            'name' => $cfg['name'],
            'url' => $cfg['url'],
            'tokenMasked' => bg_pm_mask_token($cfg['token']),
            'updatedAt' => $cfg['updatedAt'],
        );
    }
}

/* ============================================================ T04 · VersionUtil */

class BG_PM_VersionUtil
{
    public function normalize($v)
    {
        $s = is_string($v) ? trim($v) : '';
        if ($s !== '' && ($s[0] === 'v' || $s[0] === 'V')) {
            $s = substr($s, 1);
        }
        $pre = '';
        $sepPos = strcspn($s, '-+');
        if ($sepPos < strlen($s)) {
            $pre = substr($s, $sepPos + 1);
            $s = substr($s, 0, $sepPos);
        }
        $parts = explode('.', $s);
        $core = array();
        for ($i = 0; $i < 3; $i++) {
            $p = isset($parts[$i]) ? $parts[$i] : '0';
            $core[] = is_numeric($p) ? (int)$p : 0;
        }
        return array('core' => $core, 'hasPre' => $pre !== '', 'pre' => $pre);
    }

    /** 返回 -1 / 0 / 1 */
    public function compare($a, $b)
    {
        $na = $this->normalize($a);
        $nb = $this->normalize($b);
        for ($i = 0; $i < 3; $i++) {
            if ($na['core'][$i] > $nb['core'][$i]) {
                return 1;
            }
            if ($na['core'][$i] < $nb['core'][$i]) {
                return -1;
            }
        }
        if (!$na['hasPre'] && !$nb['hasPre']) {
            return 0;
        }
        if ($na['hasPre'] && !$nb['hasPre']) {
            return -1;
        }
        if (!$na['hasPre'] && $nb['hasPre']) {
            return 1;
        }
        $c = strcmp($na['pre'], $nb['pre']);
        return $c > 0 ? 1 : ($c < 0 ? -1 : 0);
    }

    public function isUpgrade($local, $remote)
    {
        return $this->compare($remote, $local) > 0;
    }

    /** 当前系统版本是否满足 minSystemVersion（为空视为满足） */
    public function systemAtLeast($min)
    {
        if (!is_string($min) || trim($min) === '') {
            return true;
        }
        if (!function_exists('bg_app_version')) {
            return true;
        }
        return $this->compare(bg_app_version(), $min) >= 0;
    }
}

/* ============================================================ T04 · IndexClient */

class BG_PM_IndexClient
{
    private $cfg;
    private $src;

    public function __construct()
    {
        $this->src = new BG_PM_SourceConfig();
        $this->cfg = $this->src->load();
    }

    /** 解析 GitHub Contents API 响应（base64 content）为索引数组 */
    public function parseGithubContents($body)
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['content']) || !is_string($data['content'])) {
            return null;
        }
        $raw = base64_decode(str_replace(array("\n", "\r"), '', $data['content']), true);
        if ($raw === false) {
            return null;
        }
        return $this->parseIndex($raw);
    }

    /** 校验 schema 并规范化索引结构 */
    public function parseIndex($raw)
    {
        $data = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($data)) {
            return null;
        }
        if (!isset($data['schema']) || $data['schema'] !== BG_PM_SCHEMA) {
            return null;
        }
        if (!isset($data['plugins']) || !is_array($data['plugins'])) {
            return null;
        }
        $plugins = array();
        foreach ($data['plugins'] as $p) {
            if (!is_array($p) || !bg_pm_valid_id(isset($p['id']) ? $p['id'] : '')) {
                continue;
            }
            $ver = isset($p['version']) && is_string($p['version']) ? $p['version'] : '0.0.0';
            $pkg = isset($p['package']) && is_array($p['package']) ? $p['package'] : array();
            $sha = isset($pkg['sha256']) && is_string($pkg['sha256']) ? strtolower($pkg['sha256']) : '';
            $size = isset($pkg['size']) ? (int)$pkg['size'] : 0;
            if (!preg_match('/^[0-9a-f]{64}$/', $sha) || $size <= 0 || $size > BG_PM_MAX_BYTES) {
                continue;
            }
            $url = isset($pkg['url']) && is_string($pkg['url']) ? $pkg['url'] : '';
            if (strpos($url, 'https://') !== 0) {
                continue;
            }
            $caps = array();
            $white = explode(',', BG_PM_CAPS);
            if (isset($p['capabilities']) && is_array($p['capabilities'])) {
                foreach ($p['capabilities'] as $c) {
                    if (is_string($c) && in_array($c, $white, true)) {
                        $caps[] = $c;
                    }
                }
            }
            $plugins[$p['id']] = array(
                'id' => $p['id'],
                'name' => bg_plugin_cut(isset($p['name']) ? $p['name'] : $p['id'], 40),
                'version' => $ver,
                'description' => bg_plugin_cut(isset($p['description']) ? $p['description'] : '', 200),
                'author' => bg_plugin_cut(isset($p['author']) ? $p['author'] : '', 40),
                'capabilities' => $caps,
                'changelog' => bg_plugin_cut(isset($p['changelog']) ? $p['changelog'] : '', 500),
                'minSystemVersion' => isset($p['minSystemVersion']) && is_string($p['minSystemVersion']) ? $p['minSystemVersion'] : '',
                'releasedAt' => isset($p['releasedAt']) && is_string($p['releasedAt']) ? $p['releasedAt'] : '',
                'tag' => isset($p['tag']) && is_string($p['tag']) ? $p['tag'] : '',
                'settings' => isset($p['settings']) && is_array($p['settings']) ? $p['settings'] : array(),
                'entry' => isset($p['entry']) && is_string($p['entry']) ? $p['entry'] : 'handler.php',
                'check' => isset($p['check']) && is_string($p['check']) ? $p['check'] : '',
                'defaultEnabled' => !isset($p['defaultEnabled']) || (bool)$p['defaultEnabled'],
                'package' => array(
                    'name' => isset($pkg['name']) && is_string($pkg['name']) ? $pkg['name'] : '',
                    'url' => $url,
                    'browserUrl' => isset($pkg['browserUrl']) && is_string($pkg['browserUrl']) ? $pkg['browserUrl'] : '',
                    'sha256' => $sha,
                    'size' => $size,
                ),
            );
        }
        return array(
            'schema' => BG_PM_SCHEMA,
            'generatedAt' => isset($data['generatedAt']) && is_string($data['generatedAt']) ? $data['generatedAt'] : '',
            'source' => isset($data['source']) && is_array($data['source']) ? $data['source'] : array(),
            'plugins' => $plugins,
        );
    }

    /** 拉取索引（force=true 绕过缓存）；失败 bg_fail(502) */
    public function fetch($force = false)
    {
        $cache = bg_pm_cache_file();
        if (!$force && is_file($cache)) {
            $age = time() - (int)@filemtime($cache);
            if ($age >= 0 && $age < BG_PM_TTL) {
                $raw = @file_get_contents($cache);
                $idx = $raw === false ? null : $this->parseIndex($raw);
                if (is_array($idx)) {
                    $idx['_cached'] = true;
                    $idx['_fetchedAt'] = date('c', (int)@filemtime($cache));
                    return $idx;
                }
            }
        }
        $cfg = $this->cfg;
        if (!$this->src->assertHttps($cfg['url'])) {
            bg_fail(403, '来源未配置或不是 HTTPS 地址');
        }
        $headers = array(
            'User-Agent: baogong-plugin-market/1.1',
            'Accept: application/vnd.github+json',
        );
        if ($cfg['token'] !== '') {
            $headers[] = 'Authorization: Bearer ' . $cfg['token'];
        }
        $res = bg_http('GET', $cfg['url'], $headers, '', 15);
        if (!$res['ok'] || $res['status'] < 200 || $res['status'] >= 300) {
            bg_fail(502, '索引拉取失败（HTTP ' . $res['status'] . '）' . ($res['error'] ? '：' . $res['error'] : ''));
        }
        $idx = $this->parseGithubContents($res['body']);
        if (!is_array($idx)) {
            bg_fail(502, '索引格式无效或 schema 不匹配');
        }
        @file_put_contents($cache, json_encode($idx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $idx['_cached'] = false;
        $idx['_fetchedAt'] = date('c');
        return $idx;
    }

    /** 测试来源连通性，不写缓存 */
    public function test($url, $token)
    {
        if (!$this->src->assertHttps($url)) {
            bg_fail(400, '来源地址必须是 https:// 开头');
        }
        $headers = array('User-Agent: baogong-plugin-market/1.1', 'Accept: application/vnd.github+json');
        if ($token !== '' && $token !== '********') {
            $headers[] = 'Authorization: Bearer ' . $token;
        } elseif ($this->cfg['token'] !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->cfg['token'];
        }
        $res = bg_http('GET', $url, $headers, '', 15);
        if (!$res['ok'] || $res['status'] < 200 || $res['status'] >= 300) {
            bg_fail(502, '连接失败（HTTP ' . $res['status'] . '）' . ($res['error'] ? '：' . $res['error'] : ''));
        }
        $idx = $this->parseGithubContents($res['body']);
        if (!is_array($idx)) {
            bg_fail(502, '返回内容不是有效的插件索引');
        }
        return array('ok' => true, 'count' => count($idx['plugins']), 'ms' => $res['ms']);
    }
}

/* ============================================================ T04 · 能力探测 */

/** 探测 zip 解压能力：ziparchive / purephp / none */
function bg_pm_probe_zip()
{
    if (class_exists('ZipArchive')) {
        return 'ziparchive';
    }
    if (function_exists('gzinflate') || function_exists('inflate_init')) {
        return 'purephp';
    }
    return 'none';
}

function bg_pm_capabilities()
{
    return array(
        'zip' => bg_pm_probe_zip(),
        'curl' => function_exists('curl_init'),
        'marketPluginsWritable' => bg_pm_ensure_dir(bg_pm_market_dir()),
        'builtinPluginsDir' => 'public/plugins',
        'marketPluginsDir' => 'data/market-plugins',
    );
}

/* ============================================================ T05 · 纯 PHP ZIP 读取器（无扩展回退，U2） */

/**
 * 最小 ZIP 读取器：解析 central directory，支持 store(0)/deflate(8)，
 * 不依赖 zip 扩展。仅用于市场插件包（≤5MB/≤100 文件），够用且可控。
 */
class BG_PM_ZipReader
{
    private $fh;
    private $size;
    private $entries = null;

    public function __construct($path)
    {
        $this->fh = @fopen($path, 'rb');
        if ($this->fh === false) {
            bg_fail(422, '无法打开插件包');
        }
        fseek($this->fh, 0, SEEK_END);
        $this->size = ftell($this->fh);
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    /** 解析并返回条目列表：name / method / csize / usize / crc / offset / isDir */
    public function entries()
    {
        if ($this->entries !== null) {
            return $this->entries;
        }
        // 从尾部找 EOCD（签名 0x06054b50），最多回看 66KB
        $max = min($this->size, 66560);
        fseek($this->fh, $this->size - $max);
        $tail = fread($this->fh, $max);
        $eocd = strrpos($tail, "\x50\x4b\x05\x06");
        if ($eocd === false) {
            bg_fail(422, '插件包格式错误（缺少 EOCD）');
        }
        $base = $this->size - $max + $eocd;
        // EOCD: +8 本盘条目数(2), +10 总条目数(2), +12 central dir 大小(4), +16 central dir 偏移(4)
        fseek($this->fh, $base + 10);
        $total = unpack('v', fread($this->fh, 2))[1];   // +10 total entries
        fread($this->fh, 4);                              // +12 size of cd（跳过）
        $cdOff = unpack('V', fread($this->fh, 4))[1];   // +16 offset of cd
        fseek($this->fh, $cdOff);
        $entries = array();
        for ($i = 0; $i < $total; $i++) {
            $hdr = fread($this->fh, 46);
            if (strlen($hdr) < 46 || substr($hdr, 0, 4) !== "\x50\x4b\x01\x02") {
                bg_fail(422, '插件包目录损坏');
            }
            // central directory header：sig(4) + verMade(2) + verNeed(2) 之后才是 flags。
            // 用绝对偏移 @ 逐字段定位，crc32 为 4 字节(V)。
            $h = unpack('@8/vflags/@10/vmethod/@12/vtime/@14/vdate/@16/Vcrc/@20/Vcsize/@24/Vusize/@28/vnlen/@30/vexlen/@32/vclen/@42/Voffset', $hdr);
            $name = fread($this->fh, $h['nlen']);
            fseek($this->fh, $h['exlen'] + $h['clen'], SEEK_CUR);
            $entries[] = array(
                'name' => $name,
                'method' => (int)$h['method'],
                'csize' => (int)$h['csize'],
                'usize' => (int)$h['usize'],
                'crc' => (int)$h['crc'],
                'offset' => (int)$h['offset'],
                'isDir' => substr($name, -1) === '/',
            );
        }
        $this->entries = $entries;
        return $entries;
    }

    /** 读取单个条目解压后的内容 */
    public function readEntry($entry)
    {
        fseek($this->fh, $entry['offset']);
        $hdr = fread($this->fh, 30);
        if (strlen($hdr) < 30 || substr($hdr, 0, 4) !== "\x50\x4b\x03\x04") {
            return false;
        }
        // local file header：sig(4) + verNeed(2) 之后是 flags；crc32 为 4 字节(V)
        $h = unpack('@6/vflags/@8/vmethod/@10/vtime/@12/vdate/@14/Vcrc/@18/Vcsize/@22/Vusize/@26/vnlen/@28/vexlen', $hdr);
        fseek($this->fh, $h['nlen'] + $h['exlen'], SEEK_CUR);
        $data = fread($this->fh, $h['csize']);
        if ($h['method'] === 0) {
            $raw = $data;
        } elseif ($h['method'] === 8) {
            if (!function_exists('gzinflate')) {
                return false;
            }
            $raw = @gzinflate($data);
            if ($raw === false) {
                return false;
            }
        } else {
            return false;
        }
        if (strlen($raw) !== $h['usize']) {
            return false;
        }
        return $raw;
    }
}

/* ============================================================ T05 · ZipInspector */

class BG_PM_ZipInspector
{
    /** 静态校验 zip 结构，返回规范化清单数组；不合规 bg_fail(422/413) */
    public function validate($zipPath, $expectId, $expectVersion = '')
    {
        if (!is_file($zipPath) || filesize($zipPath) > BG_PM_MAX_BYTES) {
            bg_fail(413, '插件包不存在或超过 5MB');
        }
        $reader = new BG_PM_ZipReader($zipPath);
        $entries = $reader->entries();
        $exts = explode(',', BG_PM_EXTS);
        $count = 0;
        $total = 0;
        $pluginJson = null;
        $hasEntryFile = false;
        foreach ($entries as $e) {
            $name = $e['name'];
            $count++;
            if ($count > BG_PM_MAX_FILES) {
                bg_fail(413, '插件包文件数超过 100');
            }
            $total += $e['usize'];
            if ($total > BG_PM_MAX_UNCOMPRESSED) {
                bg_fail(413, '插件包解压后超过 20MB');
            }
            if ($this->rejectSlip($name)) {
                bg_fail(422, '插件包含非法路径（zip-slip）');
            }
            if ($e['isDir']) {
                $depth = substr_count(rtrim($name, '/'), '/');
                if ($depth > BG_PM_MAX_DEPTH) {
                    bg_fail(413, '插件包目录层级过深');
                }
                continue;
            }
            $depth = substr_count($name, '/');
            if ($depth > BG_PM_MAX_DEPTH) {
                bg_fail(413, '插件包目录层级过深');
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $exts, true)) {
                bg_fail(422, '插件包含不允许的文件类型：.' . $ext);
            }
            if ($name === 'plugin.json') {
                if ($pluginJson !== null) {
                    bg_fail(422, '插件包含多个 plugin.json');
                }
                $pluginJson = $reader->readEntry($e);
            }
        }
        if ($pluginJson === null) {
            bg_fail(422, '插件包缺少 plugin.json');
        }
        $m = json_decode($pluginJson, true);
        if (!is_array($m)) {
            bg_fail(422, 'plugin.json 不是合法 JSON');
        }
        $id = isset($m['id']) && is_string($m['id']) ? $m['id'] : '';
        if (!bg_pm_valid_id($id) || $id !== $expectId) {
            bg_fail(422, 'plugin.json 的 id 与目标插件不一致');
        }
        $ver = isset($m['version']) && is_string($m['version']) ? $m['version'] : '';
        if ($expectVersion !== '' && $ver !== $expectVersion) {
            bg_fail(422, 'plugin.json 的 version 与目标版本不一致');
        }
        // 入口文件存在性（根目录）
        foreach ($entries as $e) {
            if ($e['name'] === (isset($m['entry']) && is_string($m['entry']) ? $m['entry'] : 'handler.php')) {
                $hasEntryFile = true;
                break;
            }
        }
        if (!$hasEntryFile) {
            bg_fail(422, '插件包缺少入口文件');
        }
        return $m;
    }

    /** 拒绝 zip-slip / 非法路径 */
    public function rejectSlip($name)
    {
        if ($name === '' || strpos($name, "\0") !== false) {
            return true;
        }
        if ($name[0] === '/' || $name[0] === '\\' || preg_match('#^[A-Za-z]:#', $name)) {
            return true;
        }
        if (strpos($name, '\\') !== false) {
            return true;
        }
        if (strpos($name, '..') !== false) {
            return true;
        }
        if (stripos($name, 'data/') === 0 || $name === 'data') {
            return true;
        }
        // 禁止敏感文件
        $base = strtolower(basename($name));
        if (in_array($base, array('.user.ini', '.htaccess'), true)) {
            return true;
        }
        // 禁止嵌套再包一层（首个路径段必须是文件而非统一目录名——只校验 id 一致性由 manifest 保证）
        return false;
    }

    /** 解压到目标目录（不含自身外层），返回 bool */
    public function extract($zipPath, $destDir)
    {
        if (!bg_pm_ensure_dir($destDir)) {
            return false;
        }
        $reader = new BG_PM_ZipReader($zipPath);
        foreach ($reader->entries() as $e) {
            if ($e['isDir']) {
                continue;
            }
            if ($this->rejectSlip($e['name'])) {
                return false;
            }
            $data = $reader->readEntry($e);
            if ($data === false) {
                return false;
            }
            // 手动 crc32 校验
            if (function_exists('crc32') && crc32($data) !== $e['crc']) {
                return false;
            }
            $target = $destDir . '/' . $e['name'];
            if (!bg_pm_assert_safe_path($target, $destDir)) {
                return false;
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
                return false;
            }
            if (@file_put_contents($target, $data) === false) {
                return false;
            }
        }
        return true;
    }
}

/* ============================================================ T05 · PackageFetcher */

class BG_PM_PackageFetcher
{
    /** 下载 zip 到 public/.cache/pm-download-<rand>.zip，校验 sha256 与大小 */
    public function downloadToTemp($url, $token, $sha256)
    {
        if (strpos($url, 'https://') !== 0) {
            bg_fail(400, '包地址必须是 https');
        }
        $cache = bg_pm_cache_dir();
        $tmp = $cache . '/pm-download-' . bin2hex(random_bytes(8)) . '.zip';
        $headers = array(
            'User-Agent: baogong-plugin-market/1.1',
            'Accept: application/octet-stream',
        );
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $res = bg_http('GET', $url, $headers, '', 30);
        if (!$res['ok'] || $res['status'] < 200 || $res['status'] >= 300) {
            @unlink($tmp);
            bg_fail(410, '插件包下载失败（HTTP ' . $res['status'] . '）');
        }
        $len = strlen($res['body']);
        if ($len > BG_PM_MAX_BYTES) {
            @unlink($tmp);
            bg_fail(413, '插件包超过 5MB');
        }
        if (function_exists('hash') && strtolower(hash('sha256', $res['body'])) !== strtolower($sha256)) {
            @unlink($tmp);
            bg_fail(410, '插件包校验失败（SHA256 不符）');
        }
        if (@file_put_contents($tmp, $res['body']) === false) {
            bg_fail(500, '插件包暂存失败');
        }
        return $tmp;
    }
}

/* ============================================================ T05 · 双目录合并扫描 */

class BG_PM_Scanner
{
    /** 合并扫描必备区（只读）+ 市场区（唯一写根），同名 market 优先 */
    public function scanAll()
    {
        $all = array();
        // 1) 必备基线（public/plugins）——只读
        $builtin = $this->scanDir(bg_pm_builtin_dir(), 'builtin');
        foreach ($builtin as $id => $m) {
            $m['source'] = 'builtin';
            $all[$id] = $m;
        }
        // 2) 市场副本（data/market-plugins）——覆盖同名
        $market = $this->scanDir(bg_pm_market_dir(), 'market');
        foreach ($market as $id => $m) {
            $m['source'] = 'market';
            $all[$id] = $m;  // market 优先
        }
        return $all;
    }

    private function scanDir($dir, $source)
    {
        $list = array();
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
                continue;  // .tmp/.bak/.trash 自动忽略
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

    /** 判断某 id 是否有市场副本 */
    public function hasMarketCopy($id)
    {
        return is_file(bg_pm_market_dir() . '/' . $id . '/plugin.json');
    }

    /** 判断某 id 是否有必备基线 */
    public function hasBuiltinCopy($id)
    {
        return is_file(bg_pm_builtin_dir() . '/' . $id . '/plugin.json');
    }
}

/* ============================================================ T05 · PluginInstaller */

class BG_PM_Installer
{
    private $vu;

    public function __construct()
    {
        $this->vu = new BG_PM_VersionUtil();
    }

    /** 从索引取条目；找不到 bg_fail(404) */
    private function entryFromIndex($id, $version)
    {
        $ic = new BG_PM_IndexClient();
        $idx = $ic->fetch(false);
        if (!isset($idx['plugins'][$id])) {
            bg_fail(404, '索引中无此插件');
        }
        $entry = $idx['plugins'][$id];
        if ($version !== '' && $this->vu->compare($entry['version'], $version) !== 0) {
            bg_fail(404, '目标版本不存在于索引');
        }
        if (!$this->vu->systemAtLeast($entry['minSystemVersion'])) {
            bg_fail(409, '当前系统版本低于插件要求（' . $entry['minSystemVersion'] . '）');
        }
        return $entry;
    }

    /** 安装或升级的公共落盘流程（只写 data/market-plugins/），返回新清单 */
    private function landPackage($entry)
    {
        $id = $entry['id'];
        $root = bg_pm_market_dir();
        if (!bg_pm_ensure_dir($root)) {
            bg_fail(500, '市场插件目录不可写');
        }
        // 清理失败中断遗留的临时解压目录（超过 1 小时）
        foreach ((array)@glob($root . '/.tmp-*') as $stale) {
            if (is_dir($stale) && @filemtime($stale) < time() - 3600) {
                bg_pm_rmrf($stale);
            }
        }
        $src = new BG_PM_SourceConfig();
        $cfg = $src->load();
        $fetcher = new BG_PM_PackageFetcher();
        $tmpZip = $fetcher->downloadToTemp($entry['package']['url'], $cfg['token'], $entry['package']['sha256']);
        $inspector = new BG_PM_ZipInspector();
        try {
            // 校验 zip 结构与版本一致性
            $inspector->validate($tmpZip, $id, $entry['version']);
            $tmpDir = $root . '/.tmp-' . $id . '-' . $entry['version'] . '-' . bin2hex(random_bytes(4));
            if (!bg_pm_assert_safe_path($tmpDir, $root)) {
                bg_fail(500, '解压路径越界');
            }
            // 解压到以插件 id 命名的子目录：bg_plugin_manifest() 要求目录名 == id
            $stage = $tmpDir . '/' . $id;
            if (!@mkdir($stage, 0755, true)) {
                bg_fail(500, '解压目录创建失败');
            }
            if (!$inspector->extract($tmpZip, $stage)) {
                bg_fail(422, '插件包解压失败');
            }
            // 二次清单校验（要求目录名==id）
            $manifest = bg_plugin_manifest($stage . '/plugin.json');
            if (!$manifest || $manifest['id'] !== $id) {
                bg_fail(422, '解压后的插件清单不合法');
            }
            $target = $root . '/' . $id;
            $bak = '';
            // 备份已有市场副本（首次安装跳过；绝不碰 public/）
            if (is_dir($target)) {
                $bak = $root . '/.bak-' . $id . '-' . bin2hex(random_bytes(3));
                if (!@rename($target, $bak)) {
                    bg_fail(500, '备份旧版本失败');
                }
            }
            // 原子生效
            if (!@rename($stage, $target)) {
                if ($bak !== '') {
                    @rename($bak, $target);
                }
                bg_fail(500, '插件生效失败，已回滚');
            }
            @unlink($tmpZip);
            if (is_dir($tmpDir)) {
                bg_pm_rmrf($tmpDir);
            }
            // 保留 .bak-* 供 rollback 回退（P1）；清理策略由后续定期任务处理，不在此即时删除
            $manifest['source'] = 'market';
            return $manifest;
        } catch (Throwable $e) {
            @unlink($tmpZip);
            if (isset($tmpDir) && is_dir($tmpDir)) {
                bg_pm_rmrf($tmpDir);
            }
            throw $e;
        }
    }

    public function install($id, $version = '')
    {
        if (!bg_pm_valid_id($id)) {
            bg_fail(400, '插件标识不合法');
        }
        $scanner = new BG_PM_Scanner();
        $local = $scanner->scanAll();
        if (isset($local[$id])) {
            bg_fail(409, '插件已安装，请使用升级');
        }
        $entry = $this->entryFromIndex($id, $version);
        $m = $this->landPackage($entry);
        bg_json(200, array('ok' => true, 'id' => $id, 'version' => $m['version'], 'plugin' => bg_plugin_public($m)));
    }

    public function upgrade($id, $version = '')
    {
        if (!bg_pm_valid_id($id)) {
            bg_fail(400, '插件标识不合法');
        }
        $scanner = new BG_PM_Scanner();
        $local = $scanner->scanAll();
        $entry = $this->entryFromIndex($id, $version);
        $localVer = isset($local[$id]) ? $local[$id]['version'] : '0.0.0';
        if (!$this->vu->isUpgrade($localVer, $entry['version'])) {
            bg_fail(409, '已是最新版本或目标版本更低');
        }
        $m = $this->landPackage($entry);
        // 配置保留：data/plugins.json 只读，不写任何键
        bg_json(200, array('ok' => true, 'id' => $id, 'from' => $localVer, 'to' => $m['version'], 'configPreserved' => true, 'plugin' => bg_plugin_public($m)));
    }

    public function uninstall($id)
    {
        if (!bg_pm_valid_id($id)) {
            bg_fail(400, '插件标识不合法');
        }
        $scanner = new BG_PM_Scanner();
        if (!$scanner->hasMarketCopy($id)) {
            if ($scanner->hasBuiltinCopy($id)) {
                bg_fail(409, '系统必备插件不可卸载');
            }
            bg_fail(404, '插件未安装');
        }
        $root = bg_pm_market_dir();
        $target = $root . '/' . $id;
        $trash = $root . '/.trash';
        bg_pm_ensure_dir($trash);
        $dest = $trash . '/' . $id . '-' . date('YmdHis');
        if (!@rename($target, $dest)) {
            bg_fail(500, '卸载失败');
        }
        $fallback = $scanner->hasBuiltinCopy($id);  // 回落到必备基线
        bg_json(200, array('ok' => true, 'id' => $id, 'fallbackToBuiltin' => $fallback));
    }

    public function rollback($id)
    {
        if (!bg_pm_valid_id($id)) {
            bg_fail(400, '插件标识不合法');
        }
        $root = bg_pm_market_dir();
        $target = $root . '/' . $id;
        $baks = @glob($root . '/.bak-' . $id . '-*');
        if (!is_array($baks) || count($baks) === 0) {
            bg_fail(404, '没有可回退的历史版本');
        }
        // 取最新的备份
        usort($baks, function ($a, $b) {
            return @filemtime($b) - @filemtime($a);
        });
        $bak = $baks[0];
        if (is_dir($target)) {
            bg_pm_rmrf($target);
        }
        if (!@rename($bak, $target)) {
            bg_fail(500, '回退失败');
        }
        $m = bg_plugin_manifest($target . '/plugin.json');
        bg_json(200, array('ok' => true, 'id' => $id, 'to' => $m ? $m['version'] : ''));
    }
}

/** 递归删除目录（仅限市场写根内调用） */
function bg_pm_rmrf($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        $p = $dir . '/' . $n;
        if (is_dir($p) && !is_link($p)) {
            bg_pm_rmrf($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

/** 供 api.php 的 bg_plugin_all() 调用的合并扫描包装 */
function bg_pm_all_plugins()
{
    $scanner = new BG_PM_Scanner();
    return $scanner->scanAll();
}

} // end BG_PM_LOADED
