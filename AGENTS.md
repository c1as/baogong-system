# 报工系统 · AI 编码导航（AGENTS）

给 AI 会话的最小导航。完整拆分方案见 `docs/superpowers/specs/2026-11-24-模块拆分与索引-design.md`。

## 去哪找代码
- 前端单页：`public/app.js`（按 `/* ===` 分节注释组织）；外壳 `public/index.html`；样式 `public/style.css`；PDF 查看 `public/pdf.html`
- 后端：`public/api.php`（工具函数 → 仓储/业务函数 → 底部 `switch ($action)` 路由）
- OCR 插件：`public/plugins/<id>/{plugin.json,handler.php,check.php}`，协议勿改
- 先读 `docs/模块索引.md` 再定位，避免整文件通读

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
