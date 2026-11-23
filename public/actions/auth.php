<?php
/* 模块: actions/auth
   Action: health login logout state bootstrap
   职责: 登录、登出、启动数据（全量快照与计数）
   依赖: lib/auth lib/repo lib/query
   注意: health/login 免登录，其余需鉴权 */

function act_health()
{
    $db = bg_db_load();
    bg_json(200, array(
        'ok' => true,
        'time' => gmdate('c'),
        'parts' => count($db['parts']),
        'reports' => count($db['reports']),
        'orders' => count($db['orders']),
    ));
}

function act_login()
{
    global $APP_PASSWORD, $method;
    if ($method !== 'POST') {
        bg_fail(405, '请求方法不支持');
    }
    $body = bg_body();
    $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'unknown';

    $fails = bg_throttle_load();
    $rec = isset($fails[$ip]) && is_array($fails[$ip]) ? $fails[$ip] : array('n' => 0, 'until' => 0);
    if (isset($rec['until']) && (int)$rec['until'] > time()) {
        bg_fail(429, '尝试次数过多，请 1 分钟后再试');
    }

    $ok = false;
    if ($APP_PASSWORD !== '' && strlen($password) === strlen($APP_PASSWORD)) {
        $ok = hash_equals($APP_PASSWORD, $password);
    }
    if (!$ok) {
        $rec['n'] = (isset($rec['n']) ? (int)$rec['n'] : 0) + 1;
        if ($rec['n'] >= 5) {
            $rec['until'] = time() + 60;
            $rec['n'] = 0;
        }
        $fails[$ip] = $rec;
        bg_throttle_save($fails);
        bg_fail(401, '密码不正确');
    }
    unset($fails[$ip]);
    bg_throttle_save($fails);
    setcookie('bg_session', bg_token(), array(
        'expires' => time() + 2592000,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    bg_json(200, array('ok' => true));
}

function act_state()
{
    global $APP_PASSWORD;
    $db = bg_db_load();
    bg_json(200, array(
        'parts' => $db['parts'],
        'reports' => bg_sorted($db['reports']),
        'orders' => bg_sorted($db['orders']),
        'auth' => $APP_PASSWORD !== '',
        'imageBase' => 'api.php?action=image&f=',
    ));
    return;
}

function act_bootstrap()
{
    global $APP_PASSWORD;
    $pdo = bg_pdo();
    $first = (string)$pdo->query('SELECT COALESCE(MIN(date), \'\') FROM reports')->fetchColumn();
    bg_json(200, array(
        'auth' => $APP_PASSWORD !== '',
        'imageBase' => 'api.php?action=image&f=',
        'counts' => bg_bootstrap_counts($pdo),
        'firstDate' => $first,
        'version' => '2.8',
    ));
    return;
}

function act_logout()
{
    setcookie('bg_session', '', array(
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    bg_json(200, array('ok' => true));
    return;
}
