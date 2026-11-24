# OCR 插件接入契约（OCS Contract）

> 目标（G5）：新增 OCR 插件只需「注册 + 声明能力 + 按统一结构返回」，融合核心零改动即可纳入投票。
> 适用：所有声明 `report.ocr` 能力的识别插件；融合引擎 `ocr-ensemble` 会自动发现并调度。

---

## 1. 注册与发现

1. 在 `public/plugins/<your-id>/` 放置 `plugin.json`、`handler.php`（可选 `check.php`）。
2. `plugin.json` 的 `capabilities` 必须包含 `report.ocr`：

```json
{
  "id": "your-ocr",
  "name": "你的 OCR 引擎",
  "capabilities": ["report.ocr"],
  "entry": "handler.php"
}
```

3. 插件需在设置页启用。融合引擎默认自动纳入「已启用 + 声明 `report.ocr`」的全部插件；
   也可在融合设置 `engines` 中显式列出插件 id（逗号分隔）。

---

## 2. 入口契约（handler.php）

- 仅允许由 `api.php?action=plugin.invoke` 引入，入口先做 ctx 校验：

```php
if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}
```

- 从 `$GLOBALS['BG_PLUGIN_CTX']` 只读：`image`（base64 dataURL）、`catalog`（零件库）、`config`（本插件设置）、`today`、`invokeId`。
- 脚本末尾 `return` 一个数组，**不输出任何内容、不 `exit`**。单路失败用 `throw new RuntimeException(...)`，融合层 try/catch 后记告警继续。

---

## 3. 统一返回结构（归一化上限）

```php
return array(
    'engine'     => '引擎名（可含型号）',
    'confidence' => 0.88,                 // 0~1，3 位小数，自报置信度
    'plugin'     => array('id' => 'your-ocr'), // 可选；融合用 plugin.id 作引擎 id，缺省回退 engine 名
    'strengths'  => array('partNo', 'qty'),   // 可选：能力标签（见 §5）
    'fields'     => array(
        'date'     => 'YYYY-MM-DD',       // 严格真实日期，否则空串
        'worker'   => '报工人（≤40字）',
        'workshop' => '车间（≤40字）',
        'note'     => '备注（≤100字）',
        'items'    => array(
            array(
                'partNo'   => '图号（≤40字）',
                'partName' => '名称（≤40字）',
                'process'  => '工序（≤40字）',
                'qty'      => 50,          // 数值，纯数字不含单位
                'price'    => null,        // 数值或 null
            ),
            // …最多 20 行
        ),
    ),
    'warnings'   => array('...'),         // 每条 ≤80 字
    'raw'        => '识别原文（≤4000字）',// 原文佐证 L1 依赖它，务必保留图号/数量片段
);
```

硬性上限：`items ≤ 20`、`warnings ≤ 5` 且每条 ≤80 字、`raw ≤ 4000` 字、日期严格 `YYYY-MM-DD`。超出部分主程序/融合层会截断。

> **关键**：`raw` 是 L1 原文佐证硬约束的依据。图号、数量必须能在 `raw` 中找到片段，否则该候选在融合层会被剔除（防引擎按零件库猜测）。

---

## 4. 字库调用方式（统一走连接层，禁止内嵌）

新插件**不得**自己 `require lexicon.php` 或维护同义/形近字典。只用连接层一行 init + 批量归一：

```php
$bglxBridge = dirname(__DIR__) . '/handwriting-lexicon/lib/bridge.php';
if (is_file($bglxBridge)) {
    require_once $bglxBridge;
    bglx_bridge_init('your-ocr');
}
// 组装完 $fields 后一次批量归一：
if (function_exists('bglx_bridge_norm_fields')) {
    $n = bglx_bridge_norm_fields($fields, array('plugin' => 'your-ocr'));
    $fields = $n['fields'];
    foreach (bglx_bridge_suggestions() as $sug) {
        $warnings[] = '字库建议：' . $sug . '，请人工确认';
    }
}
```

连接层 API：`bglx_bridge_init/norm/norm_fields/suggestions/status`。任何失败静默降级为「未命中」，不阻断识别（详见 `handwriting-lexicon/docs/ocr-integration.md` §7）。

---

## 5. 能力标签（strengths / weaknesses，可选）

信誉冷启动用到能力标签。可在返回结构带 `strengths`，或在融合设置 `strengths`（JSON）配置：

```json
{"your-ocr": ["partNo", "qty"]}
```

- 命中字段：先验 prior=0.6+0.2=0.8；声明 `weaknesses` 命中字段 prior=0.6-0.15=0.45。
- 不声明：均匀 prior=0.6，不影响接入门槛。
- 字段 id：`date|worker|workshop|note|partNo|partName|process|qty|price`。

---

## 6. 融合判定层级（你的结果如何被裁决）

`佐证(L1) > 字库硬归一(L2) > 字段级信誉(L3) > 多数投票 > 人工复核(L4-L6)`：

1. **L1**：候选值须在本路 `raw` 有佐证，否则剔除（一票否决，最高优先级）。
2. **L2**：字库 auto 硬归一改值入投票；suggest 仅提示不入赢家。
3. **L3**：按 `(引擎,字段)` 动态权重做 Dice 聚类 / 加权中位数。
4. **L4**：2 路按佐证→字库→信誉→不可裁决复核；3 路按多数簇/层级比对；N 路层级比对后仍不可解则弃权+复核。
5. **L5/L6**：校准置信度，低于字段阈值提示复核；每条字段命中规则（`rule`）可枚举并写入旁路 trace。

无需在插件内实现上述任何逻辑——融合引擎统一处理。

---

## 7. 自检清单（接入前逐项确认）

- [ ] `plugin.json` 声明 `report.ocr` 能力，插件已启用。
- [ ] `handler.php` 有 `BG_PLUGIN_CTX` 校验，末尾 `return` 结构数组，无输出/无 exit。
- [ ] 返回字段齐全，`date` 严格 `YYYY-MM-DD`，`items ≤ 20`、`warnings ≤ 5`、`raw ≤ 4000`。
- [ ] `raw` 保留图号/数量原文片段（L1 佐证需要）。
- [ ] 未内嵌任何字库/同义/形近代码（grep `lexicon.php|bglx_correct|bglx_norm|ALIAS_EXTRA` 为 0）。
- [ ] 字库经 `bglx_bridge_*` 调用，失败静默降级。
- [ ] 单路异常用 `throw` 抛出，不吞掉错误（融合层负责记告警）。
- [ ] （可选）声明 `strengths` 能力标签。
- [ ] `check.php` 自检通过；用 `tools/eval.php` 验证纳入投票后指标不劣化。

---

## 8. 示例插件验证（G5）

`mock-ocr/handler.php` 是最小接入示例（含 bridge 一行 init + 批量归一）。按本契约新建一个示例插件并在融合设置中勾选，融合核心无需任何改动即可纳入投票。
