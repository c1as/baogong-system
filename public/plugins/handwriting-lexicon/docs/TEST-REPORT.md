# 手写字词库审查工作台 v3 逐条判定改造 — QA 独立验证报告

| 项 | 内容 |
|---|---|
| QA 工程师 | 严过关 |
| 验证性质 | **独立验证**（不盲信工程师自报；关键红线自写对抗脚本交叉验证） |
| 唯一真源 | `/workspace/handwriting-review-v3/public/plugins/handwriting-lexicon/`（43 文件） |
| 基线 | 作者 fixed 版（`handwriting-lexicon-plugin.zip`，交接文档 §5 校验值） |
| 环境 | PHP 8.3.6 / Node v22.13.1 / zip 3.0 / Linux |
| 测试断言总数 | **197**（既有套件 174 + QA 独立对抗 23），通过 197，失败 0 |
| **智能路由判定** | **NoOne**（全绿，无需回派工程师） |
| 遗留阻断问题数 | **0** |

---

## 一、验证概览

独立验证按「静态校验 → 作者 7 修复保护核验 → 既有单测 → v3 专项逐条 → 回归兼容 → 打包」六段推进，全部通过。核心结论：

1. 全部 PHP 语法、JSON、JS 语法校验通过；唯一"畸形 JSONL 行"是 `tests/fixtures/sample-ocr-log.jsonl:1` 中 invoke_id 含 `-broken` 的**故意容错测试夹具**（`review.test.php` C1 断言"坏行跳过"），非缺陷。
2. **作者 7 项修复零回退**：`assets/admin.js`、`lib/log_reader.php` 两个保护文件的 SHA-256 与交接文档 §5 列出的 fixed 基线**逐字精确一致**；其余 F4/F5/F6 保护代码段均在位并有断言。
3. 工程师自报 174 项全过属实；QA 另写 23 项独立对抗断言全过，覆盖状态派生、四类覆盖、乐观锁、AI 仅 wrong、日志只读。

---

## 二、智能路由判定

| 分类 | 判定 | 说明 |
|---|---|---|
| 源码 Bug → Engineer | 无 | 未发现任何实现与 PRD/架构不符 |
| 测试 Bug → QA 自修 | 无 | 测试断言均正确且通过 |
| 全部通过 → **NoOne** | ✅ 本次结论 | 174+23 项全绿，作者修复无回退，`check.php ok=true` |

---

## 三、静态校验结果

| 校验项 | 范围 | 结果 |
|---|---|---|
| `php -l` | 25 个 `.php` 文件 | 全通过，failed=0 |
| `plugin.json` | JSON 合法性 | 合法；settings 34 项（原 30 + v3 新增 4）；review 子系统默认表 24→28 项，与架构一致 |
| `tests/fixtures/*.jsonl` | 2 个夹具 | 合法；`sample-ocr-log.jsonl:1` 为故意畸形行（容错测试用） |
| `node --check` | `review.js` / `admin.js` | 均通过 |
| 临时文件扫描 | `.lock`/`error_log`/`.swp`/`*~`/`.DS_Store` 等 | 真源 0 个 |

---

## 四、作者 7 修复保护核验结果（零回退）

**硬证据（SHA-256 逐字比对交接文档 §5）：**

| 保护文件 | 交接 fixed 基线 SHA | 当前真源 SHA | 结论 |
|---|---|---|---|
| `assets/admin.js`（F1） | `6E0ED5B5…` | `6e0ed5b5…` | ✅ 逐字一致，零回退 |
| `lib/log_reader.php`（F2/F3） | `B5D59310…` | `b5d59310…` | ✅ 逐字一致，零回退 |

**逐项核验：**

| # | 修复 | 核验点 | 结果 |
|---|---|---|---|
| F1 | Tab 可切换 | `admin.js` SHA 与基线一致；`review.js` `bglx:tab` 监听在 762 行；测试 J-F1a/b | ✅ 未回退 |
| F2 | 字段映射 no→partNo/proc→process/qty | `log_reader.php` SHA 一致；`bglx_rev_project_row()` 映射链未动；测试 J-F2、独立 F2 | ✅ 未回退 |
| F3 | 过滤 match 空记录 | `log_reader.php` SHA 一致；测试 J-F3、独立 F2（kind=match 返回空） | ✅ 未回退 |
| F4 | 稳定键 `log-<sha1>` + judgment 回显 + invokeLabel | `api.php:392` `$inv = 'log-' . substr(sha1($seed),0,20)` 原样；`review.js:140` `invokeLabel()`；列表徽标渲染在；测试 J-F4a/b/c | ✅ 未回退（顶层改派生投影，徽标/统计/筛选照常） |
| F5 | 目录默认值 | `plugin.json` `attachmentDirs=images/attachments/`、`logDir=ocr-logs/`；check.php 第 11 段实测"F5 默认值未漂移"；测试 J-F5/F5b | ✅ 未漂移 |
| F6 | 缓存版本参数 | `admin.php:401-402` `admin.js?v=1.2.0`、`review.js?v=1.2.0`（基线 1.1.0/1.1.1 → 1.2.0 已递增）；测试 J-F6 | ✅ 已递增 |
| F7 | 主程序入口 + select 字符串兼容 | `handler.php` 入口未变；插件侧对外函数签名兼容；测试 J-F7a/b | ✅ 兼容（主程序不在插件包内，签名未变） |

结论：作者 7 项修复**零回退**，无阻断 Bug。

---

## 五、v3 专项逐条结果

结合既有 `review_v3.test.php`（51 项）与 QA 独立对抗脚本（23 项）交叉核验：

| 专项 | 验证要点 | 结果 |
|---|---|---|
| **逐条判定** | 判 correct 不填值可保存、判 wrong 填正确值可保存；行级 `row_key` 独立回显；刷新后经 store 重读保持（独立 C1/C3） | ✅ |
| **状态派生** | 空行/有未判→draft；全判完自动 reviewed（无人工点完成）；任一行改回未审→回落 draft；wrong 优先聚合；imported 终态保持（独立 A1–A5、C3/C4） | ✅ |
| **四类全覆盖** | 3 明细×（图号/工序/单位）+形近字 = 10 行；第 2/3 条字段生成；`qty` 不生成判定行但字段对照区可见；`row_key` 幂等合并不丢已判结果（独立 B1–B6） | ✅ |
| **乐观锁** | 并发改同一行 version 不匹配抛 `BglxReviewLockException` → API 40901；service 层守卫 42201（判错空值）、41005（imported 终态）（独立 C2） | ✅ |
| **90 天归档** | 91 天前行进 `review-YYYY-MM.json`；主文件移除；审计搬迁不硬删 + `retention_archive` 审计；前后审计守恒；`retentionDays=0` 禁用；**先写归档文件后移除**、**先按天后条数裁剪**（代码审查 §7 + 测试 E1–E9、H1） | ✅ |
| **存量迁移** | schema1 旧行无 row_key/judgment 自动补 `row_key`；correct_value 非空→wrong、空→unreviewed；其余字段保留；写回 schema2（独立 D1–D3、测试 D1–D5） | ✅ |
| **AI 仅判错行** | 对抗哨兵：correct 行值不进 prompt、wrong 行进 prompt；`ai_organizer` 二次断言；AI 红线 pending/accepted=false/conf≤0.8（独立 E1–E3、测试 G1–G3） | ✅ |
| **人工入库仅 wrong** | manual 路径默认 only_wrong，正判行 added=0；AI 候选强制 pending + 需勾选 + 签名防伪造（测试 G4–G6、review G5/G10/G11） | ✅ |
| **日志只读** | 审查读取前后 fixture md5 一致（独立 F1）；`log_reader.php` SHA 与基线一致 | ✅ |
| **token/POST/CSRF** | 新 action 在 `$reviewWriteActions` 白名单；写动作统一 `bglx_rev_verify_write()`（强制 POST+CSRF）+ token 鉴权；测试 K1–K5 | ✅ |
| **归档目录穿越** | `..` 相对穿越、data 外绝对路径均被拒（测试 F1–F3） | ✅ |

---

## 六、回归兼容与 check.php 自检

| 项 | 结果 |
|---|---|
| `review.test.php` 74 项（含 F1–F7 保护 J 组、信封、闭环） | ✅ 全过 |
| `corrector.test.php` 30 项 / `bridge.test.php` 12 项 / `auth_loopback.test.php` 7 项 | ✅ 全过 |
| Tab 切换 / 日志列表 / 字段映射 / 稳定键回显 / 判定徽标 / 目录读取 / 导入导出 / 移动端 | ✅ 保护断言与既有用例全过，未破坏 |
| `check.php`（注入 BG_PLUGIN_CTX 后） | ✅ `ok=true`，11 项明细全绿；第 11 项"v3 逐条判定与归档"：入口齐备/归档目录可写/到期归档 90 天/整图快捷关闭/F5 默认值未漂移 |

> 说明：`check.php` 在空 `data/` 环境下"审查环境"项显示附件/日志目录不可读（信息性提示，不影响 `ok=true`），属运行数据未部署（data 不随包），非源码缺陷；线上按交接文档部署 data 目录后即正常。

---

## 七、遗留问题

**阻断问题：0。** 无源码 Bug、无作者修复回退。

非阻断的范围性说明（均符合 PRD/架构既定取舍，无需修复）：
1. 形近字 Q2 方案 B（逐位拆对）列 P2，v3 按方案 A 记录级 `note` 单行候选交付——符合 PRD §8.3。
2. R15 归档在线查询入口列 P2，本次不实现——符合架构 §11 Q3。
3. `imported` 终态改判/重新审查列 P2，本次只读——符合架构 §11 Q6。
4. `sample-ocr-log.jsonl:1` 故意畸形行为容错测试夹具，非损坏文件。

---

## 八、打包信息

| 项 | 内容 |
|---|---|
| ZIP 路径 | `/workspace/handwriting-review-v3-plugin.zip` |
| 包内根路径 | `public/plugins/handwriting-lexicon/...`（未多套一层根） |
| 文件总数 | 46（真源 43 + docs 新增 3） |
| 随包文档 | `docs/PRD.md`、`docs/ARCHITECTURE.md`、`docs/AUTHOR-FIXED-HANDOVER.md`（保留作者来源） |
| 排除项 | 临时文件 0 个；tests 正式文件与 fixtures 保留；data 运行数据未随包 |
| `unzip -t` | `No errors detected in compressed data`，全部条目 OK |
| SHA-256 | `02f2df0f293b791185b480753dbba4a0d23df8e4d3733ba66eb84cbdad5b06be` |
| 大小 | 211,683 bytes（约 207 KB） |

---

> **QA 结论**：验证全部通过，作者 7 项修复零回退，v3 三条主线（逐条判定+状态派生、四类全覆盖、90 天归档）与红线（AI 仅 wrong / 零直入、审计不硬删、token+POST+CSRF 无绕过）均达标，智能路由 **NoOne**，予以打包交付。
