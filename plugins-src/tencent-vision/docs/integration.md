# 接入说明（对齐 OCS 契约）

本插件以**单个 `report.ocr` 引擎**接入，内部双引擎差异通过 `engine` 前缀、`alternates[]` 与 `warnings` 表达。对接依据是 `ocr-ensemble/docs/ocs-contract.md` §7 自检清单。

## 1. 注册与发现

1. 目录 `public/plugins/tencent-vision/` 与 `plugin.json.id` 严格一致。
2. `plugin.json` 已声明：

```json
{ "id": "tencent-vision", "capabilities": ["report.ocr"], "entry": "handler.php", "check": "check.php" }
```

3. 在设置页启用本插件。融合引擎默认自动纳入「已启用 + 声明 `report.ocr`」的插件；
   也可在 `ocr-ensemble` 设置 `engines` 中显式填写 `tencent-vision`（逗号分隔，如 `baidu-ocr,tencent-vision`）。

```text
ocr-ensemble.engines = baidu-ocr,qwen35-ocr,tencent-vision
```

建议把本插件的 `outputMode` 设为 `fuse`（默认，单结果对接标准契约）；若希望融合层自行裁决两路差异，设为 `parallel`，此时 `alternates[]` 留存各路原始字段与原文。

## 2. OCS §7 自检清单逐项对照

| # | 契约要求 | 本插件落地 | 状态 |
|---|---|---|---|
| 1 | `plugin.json` 声明 `report.ocr` 并启用 | `capabilities:["report.ocr"]` | ✅ |
| 2 | handler 有 ctx 校验、末尾 `return`、无输出/无 exit | `handler.php` 顶部 403 守卫，末尾 `return bg_tv_run(...)` | ✅ |
| 3 | 字段齐全，`items≤20`/`warnings≤5`/`raw≤4000`/严格日期 | `bg_tv_finalize()` 统一收口 | ✅ |
| 4 | `raw` 保留图号/数量原文片段（L1 佐证） | 两路 raw 汇总，OCR 拼 `DetectedText`，Hy4 拼图号+数量 | ✅ |
| 5 | 未内嵌字库/同义/形近代码 | 仅 `lib/lex.php` 探测并 `require bridge.php` | ✅ |
| 6 | 字库经 `bglx_bridge_*`，失败静默降级 | `init/norm_fields/suggestions/status`，异常写 warning 不阻断 | ✅ |
| 7 | 单路（对外）异常 `throw` | 全路失败 `throw RuntimeException`；内部单路失败由另一路兜底 | ✅ |
| 8 | 可选声明 `strengths` | `strengths:["partNo","qty","process"]` | ✅ |
| 9 | `check.php` 自检通过 | 双引擎独立自检，未勾选引擎不检查 | ✅ |

## 3. 调用链

```text
api.php(action=plugin.invoke)
  → handler.php: BG_PLUGIN_CTX 校验 → parseEngines()
  → curl_multi 并行：lib/tencent_ocr.php:bg_tv_ocr_prepare/parse
                     lib/hy4_vision.php:bg_tv_hy4_prepare/parse
  → lib/normalize.php（统一 fields）
  → lib/lex.php（bglx_bridge_norm_fields，逐路纠错）
  → lib/fuse.php（fuse / parallel / primary）
  → lib/util.php:bg_tv_finalize() → return FinalResult
```

## 4. 原文佐证（L1）

融合层 L1 要求候选值能在本路 `raw` 找到片段，否则一票否决。本插件：

- OCR：`raw` 拼接全部 `DetectedText`（天然含图号与数量原文）；
- Hy4：`raw` 由解析字段拼「图号 名称 工序 N件」，文本兜底时保留模型原文；
- `fuse` 模式把两路 `raw` 汇总，保证赢家值至少能在某一路原文中找到；
- 字库纠错发生在 `fuse` 之前，`raw` 保留改写前原文片段，避免 L1 失效。

## 5. 字库连接层

只经 `bglx_bridge_*` 调用，按以下顺序探测 `bridge.php`（见 `lib/lex.php:bg_tv_lex_load_bridge()`）：

1. `dirname(__DIR__,2)/handwriting-lexicon/lib/bridge.php`（plugins 同级，标准布局）
2. `dirname(__DIR__,3)/handwriting-lexicon/lib/bridge.php`
3. `dirname(__DIR__,4)/public/plugins/handwriting-lexicon/lib/bridge.php`（项目根布局）
4. `dirname(__DIR__,3)/fixed-plugins/handwriting-lexicon/lib/bridge.php`

找不到 / 异常 / 熔断时：字段原样返回 + 一条「字库连接层不可用，已跳过纠错」告警，不阻断识别。

## 6. 可观测性

`usage.{prompt_tokens,completion_tokens,total_tokens}`、各路 `elapsedMs` 与编排摘要通过 `error_log('[baogong] tencent-vision: …')` 写旁路日志，带 `invokeId` 便于关联；不进返回结构、不突破上限。
