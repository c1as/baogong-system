# 端到端验收清单

## 0. 准备

```bash
cd public/plugins/handwriting-lexicon
find . -name '*.php' -exec php -l {} \;        # 全部无语法错误
php tests/corrector.test.php                   # 30/30 通过
python3 -c "import json,glob; [json.load(open(f)) for f in glob.glob('**/*.json', recursive=True)]; print('JSON OK')"
cp fixtures/demo-entries.json ../../../data/handwriting_lexicon.json   # 导入种子（路径按实际部署调整）
```

## 1. P0 需求核对

| 需求 | 验收动作 | 通过标准 |
| --- | --- | --- |
| P0-1 词库 CRUD | 管理页新增 → 编辑 → 停用 → 删除 | 每次操作后列表立即刷新，重开页面仍一致 |
| P0-2 四类分类 | 依次新增四类词条，按类型筛选 | 类型徽标正确；筛选只返回对应类型 |
| P0-3 查询接口 | 管理页测试区 + OCR 插件调用 | 未命中原样返回；命中按阈值分级 |
| P0-4 前端管理页 | 直接打开 `/plugins/handwriting-lexicon/admin.php` | 五区齐全，可登录并完成一次完整 CRUD |
| P0-5 核心字段 | 修改置信度 / 备注 / 来源 / 启用 / 审核态 | 保存后再次打开回显一致，查询行为随之改变 |
| P0-6 协议兼容 | 设置页自检 + 直接访问 `handler.php` | `check.php` 全绿；直接访问返回 403 |

## 2. 四类词条 × 三档阈值（种子数据）

| 类型 | 输入 | 预期 corrected | 预期 action | 预期 strategy |
| --- | --- | --- | --- | --- |
| 图号 | `2L59711305` | `24971b05` | `auto` | `char_norm` |
| 图号 | `24971bO5` | `24971b05` | `auto` | `char_norm` |
| 图号 | `307437/61` | `307437r61` | `auto` | `char_norm` |
| 图号 | `完全没有的图号` | 原值 | `none` | `none` |
| 工序 | `铣2` | 原值（提示 `钻孔`） | `suggest` | `exact` |
| 工序 | `铣` | `铣` | `auto` | `exact` |
| 单位 | `个` | 原值（提示 `件`） | `suggest` | `exact` |
| 形近字符 | `L`（单独查询不做整词替换） | 原值 | `none` | — |

> 最后一行验证保守原则：`char_confusion` 只参与字符归一，不会单独改写输入。

## 3. 边界与安全

| 用例 | 步骤 | 预期 |
| --- | --- | --- |
| 多候选降级 | 建立两条变体相同、标准值不同、置信度均 0.95 的词条 | `action=suggest`，`candidates` 至少 2 条 |
| 停用生效 | 停用某高置信度词条后再查询 | 立即 `none`，记录仍在列表 |
| 待审核不生效 | 新增 `status=pending` 词条后查询 | `none`；管理页高亮待审核 |
| 范围过滤 | 词条 `scope=["other-plugin"]`，以 `plugin=baidu-ocr` 查询 | `none` |
| 阈值覆盖 | 对 `铣2`（0.85）传 `auto_min=0.80` | 升为 `auto` |
| 非法词条 | 空标准值 / 空变体 / 置信度 2 | `42201`，`data.errors` 含中文字段提示 |
| 未授权 | 无 token 从非回环访问 `api.php` | `40301`（配了 token 则为 `40101`） |
| 错误 token | 带错误 `X-Bglx-Token` | `40101` |
| 锁超时 | 进程持有 `.lock` 超过 3 秒后写 | `40901` |
| CSV 导入 | `format=csv` | `50101` |
| JSON 损坏 | 手工破坏词库 JSON | `check.php` 报红；OCR 查询降级为不纠错，写 `error_log`，不抛异常 |

## 4. 并发与持久化

- 连续 create 100 条，ID 唯一且连续递增；
- 两个进程同时 create，均无丢失；
- 删除最大 ID 后新建，ID 不复用；
- 写入过程中杀进程，词库文件仍可被 `json_decode` 解析（临时文件 + rename 原子性）。

## 5. 闭环（时序 §5.3）

1. 词库空 → OCR 识别 `铣2` → 无提示；
2. 导入种子 → OCR 识别 `铣2` → `warnings` 出现「疑似钻孔」；
3. 核对员改写为「钻孔」→ 通过 API 生成 `source=ocr_feedback, status=pending, confidence=0.75` 候选；
4. 管理页审核通过 → 词条 `status=active, enabled=true`；
5. 下一次识别同类错误 → 自动归一，闭环成立。

## 6. 百度参考插件回归

| 场景 | 步骤 | 预期 |
| --- | --- | --- |
| 零词库回归 | 移走 `handwriting-lexicon` 目录后识别 | 与改造前逐字段一致，无新增 warnings |
| 词库生效 | 恢复目录并导入种子，识别含 `2L59711305` 的报工单 | 图号被纠正为 `24971b05`；返回结构不变；原 `confidence` 字段未被改写 |
| 静默降级 | 故意破坏词库 JSON 后识别 | 识别正常完成，仅 `error_log` 有降级记录 |

## 7. 审查结论记录

| 检查项 | 结果 |
| --- | --- |
| 函数前缀统一 `bglx_` / 类名 `Bglx*` | ☐ |
| PHP 7.4 语法兼容（无 `match` / `?->` / 命名参数 / 联合类型 / `str_contains`） | ☐ |
| JSON schema 一致（`schema/updated_at/next_id/entries`） | ☐ |
| 接口契约与 `ARCHITECTURE.md` §4.2 一致 | ☐ |
| 前端字段与后端接口一致 | ☐ |
| 所有 `require` 路径正确 | ☐ |
| `php -l` 全量通过 | ☐ |
| 全部 JSON 可解析 | ☐ |
| 核心函数自测通过 | ☐ |
