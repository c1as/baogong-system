<?php
/**
 * 鉴权：BglxAuth
 *
 * 本插件没有主程序会话可用，因此使用独立 session（BGLXSESSID）+ 管理令牌。
 * 四级取值优先级：请求头 X-Bglx-Token / token 参数 → 独立 Session → 环境变量。
 * 配置令牌为空时仅允许本机回环访问，方便本地零配置开发。
 */

if (!defined('BGLX_AUTH_LOADED')) {
    define('BGLX_AUTH_LOADED', 1);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/http.php';

/**
 * 手写字词库鉴权器
 */
class BglxAuth
{
    /** 独立 session 名，避免与主程序会话冲突 */
    const SESSION_NAME = 'BGLXSESSID';

    /** session 中保存的键（存配置令牌的 SHA-256，不存明文） */
    const SESSION_KEY = 'bglx_auth';

    /** @var string 配置令牌（来自 plugins.json 或环境变量） */
    private $configured;

    /**
     * @var array 可信反向代理 IP 列表；默认空，表示绝不采信 XFF / X-Real-IP
     */
    private $trustedProxies = array();

    /**
     * @param array|null $config 可选配置覆盖（键 adminToken / trustedProxy）
     */
    public function __construct($config = null)
    {
        if (is_array($config) && array_key_exists('adminToken', $config)) {
            $this->configured = trim((string)$config['adminToken']);
        } else {
            $this->configured = trim(bglx_setting('adminToken', ''));
        }

        // 显式 opt-in：只有直连对等体命中该名单时，才允许采信转发头
        if (is_array($config) && array_key_exists('trustedProxy', $config)) {
            $raw = (string)$config['trustedProxy'];
        } else {
            $raw = bglx_setting('trustedProxy', '');
        }
        if (trim($raw) !== '') {
            foreach (preg_split('/[,，\s]+/u', trim($raw)) as $ip) {
                $ip = strtolower(trim((string)$ip));
                if ($ip !== '' && !in_array($ip, $this->trustedProxies, true)) {
                    $this->trustedProxies[] = $ip;
                }
            }
        }
    }

    /**
     * 当前鉴权模式。
     *
     * @return string token（已配置令牌）或 loopback（仅本机）
     */
    public function mode()
    {
        return $this->configured === '' ? 'loopback' : 'token';
    }

    /** 是否已配置管理令牌 */
    public function hasToken()
    {
        return $this->configured !== '';
    }

    /**
     * 启动独立 session。幂等，且不在输出后重复设置 cookie。
     */
    public function startSession()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (session_name() !== self::SESSION_NAME) {
            session_name(self::SESSION_NAME);
        }
        if (!headers_sent()) {
            if (PHP_VERSION_ID >= 70300) {
                session_set_cookie_params(array(
                    'lifetime' => 0,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                ));
            } else {
                session_set_cookie_params(0, '/', '', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', true);
            }
        }
        @session_start();
    }

    /**
     * 请求中显式提供的令牌（请求头优先，其次 token 参数）。
     *
     * @return string
     */
    public function providedToken()
    {
        $header = '';
        if (isset($_SERVER['HTTP_X_BGLX_TOKEN'])) {
            $header = trim((string)$_SERVER['HTTP_X_BGLX_TOKEN']);
        }
        if ($header === '') {
            // 兼容部分 Web 服务器不转发自定义头的情况
            if (function_exists('getallheaders')) {
                $headers = getallheaders();
                if (is_array($headers)) {
                    foreach ($headers as $k => $v) {
                        if (strtolower((string)$k) === 'x-bglx-token') {
                            $header = trim((string)$v);
                            break;
                        }
                    }
                }
            }
        }
        if ($header !== '') {
            return $header;
        }
        $param = bglx_param('token', '');
        return is_scalar($param) ? trim((string)$param) : '';
    }

    /**
     * 是否来自本机回环地址。
     *
     * 默认只信任服务器直连对等体 REMOTE_ADDR（不可由客户端通过请求头伪造）。
     * 仅当部署在反向代理后并显式配置了 trustedProxy，且直连对等体命中该
     * 代理名单时，才采信 X-Forwarded-For / X-Real-IP 的第一跳。
     *
     * @return bool
     */
    public function isLoopback()
    {
        $peer = strtolower(bglx_peer_ip());
        $ip = $peer;
        if ($peer !== '' && $this->trustedProxies && in_array($peer, $this->trustedProxies, true)) {
            $forwarded = strtolower(bglx_forwarded_ip());
            if ($forwarded !== '') {
                $ip = $forwarded;
            }
        }
        $allowed = array('127.0.0.1', '::1', '::ffff:127.0.0.1', 'localhost');
        return in_array($ip, $allowed, true);
    }

    /**
     * 校验令牌或会话。
     *
     * @param string|null $token 显式令牌；null 时从请求中解析
     * @return bool
     */
    public function check($token = null)
    {
        // 未配置令牌：仅回环地址放行
        if ($this->configured === '') {
            return $this->isLoopback();
        }
        if ($token === null) {
            $token = $this->providedToken();
        }
        $token = is_scalar($token) ? trim((string)$token) : '';

        if ($token !== '') {
            return hash_equals($this->configured, $token);
        }

        // 请求未带令牌时，检查独立 session 中保存的摘要
        $this->startSession();
        $stored = isset($_SESSION[self::SESSION_KEY]) ? (string)$_SESSION[self::SESSION_KEY] : '';
        $expected = hash('sha256', $this->configured);
        return $stored !== '' && hash_equals($expected, $stored);
    }

    /**
     * 管理页登录：校验令牌并写入 session 摘要。
     *
     * @param string $token 用户提交的令牌
     * @return bool
     */
    public function login($token)
    {
        $token = is_scalar($token) ? trim((string)$token) : '';
        if ($this->configured === '') {
            // 未配置令牌时，回环地址允许直接登录（session 中写入空摘要标记）
            if (!$this->isLoopback()) {
                return false;
            }
            $this->startSession();
            $_SESSION[self::SESSION_KEY] = hash('sha256', '');
            return true;
        }
        if ($token === '' || !hash_equals($this->configured, $token)) {
            return false;
        }
        $this->startSession();
        $_SESSION[self::SESSION_KEY] = hash('sha256', $this->configured);
        return true;
    }

    /** 退出登录 */
    public function logout()
    {
        $this->startSession();
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * 校验失败时输出统一错误并终止；成功时静默返回。
     */
    public function requireAuth()
    {
        if ($this->check()) {
            return;
        }
        if ($this->configured === '' && !$this->isLoopback()) {
            bglx_fail(40301, '未配置管理令牌，仅允许本机回环地址访问');
        }
        bglx_fail(40101, '管理令牌缺失或无效');
    }
}
