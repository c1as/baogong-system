# 报工管理系统

面向机加工车间的轻量化计件报工系统：管理零件与工序单价、订单、报工记录，自动核算金额，并提供统计、导出和数据备份。后端为 PHP + SQLite，前端为原生 HTML / JS / CSS，单文件部署，适合放在群晖 Web Station 等 PHP 环境中内网使用。

> 当前仓库为 **SQLite 版本**（数据存储在 `data/baogong.sqlite`）。早期的 JSON 文件存储版本保留在 git 历史中（提交 `3aca7bf`），可随时回退。

## 功能特性

- **零件管理**：每个零件可设置多道工序与单价，支持图号查重、库存数量、批量 CSV 导入。
- **订单管理**：订单号自动生成（`PO+日期-序号`），按图号 + 工序汇总，显示已报 / 总数量 / 剩余。
- **报工录入**：选零件自动带出工序和单价，金额自动计算；一条报工单可含多个明细；订单可留空，保存时自动建单。
- **报工统计**：按日期范围、订单（多选）、零件筛选，列表同时展示订单数量与实际报工数量。
- **附件**：报工单可上传多张照片，删除报工/附件时自动清理磁盘文件。
- **数据量统计**：展示条数、数据库 / 图片 / 备份占用与年度分布，超过 8MB 提示归档。
- **导入导出**：CSV 明细导出（带合计，支持月份筛选）、JSON 全量备份与恢复。
- **访问控制**：`app.env` 设置单一访问密码，登录失败有节流。
- **移动端适配**：手机底栏导航，页面自适应窄屏。

## 技术栈

| 层 | 选型 |
| --- | --- |
| 前端 | 原生 HTML / JavaScript / CSS，无构建依赖 |
| 后端 | PHP 8.x（单文件 `api.php`） |
| 存储 | SQLite（PDO `pdo_sqlite`，WAL 模式） |
| 部署 | 群晖 Web Station / 任意支持 PHP 的 Web 服务器 |

## 快速开始

### 本地运行

需要 PHP 8.x 并启用 `pdo_sqlite` / `sqlite3` 扩展：

```powershell
php -S 127.0.0.1:8080 -t public
```

然后浏览器打开 http://127.0.0.1:8080。首次访问会自动创建 `data/baogong.sqlite`；若 `data/` 下有旧的 `db.json`，会自动导入并改名为 `db.json.imported` 留档。

访问密码在 `app.env` 中配置（复制 `app.env.example` 为 `app.env`，修改 `APP_PASSWORD`；留空表示不登录）。

### 部署到群晖

完整步骤（Web Station 站点创建、SQLite 扩展启用、数据目录权限、从旧 JSON 版迁移等）见 [部署说明.md](部署说明.md)。

## 目录结构

```
报工系统/
├── public/            网站根目录（Web 服务器文档根指向这里）
│   ├── index.html
│   ├── app.js
│   ├── style.css
│   ├── favicon.svg
│   └── api.php        后端（SQLite 版）
├── data/              数据目录（运行时生成，不入库；需给 Web 用户写权限）
│   ├── baogong.sqlite
│   ├── images/
│   └── backups/
├── 预览/              各页面截图
├── app.env.example    配置模板
├── 部署说明.md
├── 零件导入模板.csv    零件批量导入模板
└── README.md
```

## 数据与备份

- 主数据库：`data/baogong.sqlite`（WAL 模式，运行时会产生 `-wal` / `-shm` 文件，属正常现象）。
- 自动备份：距上次超过 12 小时自动用 `VACUUM INTO` 生成一份 `.sqlite` 快照，保留最近 30 份，存放在 `data/backups/`。
- 手动备份：页面左下角「数据备份 → 导出 JSON」，或直接复制整个 `data/` 目录。
- 恢复：用页面的「从 JSON 备份恢复」，或把备份 `.sqlite` 改名为 `baogong.sqlite` 放回 `data/`。

## 初始数据

`零件导入模板.csv` 为导入模板（17 列：图号、名称、材料、库存、备注 + 最多 6 道工序/单价）。在「零件列表 → 导入」选择符合该格式的 CSV 即可批量建零件。

## 常见问题

- **500 `could not find driver`**：PHP 未启用 `pdo_sqlite` 扩展，在 Web Station 的 PHP 配置里勾选即可。
- **500 数据目录不可写**：给 `data/` 目录 Web 用户读写权限，并确认 `open_basedir` 未限制该目录。
- **直连 github 推送超时**：本机走代理时，推送需带代理环境变量，例如 `$env:HTTPS_PROXY='http://127.0.0.1:7890'; git push`。

更多排错与迁移说明见 [部署说明.md](部署说明.md)。
