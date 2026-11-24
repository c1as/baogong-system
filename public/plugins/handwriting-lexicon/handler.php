<?php
/**
 * 手写字词库管理插件 · 协议入口
 *
 * 本插件不执行 OCR 识别，仅声明协议完整性并返回安全的空结果。
 * 真正的业务入口是同目录下的 admin.php（管理页）与 api.php（JSON 接口）。
 *
 * 由 api.php?action=plugin.invoke 调用，读取 $GLOBALS['BG_PLUGIN_CTX']，
 * 返回与主程序约定一致的字段结构：engine / confidence / fields / warnings / raw。
 */

/* 仅允许由主程序加载，禁止直接访问 */
if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

return array(
    'engine' => 'handwriting-lexicon',
    'confidence' => 0,
    'fields' => array(
        'date' => '',
        'items' => array(),
    ),
    'warnings' => array('本插件仅提供词库管理，不执行 OCR 识别'),
    'raw' => '',
);
