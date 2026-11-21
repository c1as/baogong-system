# 通义千问 Qwen3.5-OCR 报工识别插件

把报工单照片交给阿里云百炼的 `qwen3.5-ocr` 模型，返回报工日期、报工人、车间、备注和零件明细，
并把图号、名称、工序尽量对齐到系统零件库。

## 安装

1. 解压本目录到 `public/plugins/`，得到 `public/plugins/qwen35-ocr/`（目录名必须与 `plugin.json` 的 `id` 一致）。
2. 打开「识别插件设置」，应能看到 **通义千问 Qwen3.5-OCR**。
3. 填写 API Key（阿里云百炼控制台「API-KEY 管理」，`sk-` 开头，与接口地址同地域）。
4. 点「API 链接测试」，四项明细全绿后启用插件。
5. 「新增报工 → 拍照识别」上传一张单子验证结果面板。

## 文件

| 文件 | 作用 |
| --- | --- |
| `plugin.json` | 清单与设置项 |
| `handler.php` | 识别主流程，`return` 结果数组 |
| `check.php` | 设置页「API 链接测试」 |
| `shared.php` | 出网封装、零件库拼装、结果归一化等公共函数（非入口） |

## 出网说明

未直接调用 `lib/net.php` 的函数（其函数签名不在接入文档内），而是自带 HTTPS 封装：
优先 `curl`，无 curl 扩展时退回 `stream_socket_client` 的 SSL socket；两种路径都会自动加载
`public/lib/cacert.pem`，找不到才用系统 CA 包。若后续要与 `baidu-ocr` 统一走 `lib/net.php`，
只需把 `shared.php` 里的 `qwenocr_http_post()` 换成对应调用，其余逻辑无需改动。

## 提示词占位符

- `{TODAY}` → 上下文里的 `today`（Asia/Shanghai 当天）
- `{CATALOG}` → 零件库清单，每行 `图号 | 名称 | 工序`，条数受「零件库注入条数」限制

改提示词时保留输出 JSON 的字段名（`date/worker/workshop/note/items/partNo/partName/process/qty/price`），
否则识别结果填不进面板。

## 容错

- 模型没吐合法 JSON（或带 ```json 围栏、前后有说明文字）时会自动抠 JSON；仍失败则走文本兜底解析：
  日期、报工人、车间用正则取，明细行只在该行命中零件库图号（或形如图号的串）时才生成，
  并在 `warnings` 里提示「按文本兜底解析，请逐条核对」。
- 数量识别不到按 0 返回并计入警告；日期识别不到用当天兜底。
- HTTP 401/403/404/429/5xx 与业务错误码都会转成中文提示，走 502 显示在识别面板上。

## 常见报错

| 提示 | 处理 |
| --- | --- |
| 千问OCR调用失败 HTTP 401 | Key 错、地域不匹配，或该地域未开通 qwen3.5-ocr |
| HTTP 404 | 接口地址或模型名有误 |
| HTTP 429 | 限流或额度用尽 |
| 无法访问千问OCR接口 | 服务器出网被限制，用设置页「互联网连接测试」进一步定位 |
| 图片格式不支持 | 前端只接受 JPG/PNG/WebP，且 ≤8MB |
