<?php
/*
 * 识别日志插件 · 处理器
 *
 * 本身不做识别，只负责在设置页提供开关；实际记录由 api.php 的
 * bg_log_* 辅助函数在每次识别调用后写入 data/ocr-logs/。
 */

if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

return array(
    'engine' => '识别日志',
    'confidence' => 0,
    'fields' => array(),
    'warnings' => array('识别日志插件不提供识别能力，仅用于记录其它引擎的调用结果'),
    'raw' => '',
);
