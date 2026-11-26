<?php
/* 模块: lib/auth
   职责: 登录 token 签发/校验、频率限制
   依赖: 无
   注意: token 依赖 data/.secret */

/* ================================================================ 登录 */

function bg_b64url($s)
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function bg_b64url_decode($s)
{
    $s = strtr($s, '-_', '+/');
    $n = strlen($s) % 4;
    if ($n !== 0) {
        $s .= str_repeat('=', 4 - $n);
    }
    $r = base64_decode($s, true);
    return $r === false ? '' : $r;
}

function bg_secret()
{
    global $SECRET_FILE;
    if (is_file($SECRET_FILE)) {
        $s = @file_get_contents($SECRET_FILE);
        if ($s !== false && strlen($s) >= 16) {
            return $s;
        }
    }
    $s = random_bytes(32);
    @file_put_contents($SECRET_FILE, $s, LOCK_EX);
    @chmod($SECRET_FILE, 0600);
    return $s;
}

/* 会话有效期（秒）；到期前一半时间会滑动续期 */
function bg_session_ttl()
{
    return 604800;
}

/* 密码指纹：写进 token 载荷，改密后旧 token 立即失效 */
function bg_password_fp()
{
    global $APP_PASSWORD;
    return substr(hash_hmac('sha256', 'pwd:' . (string)$APP_PASSWORD, bg_secret()), 0, 32);
}

function bg_token()
{
    $now = time();
    $payload = bg_b64url(json_encode(array(
        'exp' => $now + bg_session_ttl(),
        'iat' => $now,
        'fp' => bg_password_fp(),
    )));
    $sig = bg_b64url(hash_hmac('sha256', $payload, bg_secret(), true));
    return $payload . '.' . $sig;
}

/* 校验签名、有效期与密码指纹，通过时返回载荷 */
function bg_token_claims($t)
{
    if (!is_string($t) || $t === '') {
        return null;
    }
    $pos = strrpos($t, '.');
    if ($pos === false) {
        return null;
    }
    $payload = substr($t, 0, $pos);
    $sig = substr($t, $pos + 1);
    $expect = bg_b64url(hash_hmac('sha256', $payload, bg_secret(), true));
    if (!hash_equals($expect, $sig)) {
        return null;
    }
    $json = bg_b64url_decode($payload);
    if ($json === '') {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['exp']) || !isset($data['fp'])) {
        return null;
    }
    if (!hash_equals(bg_password_fp(), (string)$data['fp'])) {
        return null;
    }
    if ((int)$data['exp'] <= time()) {
        return null;
    }
    return $data;
}

function bg_verify_token($t)
{
    return bg_token_claims($t) !== null;
}

function bg_is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443;
}

function bg_session_cookie($token, $expires)
{
    if (headers_sent()) {
        return;
    }
    setcookie('bg_session', $token, array(
        'expires' => $expires,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => bg_is_https(),
    ));
}

/** 是否为回环直连请求（不信任任何代理头，只看 TCP 对端地址） */
function bg_request_loopback()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    return $ip === '127.0.0.1' || $ip === '::1' || $ip === '::ffff:127.0.0.1';
}

function bg_authed()
{
    global $APP_PASSWORD;
    if ($APP_PASSWORD === '') {
        /* 未设密码时仅允许本机访问：空密码不再等于"全开放"，避免局域网/公网裸奔 */
        return bg_request_loopback();
    }
    if (!isset($_COOKIE['bg_session']) || !is_string($_COOKIE['bg_session'])) {
        return false;
    }
    $claims = bg_token_claims($_COOKIE['bg_session']);
    if ($claims === null) {
        return false;
    }
    /* 滑动续期：剩余不足一半有效期时重新签发，长期在线不会突然掉线 */
    if (!isset($claims['iat']) || (int)$claims['iat'] + (int)(bg_session_ttl() / 2) < time()) {
        bg_session_cookie(bg_token(), time() + bg_session_ttl());
    }
    return true;
}

function bg_throttle_load()
{
    global $DATA_DIR;
    $f = $DATA_DIR . '/login-fails.json';
    if (!is_file($f)) {
        return array();
    }
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : array();
}

function bg_throttle_save($d)
{
    global $DATA_DIR;
    @file_put_contents($DATA_DIR . '/login-fails.json', json_encode($d), LOCK_EX);
}
