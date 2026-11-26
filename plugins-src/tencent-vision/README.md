# 腾讯云多引擎视觉识别插件（tencent-vision）

在一个插件内同时提供**腾讯云 OCR**与**混元 Hy4 视觉理解**两条识别支路：可多选、并行调用、独立容错，统一归一为报工系统标准字段，经字库连接层纠错后按三种输出模式收口。对外声明 **1 个 `report.ocr` 能力**，可被 `ocr-ensemble` 零改动纳入融合。

## 1. 安装

把整个 `tencent-vision/` 目录放入报工系统的 `public/plugins/` 下（目录名必须与 `plugin.json` 的 `id` 一致）：

```text
public/plugins/tencent-vision/
├── plugin.json
├── handler.php
├── check.php
├── README.md
├── lib/
│   ├── util.php
│   ├── tencent_ocr.php
│   ├── hy4_vision.php
│   ├── normalize.php
│   ├── fuse.php
│   └── lex.php
├── docs/
│   ├── integration.md
│   └── hy4-prompt-tuning.md
└── tests/
    ├── tencent_ocr.test.php
    ├── hy4_vision.test.php
    ├── normalize_fuse.test.php
    └── fixtures/
```

后端自动发现插件；在「识别插件设置」中启用并填写凭据即可。纯 PHP 7.4+，**零 Composer 依赖**；`curl` 可选（缺失自动走 SSL socket 串行降级），`mbstring` 可选（有 polyfill）。

## 2. 配置

设置页按四组展示，密钥类为 `password` 不回显。

| 组 | 关键项 | 说明 |
|---|---|---|
| 通用 | `engines` | 多选：`tencentOcr`（腾讯云 OCR）/ `hy4Vision`（混元 Hy4），默认全选，至少选 1 项 |
| 通用 | `outputMode` | `fuse`（默认）/ `parallel` / `primary` |
| 通用 | `primaryEngine` | 平局裁决主引擎，同时决定返回 `engine` 前缀 |
| 通用 | `timeout` / `retries` | 单路超时默认 30 秒；网络类故障与 429/5xx 重试 1 次，4xx 不重试 |
| 腾讯云 OCR | `secretId` / `secretKey` | 腾讯云 API 密钥，仅 OCR 使用 |
| 腾讯云 OCR | `ocrEndpoint` / `region` / `ocrType` / `configId` | 域名、地域、`accurate|handwriting|basic`、多语 ConfigID |
| 混元 Hy4 | `hy4ApiKey` | 混元控制台 API Key（Bearer，**独立于** SecretId/Key） |
| 混元 Hy4 | `hy4Endpoint` / `hy4Model` / `maxTokens` / `catalogLimit` / `hy4Prompt` | OpenAI 兼容地址、模型名（默认 `hunyuan-vision`）、输出上限、零件库注入条数、提示词模板 |
| 融合与阈值 | `agreeThreshold` / `reviewThreshold` / `compareMode` | 一致阈值 0.85、复核阈值 0.6、差异对比开关（默认关） |

两套密钥属于不同控制台，分别用设置页「API 链接测试」自检：OCR 用 1×1 图探针，Hy4 用文本最小请求；未勾选的引擎不检查。

## 3. 三种输出模式

- **fuse（默认）**：字段级轻量择优（有值优先 → 一致取主 → 原文佐证 → 自报置信度），输出单结果，符合单结果契约。
- **parallel**：标准字段取主引擎，各路结果存入 `alternates[]` 供融合层/人工确认；扩展键不影响主程序解析标准字段。
- **primary**：仅主引擎结果；开启 `compareMode` 时把字段差异写入 `warnings`。

> 轻量择优**不是** `ocr-ensemble` 的 L0–L6：无 Dice 聚类、无字段级信誉、无校准与裁决；复杂分歧交给融合层。

## 4. 返回结构

```php
array(
    'engine'     => 'tencent-vision/tencentOcr',
    'confidence' => 0.88,
    'plugin'     => array('id' => 'tencent-vision'),
    'strengths'  => array('partNo', 'qty', 'process'),
    'fields'     => array(
        'date' => '2026-11-20', 'worker' => '张三', 'workshop' => '一车间', 'note' => '',
        'items' => array(array('partNo' => 'TD24510R04', 'partName' => '固定块', 'process' => '铣', 'qty' => 80, 'price' => null)),
    ),
    'warnings'   => array(),
    'raw'        => '识别原文（保留图号与数量片段）',
    // 'alternates' => array(), 仅 parallel 模式
)
```

硬上限：`items ≤ 20`、`warnings ≤ 5` 且每条 ≤80 字、`raw ≤ 4000` 字、日期严格 `YYYY-MM-DD`、置信度 0~1 三位小数。所有分支统一经 `bg_tv_finalize()` 收口。

## 5. 排错

| 现象 | 提示 / 处理 |
|---|---|
| 「请至少勾选一个识别引擎」 | 在设置页勾选至少一个引擎 |
| 「未配置 SecretId / SecretKey」 | 填腾讯云密钥；仅勾选 OCR 时直接报错，双引擎时跳过 OCR 由 Hy4 兜底 |
| 「请先填写混元 API Key」 | 填混元控制台 Key；注意与 SecretId/Key 不是同一套 |
| 「密钥无效或地域不匹配」 | 检查 Key 是否正确、模型/地域是否开通；Hy4 还需确认模型名 |
| 「接口地址或模型名有误」 | 核对 `hy4Model` 与控制台实际模型 id |
| 「模型未返回结构化结果…」 | 已走文本兜底解析，请逐条核对；可优化 `hy4Prompt`（见 docs） |
| 「字库连接层不可用，已跳过纠错」 | 字库插件未安装或熔断，不影响识别；必要时安装 handwriting-lexicon |
| 「触发限流 / 网络请求失败」 | 稍后重试或调大 `timeout`；重试仅 1 次避免重复计费 |

## 6. 约束与红线

- 请求体**绝不出现 `ImageQuestion`**：Hy4 只用 `chat/completions` + `image_url` + `text`。
- **不内嵌字库**：只经 `bglx_bridge_*` 调用 `handwriting-lexicon/lib/bridge.php`，失败静默降级。
- **不实现 L0–L6 融合**：复杂裁决交 `ocr-ensemble`。
- 入口有 `BG_PLUGIN_CTX` 校验，全程 `return`、无输出、无 `exit`；全路失败时 `throw RuntimeException`。

## 7. 自检与测试

```bash
php -l handler.php && php -l check.php
for f in lib/*.php tests/*.test.php; do php -l "$f"; done
python3 -m json.tool plugin.json > /dev/null
php tests/tencent_ocr.test.php
php tests/hy4_vision.test.php
php tests/normalize_fuse.test.php
```

接入 `ocr-ensemble` 的步骤见 [docs/integration.md](docs/integration.md)；提示词调优见 [docs/hy4-prompt-tuning.md](docs/hy4-prompt-tuning.md)。
