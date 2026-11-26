<?php
/* 模块: actions/auth
   Action: health login logout state bootstrap
   职责: 登录、登出、启动数据（全量快照与计数）
   依赖: lib/auth lib/repo lib/query
   注意: health/login 免登录，其余需鉴权 */

function act_health()
{
    /* 免登录接口只做计数，不再全量读库 + 逐条 md5，避免未认证请求放大 CPU/IO */
    $pdo = bg_pdo();
    bg_json(200, array(
        'ok' => true,
        'time' => gmdate('c'),
        'parts' => (int)$pdo->query('SELECT COUNT(*) FROM parts')->fetchColumn(),
        'reports' => (int)$pdo->query('SELECT COUNT(*) FROM reports')->fetchColumn(),
        'orders' => (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
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

    if ($APP_PASSWORD === '') {
        bg_fail(403, '服务器未设置访问密码，仅允许本机访问；请先在 app.env 设置 APP_PASSWORD');
    }

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
    bg_session_cookie(bg_token(), time() + bg_session_ttl());
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
        'version' => '3.6',
    ));
    return;
}

function act_logout()
{
    bg_session_cookie('', time() - 3600);
    bg_json(200, array('ok' => true));
    return;
}
