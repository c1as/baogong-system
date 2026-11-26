# OCR 识别插件 · 开发与接入文档

> 适用版本：v2.6（2026-11-22）
> 相关代码：`public/api.php`（插件调度）、`public/lib/net.php`（自检与出网）、`public/plugins/`（插件目录）
> 参考实现：`plugins-src/mock-ocr/`（最小示例）、`plugins-src/baidu-ocr/`（百度通用 OCR，含超时重试）、`plugins-src/qwen35-ocr/`（千问多模态，含文本兜底解析）、`plugins-src/mimo-v26-flash/`（小米 MiMo 视觉模型，含零件库注入与重试）、`plugins-src/tencent-vision/`（腾讯云 OCR + 混元 Hy4 双引擎）、`plugins/ocr-ensemble/`（多路融合）、`plugins/ocr-log/`（识别日志）。非内置插件源码统一放仓库根 `plugins-src/<id>/`，以插件包形式从市场分发，不进主程序热更包。

---

## 1. 插件是怎么被调用的

```
用户拍照 / 选图
   │  前端压成 dataURL（JPG/PNG/WebP，≤8MB）
   ▼
POST api.php?action=plugin.invoke&id=<插件id>   请求体 {"image":"data:image/jpeg;base64,..."}
   ▼
api.php 组装上下文 BG_PLUGIN_CTX（图片、零件库、插件配置、当天日期）
   ▼
include plugins/<id>/handler.php  →  返回数组（或 return 结果）
   ▼
后端归一化（截断、去非法值、限制条数）
   ▼
前端识别结果面板：日期 + 明细行 + 警告 + 原文，可点选手动填充
```

插件是**纯 PHP**：一个目录、一个清单文件、一个执行脚本，可选一个自检脚本。不需要构建，也不需要注册到数据库——把目录放进 `public/plugins/` 即自动被发现。

---

## 2. 目录与最小结构

```
public/plugins/my-ocr/
├── plugin.json     清单（必需）
├── handler.php     执行脚本（必需，文件名由 entry 指定）
└── check.php       自检脚本（可选，文件名由 check 指定）
```

最小可用插件只要 `plugin.json` + `handler.php` 两个文件。目录里还可以放公共 PHP 文件（如 `qwen35-ocr` 的 `shared.php`），由 handler / check 手动 `require`，接口不会自动加载。

---

## 3. plugin.json 清单

```json
{
  "id": "my-ocr",
  "name": "我的识别引擎",
  "version": "0.1.0",
  "description": "一句话说明这个插件识别什么、怎么识别。",
  "author": "你的名字",
  "capabilities": ["report.ocr"],
  "entry": "handler.php",
  "check": "check.php",
  "settings": [
    {
      "key": "apiKey",
      "label": "API Key",
      "type": "password",
      "placeholder": "控制台获取的 Key",
      "hint": "仅保存在服务器 data/plugins.json，不会回显"
    }
  ]
}
```

### 3.1 顶层字段

| 字段 | 必填 | 规则 |
| --- | --- | --- |
| `id` | 是 | 小写字母/数字/短横线，1–32 位；**必须与目录名完全一致**，否则插件不会被加载 |
| `name` | 否 | 展示名，≤40 字，缺省用 id |
| `version` | 否 | 展示用，≤20 字，建议语义化版本 |
| `description` | 否 | ≤200 字 |
| `author` | 否 | ≤40 字 |
| `capabilities` | 否 | 能力标签数组：识别引擎用 `report.ocr`；融合类插件再声明 `report.ocr.fusion`，前端会优先把它作为默认识别引擎；日志插件声明 `report.ocr.log`，设置页按钮显示「查看日志」 |
| `entry` | 否 | 执行脚本文件名，默认 `handler.php`；必须存在且以 `.php` 结尾 |
| `check` | 否 | 自检脚本文件名，可选；不存在则设置页不显示「API 链接测试」 |
| `defaultEnabled` | 否 | 缺省 `true`；设为 `false` 时首次安装默认停用，适合融合、收费或需先配置密钥的插件 |
| `settings` | 否 | 配置项数组，见下文 |

### 3.2 settings 配置项

| 字段 | 说明 |
| --- | --- |
| `key` | 必填，字母开头，字母/数字/下划线，≤32 位，同一插件内唯一 |
| `label` | 设置页显示名称，≤40 字 |
| `type` | `text`（默认）/ `password` / `number` / `select` / `checkbox` / `textarea` / `multiselect` |
| `placeholder` | 输入框占位文字，≤120 字 |
| `hint` | 输入框下方说明，≤120 字 |
| `default` | 默认值（字符串；checkbox 用 `"1"` 表示默认勾选；multiselect 用逗号分隔的值） |
| `rows` | 仅 textarea，2–12，默认 4 |
| `options` | 仅 select / multiselect，`[{"value":"...","label":"..."}]`，value 必填 |
| `source` | 仅 multiselect；填 `ocrEngines` 时由后端自动生成「其它已安装识别插件」选项，无需手写 options |
| `min` / `max` / `step` | 仅 number，设置页会渲染为输入框约束，后端保存时按范围校验 |

保存规则（由后端统一处理，插件无需关心）：

- `password`：前端回显 `********`；提交该占位值时**不覆盖**原值，只有改动了才写入。
- `checkbox`：保存为 `"1"` 或 `""`。
- `number`：空串原样保存，否则规范为数字字符串；声明了 min / max 时越界会拒绝保存。
- `multiselect`：前端提交数组，后端只保留清单允许的选项并用逗号拼成字符串；`source: ocrEngines` 只允许其它声明了 `report.ocr` 的插件。
- 其它：`text`/`select` ≤500 字，`textarea` ≤2000 字。
- 只保存清单里声明过的 key，未知 key 会被忽略。

---

## 4. handler.php 执行契约

### 4.1 开头必须做的检查

脚本只能由 `api.php` 引入，直接访问应拒绝：

```php
<?php
if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}
```

### 4.2 可用上下文 `$BG_PLUGIN_CTX`

| 键 | 类型 | 说明 |
| --- | --- | --- |
| `id` | string | 插件 id |
| `image` | string | 图片 dataURL，形如 `data:image/jpeg;base64,...` |
| `catalog` | array | 零件库，最多 8000 条：`[{"no":"图号","name":"名称","processes":["车","铣"]}]` |
| `config` | array | 当前插件设置（已合并 default），键为 settings 的 key |
| `locale` | string | 固定 `zh-CN` |
| `today` | string | 服务器当天 `YYYY-MM-DD`（Asia/Shanghai） |

注意：`catalog` 只含零件库，不含订单、报工记录；调度层下发全量（上限 8000 条），各引擎应只把 `catalogLimit` 允许的前 N 条塞进提示词，避免 token 过大。如需更多数据请在自己的插件内直接读 `data/db/baogong.sqlite`（只读建议）。

### 4.3 两种返回方式

方式 A（推荐，脚本最后 `return` 数组）：

```php
return array(
    'engine' => 'my-ocr',
    'confidence' => 0.92,
    'fields' => array(
        'date' => '2026-11-20',
        'worker' => '张三',
        'workshop' => '一车间',
        'note' => '',
        'items' => array(
            array('partNo' => 'TD90523R301', 'partName' => '固定块', 'process' => '铣', 'qty' => 12, 'price' => null),
        ),
    ),
    'warnings' => array('第 3 行数量未识别，已置 0'),
    'raw' => '识别到的原始文字……',
);
```

方式 B（老式写法，handler.php 里 `return` 非数组时会调用同名全局函数）：

```php
function bg_plugin_run(array $ctx) {
    return array(/* 同上 */);
}
```

### 4.4 返回值字段与后端归一化

| 字段 | 类型 | 归一化规则 |
| --- | --- | --- |
| `engine` | string | ≤40 字，展示用 |
| `confidence` | number | 0–1，保留 3 位小数；超出范围截断 |
| `fields.date` | string | 严格 `YYYY-MM-DD` 且日期真实存在，否则置空 |
| `fields.worker` | string | ≤40 字 |
| `fields.workshop` | string | ≤40 字 |
| `fields.note` | string | ≤100 字 |
| `fields.items` | array | **最多 20 条**；每条的 `partNo`/`partName`/`process` ≤40 字；`qty` 0–1,000,000，保留 2 位；`price` 非数字时为 `null` |
| `warnings` | string[] | 最多 5 条，每条 ≤80 字，空串忽略 |
| `raw` | string | ≤4000 字，前端在识别面板展示并支持点选填充 |

`items` 里的 `partNo` / `partName` / `process` 建议尽量使用零件库里的原始值；前端会再做模糊匹配与手动选择，所以识别不确定时**宁可返回原文片段也不要臆造**，并把不确定项写进 `warnings`。

### 4.5 错误处理

- 直接 `throw new RuntimeException('未配置 API Key')` → 后端捕获，返回 HTTP 502，前端展示「识别失败：未配置 API Key」。
- 用 `bg_fail(400, '...')` 可以返回自定义状态码与消息（api.php 提供的全局函数）。
- 详细错误信息会写入 PHP error_log，前缀 `[baogong] 插件 <id> 执行失败：`。
- 不要在插件里 `exit` 或输出内容，否则会被判定为「没有返回结果」。

### 4.6 融合插件调用其它引擎

融合插件在自己的 `handler.php` 里用 `bg_plugin_invoke_engine($id, $ctx)` 调用其它识别插件，返回与普通调用相同的归一化结构。与 `bg_plugin_invoke()` 不同，单路失败只抛出 `RuntimeException`，不会中断整个请求，调用方可以捕获后记一条告警继续融合；被调用的插件必须已安装且已启用。

`plugins/ocr-ensemble/` 是参考实现：按 `engines` 设置逐路调用，字段相似度聚类投票、数量取置信度加权中位数，再按一致性系数计算综合置信度。融合时可用子引擎返回的 `raw` 与零件库做图号校正——只有原文确有佐证的图号才采纳，防止某一路按零件库「猜」出单据上不存在的图号。

### 4.7 识别日志插件（`report.ocr.log`）

声明 `report.ocr.log` 的插件被当作「日志开关」：主程序在每次 `plugin.invoke` 与融合子引擎调用后，自动把引擎、耗时、字段、明细、告警、原文（详细级别）与零件匹配候选写入 `data/ocr-logs/YYYY-MM-DD.jsonl`，插件自身无需处理识别逻辑。约定：

- 必须提供 `check.php`，用于检查日志目录可写并展示今日条数与最近记录（设置页按钮文案为「查看日志」）。
- 建议设置项：`level`（`full` / `basic`）、`keepDays`（保留天数，默认 14）、`logMatch`（是否记录零件匹配候选）。
- 关闭（停用）该插件即完全停止写日志；`handler.php` 保留最小实现即可。
- 参考 `plugins/ocr-log/`。

---

## 5. check.php 自检契约

设置页「API 链接测试」会加载 `check.php`（**不受启用状态影响**），用于确认密钥、网络、接口地址是否可用；声明 `report.ocr.log` 的插件按钮文案为「查看日志」。

```php
<?php
if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

$cfg = $GLOBALS['BG_PLUGIN_CTX']['config'];
$ok = !empty($cfg['apiKey']);

return array(
    'ok' => $ok,
    'message' => $ok ? '配置完整，接口可访问' : '请先填写 API Key',
    'details' => array(
        array('label' => 'API Key', 'ok' => !empty($cfg['apiKey']), 'detail' => ''),
        array('label' => '接口连通', 'ok' => true, 'detail' => 'HTTP 200，耗时 320ms'),
    ),
);
```

| 字段 | 说明 |
| --- | --- |
| `ok` | 总体是否通过 |
| `message` | 结论文字，≤160 字 |
| `details` | 明细，**最多 10 条**，每条 `label` ≤40 字、`detail` ≤160 字 |

自检里 `$BG_PLUGIN_CTX` 的 `image` 为空、`catalog` 为空，`action` 为 `check`。

服务器出网统一走 `lib/net.php` 的封装（自动带 `lib/cacert.pem`，支持代理与跳过校验），可参考 `baidu-ocr` 的实现，不建议直接用 `file_get_contents` 访问外网。

---

## 6. 完整最小示例

`public/plugins/my-ocr/plugin.json`：

```json
{
  "id": "my-ocr",
  "name": "我的识别引擎",
  "version": "0.1.0",
  "description": "演示插件：把零件库第一条当作识别结果返回。",
  "capabilities": ["report.ocr"],
  "entry": "handler.php",
  "settings": [
    { "key": "note", "label": "提示语", "type": "text", "default": "演示插件" }
  ]
}
```

`public/plugins/my-ocr/handler.php`：

```php
<?php
/* 仅允许由 api.php 加载 */
if (!isset($GLOBALS['BG_PLUGIN_CTX']) || !is_array($GLOBALS['BG_PLUGIN_CTX'])) {
    http_response_code(403);
    exit;
}

$ctx = $GLOBALS['BG_PLUGIN_CTX'];
$catalog = $ctx['catalog'];
$cfg = $ctx['config'];

if (!$catalog) {
    throw new RuntimeException('零件库为空，请先录入零件');
}

$part = $catalog[0];
$proc = isset($part['processes'][0]) ? $part['processes'][0] : '';

return array(
    'engine' => 'my-ocr',
    'confidence' => 0.5,
    'fields' => array(
        'date' => $ctx['today'],
        'items' => array(
            array(
                'partNo' => $part['no'],
                'partName' => $part['name'],
                'process' => $proc,
                'qty' => 1,
                'price' => null,
            ),
        ),
    ),
    'warnings' => array($cfg['note'] . '：仅用于流程验证'),
    'raw' => $part['no'] . "\t" . $proc . "\t1",
);
```

---

## 7. 接入步骤清单

1. 在 `public/plugins/` 下新建目录，目录名即插件 id（小写、短横线）。
2. 放入 `plugin.json`、`handler.php`（可选 `check.php`）。
3. 打开「识别插件设置」，确认插件出现在列表中。
4. 填写密钥等设置并保存（`password` 保存后回显 `********`）。
5. 点「API 链接测试」跑 `check.php`；外网问题可用「互联网连接测试」进一步定位。
6. 启用插件，在「新增报工 → 拍照识别」上传一张单子验证结果面板。
7. 同一能力有多个插件时，可在设置页「识别引擎」选择默认识别引擎（默认「自动」，优先使用融合引擎），拍照识别处也能临时切换；启用状态记录在 `data/plugins.json`。
8. 日志 / 开关类插件声明 `report.ocr.log`，在 `check.php` 里输出日志目录可写性与最近记录即可，无需实现识别逻辑。

---

## 8. 调试与排错

| 现象 | 排查方向 |
| --- | --- |
| 设置页看不到插件 | 目录名与 `id` 是否一致；`plugin.json` 是否合法 JSON；`entry` 文件是否存在 |
| 识别返回「插件没有返回结果」 | `handler.php` 是否 `return` 了数组；是否在其中 `exit`/输出内容 |
| 提示「该识别插件已停用」 | 设置页启用开关 |
| 502 且消息为自定义文案 | 插件内 `RuntimeException` 内容，检查密钥、接口地址 |
| 图片相关报错 | 前端只接受 JPG/PNG/WebP，≤8MB |
| 识别结果条数被截断 | `items` 上限 20 条，`warnings` 上限 5 条 |
| 日期没填上 | 必须返回真实存在的 `YYYY-MM-DD`；也可在插件里用 `today` 兜底 |
| 日志目录不可写 | 检查 `data/ocr-logs/` 权限（Web 用户需可写）；在设置页点 `ocr-log` 的「查看日志」可看到具体路径 |

日志：PHP `error_log`，关键词 `[baogong] 插件`。

---

## 9. 安全与约定

- 脚本第一行做 `BG_PLUGIN_CTX` 检查，防止被直接访问。
- 密钥只存服务器 `data/plugins.json`，不要写进代码、不要返回给前端。
- 图片 dataURL 可能很大，解析前先校验前缀与长度。
- 外部请求设置超时（建议 15–45 秒，视觉大模型可到 45 秒），并对超时 / 连接中断 / 429 限流 / 5xx 等瞬时错误做有限次重试（参考 `baidu-ocr`：25 秒超时、最多重试 1 次；`mimo-v26-flash`：45 秒超时、可配 0~2 次退避重试），HTTP 业务错误不重试，失败抛出可读的中文错误。
- 插件版本独立于系统版本号；升级系统不覆盖 `plugins/` 下的自定义插件。
