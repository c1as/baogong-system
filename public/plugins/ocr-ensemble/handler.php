<?php
/*
 * 报工识别插件 · 多 OCR 融合引擎（编排入口）
 *
 * 本文件只做编排：ctx 校验 → 加载 OCF 增强引擎 → 调用 ocf_run()。
 * 全部判定算法（L0–L6）已迁移至 ocf/ 目录，遵循「原文佐证 > 字库硬归一 >
 * 字段级信誉 > 多数投票 > 人工复核」的分层裁决原则。
 *
 * 由 api.php?action=plugin.invoke 引入，脚本末尾 return 结果数组。
 */

if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

$ctx = $GLOBALS['BG_PLUGIN_CTX'];
$cfg = isset($ctx['config']) && is_array($ctx['config']) ? $ctx['config'] : array();

/* 多路引擎串行调用耗时可能超过默认 30s，适当放宽（基线契约保留） */
if (function_exists('set_time_limit')) {
    @set_time_limit(180);
}

/* 字库连接层（唯一入口，静默降级） */
$bglxBridgeFile = dirname(__DIR__) . '/handwriting-lexicon/lib/bridge.php';
if (is_file($bglxBridgeFile)) {
    require_once $bglxBridgeFile;
    bglx_bridge_init('ocr-ensemble');
}

/* OCF 增强引擎：bootstrap 统一引入全部 ocf/* 模块（含 run.php 编排） */
require_once __DIR__ . '/ocf/bootstrap.php';
require_once __DIR__ . '/ocf/run.php';

/* 集中阈值配置：默认值 ← ctx.config 覆盖（含字段差异化） */
$ocfCfg = ocf_config_build($ctx, $cfg);

/* 统一编排入口；致命错误（无可用引擎）交由主程序 try/catch 呈现 */
return ocf_run($ctx, $ocfCfg);
