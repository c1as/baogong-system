# 人工判断审查工作台 · 操作手册

> 适用版本：handwriting-lexicon 1.1.0 起。
> 本文描述「只读观察 → 人工决策 → 受控写入」旁路流水线，不改动 OCR 热路径。

---

## 1. 它解决什么问题

报工系统每次识别会落盘两样东西：**识别图片**（附件目录）与**识别日志**（`data/ocr-logs/YYYY-MM-DD.jsonl`，可选融合 trace）。过去这些结果无人复核，错字无法沉淀为词库资产。

审查工作台把二者对照展示，由人判定「正确 / 错误」；错误时逐条录入「图号 / 工序 / 单位 / 形近字」的正确值；随后二选一入库：

1. **AI 整理路径**：AI 把零散纠错归纳为规范候选 → 人工逐条勾选 → 以 `pending` 入库 → 走既有 `review` 动作审核后生效。
2. **人工直接导入路径**：人工录入即视为已审，默认以 `active` 入库（冲突项强制 `pending`）。

两条路径回流的新词条统一 `source=ocr_feedback`，写操作内已触发 `bglx_reload()`，各 OCR 插件**下一次识别**即命中，无需重启。

---

## 2. 快速开始

1. 在插件设置中打开 `reviewEnabled`。
2. 按部署实际填写 `attachmentDirs`（**每行一个路径，顺序敏感**）。相对路径相对 `data/` 目录解析；系统按顺序取首个「存在且可读」的目录。主程序识别图片在 `data/images/attachments/`，默认值即 `images/attachments/`。
3. 确认 `logDir` 指向主程序写日志的目录，默认 `ocr-logs/`（即 `data/ocr-logs/`）。日志只以只读方式逐行解析，**不会回写**。
4. 浏览器打开 `/plugins/handwriting-lexicon/admin.php`，登录后切换顶部「审查工作台」Tab。
5. 在自检页确认「审查环境」明细为通过；缺 GD、缺目录会给出提示但不影响人工路径。

> 需要 AI 整理时再打开 `aiEnabled` 并配置 `aiEndpoint` / `aiModel` / `aiKey`。AI Key 只在服务端读取，界面与接口只回显 `aiKeySet` 布尔值。

---

## 3. 配置速查（24 项）

| key | 类型 | 默认 | 作用 |
|---|---|---|---|
| `reviewEnabled` | checkbox | `0` | 总开关；关闭时入口隐藏、所有 `review_*` 返回 41001 |
| `attachmentDirs` | textarea | `images/attachments/` | 图片候选目录，顺序敏感；默认指向主程序识别图片目录，用文本域而非多选以保证顺序与任意路径 |
| `attachmentExtensions` | text | `jpg,jpeg,png,webp` | 扩展名白名单，叠加 MIME 校验（拒 SVG/GIF） |
| `attachmentMaxBytes` | number | `8388608` | 单图 8MB 上限 |
| `thumbnailSize` | number | `320` | 缩略图边长，缓存于 `data/review-thumbs/` |
| `logDir` | text | `ocr-logs/` | 识别日志目录（相对 `data/`，即 `data/ocr-logs/`） |
| `logFilePattern` | text | `YYYY-MM-DD.jsonl` | 主日志命名 |
| `fusionTracePattern` | text | `fusion-trace-YYYY-MM-DD.jsonl` | trace 命名 |
| `fusionTraceEnabled` | checkbox | `1` | 是否读取展示融合裁决 chain |
| `linkStrategy` | select | `auto` | `auto/invokeId/filename/mtime/manual` |
| `linkTimeWindowSec` | number | `300` | mtime 关联容差 ±5min |
| `aiEnabled` | checkbox | `0` | AI 整理开关 |
| `aiEndpoint` | text | 混元 OpenAI 兼容端点 | 可切换其他兼容实现 |
| `aiKey` | password | 空 | Bearer Key，脱敏 |
| `aiModel` | text | `hunyuan` | 模型名 |
| `aiTimeoutSec` | number | `30` | 超时即降级人工 |
| `aiMaxCandidates` | number | `50` | 单次候选上限 |
| `aiFallbackToManual` | checkbox | `1` | 失败保留人工导入按钮 |
| `autoImport` | checkbox | `0` | 仅影响人工确认步骤；**AI 不豁免审核** |
| `manualImportStatus` | select | `active` | 人工路径默认状态，可改 `pending` |
| `aiImportStatus` | select | `pending` | 仅显式声明，运行时硬编码 clamp 为 `pending` |
| `keepOriginalImage` | checkbox | `1` | 只保留路径引用 + 缩略图缓存，不复制二进制 |
| `maskSensitive` | checkbox | `1` | 工人姓名掩码为「姓+*」 |
| `reviewPageSize` | number | `50` | 单页条目，运行时硬顶 200 |

高级运维项（不在设置 UI 中）：`aiSslVerify`（默认 `1`）控制 HTTP 客户端 SSL 校验；仅在私有证书环境才考虑关闭。

---

## 4. 日常操作

### 4.1 筛选与对照

左侧日志列表支持起始日期、结束日期、引擎、判定状态、关键词五个条件。每条卡片显示缩略图、图号、引擎、invokeId 与关联策略/置信度。右侧显示原图、识别字段只读对照、融合 trace（有则展示）。

### 4.2 图片关联（四级）

| 级别 | 策略 | 规则 | 置信度 |
|---|---|---|---|
| L1 | `invokeId` | trace/文件名含完整 invokeId | 1.00（精确）/ 0.95（包含） |
| L2 | `filename` | 文件名 token 与 partNo / invokeId 尾 4 位匹配 | 0.85 |
| L3 | `mtime` | 文件 mtime 与日志时间差 ≤ 窗口；最小差与次小差 < 30s 判歧义 | 0.60 |
| L4 | `manual` | 审查员改绑，永久覆盖自动结果 | 1.00 |

歧义时界面提示「多张候选，请手动指定」，不会自动采用。在右侧「图片相对路径」输入框填入路径并点「应用关联」即完成人工绑定。

### 4.3 判定与纠错

- 点「正确 / 错误 / 撤销判定」即持久化；判定结果带 `version` 乐观锁，两人同时改判会提示 40901，刷新后重试。
- 判为「错误」后，下方自动带出四行纠错（错误值取自识别结果）。填写正确值、补充变体（逗号分隔）、备注，点「保存纠错草稿」。
- 可「+ 添加纠错行」处理同一条日志中的多处错误；可删除多余行。

### 4.4 入库

**人工直接导入**：至少填一条正确值，选冲突策略后点按钮。默认 `active`；命中冲突则强制 `pending` 并在结果中提示跳过数。

**AI 整理**：点「AI 整理纠错」→ 候选卡片逐条勾选「采纳」，可直接修改 standard / variants → 点「导入已勾选的 AI 候选」。所有 AI 词条一律 `pending` + `confidence ≤ 0.8`，**不会自动生效**。回到「词库管理」Tab，对 pending 词条点「通过」即走既有 `review` 动作激活，随后 OCR 下一次识别生效。

冲突策略：`ask`（冲突转待审）/ `skip`（跳过）/ `merge` / `overwrite`（后两者同样转待审，不做破坏性覆盖）。

### 4.5 撤销草稿

`review_delete_draft` 可删除审查记录（前端二次确认）。日志本身是外部只读文件，删除不会影响它，下次列表仍会出现为「未审」。

---

## 5. 数据落盘与隔离

| 用途 | 路径（相对 `data/`） |
|---|---|
| 词库（既有） | `handwriting_lexicon.json` + `.lock` |
| 审查记录（新） | `handwriting_review.json` + `.lock` |
| 缩略图缓存 | `review-thumbs/<md5>.jpg` 或 `.webp` |
| 按月归档 | `review-archive/review-YYYY-MM.json` |
| 识别日志（只读） | `ocr-logs/YYYY-MM-DD.jsonl` |
| 融合 trace（只读） | `ocr-logs/fusion-trace-YYYY-MM-DD.jsonl` |

审查记录与词库**各自独立锁**，互不阻塞 OCR 热路径。`audit` 上限 5000 条，超出把最早 1000 条按月归档；`items` 上限 20000 条，超出裁剪最早条目并写审计。

---

## 6. 降级矩阵

| 故障 | 表现 | 降级行为 | 是否阻断人工路径 |
|---|---|---|---|
| `reviewEnabled=0` | 入口隐藏、API 41001 | 无需降级，按设计关闭 | — |
| 附件目录不可读 | 列表 warnings | 顺序取下一个候选目录；全不可读时图片区为空 | 否 |
| 日志目录不可读 | warnings「日志不可读：日期」 | 跳过该日，继续其他日期 | 否 |
| 日志坏行 | `bad_lines` 计数 | 跳过坏行，界面提示 | 否 |
| 日志超 20 万行 / 31 天 / 200 条 | `truncated` 标记 | 返回结果标注「约」 | 否 |
| 图片非白名单类型 / 超 8MB / 损坏 | 跳过并在 warnings 记录原因 | 其他图片照常展示 | 否 |
| GD 未安装 | `thumb_fallback='original'` | `<img>` 直出原图 | 否 |
| WebP 解码不支持 | 缩略图降级 JPEG 缓存 | 原图仍可查看 | 否 |
| AI 未启用 / Key 缺失 | 不显示 AI 按钮 | 人工导入始终可用 | 否 |
| AI 超时 / 非 2xx / 解析 0 候选 | API 41003 + `fallback=true` | 界面保留人工导入按钮并提示原因 | 否 |
| 导入冲突 | 结果提示与待审数 | 强制 `pending`，可合并/跳过 | 否 |
| 版本冲突 | 40901 | 提示刷新后重试 | 否 |

---

## 7. 安全红线（实现层保证）

- **目录穿越**：`..` 段拒绝 + `realpath` + base 前缀带尾 `/` 比对，符号链接越界同样被挡；图片、日志、缩略图缓存各自独立校验。
- **日志只读**：只 `fopen(..., 'rb')`，无写模式；`raw` 截断 2000 字；姓名掩码。
- **鉴权无旁路**：所有 `review_*` 与既有动作共用同一 `BglxAuth::requireAuth()`；写动作额外要求 POST + 会话级 CSRF nonce（`X-Bglx-CSRF`），无效返回 41004。
- **AI 零直入四重保证**：① `resolve_status('ai',...)` 恒 `pending`；② 冲突强制 `pending`；③ 不信任客户端 `status`，`source=ai` 必须 `accepted=true`，服务端按候选签名复核来源，防伪造 `source`；④ AI 置信度上限 `0.8 < autoThreshold 0.90`，激活唯一途径是既有 `review` 动作。
- **XSS**：纠错文本、文件名、日志字段一律前端 `textContent`，PHP 侧 `bglx_h()` / `bglx_cut()`；图片输出带 `nosniff` + `inline` + MIME 白名单。
- **AI Key**：只在服务端读取，任何响应、自检 detail、审计 detail 都不含明文，只回 `aiKeySet`。

---

## 8. API 速查

| action | 方法 | 说明 |
|---|---|---|
| `review_settings` | GET | 能力标志、页大小、`aiKeySet`（无 Key） |
| `review_list_logs` | GET | 日期/引擎/判定/关键词筛选，返回只读投影 + 关联 + 草稿状态 |
| `review_list_images` | GET | 图片元数据与告警 |
| `review_image` | GET | 受控图片流，`path` + `thumb` |
| `review_link` | POST | 人工改绑（`version` 乐观锁） |
| `review_judge` | POST | 正确 / 错误 / 撤销 |
| `review_save_corrections` | POST | 纠错草稿 |
| `review_ai_organize` | POST | AI 整理；失败 41003 + `fallback=true` |
| `review_import_decisions` | POST | 受控入库，返回 added/pending/skipped/errors/ids |
| `review_stats` | GET | 审查统计卡 |
| `review_delete_draft` | POST | 撤销审查草稿 |

错误码：`41001` 未启用、`41002` 目录不可读、`41003` AI 失败、`41004` CSRF 无效、`40901` 乐观锁冲突、`42201` 字段校验失败；其余沿用既有码。

---

## 9. 架构取值记录（Q1–Q8）

| 编号 | 取值 |
|---|---|
| Q1 | `auto`：invokeId(1.0/0.95) → filename(0.85) → mtime ±300s(0.60) → 人工(1.0)；歧义不自动采用 |
| Q2 | 禁止 AI 直入；强制 pending + accepted + conf≤0.8，四重保证见 §7 |
| Q3 | 人工 active（`manualImportStatus` 可改 pending）、AI pending、冲突 pending |
| Q4 | 默认 4 个附件候选目录，顺序首个可读者胜出，textarea 可现场改 |
| Q5 | 单位取 `items[].unit` → `fields.unit` → `qty` 尾缀正则；形近字取 `note/raw`，等长逐位拆对 |
| Q6 | `keepOriginalImage=true` 仅保留路径引用与缩略图缓存；`maskSensitive=true` 姓名掩码 |
| Q7 | 仅混元 OpenAI 兼容端点，不接双引擎交叉校验 |
| Q8 | 独立 `handwriting_review.json`（与词库隔离），flock + rename；audit 5000 上限、按月归档 |

---

## 10. 自检与测试

```bash
cd /workspace/handwriting-review/public/plugins/handwriting-lexicon

# 语法与 JSON
for f in $(find . -name '*.php'); do php -l "$f" || exit 1; done
python3 -m json.tool plugin.json >/dev/null

# 全量测试
php tests/review.test.php
php tests/bridge.test.php
php tests/corrector.test.php
php tests/auth_loopback.test.php
```

自检入口由主程序 `api.php?action=plugin.check` 触发，共 10 条明细；第 10 条为合并的「审查环境」。

---

## 11. 双副本同步

开发目录 `/workspace/handwriting-review/public/plugins/handwriting-lexicon/` 是**唯一真源**，禁止在副本单独改代码。同步命令：

```bash
SRC=/workspace/handwriting-review/public/plugins/handwriting-lexicon
for DST in /workspace/public/plugins/handwriting-lexicon \
           /workspace/ocr-fusion/public/plugins/handwriting-lexicon; do
  # 基础版缺 bridge.php，先从含 bridge 版补齐（仅基础版需要）
  [ -f "$DST/lib/bridge.php" ] || cp /workspace/ocr-fusion/public/plugins/handwriting-lexicon/lib/bridge.php "$DST/lib/bridge.php"
  for F in api.php admin.php check.php plugin.json \
           lib/review.php lib/review_store.php lib/attachment.php lib/log_reader.php \
           lib/linker.php lib/ai_organizer.php lib/importer.php lib/config.php lib/http.php \
           assets/review.js assets/admin.css \
           docs/review-workflow.md tests/review.test.php tests/fixtures/sample-ocr-log.jsonl \
           tests/fixtures/sample-fusion-trace.jsonl; do
    mkdir -p "$DST/$(dirname "$F")"; cp "$SRC/$F" "$DST/$F"
  done
done
```

同步后在三处分别运行 `php tests/review.test.php` 与 `plugin.check`，确认结果一致。
