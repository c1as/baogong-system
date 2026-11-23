<?php
/* 模块: lib/config
   职责: 读取 app.env 配置（密码、数据目录等）
   依赖: 无
   注意: api.php 启动时最先调用 */

/* ================================================================ 配置 */

function bg_load_env($file)
{
    $cfg = array();
    if (!is_file($file)) {
        return $cfg;
    }
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $cfg;
    }
    foreach ($lines as $line) {
        $line = trim(str_replace("\r", '', $line));
        if ($line === '' || substr($line, 0, 1) === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        $len = strlen($v);
        if ($len >= 2) {
            $a = substr($v, 0, 1);
            $b = substr($v, -1);
            if (($a === '"' && $b === '"') || ($a === "'" && $b === "'")) {
                $v = substr($v, 1, $len - 2);
            }
        }
        if ($k !== '') {
            $cfg[$k] = $v;
        }
    }
    return $cfg;
}
