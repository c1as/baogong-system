# 交接文档 · 手写字词库管理插件 v3（逐条判定审查工作台）

> 本文档是当前最新版的**唯一交接入口**。适用于 `handwriting-review-v3` 全部交付。
> 版本迭代：v1（词库基础）→ v2（人工审查闭环）→ **作者 fixed 版（线上部署，7 项修复）→ v3（逐条判定改造，本文）**。
> 设计依据见同目录 `ARCHITECTURE.md`，验收证据见 `TEST-REPORT.md`，作者历史修复见 `AUTHOR-FIXED-HANDOVER.md`。

| 项 | 内容 |
|---|---|
| 插件 id | `handwriting-lexicon`（目录名与 id 严格一致） |
| plugin.json version | `1.1.0` |
| 前端缓存版本 | `admin.js?v=1.2.0` / `review.js?v=1.2.0`（已递增，防浏览器缓存） |
| capabilities | `lexicon.manage`、`lexicon.review` |
| 目标工程 | 机加工报工系统 `c1as/baogong-system`（纯 PHP，无 composer） |
| 基线 | **主程序作者 fixed 版**（线上在用的 7 项修复版），v3 在其上增量改造，7 项修复零回退 |
| 交接日期 | 2026-11-24 |

---

## 1. 一句话交接与快速上手

**它是什么**：一个可独立部署的报工系统插件，把散落在各 OCR 插件里的手写纠错经验（图号别名、工序同义、单位同义、形近字符）沉淀为统一词库；并在 v2 基础上提供「附件图片 + 识别日志对照 → 人工逐条判定 → 错误则录入正确值 → AI 整理或人工导入词库」的审查闭环。v3 把判定从"整张图"下沉到"每一条"，并新增 90 天到期归档。

**接手人 5 分钟上手**：

```bash
# 1) 部署：解压包，把 public/ 覆盖到报工系统项目根目录（插件自动被发现，无需改主程序）
unzip handwriting-lexicon-v3-交接包.zip -d <项目根目录>

# 2) 自检：设置页点「API 链接测试」，或命令行验证（期望 ok=true，11 项全绿）
php public/plugins/handwriting-lexicon/check.php

# 3) 访问管理页（设置里的令牌或本机访问）
<项目域名>/plugins/handwriting-lexicon/admin.php

# 4) 开启审查：设置页 reviewEnabled=1，配置 attachmentDirs / logDir（默认 images/attachments/ 与 ocr-logs/）
#    管理页切到「审查」Tab → 选日期/引擎 → 逐条判定 → 全部判完自动转「审查完成」

# 5) AI 整理（可选）：填 aiEndpoint/aiKey/aiModel（混元 OpenAI 兼容接口即可）；不填可纯人工导入
```

**安全硬要求**：生产环境务必配置 `adminToken`（管理页访问令牌）。留空时仅允许服务器本机访问。R1 已修复"伪造 X-Forwarded-For 绕过"，默认只信任 `REMOTE_ADDR`。

---

## 2. 四次迭代与当前基线

| 阶段 | 交付 | 关键内容 | 状态 |
|---|---|---|---|
| v1 | `handwriting-lexicon-plugin.zip`（旧） | 词库 CRUD + 管理页 + 公共库 `bglx_*` + 两级纠错 | 已被 v3 取代 |
| v2 | `handwriting-review-plugin.zip` | 新增人工审查闭环（图片+日志对照、逐条纠错、AI整理/人工入库） | 已被 v3 取代 |
| 作者 fixed 版 | `handwriting-lexicon-fixed.zip` | 主程序作者修 7 个 bug 让工作台真正可用，**线上在部署** | **不可回退基线** |
| **v3（当前）** | `handwriting-review-v3-plugin.zip` / 本交接包 | 逐条判定 + 状态派生 + 四类全覆盖 + 90 天归档 | ✅ 最新，推荐部署 |

**作者 7 项修复（v3 已逐项核验零回退，SHA 逐字一致）**：

| # | 修复 | 保护点 |
|---|---|---|
| F1 | Tab 可切换 | `admin.js` 的 `initTabs`、`review.js` 的 `bglx:tab` 监听 |
| F2 | 字段映射 `no→partNo`/`proc→process`/`qty` | `log_reader.php` 的 `bglx_rev_project_row()` 映射链 |
| F3 | 过滤 `match` 空记录 | `log_reader.php` 同文件（SHA 与基线一致） |
| F4 | 稳定键 `log-<sha1>` + judgment 回显 + invokeLabel | `api.php:392` 稳定键算法、`review.js:140 invokeLabel()`、列表徽标 |
| F5 | 目录默认值 | `plugin.json`：`attachmentDirs=images/attachments/`、`logDir=ocr-logs/` |
| F6 | 缓存版本参数 | `admin.php`：`admin.js?v=1.2.0`、`review.js?v=1.2.0` |
| F7 | 主程序入口 + select 字符串兼容 | `handler.php` 入口、对外函数签名 |

> ⚠️ 后续任何改动都**不得回退**上述 7 项；回归验证点见 `TEST-REPORT.md` §四。

---

## 3. v3 三项核心改造（本次重点）

### 3.1 判定下沉到逐条

**问题**：v2 的 `judgment`（正确/错误）挂在整条日志记录顶层，三个按钮判的是**整张图**——表达不了"明细1图号错、明细3全对"。

**改法**：判定下沉到**每一行纠错行**，`corrections[].judgment` 成为**唯一事实源**；顶层 `judgment/status` 由服务端派生投影。

- 判「正确」的行：不填正确值即可保存；
- 判「错误」的行：填正确值；
- **所有条目判完 → 自动转「审查完成」`reviewed`**，无需手动点完成；
- 任一行改回未审 → 顶层回落 `draft`。

**状态派生表**：

| 行状态 | 顶层 status | 顶层 judgment |
|---|---|---|
| 存在 `unreviewed` 行 | `draft` | 按已判行聚合 |
| 全部判完且有 wrong | `reviewed` | `wrong` |
| 全部判完且全 correct | `reviewed` | `correct` |
| 已导入 | `imported`（终态） | 保持 |

> 设计收益：作者 F4 的列表徽标/统计/筛选读取的是顶层派生字段，**零改动照常工作**。

### 3.2 四类词库字段全覆盖

**问题**：v2 的推断只取第一条明细且字段标签无"数量"，你截图里 3 条明细只填了 1 条。

**改法**：遍历**所有明细行**生成纠错行，`row_key` 幂等：

| row_key | 来源 | 词条类型 |
|---|---|---|
| `i:partNo` | 第 i 条明细的图号 | `drawing_alias` |
| `i:process` | 第 i 条明细的工序 | `process_synonym` |
| `i:unit` | 第 i 条明细的单位 | `unit_synonym` |
| `note:char` | 备注级形近字候选 | `char_confusion` |

- 3 条明细 → 3×（图号/工序/单位）+ 形近字 = 完整行集；
- **数量 qty 不生成判定行、不进词库**（词库四类不含数值），但仍在字段对照区只读可见；
- 重复推断按 `row_key` 合并且不丢已判结果。

### 3.3 90 天到期归档

- 新增 `reviewRetentionDays=90`：主文件保留 90 天，更早记录按月归档到 `data/review-archive/review-YYYY-MM.json`（**保留审计、不硬删**）；
- 计算基准 `max(created_at, updated_at)`；`retentionDays=0` 可禁用；
- 顺序严格：**先写归档文件成功 → 再从主文档移除 → 最后条数裁剪**；审计记 `expire_archive` 且守恒；
- 与日志 `ocr-log` 默认 `keepDays=14` / 图片保留期的关系：超期审查记录里的图片链接可能已失效（作者说明线上旧批次原图已删），归档主要用于审计追溯，非重新看图。

---

## 4. 交付物清单与目录结构

```
handwriting-lexicon-v3-交接包.zip
├── 交接文档/                          ← 本文档及配套流程文档（不随插件部署）
│   ├── HANDOVER.md                     ★ 本文档：唯一交接入口
│   ├── PRD.md                          v3 增量需求（含作者修复保护清单）
│   ├── ARCHITECTURE.md                 v3 系统设计（897 行 / 6 张 Mermaid 图）
│   ├── TEST-REPORT.md                  197/197 验证报告
│   └── AUTHOR-FIXED-HANDOVER.md        作者 7 项修复原始说明
└── public/plugins/handwriting-lexicon/ ← 可直接部署内容（43 文件）
    ├── plugin.json                    清单（34 项 settings，capabilities 2 项）
    ├── handler.php                    协议入口（report 调用，直访 403）
    ├── check.php                      自检（11 项）
    ├── admin.php / api.php            管理页入口 / JSON 接口（含 review_judge_row 等）
    ├── assets/
    │   ├── admin.css / admin.js       管理页前端（Tab 切换 F1）
    │   ├── review.js                  审查工作台前端（逐条判定 F4/F6）
    │   └── icon.svg / manifest.webmanifest   PWA「添加到主屏幕」
    ├── lib/
    │   ├── lexicon.php                ★ 公共库唯一入口（供 OCR 插件 require）
    │   ├── bridge.php                 ★ 字库连接层 bglx_bridge_*（供融合体系调用）
    │   ├── corrector.php / schema.php / store.php / config.php / auth.php / http.php
    │   ├── review.php                 审查子系统唯一引导器（按需 require）
    │   ├── review_judge.php           ★ v3 逐条判定服务 + 派生 + 推断 + 归档
    │   ├── review_store.php           审查记录存储（含 90 天归档 pruneExpired）
    │   ├── attachment.php / log_reader.php / linker.php
    │   └── ai_organizer.php / importer.php   AI 整理 / 受控入库
    ├── fixtures/demo-entries.json     种子词条
    ├── docs/{ocr-integration,import-format,acceptance-checklist,review-workflow}.md
    ├── examples/integrate-*.php.txt   历史接入片段（新接入以 bridge 为准）
    ├── tests/*.test.php               自带测试（含 review_v3.test.php）
    └── README.md
```

---

## 5. 部署与配置

### 5.1 部署

1. 解压，将 `public/` 合并到报工系统项目根目录（落到 `public/plugins/handwriting-lexicon/`）。
2. 目录名必须等于插件 id `handwriting-lexicon`（连字符、不含点号）——系统硬约束。
3. PHP 7.4+（v3 实测环境 8.3.6），需 `json` 扩展；`mbstring` 可选（有 polyfill 降级）；`gd`/`curl` 可选（缺失时缩略图走原图、HTTP 走 stream）。
4. 运行数据目录自动创建于 `<项目根>/data/`：
   - 词库 `data/handwriting_lexicon.json`
   - 审查记录 `data/handwriting_review.json`
   - 归档 `data/review-archive/review-YYYY-MM.json`
   - **需确保 `data/` 对 Web 用户可写**。

### 5.2 设置项（plugin.json，34 项）

**基础（6 项）**：

| key | 默认 | 说明 |
|---|---|---|
| `adminToken` | 空 | **生产必填**；留空仅本机访问；支持环境变量 `BGLX_ADMIN_TOKEN` |
| `adminUrl` | `/plugins/handwriting-lexicon/admin.php` | 展示/收藏地址 |
| `autoThreshold` | `0.90` | ≥ 此值自动纠正 |
| `suggestThreshold` | `0.75` | suggest 区间仅提示不改 |
| `trustedProxy` | 空 | 仅反代场景填写，否则留空（安全默认） |
| `lexiconFile` | 空 | 非标准 data/ 位置时指定词库绝对路径 |

**审查（24 项，节选关键）**：

| key | 默认 | 说明 |
|---|---|---|
| `reviewEnabled` | `0` | 审查工作台总开关，用前打开 |
| `attachmentDirs` | `images/attachments/` | 附件图片候选目录（textarea 保序，多候选探测） |
| `logDir` / `logFilePattern` | `ocr-logs/` / `YYYY-MM-DD.jsonl` | 识别日志位置 |
| `fusionTraceEnabled` / `fusionTracePattern` | `1` / `fusion-trace-YYYY-MM-DD.jsonl` | 融合 trace 旁路关联 |
| `linkStrategy` / `linkTimeWindowSec` | `auto` / `300` | 图片-日志关联策略与时间窗 |
| `aiEnabled` / `aiEndpoint` / `aiKey` / `aiModel` | `0` / 混元兼容端点 / 空 / `hunyuan` | AI 整理（可选） |
| `aiImportStatus` / `manualImportStatus` | `pending` / `active` | AI 候选强制 pending；人工导入默认 active |
| `keepOriginalImage` / `maskSensitive` | `1` / `1` | 原图保留 / 脱敏 |

**v3 新增（4 项）**：

| key | 默认 | 说明 |
|---|---|---|
| `reviewRetentionDays` | `90` | 审查记录保留天数，到期归档；设 0 禁用 |
| `reviewArchiveEnabled` | `1` | 是否启用到期归档 |
| `reviewArchiveDir` | `review-archive/` | 归档子目录 |
| `wholeImageJudgeEnabled` | `0` | 整图判定快捷按钮，默认关（判定以逐条为准） |

---

## 6. 使用指南

### 6.1 管理页（上端）

地址 `<域名>/plugins/handwriting-lexicon/admin.php`，两大功能区：

- **词区区**：按类型/启用状态筛选、搜索、新增/编辑弹窗、JSON/procAlias 导入导出、测试纠错区（看 `original/corrected/action/confidence/reason`）。
- **审查 Tab（v3）**：
  1. 左侧日志列表按日期/引擎筛选，卡片带状态徽标（未审/正确/错误/审查完成）；
  2. 右侧图片 + 字段对照（含所有明细的图号/工序/数量）；
  3. 每条纠错行有「正确/错误」判定：**正确不填、错误填正确值**；
  4. 全部判完 → 自动「审查完成」；
  5. AI 整理（仅判错行生成候选，人工勾选确认）或人工直接导入 → 入库 → bridge 即时生效。

移动端已适配（表格转卡片、16px 输入、PWA 添加到主屏幕）。

### 6.2 四类词条映射

| type | 用途 | 错误值 → variants，正确值 → standard |
|---|---|---|
| `drawing_alias` | 图号别名 | variants=`2L59711305`，standard=`24971b05` |
| `process_synonym` | 工序同义 | variants=`线,绕,銑`，standard=`铣` |
| `unit_synonym` | 单位同义 | variants=`个,仟`，standard=`件` |
| `char_confusion` | 字符形近 | variants=`O,o,l`，standard=`0,1` |

复用 `bglx_build_entry` / `bglx_validate_entry` 校验；冲突检测、存量幂等合并。

### 6.3 公共库 API 与 bridge（下端，供 OCR 插件调用）

```php
require_once __DIR__ . '/../handwriting-lexicon/lib/bridge.php';
bglx_bridge_init('my-ocr');                    // 初始化（含熔断降级）
$r = bglx_bridge_norm_fields($fields);         // 批量归一图号/工序/单位
$w = bglx_bridge_suggestions();                // suggest 提示并入 warnings
// 或直接：bglx_correct_partno / bglx_norm_process / bglx_norm_unit / bglx_correct_text
```

**红线**：词库缺失/异常时静默降级、行为与未接入一致，**不阻断识别**。返回对象统一字段 `original/corrected/type/confidence/action(auto|suggest|none)/matched_id/strategy/candidates/reason`。

### 6.4 HTTP 接口（api.php）

- 词库：`stats/list/query/test/create/get/update/set_enabled/delete/import/export`
- 审查：`review_list_logs / review_link / review_judge_row / review_save_corrections / review_ai_organize / review_import` 等
- 统一 JSON 信封；写操作强制 **POST + CSRF + token**；并发冲突返回 `40901`；`handler.php` 直访返回 403。

---

## 7. 与 OCR 插件打通

所有 OCR 插件（百度/腾讯/Qwen3.5/MiMo/mock + tencent-vision 双引擎）统一走 `bglx_bridge_*` 连接层，不再内嵌字库胶水；本插件同时声明 `lexicon.review` 能力。闭环：

```
OCR 识别 → bridge 查词库 → auto 替换 / suggest 入 warnings
        → 审查台看图+日志逐条判定 → 错误行录正确值
        → AI 整理(候选 pending) 或人工导入 → 词库更新 → bridge 下次识别即时生效
```

---

## 8. 验证状态与证据

| 项目 | 结果 |
|---|---|
| 测试断言总数 | **197/197 通过**（既有套件 174 + QA 独立对抗 23），失败 0 |
| 智能路由判定 | **NoOne**（全绿，无需回派） |
| 阻断遗留问题 | **0** |
| 作者 7 修复 | 零回退（保护文件 SHA 与 fixed 基线逐字一致） |
| 静态校验 | `php -l` 全通过；plugin.json 合法（34 settings）；`review.js`/`admin.js` `node --check` 通过 |
| v3 专项 | 逐条判定、状态派生、四类全覆盖、乐观锁 40901、90 天归档先写后移、存量迁移、AI 仅 wrong、日志只读，全部 ✅ |
| `check.php` | `ok=true`，11 项全绿 |

> 验证边界（非缺陷）：沙箱无真实 OCR 密钥与主程序源码，未做端到端真实单据识别与主程序菜单集成；空 `data/` 下 check.php 报附件/日志目录不可读为信息性提示，部署 data 后即正常。

一键复现：`php public/plugins/handwriting-lexicon/tests/review_v3.test.php`。逐项见 `TEST-REPORT.md`。

---

## 9. 已知限制与待明确事项

| # | 事项 | 状态/建议 |
|---|---|---|
| 1 | 形近字按「记录级 note」单行候选（Q2 方案 A） | P2 逐位拆对方案未实现，符合 PRD §8.3 |
| 2 | 归档记录在线查询入口 | P2 未实现；归档文件可直接按 `review-YYYY-MM.json` 查阅 |
| 3 | `imported` 终态改判/重新审查 | P2 本次只读 |
| 4 | 主程序设置页无跳转按钮 | 主程序仅支持 7 类设置项，无法插 link/button；已用 PWA「添加到主屏幕」+ 复制地址替代 |
| 5 | 图片-日志自动关联歧义 | 四级策略 `invokeId > 文件名 > mtime > 人工`；歧义时强制人工绑定 |
| 6 | 归档图片链接 | 超期原图可能已被日志/附件策略清理，归档用于审计非看图；保留期需与 `ocr-log keepDays` 协同规划 |

---

## 10. 故障排查速查

| 现象 | 排查 |
|---|---|
| 审查 Tab 打不开 | 确认 `reviewEnabled=1`；缓存版本已递增后仍异常则 `Ctrl+F5` 强刷；检查 `admin.js`/`review.js` 是否 404 |
| 列表徽标不更新 | 顶层字段由服务端派生，逐条判定后刷新列表；确认未回退 F4 稳定键算法 |
| 明细只显示部分 | v3 已全覆盖所有明细；若仍缺，检查 `log_reader.php` 的 `no/proc/qty` 映射（F2 保护） |
| 判定后没转「审查完成」 | 确认所有行都已判定；有 `unreviewed` 行则保持 `draft` |
| 归档没生效 | 检查 `reviewRetentionDays>0`、`reviewArchiveEnabled=1`、`data/review-archive/` 可写 |
| 纠错不生效 | 词条 `enabled` 开启？置信度是否低于阈值？测试区看 `reason`；确认 bridge 已 init |
| 词库写入失败 | 检查 `data/` 可写、`flock` 可用、`lexiconFile` 路径 |
| 自检失败 | 按 check.php 的 11 项逐项定位 |

---

## 11. 交付方介绍：主理人、团队与所用技能

### 11.1 主理人自我介绍

我是**齐活林（Qi）· 交付总监（Delivery Director）**，本项目软件开发团队的主理人。职责不是亲自写代码，而是**协调整个软件开发工作流**：判断需求规模、选择工作流、把任务分派给合适的成员、在中转中传递上下文、汇编最终交付。团队遵循 SOP（标准作业程序）理念，信奉 **代码 = SOP(团队)**：用标准化协作保证交付质量可预期、可追溯。

本次我负责：把作者 fixed 版锁定为不可回退基线、拍板四项改造决策（逐条判定 / 四类全覆盖 / 90 天归档 / fixed 基线增量）、各阶段产物中转与质量关卡、最终汇编交付。

### 11.2 团队介绍（SOP 标准工作流）

本项目走**标准 SOP**：`产品经理(PRD) → 架构师(设计+任务分解) → 工程师(实现) → QA(测试)`，顺序流转，每步产物作为下一步输入，跨成员信息流经主理人中转。

| 角色 | 姓名 | 本次职责与产物 |
|---|---|---|
| 主理人 / 交付总监 | 齐活林 Qi | 编排、基线锁定、决策、质量关卡、汇编交付 |
| 产品经理 | 许清楚 Xu | `PRD.md`：v3 增量需求、作者修复保护清单、状态机、四类映射 |
| 架构师 | 高见远 Gao | `ARCHITECTURE.md`：897 行 / 6 张 Mermaid 图、逐条判定数据模型、派生表、归档算法、保护清单 |
| 工程师 | 寇豆码 Kou | v3 全部代码（逐条判定服务、派生、推断、归档、前端），作者 7 修复零回退 |
| QA 工程师 | 严过关 Yan | `TEST-REPORT.md`：独立验证 197/197、作者修复 SHA 逐字比对、路由 NoOne |

### 11.3 所用技能与协作机制

| 技能/机制 | 作用 | 本项目中的使用 |
|---|---|---|
| **token-saver-qclaw** | Token 智能省流，压缩输入与上下文、节省推理开销 | 用户以 `@skill` 点名；贯穿本次迭代 |
| **SOP 多智能体团队协作** | 主理人 + PM/架构师/工程师/QA 角色化协作，含顺序流转、信息中转、质量关卡、反馈回路 | 驱动四阶段；把作者 fixed 版列为保护基线逐项回归 |
| **开工前伪启动闸门** | 实质性开工前逐项核对，防止未准备好就动手 | 每次写文件/打包前的起飞检查 |
| **交付前伪阻断闸门** | 交付前扫描责任转嫁、半途交付、结论完整 | 交付前的最终拦截 |
| **联网检索与工程工具** | 技术查证、脚本执行、语法/JSON/JS 校验、打包 | 查证混元 OpenAI 兼容接口；`php -l`、`node --check`、zip 打包 |

### 11.4 开源借鉴

延续前期的保守纠错理念（借鉴 ocr-stringdist 加权混淆、ALFADEL 词库驱动 + 人工审核、pycorrector 中文形近字方向），v3 在判定层借鉴"单一事实源 + 派生投影"与乐观锁并发控制思想；均为理念借鉴，纯 PHP 实现、无第三方依赖。

### 11.5 最终建议（接手路线）

先配 `adminToken` → 打开 `reviewEnabled` 并确认目录默认值 → 导入种子词库 → 管理页逐条判定跑通一轮 → 配 AI（可选）整理候选 → 观察 `data/review-archive/` 归档与 `reviewRetentionDays` 是否符合数据政策 → 拿到主程序源码后校准路径与配置结构。任何后续改动**先跑 `tests/review_v3.test.php` 与作者 7 修复保护断言**，确保零回退。
