# Hy4 提示词模板调优与 JSON 稳定性

## 1. 占位符

`hy4Prompt` 支持两个占位符，调用前由 `bg_tv_hy4_render_prompt()` 替换：

| 占位符 | 替换为 | 来源 |
|---|---|---|
| `{TODAY}` | 当天日期 `YYYY-MM-DD` | `ctx.today`（非法时取服务器当天） |
| `{CATALOG}` | 零件库文本，每行 `图号 \| 名称 \| 工序` | `ctx.catalog` 前 `catalogLimit` 条（默认 200，范围 0~300） |

零件库为空时注入提示文案「零件库为空，请完全按单据原文填写图号与名称」，避免模型凭空猜测图号。

## 2. JSON 字段名约束

输出结构示例中的字段名必须与契约完全一致：

```json
{"date":"YYYY-MM-DD","worker":"报工人","workshop":"车间","note":"备注","confidence":0.9,
 "items":[{"partNo":"图号","partName":"零件名称","process":"工序","qty":12,"price":null}]}
```

- `date`：严格 `YYYY-MM-DD`，只写月日时按当前年份补全，识别不到填空串；
- `qty` / `price`：纯数字不带单位，看不清填 `null`，不要猜；
- `confidence`：0~1；
- `items`：最多 20 行，表头/合计/小计/签名行不算明细；
- 模糊单字用英文 `?`，宁可留空也不编造。

`check.php` 会扫描模板是否含 `"date"`、`"items"`、`"partNo"`、`"qty"` 及两个占位符，缺失时在 details 中给出中文提示（不阻断连通性结论）。

## 3. JSON 稳定性机制

1. **提示词约束**：明确「只输出一个 JSON 对象，不要解释、不要 markdown 代码块」。
2. **抠取容错**：`bg_tv_hy4_extract_json()` 依次尝试——
   - 去掉首尾 ```json 围栏；
   - 直接 `json_decode`；
   - 抠首个 `{…}` 或 `[…]` 子串再 `json_decode`。
3. **文本兜底**：仍失败时走 `bg_tv_hy4_fallback()`，只取原文中确实存在的信息（图号须命中零件库或形如图号串，数量取行内裸数字），并写告警「模型未返回结构化结果，明细按文本兜底解析，请逐条核对」。
4. **长度告警**：`finish_reason=length` 时提示明细可能不完整，可上调 `maxTokens`。

## 4. 模型名与密钥归属联调

- 默认 `hy4Model=hunyuan-vision`。**默认值不等于一定可用**：Hy4 / TokenHub 系列模型 id 请按控制台实际名称填写后联调。
- HTTP 404 → 「接口地址或模型名有误」；401/403 → 「密钥无效或地域不匹配」。
- 混元 API Key（`hy4ApiKey`，Bearer）与腾讯云 `secretId/secretKey` 属不同控制台体系；自检分别进行，可快速定位是哪套凭据出问题。
- 接口地址为 OpenAI 兼容的 `https://api.hunyuan.cloud.tencent.com/v1/chat/completions`，请求体只允许 `model/messages/max_tokens/stream`，**绝不使用已下线的 `ImageQuestion`**。

## 5. 调优建议

| 场景 | 调整 |
|---|---|
| 手写潦草、图号常被改写 | 增大 `catalogLimit`（≤300），并在规则 4 强调「完全一致写法」 |
| 明细被截断 | 调大 `maxTokens`（如 8192），检查 `finish_reason=length` 告警 |
| 模型爱输出解释文字 | 在模板开头加「只输出 JSON」强化语句；抠取逻辑已能容忍前后说明 |
| 印刷体一致性判定过严/过松 | 调 `agreeThreshold`（印刷 0.9，手写 0.8） |
| 双路常出现分歧 | 用 `parallel` 模式保留 `alternates[]`，开启 `compareMode` 观察字段差异 |
