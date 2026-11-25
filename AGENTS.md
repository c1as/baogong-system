# 报工系统 · AI 编码导航（AGENTS）

给 AI 会话的最小导航。完整拆分方案见 `docs/superpowers/specs/2026-11-24-模块拆分与索引-design.md`。

## 去哪找代码
- 前端：`public/js/`（`views`=页面，`features`=能力，`core`=工具，`shell`=外壳与启动，`ui`=通用组件），由 `public/index.html` 按顺序加载
- 后端：`public/api.php` 仅启动 + 鉴权 + `$ACTIONS` 路由表；接口实现在 `public/actions/*.php`，通用函数在 `public/lib/*.php`
- OCR 插件：内置 3 个在 `public/plugins/<id>/`（handwriting-lexicon、ocr-ensemble、ocr-log）；其余识别插件源码在仓库根 `plugins-src/<id>/`，以插件包形式发布，不进 web 包；插件协议勿改
- 先读 `docs/模块索引.md` 再定位，避免整文件通读

## 已知例外
- `public/js/views/report-form.js` 861 行：其中 `viewReportForm` 是单个 784 行大函数，不重构内部无法降到 700 行以下，暂作为唯一超限文件

## 改代码前
1. 单文件 ≤700 行；新代码按 `core/features/views`（前端）、`lib/actions`（后端）分层
2. 不改接口路径、返回结构、数据库 schema、插件协议
3. 拆分阶段：一次只动一层功能域，改完即跑回归

## 验证
- 接口回归：`node work/api-test.mjs`（36 例）
- 页面：登录 → 零件 → 订单 → 报工 → 统计 → 设置/插件 → PDF，控制台无错
- 移动端 390px 必须正常

## 约束
- 零构建：经典 script + 全局函数，禁用 ES Module
- 静态资源统一带 `?v=版本号`，与设置菜单版本一致
- 中文路径文件用 Write 工具写入，避免 PowerShell 编码问题
