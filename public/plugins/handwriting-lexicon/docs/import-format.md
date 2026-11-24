# 词条导入格式说明

`api.php?action=import` 支持两种格式：`json` 与 `procAlias`。`csv` 在 P1 开放，当前返回 `50101`。

## 1. JSON 格式（推荐）

两种形态都接受：

```json
{
  "schema": 1,
  "updated_at": "2026-06-10 09:30:00",
  "entries": [ { "type": "drawing_alias", "standard": "24971b05", "variants": ["2L59711305"], "confidence": 0.95 } ]
}
```

或直接用词条数组：

```json
[
  { "type": "drawing_alias", "standard": "24971b05", "variants": ["2L59711305", "24971bO5"], "confidence": 0.95 },
  { "type": "process_synonym", "standard": "钻孔", "variants": ["铣2"], "confidence": 0.85, "source": "ocr_feedback", "status": "pending" }
]
```

字段约束（与 `bglx_validate_entry()` 完全一致）：

| 字段 | 必填 | 约束 |
| --- | --- | --- |
| `type` | 是 | `drawing_alias` / `process_synonym` / `unit_synonym` / `char_confusion` |
| `standard` | 是 | trim 后非空，≤120 字符；`char_confusion` 必须单字符 |
| `variants` | 是 | 至少 1 项，去重，每项非空且 ≤120 字符；`char_confusion` 每项单字符 |
| `confidence` | 是 | 0.00~1.00，保留 2 位 |
| `enabled` | 否 | 默认 `true` |
| `status` | 否 | 默认 `active`；可填 `pending` / `rejected` |
| `source` | 否 | 默认 `manual`；可填 `ocr_feedback` / `import` / `learning` |
| `scope` | 否 | 默认 `["*"]` |
| `priority` | 否 | 默认 10，范围 0~100 |
| `note` | 否 | ≤500 字符 |
| `stats` | 否 | 默认全 0；导入时不累加 |

**`id` 由存储在写锁内分配，导入时即使提供了也会被忽略。**

`variants` 也可以传逗号分隔字符串：`"variants": "线, 绕, 鉄"`。

## 2. procAlias 格式（迁移现有 OCR 配置）

与百度 / 腾讯云插件现有 `procAlias` 设置项解析规则一致：

```
# 注释行以 # 或 ; 开头，会被忽略
铣 = 线, 绕, 鉄, 銑, 洗
钻 = 打眼, 钻孔攻丝
车：车削、车工
```

解析规则：

- 每行一条，正则 `/^([^:：=]+)[:：=](.+)$/u` 拆分左右两侧；
- 右侧按 `/[,，、\s]+/u` 切分；
- 生成的词条：`type=process_synonym`、`standard=左侧基字`、`variants=右侧各项 + 左侧基字`、
  `source=import`、`status=active`、`enabled=true`、`confidence=0.90`、`note="从 procAlias 迁移"`。

> 0.90 是迁移默认值，会触发自动纠正。若对某词条不放心，导入后在管理页把置信度调低或先停用。

## 3. 导入模式

| 参数 | 行为 |
| --- | --- |
| `mode=merge`（默认） | 按「类型 + 标准值 + 变体集合」签名去重，已存在则跳过 |
| `mode=replace` | 先清空全库，再导入；适合从零重建词库 |

返回结果：

```json
{"code":0,"message":"导入完成","data":{"added":8,"updated":0,"skipped":0,"errors":[]}}
```

`errors` 中每项形如 `{"line": 3, "message": "..."}`，`line` 为源内容行号（JSON 数组为元素序号）。

## 4. 示例请求

```bash
curl -X POST http://localhost/api.php \
  -H 'X-Bglx-Token: 你的令牌' \
  -H 'Content-Type: application/json' \
  -d '{"action":"import","format":"procAlias","mode":"merge","content":"铣 = 线, 绕, 鉄"}'
```

## 5. 导出

```
GET api.php?action=export[&type=drawing_alias]
```

直接返回 `application/json` 附件，内容为 `{schema, updated_at, entries}`，可直接作为再次导入的 JSON 源文件。
