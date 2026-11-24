# 手写字词库管理插件（handwriting-lexicon）

统一维护手写报工单识别中的**图号别名 / 工序同义 / 单位同义 / 形近字符**，供所有 OCR 插件共享查询，形成「识别 → 发现错字 → 人工纠正 → 沉淀词库 → 下次自动纠正」闭环。

- 插件 id：`handwriting-lexicon`（目录名与 id 严格一致）
- 目标环境：PHP 7.4+，无 composer 依赖，纯 PHP + 原生 HTML/CSS/JS
- 存储：`data/handwriting_lexicon.json`（可用设置项 `lexiconFile` 覆盖）
- 能力：`lexicon.manage`（自定义能力，供后续主程序注册菜单使用）

---

## 1. 安装

```bash
# 把整个目录拷到主程序的 public/plugins/ 下即可
cp -r public/plugins/handwriting-lexicon /path/to/baogong-system/public/plugins/

# 可选：导入种子词库（PRD §4.3 的 8 条示例）
cp public/plugins/handwriting-lexicon/fixtures/demo-entries.json \
   /path/to/baogong-system/data/handwriting_lexicon.json
```

不导入种子也能运行：词库文件不存在时按空词库处理，查询一律原样返回。首次写入由 `BglxStore` 自动创建文件与目录。

目录结构：

```
handwriting-lexicon/
├── plugin.json              插件清单与设置项
├── handler.php              协议入口（安全空 OCR 结果）
├── check.php                设置页自检
├── api.php                  JSON 接口（管理页与反馈通道）
├── admin.php                独立管理页（PWA，可添加到主屏幕）
├── manifest.webmanifest     PWA 清单（桌面图标入口）
├── assets/icon.svg          PWA/登录页图标
├── assets/admin.css         管理页样式（响应式，适配手机）
├── assets/admin.js          管理页交互
├── lib/
│   ├── config.php           路径 / 配置解析
│   ├── schema.php           数据模型与校验
│   ├── store.php            BglxStore 存储抽象
│   ├── corrector.php        BglxCorrector 两级纠错
│   ├── auth.php             BglxAuth 鉴权
│   ├── http.php             JSON 信封与参数读取
│   └── lexicon.php          ★ 唯一公共库入口（OCR 插件只 require 它）
├── fixtures/demo-entries.json  种子词条
├── tests/corrector.test.php  零依赖单元测试
├── docs/                    接入 / 导入格式 / 验收清单
└── examples/                四个 OCR 插件改造片段
```

---

## 2. 访问管理页

主程序目前没有插件菜单注册能力，因此管理页使用**独立 URL**：

```
/plugins/handwriting-lexicon/admin.php
```

- 在插件「设置」页能看到 `adminUrl` 只读提示项，**复制该地址到浏览器打开并收藏**。
- **关于"设置页跳转按钮"**：主程序的插件设置项仅支持 `text / password / number / select / checkbox / textarea / multiselect` 七种类型，**不支持 link / button / url**，因此插件侧无法在设置页渲染一个可点击的跳转按钮（这是主程序能力限制，非本插件缺陷）。
  - **手机端推荐做法**：用手机浏览器打开管理页后，点浏览器菜单「添加到主屏幕」（iOS Safari：分享 → 添加到主屏幕；Android Chrome：菜单 → 添加到主屏幕）。管理页已内置 PWA manifest 与图标，添加后生成桌面图标，**以后一键直接进入**，等价于一个常驻跳转入口。登录页也提供「复制本页地址」按钮。
  - 若将来主程序支持插件菜单注册或 link 类型设置项，只需增加相应钩子/字段，无需改动本插件内部实现。

**移动端适配**：管理页已完整适配手机——统计卡片 2×2、筛选控件整行、词条表格在窄屏自动转为卡片式（每字段带中文标签）、弹窗全屏单列、触摸目标放大，兼容 320px 起窄屏，无横向溢出。

登录方式（三选一）：

1. 在管理页表单输入 `adminToken` 登录（写入独立 session `BGLXSESSID`）；
2. URL 带参数：`/plugins/handwriting-lexicon/admin.php?token=你的令牌`；
3. 请求头 `X-Bglx-Token: 你的令牌`。

**未配置令牌时仅允许本机 `127.0.0.1` / `::1` 访问**，方便本地开发零配置使用；非回环且无令牌返回 `40301`。生产环境请务必设置 `adminToken`。

---

## 3. 配置说明（`plugin.json` settings）

| 设置项 | 类型 | 默认 | 说明 |
| --- | --- | --- | --- |
| `adminToken` | password | 空 | 管理页令牌；留空仅回环访问。也可用环境变量 `BGLX_ADMIN_TOKEN` |
| `adminUrl` | text | `/plugins/handwriting-lexicon/admin.php` | 只读提示，便于管理员复制收藏 |
| `autoThreshold` | text | `0.90` | 自动纠正阈值，命中置信度 ≥ 此值时直接替换 |
| `suggestThreshold` | text | `0.75` | 人工确认阈值，介于两者之间只提示不替换 |
| `lexiconFile` | text | 空 | 词库文件绝对路径；仅当 `data/` 不在标准位置时需要 |
| `trustedProxy` | text | 空 | 可信反向代理 IP（逗号分隔）；留空时回环判定只看 `REMOTE_ADDR`，伪造 XFF 无效 |

> **安全默认**：未配置令牌时仅信任服务器直连地址 `REMOTE_ADDR`，`X-Forwarded-For` / `X-Real-IP` 一律忽略。只有确实部署在反向代理后，才把代理 IP 填入 `trustedProxy`；此时且仅当直连地址命中该名单时才采信转发头。也可用环境变量 `BGLX_TRUSTED_PROXY` 设置。

> 配置从 `data/plugins.json` 读取，兼容 `plugins.<id>.config` / `<id>.config` / `<id>` / `plugins.<id>.settings` 四种形态；主程序结构确认后可微调 `bglx_plugin_config()`。

---

## 4. 纠错规则（保守原则）

两级处理：

1. **字符级归一**：`char_confusion` 词条把 `L/l→1`、`O/o→0`、`/→7` 等形近字符替换为标准字符；
2. **词条级匹配**：用归一结果精确匹配 `drawing_alias` / `process_synonym` / `unit_synonym` 的 `standard` 或 `variants`。

**归一后没有命中任何词条，一律原样返回**——绝不因为字符映射本身改写输入。

| 条件 | action | 处理 |
| --- | --- | --- |
| 唯一命中，有效置信度 ≥ 自动阈值 | `auto` | OCR 插件直接替换字段值 |
| ≥ 提示阈值且 < 自动阈值 | `suggest` | 保留原值，追加 warnings 交人工确认 |
| 多候选且标准值不同 | `suggest`（强制降级） | 附 candidates 供界面提示 |
| 低于提示阈值 / 未命中 | `none` | 原样返回 |

有效置信度 = `min(命中词条 confidence, 参与字符归一的最小 char_confusion confidence)`。

图号匹配大小写不敏感、忽略空格与 `-_.` 分隔符；返回值使用词条的 `standard` 原样（如 `24971b05` 保持小写 b）。工序 / 单位为中文精确匹配。

---

## 5. OCR 插件接入

> **推荐**：新接入统一走连接层 `lib/bridge.php`（一行 init + 批量归一 + 静默降级），不再各自 require `lexicon.php`。下面的直接 require 写法为兼容保留。

**连接层接入（推荐）**：

```php
$bglxBridge = dirname(__DIR__) . '/handwriting-lexicon/lib/bridge.php';
if (is_file($bglxBridge)) {
    require_once $bglxBridge;
    bglx_bridge_init('your-plugin-id');
}
if (function_exists('bglx_bridge_norm_fields')) {
    $n = bglx_bridge_norm_fields($fields, array('plugin' => 'your-plugin-id'));
    $fields = $n['fields'];                       // auto 已改写
    foreach (bglx_bridge_suggestions() as $sug) { // suggest 仅提示
        $warnings[] = '字库建议：' . $sug . '，请人工确认';
    }
}
```

连接层 API 速查：

| 接口 | 用途 |
|---|---|
| `bglx_bridge_init($pluginId, $opts)` | 初始化（幂等），返回是否可用 |
| `bglx_bridge_norm($kind, $raw, $opts)` | 单值归一（text/partno/process/unit） |
| `bglx_bridge_norm_fields($fields, $opts)` | 批量归一，返回 `{fields, changes, suggestions}` |
| `bglx_bridge_suggestions()` | 本次请求 suggest 提示（去重） |
| `bglx_bridge_status()` | `available/degraded/failCount/version` |

**降级说明**：词库缺失 / 损坏 / 函数不存在 / 查询异常 / 连续失败 ≥3 次熔断，一律等价「未命中」（`action=none`），不阻断识别、不改变字段语义。详见 `docs/ocr-integration.md` §7。

**直接 require 公共库（兼容保留）**：

```php
require_once dirname(__DIR__) . '/handwriting-lexicon/lib/lexicon.php';

$r = bglx_correct_partno('2L59711305', array('plugin' => 'baidu-ocr'));
if ($r['action'] === 'auto') {
    $partNo = $r['corrected'];               // 24971b05
} elseif ($r['action'] === 'suggest') {
    $warnings[] = '图号疑似「' . $r['corrected'] . '」，请人工确认';
}
```

- `bglx_correct_partno()` 图号、`bglx_norm_process()` 工序、`bglx_norm_unit()` 单位；
- 新增词条后**下一次识别立即生效**，无需重启、无需网络请求（同进程读同一 JSON）。
- 腾讯云 / Qwen3.5 / MiMo 的接入片段见 `docs/ocr-integration.md` 与 `examples/`。
- 本次实际改造的百度插件参考位于 `public/plugins/baidu-ocr-lexicon-demo/`；主工程 `fixed-plugins/baidu-ocr/handler.php` 已同步加入同样的受保护改造。

---

## 6. 灰度与回退

| 场景 | 做法 |
| --- | --- |
| 新词条先观察 | 设为 `status=pending` 或 `enabled=false`，管理页高亮待审核，查询不生效 |
| 灰度到单插件 | 词条 `scope` 填写插件 id（P0 已实现过滤逻辑，UI 固定 `*`） |
| 发现误伤 | 在管理页把该词条启用开关关闭即可，下一次查询立即失效；记录保留便于复盘 |
| 整体回退 | 删除 OCR handler 中的 `require_once` 行即可（所有调用都在 `function_exists` 保护内） |

人工核对员改写后的正确值，建议通过 API 生成候选词条：

```
POST api.php
X-Bglx-Token: <token>
{"action":"create","type":"process_synonym","standard":"钻孔","variants":["铣2"],"source":"ocr_feedback","status":"pending","confidence":0.75}
```

管理员在管理页审核通过后，所有 OCR 插件共享该词条，闭环完成。

---

## 7. 自检与测试

```bash
cd public/plugins/handwriting-lexicon

# 全部 PHP 文件语法检查
find . -name '*.php' -exec php -l {} \;

# 纠错引擎单元测试（零依赖，30 个断言）
php tests/corrector.test.php

# 快速验证核心函数
php -r 'require "lib/lexicon.php"; var_export(bglx_correct_partno("2L59711305"));'
# 期望：corrected=24971b05, action=auto, strategy=char_norm
```

插件设置页的「自检」调用 `check.php`，检查 PHP 版本、数据目录可写、JSON 合法、四类计数、阈值、鉴权模式与示例查询结构，返回 `{ok, message, details}`。

---

## 8. API 速查

统一信封：`{"code":0,"message":"ok","data":{...}}`

| action | 方法 | 说明 |
| --- | --- | --- |
| `list` | GET | 分页列表 + 统计；参数 `type/status/enabled/keyword/page/page_size` |
| `get` | GET | 按 id 取单条 |
| `create` / `update` / `delete` | POST | 增删改 |
| `set_enabled` / `review` | POST | 启停 / 审核 |
| `query` | GET/POST | 纠错查询（与公共库同一结果对象） |
| `test` | POST | 管理页测试区，返回 `result` + `trace` |
| `stats` | GET | 统计摘要 |
| `import` | POST | `format=json\|procAlias`，`mode=merge\|replace`；csv 返回 `50101` |
| `export` | GET | 直接下载 JSON 附件（不走信封） |

错误码：`40001` 参数 / `40101` 未授权 / `40301` 禁止 / `40401` 不存在 / `40901` 锁冲突 / `42201` 校验失败（`data.errors`）/ `50101` 未开放 / `50001` 存储失败。

---

## 9. 待明确事项（不阻塞 P0，已给兜底）

| # | 事项 | 当前兜底 | 需谁确认 |
| --- | --- | --- | --- |
| A1 | `data/plugins.json` 真实结构 | 多形态解析 + 环境变量兜底 | 拿到主程序源码后校准 |
| A2 | 设置页是否把 `adminUrl` 渲染为可点击链接 | hint 中给出完整 URL 文本 | 主程序前端行为 |
| A3 | 直接访问 Web 时 `DATA_DIR` 是否可用 | 从插件目录上溯 4 级 + `lexiconFile` 覆盖 | 部署环境 |
| A4 | 其余三个 OCR 插件改造排期 | 已提供片段，本次只改百度 | 主理人 / 产品 |
| A5 | CSV 导入列格式 | 返回 `50101`，扩展点已预留 | 产品（P1） |
| A6 | 命中统计写入频率 | P0 只保留 `stats` 结构不累加 | 产品（P1） |
| A7 | 中文形近字是否用混淆集模型 | P0 用显式词条覆盖 | 产品（P2） |

P1 / P2 扩展点已在代码注释中标注；本版本严格只交付 P0 功能。
