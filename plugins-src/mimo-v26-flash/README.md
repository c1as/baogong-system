# 小米 MiMo V2.6 Flash 报工识别插件

把报工单照片交给小米 MiMo 开放平台的 `mimo-v2.6-flash` 视觉模型（OpenAI Chat Completions 兼容接口），
返回报工日期、报工人、车间、备注和零件明细，并把图号、名称、工序尽量对齐到系统零件库。

API 参考：https://mimo.mi.com/docs/zh-CN/api/guidance/rate-limit 及同站接口文档。

## 安装

1. 把本目录放到 `public/plugins/`，得到 `public/plugins/mimo-v26-flash/`（目录名必须与 `plugin.json` 的 `id` 一致；
   id 只允许小写字母、数字和连字符，不含点号，所以目录名是 `mimo-v26-flash`，模型名 `mimo-v2.6-flash` 作为设置项填写）。
2. 打开「识别插件设置」，应能看到 **小米 MiMo V2.6 Flash**。
3. 填写 API Key（platform.xiaomimimo.com「API Keys」获取）：
   - `sk-` 开头 → 按量付费，接口地址用默认 `https://api.xiaomimimo.com/v1/chat/completions`
   - `tp-` / `ttp-` 开头 → Token Plan，接口地址须改成 `https://token-plan-cn.xiaomimimo.com/v1/chat/completions`
   - 两类密钥与地址混用会返回 401，「API 链接测试」会提示「密钥与地址匹配」是否通过。
4. 点「API 链接测试」，明细全绿后启用插件。
5. 「新增报工 → 拍照识别」上传一张单子验证结果面板。

## 文件

| 文件 | 作用 |
| --- | --- |
| `plugin.json` | 清单与 10 个设置项 |
| `handler.php` | 识别主流程，`return` 结果数组 |
| `check.php` | 设置页「API 链接测试」（配置检查 + 一次最小文本调用） |
| `shared.php` | 出网封装、重试、提示词、结果归一化、文本兜底等公共函数（非入口） |

所有入口脚本首行都校验 `$GLOBALS['BG_PLUGIN_CTX']`，直接访问返回 403。

## 出网与重试

- 优先走系统统一出网 `lib/net.php` 的 `bg_http()`（自动带 CA、代理、超时）；
  无该函数时退回 cURL，再退回流式 SSL socket，三级回退共用同一份请求体编码
  （数组 payload 在 `mimoocr_http_post()` 入口统一 `json_encode`）。
- 仅对**传输层失败 / 429 / 500 / 502 / 503** 重试，退避 750ms × 第 N 次，次数由「自动重试次数」（0~2）控制。
- MiMo `mimo-v2.6-flash` 限流为 RPM 100 / TPM 10M，429 属正常现象，靠上述退避消化。

## 请求要点（MiMo 接口）

- 端点：`POST {endpoint}`，鉴权头 `api-key: <key>`（也兼容 `Authorization: Bearer`）。
- 图片放 `messages[0].content[0]`，类型 `image_url`，`url` 传 dataURL（JPEG/PNG/GIF/WebP/BMP，≤50MB；前端限 8MB）。
- `stream: false`；`max_completion_tokens` 取「最大输出 Token」（256~131072，**含思考 token**）。
- `thinking.type`：`disabled`（默认，快、省，请求带 `temperature: 0.2`）/ `enabled`
  （flash 默认开启思考；开启时官方会把 temperature/top_p 强制为 1.0/0.95，故插件不下发这两个参数，
  响应中会多出 `reasoning_content`，耗时与 token 消耗上升，需配合更大的最大输出 Token）。
- 响应取 `choices[0].message.content`（string 或 parts 数组），`finish_reason=length` 等异常会转成 `warnings`。

## 提示词占位符

- `{TODAY}` → 上下文里的 `today`（Asia/Shanghai 当天）
- `{CATALOG}` → 零件库清单，每行 `序号. 图号 | 名称 | 工序`，条数受「零件库注入条数」（0~300）限制

改提示词时保留输出 JSON 的字段名（`date/worker/workshop/note/confidence/items/partNo/partName/process/qty/price`），
否则识别结果填不进面板。

## 结果归一化

- 返回：`engine`（`小米 MiMo <模型名>`，≤40 字）、`confidence`（0~1）、`fields`、`warnings`（≤5 条、每条 ≤80 字）、`raw`（≤4000 字，含模型原文）。
- `items` 最多 20 条；`qty` 纯数字（整数返回 int，带小数保留两位），识别不到按 0；`price` 无则 `null`。
- `date` 支持 `2026-11-20` / `2026/11/20` / `11.20`（补当前年），非法日期置空并告警。

## 容错

- 模型没吐合法 JSON（或带 ```json 围栏、前后有说明文字）时自动抠 JSON；仍失败走文本兜底解析：
  日期、报工人、车间用 `/u` 正则取，明细行按行匹配零件库（图号完全命中优先，图号 ≥6 位容 1 处错字），
  零件库行的 `序号. ` 前缀会被剥掉后再比对；兜底时 `confidence` 固定 0.3 并在 `warnings` 提示。
- HTTP 错误码中文映射：400 参数、401 认证（提示密钥/地址混用）、402 额度、403 权限、404 接口或模型不存在、
  421 并发、429 限流（重试后仍失败才报）、500/503 服务端繁忙，均走 502 显示在识别面板上。
- 密钥前缀与接口地址域名不匹配时直接给出告警，避免反复撞 401。

## 常见报错

| 提示 | 处理 |
| --- | --- |
| 认证失败（HTTP 401） | Key 错误，或 `sk-` 与 `tp-` 密钥和接口地址混用 |
| HTTP 402 | 额度用尽，去平台充值 |
| HTTP 404 | 接口地址或模型名有误 |
| HTTP 429 | 超出 RPM 100 / TPM 10M 限流，稍后重试或调大重试次数 |
| 请求参数错误（HTTP 400） | 多为 `max_completion_tokens` 超范围（256~131072）或图片格式不支持 |
| 无法连接 MiMo 接口 | 服务器出网被限制，用设置页「互联网连接测试」定位 |
| 图片格式不支持 | 前端只接受 JPG/PNG/WebP，且 ≤8MB |
| finish_reason=length | 输出被截断，调大「最大输出 Token」（开思考时更明显） |
