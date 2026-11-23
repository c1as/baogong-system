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

function bg_token()
{
    $payload = bg_b64url(json_encode(array('exp' => time() + 2592000)));
    $sig = bg_b64url(hash_hmac('sha256', $payload, bg_secret(), true));
    return $payload . '.' . $sig;
}

function bg_verify_token($t)
{
    $pos = strrpos($t, '.');
    if ($pos === false) {
        return false;
    }
    $payload = substr($t, 0, $pos);
    $sig = substr($t, $pos + 1);
    $expect = bg_b64url(hash_hmac('sha256', $payload, bg_secret(), true));
    if (!hash_equals($expect, $sig)) {
        return false;
    }
    $json = bg_b64url_decode($payload);
    if ($json === '') {
        return false;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['exp'])) {
        return false;
    }
    return (int)$data['exp'] > time();
}

function bg_authed()
{
    global $APP_PASSWORD;
    if ($APP_PASSWORD === '') {
        return true;
    }
    if (!isset($_COOKIE['bg_session']) || !is_string($_COOKIE['bg_session'])) {
        return false;
    }
    return bg_verify_token($_COOKIE['bg_session']);
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
