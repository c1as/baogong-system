# 手写字词库插件修正交接文档

> 修正对象：`handwriting-lexicon` 1.1.0（齐活林交付版）
> 修正日期：2026-11-24
> 交付内容：本交接文档 + `handwriting-lexicon-fixed.zip`（修正后的完整插件）
> 验证环境：线上群晖 `http://192.168.123.225:2095`（PHP 8.2）+ 本地 PHP 8.5

---

## 1. 交接摘要

本次针对新插件套件上线后暴露的 7 个问题做了修正，集中在字库插件的审查工作台与主程序接入点。

| # | 问题 | 影响 | 修正文件 |
|---|---|---|---|
| 1 | 审查工作台标签点击无反应 | 功能不可用 | `assets/admin.js` |
| 2 | 日志有识别信息但界面不显示图号/工序/数量/日期 | 无法审查 | `lib/log_reader.php` |
| 3 | 出现「未知引擎 / 无图号」空记录 | 列表噪声 | `lib/log_reader.php` |
| 4 | 判定报「缺少 item_id 或 invoke_id」 | 判定/纠错不可用 | `api.php`、`assets/review.js` |
| 5 | 附件目录、日志目录默认值解析错误 | 审查读不到图片/日志 | `plugin.json`、`docs/review-workflow.md` |
| 6 | 修改后脚本被浏览器缓存 | 修复不生效 | `admin.php`（版本参数） |
| 7 | 主程序缺少字库管理入口；下拉选项不兼容 | 入口难找、设置页下拉为空 | 主程序 `app.js` / `plugin-settings.js` / `layout.js` / `api.php` / `lib/plugin.php` |

> 第 1–6 项已包含在 `handwriting-lexicon-fixed.zip`；第 7 项属于主程序改动，不在插件包内，按第 7 节说明同步。

---

## 2. 逐项说明

### 2.1 审查工作台打不开

**现象**：管理页能打开，点击「审查工作台」标签没有任何反应。

**根因**：`assets/admin.js` 中没有 Tab 切换逻辑；`assets/review.js` 监听 `bglx:tab` 事件做延迟初始化，但没有任何代码派发该事件。

**修复**：在 `admin.js` 末尾新增 `initTabs()`：

- 点击 `.bglx-tab` 时，按 `data-tab` 切换 `.is-active`；
- 按 `data-panel` 切换 `.bglx-tabpanel` 的 `hidden`；
- 派发 `CustomEvent('bglx:tab', {detail: name})`，触发 `review.js` 初始化；
- 支持 `admin.php#review` 直接打开审查页。

**验证**：本地浏览器点击后，统计区、筛选区、日志空态正常出现，控制台无报错。

---

### 2.2 识别日志有数据但界面没有明细

**现象**：审查列表只显示日志行，图号显示「（无图号）」，右侧没有识别明细、日期为空。

**根因**：主程序日志与插件字段命名不一致：

| 日志字段 | 审查前端读取 |
|---|---|
| `items[].no` | `items[].partNo` |
| `items[].proc` | `items[].process` |
| `items[].qty` | `items[].qty`（一致） |
| 行级 `date/worker/workshop/note` | `fields.date/...` |

**修复**：`lib/log_reader.php` 的 `bglx_rev_project_row()` 中增加兼容映射：

- `partNo` 缺失时依次取 `no / drawingNo / part_no / drawing`；
- `process` 缺失时依次取 `proc / procedure / processName`；
- `qty` 缺失时依次取 `quantity / count / num`；
- `date/worker/workshop/note` 从日志行平铺字段回填到 `fields`；
- 返回行的 `date` 不再固定为空，优先取行级 `date`，其次 `fields.date`。

**验证**：线上 `2026-11-24 18:26` 融合结果已正常显示：

```
TD90535R184 / 铣光2 / 204
TD90523R301 / 铣光1 / 404
TD90523R301 / 铣光2 / 404
```

---

### 2.3 出现「未知引擎」

**现象**：审查列表出现「未知引擎 / 无图号 / 无 invokeId」的空记录。

**根因**：日志文件混有三类 `kind`：

- `engine`：单引擎识别结果；
- `ocr`：融合识别结果；
- `match`：识别后零件库匹配的辅助记录，无 `engine`、无 `items`。

工作台把三类都当成识别记录展示。

**修复**：`bglx_rev_log_matches()` 增加过滤：

- 有 `kind` 时仅保留 `engine` / `ocr` / `fusion`；
- 无 `kind` 时，若既无 `engine` 也无 `items`，跳过。

**验证**：线上当天日志从 16 条降为 7 条，空记录消失，分页总数同步正确。

---

### 2.4 判定失败：缺少 item_id 或 invoke_id

**现象**：点击「正确 / 错误」提示 `判定失败：缺少 item_id 或 invoke_id 参数`。

**根因**：

1. 主程序日志没有 `invokeId`；
2. 审查记录以 `invokeId` 为唯一键，日志行也没有已存在的 `review_id`；
3. 前端把空的 `invoke_id` / `item_id` 传给后端，被参数校验拒绝。

**修复**：

- `api.php` 的 `review_list_logs` 在**图片关联之后**，为没有 `invoke_id` 的日志生成稳定键：
  `log-` + `sha1(date | ts | engine | fields | items)` 前 20 位；
- 同一行日志每次读取生成相同键，判定、纠错可持久化并在刷新后回显；
- 生成放在关联之后，避免合成键干扰原有的图片文件名/时间关联；
- `review.js` 新增 `invokeLabel()`，`log-` 前缀统一显示为「日志记录」，不显示哈希；
- 列表回显 `judgment`：此前保存成功但刷新后又显示「未审」，本次一并修正。

**验证**：线上浏览器实测点击「正确」后提示保存成功、卡片徽标变为「正确」、统计计数同步；API 复核 `judgment=correct`、`review_id=RV-000001`。验证产生的测试记录已清理。

---

### 2.5 附件目录 / 日志目录默认值错误

**现象**：审查工作台读不到图片和日志，自检提示日志目录不可读。

**根因**：插件内相对路径统一按 **data 目录**解析，但默认值写成了 `data/ocr-logs/`、`data/uploads/`，实际会解析为 `data/data/ocr-logs/` 等不存在的路径。

**修复**（`plugin.json` + `docs/review-workflow.md`）：

| 设置项 | 原默认 | 新默认 | 实际路径 |
|---|---|---|---|
| `attachmentDirs` | 4 行 `data/...` | `images/attachments/` | `data/images/attachments/` |
| `logDir` | `data/ocr-logs/` | `ocr-logs/` | `data/ocr-logs/` |

线上同时保存了对应配置值，并开启 `reviewEnabled=1`。该配置属于运行数据，不在插件 zip 内。

**验证**：`review_settings` 返回附件目录、日志目录均可读，告警 0；扫描到 2 张识别图片、5 条 2026-11-22 日志。

---

### 2.6 浏览器缓存导致修复不生效

`admin.php` 的脚本引用增加版本参数：

```html
<script src="assets/admin.js?v=1.1.0"></script>
<script src="assets/review.js?v=1.1.1"></script>
```

主程序线上 `index.html` 的 `app.js` 同步升到 `?v=2.7.1`。

---

## 3. 部署步骤（仅插件）

1. 解压 `handwriting-lexicon-fixed.zip`，将 `public/` 合并到报工系统项目根目录，覆盖 `public/plugins/handwriting-lexicon/`。
2. 确认 `data/`、`data/images/attachments/`、`data/ocr-logs/` 对 Web 用户可写可读。
3. 进入「识别插件设置 → 手写字词库管理」确认：
   - `attachmentDirs` = `images/attachments/`
   - `logDir` = `ocr-logs/`
   - `reviewEnabled` = 开
   - `adminToken` = 强令牌（不要使用默认弱口令）
4. 打开管理页后按 `Ctrl+F5` 强制刷新。
5. 运行自检：

```bash
php public/plugins/handwriting-lexicon/check.php
```

期望 `ok=true`，数据目录、词库、锁、审查、日志目录、附件目录均正常。

---

## 4. 主程序接入改动（不在插件 zip 内）

线上主程序是单文件 `public/app.js`（v2.7），本地仓库是模块化前端（v2.8），两处都已同步同样的逻辑：

### 4.1 设置页入口按钮

文件：`public/js/views/plugin-settings.js`（线上 `public/app.js` 的 `pluginCardHtml`）

```js
const isLexicon = caps.includes('lexicon.manage');
const adminField = (p.settings || []).find((f) => f.key === 'adminUrl');
const adminHref = adminField && adminField.value
  ? String(adminField.value)
  : '/plugins/handwriting-lexicon/admin.php';
```

在插件卡片操作区渲染：

```html
<a class="btn btn-ghost" href="{adminHref}" target="_blank" rel="noopener">打开手写字词库管理</a>
```

### 4.2 齿轮菜单入口

文件：`public/js/shell/layout.js`（线上 `public/app.js` 的设置菜单）

```html
<a href="/plugins/handwriting-lexicon/admin.php" target="_blank" rel="noopener">手写字词库管理</a>
```

### 4.3 下拉选项字符串兼容

文件：本地 `public/lib/plugin.php`、线上 `public/api.php`

新插件的 `select` 选项是字符串数组（如 `["auto","suggest","none","exact","fuzzy"]`），旧主程序只认对象数组。已增加 `is_scalar($o)` 分支：

```php
if (is_scalar($o)) {
    $o = (string)$o;
    if ($o !== '') {
        $opts[] = [
            'value' => bg_plugin_cut($o, 100),
            'label' => bg_plugin_cut($o, 40),
        ];
    }
    continue;
}
```

> 若不应用此项，字库设置页的「链接策略」「重试策略」等下拉框会为空。

---

## 5. 变更文件清单与校验值

以下 SHA-256 对应插件包内文件：

| 文件 | 大小 | SHA-256 |
|---|---|---|
| `plugin.json` | 9,374 | `C1F8A742C1EE435B8E33F1BFFA6F89B070D8724F3F677D39E7DC48635B73338D` |
| `admin.php` | 16,982 | `71A127EEA6ABEE56C9BE8797EADDD34ACB635191266658B7AE8716771E174E8B` |
| `api.php` | 23,883 | `20F0DC425BD39183896956E8C900BCAD362E85D92ACF1C591EF7A9AF1A71D92A` |
| `assets/admin.js` | 18,904 | `6E0ED5B59714763C0FD8B029A75ED1FF3579D4131087EE6E33A6DA8D8F843716` |
| `assets/review.js` | 25,662 | `91FE26F5223E0F825E27F254AF37401D3FAB438B1F913CE1534190B4AB804AC0` |
| `lib/log_reader.php` | 15,575 | `B5D5931014A767C5B08CDE6EC2D72F88BE6D71CF2645F0F619F52E5EC6C2232A` |
| `docs/review-workflow.md` | 13,187 | `E901E9E92EFB19477CCEC534DA016F40F9D514EF4AA13E7CEE7BDB17A9ACA16A` |

完整插件共 41 个文件（含 tests、fixtures、lib、assets、docs），zip 顶层结构：

```
HANDOVER-手写字词库修正.md
public/plugins/handwriting-lexicon/...
```

---

## 6. 验证记录

| 项目 | 结果 |
|---|---|
| PHP 语法 | 插件全部 `.php` 通过 `php -l` |
| JSON | `plugin.json` 合法 |
| 本地浏览器 | 审查 Tab 打开、日志列表/详情、判定保存均通过，控制台无报错 |
| 线上浏览器 | 判定「正确」成功，徽标与统计同步更新 |
| 线上接口 | `review_list_logs` 返回 7 条识别记录，无「未知引擎」；日期、图号、工序、数量正常 |
| 附件/日志 | `review_settings` 两项均 `true`，告警 0 |
| 图片关联 | 2026-11-24 18:26 记录关联 `20261124-001.jpg` |

---

## 7. 已知限制与遗留

1. **日志无 invokeId**：当前用日志内容哈希生成稳定键。若日志行被外部改写，键会变化；正常追加写入不受影响。
2. **旧批次无图**：2026-11-22 及更早的部分记录原图已删除，只能审查文字，无法看原图。
3. **管理令牌**：线上当前为弱令牌，建议在插件设置中更换为强随机值。
4. **数据文件不随包**：`data/handwriting_lexicon.json`、`data/handwriting_review.json` 属于运行数据，未打包，避免覆盖现场评审记录。
5. **目录权限**：`data/ocr-logs/`、`data/images/attachments/` 需 Web 用户可读，否则工作台仍会提示目录不可读。

---

## 8. 回滚方式

- 插件回滚：用原始交付包 `ocr-fusion-plugin.zip` 中的 `public/plugins/handwriting-lexicon/` 覆盖本修正版；撤销主程序三处入口/兼容改动。
- 现场数据无需回滚；审查记录独立存放于 `data/handwriting_review.json`。
- 本次上线前的旧插件备份：`C:\Users\ccccc\AppData\Local\Temp\baogong-ocr-backup-20261124-175728.zip`（含旧版全部插件与配置）。
