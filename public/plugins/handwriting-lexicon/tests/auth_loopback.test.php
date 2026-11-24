<?php
/**
 * R1 安全加固回归测试：空 token 时回环判定不得采信可伪造的代理头。
 *
 * 运行：php tests/auth_loopback.test.php
 */

require_once dirname(__DIR__) . '/lib/lexicon.php';

$pass = 0;
$fail = 0;

/**
 * @param bool   $condition 断言
 * @param string $label     用例名
 */
function bglx_auth_assert($condition, $label)
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "[PASS] " . $label . "\n";
    } else {
        $fail++;
        echo "[FAIL] " . $label . "\n";
    }
}

/** 设置模拟请求环境 */
function bglx_auth_env($peer, $forwarded)
{
    $_SERVER['REMOTE_ADDR'] = $peer;
    if ($forwarded === '') {
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
    } else {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $forwarded;
        $_SERVER['HTTP_X_REAL_IP'] = $forwarded;
    }
}

echo "=== R1 回环判定安全测试 ===\n";

/* 1. 攻击场景：远端伪造 XFF 为 127.0.0.1，默认配置下必须拒绝 */
bglx_auth_env('8.8.8.8', '127.0.0.1');
$auth = new BglxAuth(array('adminToken' => '', 'trustedProxy' => ''));
bglx_auth_assert($auth->isLoopback() === false, '伪造 X-Forwarded-For: 127.0.0.1 不能判为回环');
bglx_auth_assert($auth->check() === false, '空令牌 + 伪造代理头时 check() 拒绝访问');

/* 2. 反向场景：本机直连，即使声明了远端 IP 也仍按直连地址判为回环 */
bglx_auth_env('127.0.0.1', '8.8.8.8');
$auth = new BglxAuth(array('adminToken' => '', 'trustedProxy' => ''));
bglx_auth_assert($auth->isLoopback() === true, '本机直连时忽略伪造的远端 XFF');

/* 3. 显式 opt-in：直连对等体在可信代理名单内才采信 XFF */
bglx_auth_env('8.8.8.8', '127.0.0.1');
$auth = new BglxAuth(array('adminToken' => '', 'trustedProxy' => '8.8.8.8'));
bglx_auth_assert($auth->isLoopback() === true, '显式配置可信代理后采信其 XFF');

$auth = new BglxAuth(array('adminToken' => '', 'trustedProxy' => '1.1.1.1'));
bglx_auth_assert($auth->isLoopback() === false, '直连地址不在可信代理名单时仍拒绝');

/* 4. 通用客户端 IP 函数行为保持不变，仅供日志等非鉴权用途 */
bglx_auth_env('8.8.8.8', '127.0.0.1');
bglx_auth_assert(bglx_client_ip() === '127.0.0.1', 'bglx_client_ip() 仍返回声明值（日志用途，行为未变）');
bglx_auth_assert(bglx_peer_ip() === '8.8.8.8', 'bglx_peer_ip() 只返回不可伪造的直连地址');

echo "\n=== 结果：" . $pass . " 通过 / " . $fail . " 失败 ===\n";
exit($fail === 0 ? 0 : 1);
