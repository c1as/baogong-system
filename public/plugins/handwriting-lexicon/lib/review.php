<?php
/**
 * 人工判断审查子系统 · 唯一引导器
 *
 * 只有 api.php / admin.php / check.php 三处引入本文件；
 * lexicon.php / bridge.php / corrector.php 完全不感知审查逻辑，
 * 因此 OCR 热路径不因本次增量增加任何开销或故障面。
 *
 * 依赖方向单向：review.php → 各审查 lib → 既有 lib，杜绝循环 require。
 */

if (defined('BGLX_REVIEW_BOOT_LOADED')) {
    return;
}
define('BGLX_REVIEW_BOOT_LOADED', 1);

/* ---------------- 错误码与硬上限常量 ---------------- */
if (!defined('BGLX_REV_DISABLED')) {
    define('BGLX_REV_DISABLED', 41001);          // 审查功能未启用
    define('BGLX_REV_DIR_UNREADABLE', 41002);    // 附件/日志目录不可读
    define('BGLX_REV_AI_FAILED', 41003);         // AI 整理失败（可降级）
    define('BGLX_REV_CSRF_INVALID', 41004);      // CSRF nonce 无效
    define('BGLX_REV_VERSION_CONFLICT', 40901);  // 乐观锁冲突（与 BglxLockException 同码）
    define('BGLX_REV_IMMUTABLE', 41005);         // 已入库终态只读 / 整图批量判定关闭
    define('BGLX_REV_ARCHIVE_FAIL', 41006);      // 归档失败告警（不回滚判定，仅 warnings）
}
if (!defined('BGLX_REV_LIMITS')) {
    define('BGLX_REV_MAX_SCAN_FILES', 5000);     // 单次图片扫描上限
    define('BGLX_REV_MAX_SCAN_LINES', 200000);   // 单日志文件扫描行上限
    define('BGLX_REV_MAX_DAYS', 31);             // 跨日期查询上限
    define('BGLX_REV_LINE_BYTES', 131072);       // 单行 fgets 限长
    define('BGLX_REV_MAX_PAGE', 200);            // 返回条数硬顶
    define('BGLX_REV_CSRF_SESSION_KEY', 'bglx_rev_csrf');
    define('BGLX_REV_LIMITS', 1);
}

require_once __DIR__ . '/lexicon.php';       // 既有：bglx_store / bglx_build_entry / bglx_validate_entry
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/http.php';

/**
 * 按需加载全部审查模块。
 *
 * require_once 保证幂等；顺序遵循依赖：存储 → 判定服务 → 附件 → 日志 → 关联 → AI → 入库。
 * review_judge.php 必须紧跟 review_store.php（派生函数被存储层写回时调用）。
 *
 * @return void
 */
function bglx_rev_bootstrap()
{
    require_once __DIR__ . '/review_store.php';
    require_once __DIR__ . '/review_judge.php';
    require_once __DIR__ . '/attachment.php';
    require_once __DIR__ . '/log_reader.php';
    require_once __DIR__ . '/linker.php';
    require_once __DIR__ . '/ai_organizer.php';
    require_once __DIR__ . '/importer.php';
}

/**
 * 审查功能总开关守卫：关闭时所有 review_* 动作统一 41001。
 *
 * @return void
 */
function bglx_rev_require_enabled()
{
    if (!bglx_rev_setting_bool('reviewEnabled', false)) {
        bglx_fail(BGLX_REV_DISABLED, '人工判断审查功能未启用，请先在插件设置中打开 reviewEnabled');
    }
}

/**
 * 生成（或在会话中取回）本页 CSRF nonce。
 *
 * 与鉴权共用独立会话 BGLXSESSID，cookie 保持 SameSite=Lax + HttpOnly。
 *
 * @return string 十六进制 nonce
 */
function bglx_rev_csrf_token()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (session_name() !== BglxAuth::SESSION_NAME) {
            session_name(BglxAuth::SESSION_NAME);
        }
        if (!headers_sent()) {
            $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            if (PHP_VERSION_ID >= 70300) {
                session_set_cookie_params(array(
                    'lifetime' => 0, 'path' => '/', 'httponly' => true,
                    'samesite' => 'Lax', 'secure' => $secure,
                ));
            } else {
                session_set_cookie_params(0, '/', '', $secure, true);
            }
        }
        @session_start();
    }
    if (empty($_SESSION[BGLX_REV_CSRF_SESSION_KEY])) {
        $nonce = function_exists('random_bytes')
            ? bin2hex(random_bytes(16))
            : md5(uniqid('bglx-rev-', true) . mt_rand());
        $_SESSION[BGLX_REV_CSRF_SESSION_KEY] = $nonce;
    }
    return (string)$_SESSION[BGLX_REV_CSRF_SESSION_KEY];
}

/**
 * 写动作二次校验：必须 POST，且必须携带本会话签发的 nonce。
 *
 * nonce 允许来自请求头 X-Bglx-CSRF 或请求参数 csrf；
 * 不采信 Referer（可被代理剥离）。
 *
 * @return void
 */
function bglx_rev_verify_write()
{
    if (!bglx_is_post()) {
        bglx_fail(40001, '写操作必须使用 POST 请求');
    }
    $expected = bglx_rev_csrf_token();

    $provided = '';
    if (isset($_SERVER['HTTP_X_BGLX_CSRF'])) {
        $provided = trim((string)$_SERVER['HTTP_X_BGLX_CSRF']);
    }
    if ($provided === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strtolower((string)$k) === 'x-bglx-csrf') {
                    $provided = trim((string)$v);
                    break;
                }
            }
        }
    }
    if ($provided === '') {
        $provided = trim((string)bglx_param('csrf', ''));
    }
    if ($provided === '' || !hash_equals($expected, $provided)) {
        bglx_fail(BGLX_REV_CSRF_INVALID, '审查操作的安全令牌无效或已过期，请刷新页面后重试');
    }
}

/**
 * 脱敏后的操作人标识：不落明文令牌、不落完整客户端 IP。
 *
 * @return string 形如 token:a1b2c3d4 / session:xxxx / ip:127.0.0.1 / unknown
 */
function bglx_rev_reviewer()
{
    $auth = new BglxAuth();
    $token = $auth->providedToken();
    if ($token !== '') {
        return 'token:' . substr(hash('sha256', $token), 0, 8);
    }
    if (session_status() === PHP_SESSION_ACTIVE
        && !empty($_SESSION[BglxAuth::SESSION_KEY])) {
        return 'session:' . substr((string)$_SESSION[BglxAuth::SESSION_KEY], 0, 8);
    }
    $peer = bglx_peer_ip();
    return $peer !== '' ? 'ip:' . bglx_cut($peer, 45) : 'unknown';
}
