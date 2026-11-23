<?php
/* 模块: lib/http
   职责: JSON 响应、请求体解析、字段校验与归一化工具
   依赖: 无
   注意: bg_json 会直接 exit，调用后不再返回 */

/* ================================================================ 输出工具 */

function bg_json($status, $data)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bg_fail($status, $message)
{
    global $BG_THROW;
    if ($BG_THROW) {
        throw new RuntimeException($message);
    }
    bg_json($status, array('error' => $message));
}

function bg_len($s)
{
    if (function_exists('mb_strlen')) {
        return (int)mb_strlen($s, 'UTF-8');
    }
    $n = @preg_match_all('/./us', $s);
    return ($n === false) ? strlen($s) : (int)$n;
}

function bg_str($v, $max, $field)
{
    if ($v === null) {
        $v = '';
    }
    if (is_array($v) || is_object($v)) {
        $v = '';
    }
    if (!is_string($v)) {
        $v = (string)$v;
    }
    $v = trim($v);
    if (bg_len($v) > $max) {
        bg_fail(400, $field . '最多 ' . $max . ' 个字符');
    }
    return $v;
}

function bg_num($v, $field, $min = 0, $max = 1000000000)
{
    if ($v === null || $v === '' || is_bool($v) || is_array($v)) {
        bg_fail(400, $field . '必须是数字');
    }
    if (!is_numeric($v)) {
        bg_fail(400, $field . '必须是数字');
    }
    $n = (float)$v;
    if (!is_finite($n)) {
        bg_fail(400, $field . '必须是数字');
    }
    if ($n < $min) {
        bg_fail(400, $field . '不能小于 ' . $min);
    }
    if ($n > $max) {
        bg_fail(400, $field . '不能大于 ' . $max);
    }
    return $n;
}

function bg_round($n)
{
    return round($n, 2);
}

function bg_date($v)
{
    $s = bg_str($v, 10, '日期');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        bg_fail(400, '日期格式应为 YYYY-MM-DD');
    }
    $parts = explode('-', $s);
    if (count($parts) !== 3) {
        bg_fail(400, '日期格式应为 YYYY-MM-DD');
    }
    if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        bg_fail(400, '日期不存在');
    }
    return $s;
}

function bg_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return array();
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        bg_fail(400, '数据格式错误');
    }
    return $data;
}

function bg_uuid()
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
