# state 接口服务端分页改造方案（v2.2）

## 一、背景

压测数据量：1034 个零件（1001 张图片）、3002 张订单、3003 张报工单（6061 条明细）。

当前启动流程为 `boot()` → `refreshState()`（`public/app.js:369`）→ `GET api.php?action=state`，服务端 `bg_db_load()` 一次性 `SELECT *` 读取全部表（`public/api.php:698`），接口返回 3.25MB / 约 300ms，前端把三份完整数组放进全局 `state`，所有搜索、日期筛选、统计、翻页全部在浏览器内完成。

数据继续增长时，首屏加载、每次保存后的 `refreshState()` 都会线性变慢；1 万张报工单时单次响应会接近 10MB。

目标：

- 首屏只取元信息 + 当前页数据，启动响应 ≤ 10KB；
- 列表每页响应 ≤ 80KB、P95 ≤ 200ms（当前 5 千条规模下 ≤ 100ms）；
- 搜索、日期范围、统计合计全部由 SQL 完成；
- 功能、交互、导出结果与 v2.1 保持一致；
- 不改表结构，只补索引，不做数据迁移。

## 二、总体思路

采用「bootstrap + 按需分页」两层结构：

1. `bootstrap`：只返回登录状态、图片地址、各表总数、最早日期、版本号；
2. 列表页各自调用分页接口，参数化搜索/日期/排序，返回 `items + total + summary`；
3. 表单、详情、OCR 手动填充改为按需查询（搜索型下拉、按 id 取详情）；
4. `state` 接口在 v2.2 保留但前端不再调用，作为回退兼容；v2.3 再移除；
5. 写接口（create/update/delete/restore/export）继续沿用现有 `bg_db_load/bg_db_save`，本次不改写路径。

## 三、接口设计

统一参数：`page`（从 1 开始）、`pageSize`（默认 50，上限 200）、`q`（关键词，服务端 `LIKE`）。
统一响应包：

```json
{ "ok": true, "items": [], "total": 0, "page": 1, "pageSize": 50, "hasMore": false }
```

列表类接口额外返回 `summary`（按当前筛选条件、不分页的合计）。

| action | 参数 | 返回要点 | 替代的现有逻辑 |
| --- | --- | --- | --- |
| `bootstrap` | - | `auth`、`imageBase`、`counts{parts,orders,reports,items}`、`firstDate`、`version` | `state` 的启动职责 |
| `parts.list` | `q`、`page`、`pageSize`、`sort=updated` | 零件字段 + `usage{qty,times}` | 零件列表 `state.parts`（app.js:812-884） |
| `parts.search` | `q`、`limit=20` | 精简零件（含 `processes`，用于带出工序单价） | 搜索型下拉 `setupCombo`（app.js:591） |
| `parts.get` | `id` | 零件详情 + 使用量 | 零件编辑/详情（app.js:909、2862） |
| `parts.match` | `no`、`name` | 按 v1.7 归一化/相似度算法返回候选零件 | OCR 手动填充的本地匹配（app.js:1950-2002） |
| `orders.list` | `from`、`to`、`q`、`page`、`pageSize` | 订单字段 + `usage{reportedQty,reportedAmount,remaining}` | 订单列表（app.js:1217-1305） |
| `orders.search` | `partId`、`process`、`q`、`limit=50`、`excludeReportId` | 订单 + 已报/剩余（排除当前编辑单） | 报工表单订单下拉 `matchingOrders`（app.js:325、1628） |
| `orders.get` | `id` | 订单详情 + 使用量 | 订单编辑/详情（app.js:1325、2905） |
| `reports.list` | `from`、`to`、`q`、`page`、`pageSize` | 报工单（含明细）+ `summary{count,qty,amount}` | 报工列表（app.js:1436-1588） |
| `reports.get` | `id` | 单张报工单 + 明细 | 报工编辑/详情（app.js:1687、2885） |
| `stats.list` | `from`、`to`、`q`、`orderIds`、`partIds`、`page`、`pageSize` | 明细行（含订单数量/报工人）+ `summary{rows,qty,amount}` | 报工统计（app.js:2606-2757） |
| `stats.overview` | - | 各表总数、明细数、最早日期、按年汇总 | 数据量统计（app.js:2759-2798） |
| `export.parts` | `q` | 零件 CSV（全量流式输出） | 前端 `exportParts()`（app.js:1111） |
| `export.stats` | 同 `stats.list` | 统计 CSV | 统计页导出 |
| `state` | - | 保持原样，标记 deprecated | 回退兼容 |

筛选语义沿用 v2.1，保证结果一致：

- 零件搜索：`no / name / material` 包含关键词；排序 `updated_at DESC`。
- 订单搜索：`no / part_no / part_name / process / note`；排序 `date DESC, created_at DESC`。
- 报工搜索：`no / worker / order_no / 明细的 part_no、part_name、order_no`；排序 `date DESC, created_at DESC`。
- 统计搜索：`no / worker / order_no / 明细 order_no / part_no / part_name / process`；排序 `date DESC, 报工单号 DESC`。
- 日期均为 `YYYY-MM-DD` 字符串，可直接做 `>= / <=` 比较。

## 四、后端实现（public/api.php）

### 1. 索引（启动时 `CREATE INDEX IF NOT EXISTS`，无需迁移）

```sql
CREATE INDEX IF NOT EXISTS idx_orders_part      ON orders(part_id, date);
CREATE INDEX IF NOT EXISTS idx_parts_updated    ON parts(updated_at DESC);
CREATE INDEX IF NOT EXISTS idx_reports_created  ON reports(date DESC, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_items_part_order ON report_items(part_id, order_id);
```

现有索引保留：`idx_reports_date`、`idx_orders_date`、`idx_items_report`、`idx_items_order`、`idx_items_part`。
首次升级后执行一次 `ANALYZE` / `PRAGMA optimize`。

### 2. 公共辅助函数

- `bg_page()`：解析并夹紧 `page/pageSize`；
- `bg_like($q)`：生成 `%q%`，统一 ASCII 小写处理；
- `bg_date_where($from, $to, $alias)`：复用日期范围条件；
- `bg_report_where($q)`：报工搜索条件，`reports.list` 与 `export.csv` 共用，避免两套逻辑；
- `bg_stats_where($q, $orderIds, $partIds)`：统计条件，列表与导出共用；
- `bg_parts_usage($pdo, $ids)`：按当页 id 聚合 `report_items.part_id → SUM(qty), COUNT(*)`；
- `bg_orders_usage($pdo, $ids)`：按当页 id 聚合已报数量/金额，需兼容历史数据：
  `COALESCE(NULLIF(ri.order_id,''), r.order_id)` 作为归属订单。

### 3. 典型 SQL

零件列表（搜索 + 分页）：

```sql
SELECT p.* FROM parts p
WHERE (:q = '' OR p.no LIKE :like OR p.name LIKE :like OR p.material LIKE :like)
ORDER BY p.updated_at DESC, p.id DESC
LIMIT :limit OFFSET :offset;
```

报工列表：先按 `reports` 条件分页取当页单据，再用 `report_items WHERE report_id IN (...)` 一次取明细，避免 JOIN 放大导致分页错位；合计单独聚合：

```sql
SELECT COUNT(*) AS cnt, COALESCE(SUM(ri.qty),0) AS qty, COALESCE(SUM(ri.amount),0) AS amount
FROM reports r LEFT JOIN report_items ri ON ri.report_id = r.id
WHERE <与列表相同的条件>;
```

含明细关键词时用 `EXISTS` 子查询，避免同一张报工单重复计数。

统计明细：`reports JOIN report_items`，条件与 `bg_stats_where` 一致，`ORDER BY r.date DESC, r.no DESC` 分页；`summary` 用同一 `WHERE` 聚合。

### 4. 兼容与边界

- 空 `q` 不拼 `LIKE`；`pageSize` 上限 200；
- 深分页使用 `OFFSET`，5 万条以内可接受；超过后再改 keyset（`date+id` 游标），本期不做；
- `LIKE '%q%'` 无法走索引，当前 3 千～1 万条实测可接受；数据到 10 万级再评估 FTS5；
- 读接口不再调用 `bg_db_load()`，不构建全量 `BG_SNAP`；
- `restore`、`export.json`、写入路径保持现状。

## 五、前端改造（public/app.js）

### 1. 状态结构

- 删除全局全量数组，改为：
  - `meta = { counts, firstDate, imageBase, auth }`；
  - 各页面局部 `store = { items, total, page, q, range, loading, hasMore }`；
- `refreshState()` 改为 `bootstrap()`（只取元信息）与 `reloadCurrentView()`；
- 保存/删除成功后：刷新 `meta`，并按当前 hash 重新加载当页；同一路由下显式触发一次 view loader。

### 2. 列表页

- 零件页（app.js:812）：搜索防抖 300ms → `parts.list?q=&page=1`；「加载更多」→ `page+1` 追加；`usage` 用服务端返回值，删除 `usageCache`（app.js:763 一带）；
- 订单页（app.js:1217）：日期范围换算 `from/to` 后请求；进度条使用服务端 `usage`，删除本地 `orderUsageCache`（app.js:279-323）；
- 报工页（app.js:1436）：日期范围 + 关键词服务端过滤；顶部三张统计卡直接使用 `summary`；
- 统计页（app.js:2606）：`collect()` 删除，改为 `stats.list`；筛选下拉改为远程搜索（见下）；
- 数据量统计页（app.js:2759）：改用 `bootstrap` + `stats.overview`。

### 3. 表单与选择器

- 零件下拉 `setupCombo()`（app.js:591）：输入时防抖调用 `parts.search`，返回含 `processes/price/image`，选中后带出工序与单价；
- 报工表单订单下拉（app.js:1628）：选中零件/工序后调用 `orders.search?partId=&process=`，选项显示服务端剩余量；编辑时传入 `excludeReportId`，避免把自己已报数量算进去；
- 新建订单、快速建单（app.js:2461）：同样使用远程零件搜索；
- 编辑页：`viewPartForm` / `viewOrderForm` / `viewReportForm` 先按 id 取详情，不再从全量数组查找；
- OCR 手动填充（app.js:1950-2002）：保留现有归一化与模糊匹配规则，改调 `parts.match`，由服务端返回候选后仍可手选；
- 统计页订单/零件多选筛选（app.js:2614-2638）：改为输入即搜索的远程多选，已选项以标签保留，不再一次性渲染 3000 个订单选项。

### 4. 详情与导出

- 零件/订单/报工详情页（app.js:2862、2905、2885）：按 id 请求，附带使用量；
- 零件 CSV 导出改为服务端 `export.parts`；
- 统计 CSV 导出改为服务端 `export.stats`（与列表同条件）；
- 报工 CSV 继续走 `export.csv`，内部复用新的筛选条件函数。

### 5. 资源版本

- `index.html` 的 `style.css?v=` / `app.js?v=` 升级为 `2.2`，避免浏览器缓存旧脚本继续调用 `state`。

## 六、风险与对策

| 风险 | 对策 |
| --- | --- |
| 旧缓存前端仍调用 `state` | 保留 `state` 一个版本；静态资源加 `?v=2.2` |
| 表单需要全量零件/订单 | 搜索型下拉按需查询；编辑时按 id 回填已选项 |
| 统计多选筛选需要大量选项 | 远程搜索 + 已选标签，不预载全量 |
| OCR 模糊匹配依赖全量零件 | 新增 `parts.match`，复用 v1.7 归一化算法 |
| 保存后当前页不刷新 | 统一 `reloadCurrentView()`；同路由也强制重载 |
| 订单已报量口径（旧数据 `report_items.order_id` 为空） | 聚合时 `COALESCE(NULLIF(ri.order_id,''), r.order_id)` |
| LIKE 全表扫描 | 当前规模可接受；预留 FTS5 方案，不在本期 |
| 写路径仍是全量读写 | 本期不动；后续可单独优化报工单号生成与定向 INSERT |

## 七、实施步骤与归档

1. 归档当前版本到 `报工系统-历史版本/baogong-v2.1-<日期>.zip`；
2. `api.php`：补索引、公共筛选/分页函数、新增只读接口（保留 `state`）；
3. `app.js`：bootstrap、三个列表页、统计页、数据量统计页改为分页；
4. `app.js`：表单选择器、详情页、OCR 手动填充、导出切换；
5. 本地预览（`报工系统/预览`）用 NAS 数据库快照验证，再部署到 `\\192.168.123.225\web\baogong`；
6. 部署前在页面创建快照，部署后核对数量与金额；
7. 更新 `README.md` 版本记录为 v2.2 与 `index.html` 资源版本号。

## 八、验收清单

接口层：

- [ ] 启动仅请求 `bootstrap`，响应 ≤ 10KB，无 `state` 调用；
- [ ] `parts.list` 100 条 ≤ 60KB；`reports.list` 50 条 ≤ 80KB；`stats.list` 100 行 ≤ 50KB；
- [ ] 各接口 P95 ≤ 200ms（当前数据规模下目标 ≤ 100ms）；
- [ ] 分页 `page/pageSize` 边界、空结果、特殊字符均正常。

功能层：

- [ ] 零件列表：搜索、加载更多、图片、库存修改、已报数量；
- [ ] 订单列表：日期范围、搜索、进度/剩余、编辑删除；
- [ ] 报工列表：日期范围、搜索、分页、统计卡、编辑删除；
- [ ] 统计页：日期、订单/零件多选、关键词、合计、CSV；
- [ ] 报工表单：零件搜索、工序带价、订单剩余、自动建单、编辑回填；
- [ ] OCR 手动填充的图号模糊匹配与 v1.7 一致；
- [ ] 数据量统计页数字与升级前一致；
- [ ] 备份快照、JSON 导出/恢复、插件设置不受影响。

数据核对（当前压测库）：

- [ ] 零件 1034、订单 3002、报工单 3003、明细 6061；
- [ ] 2026-11 报工统计：526 张 / 数量 86,814 / 金额 ¥357,661.25（以升级前页面显示为准）。

回退：

- [ ] 恢复 v2.1 压缩包 + 部署前快照 `db-*.sqlite` 可完整回退。
