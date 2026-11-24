# OCR 插件接入手写字词库指南

> 适用插件：百度 OCR、腾讯云 OCR、通义千问 Qwen3.5-OCR、MiMo v2.6 Flash
> 本次交付：**公共库 + 百度插件实际改造参考 + 四插件函数级改造片段**；其余三个插件按本文排期接入。

---

## 1. 接入原则（三条硬约束）

1. **只加不改**：只增加 `require_once` 与若干调用点，不修改 OCR 引擎、不修改返回结构、不修改 `confidence` 字段语义。
2. **静默降级**：所有调用必须包在 `function_exists('bglx_*')` 中并 `try/catch`；词库插件缺失、词库损坏、锁超时都按「未命中」处理，识别流程不中断。
3. **分级处理**：`auto` 才替换字段值；`suggest` 只追加 `warnings` 交人工确认；`none` 什么都不做。

```php
/* 通用判断模板 */
$r = bglx_correct_partno($raw, array('plugin' => '当前插件 id'));
if ($r['action'] === 'auto') {
    $value = $r['corrected'];
} elseif ($r['action'] === 'suggest') {
    $warnings[] = '「' . $r['original'] . '」疑似「' . $r['corrected'] . '」，请人工确认';
}
// action=none 时保持原值
```

---

## 2. 引入公共库（各插件统一写法）

在插件入口文件中、`require_once __DIR__ . '/lib.php'`（或 `shared.php`）之后加入：

```php
/* 手写字词库：可选依赖，缺失或加载失败时静默降级 */
$bglxCandidates = array(
    dirname(__DIR__) . '/handwriting-lexicon/lib/lexicon.php',              // public/plugins/ 同级布局
    dirname(__DIR__, 2) . '/public/plugins/handwriting-lexicon/lib/lexicon.php', // fixed-plugins/ 布局
);
foreach ($bglxCandidates as $bglxFile) {
    if (is_file($bglxFile)) {
        try {
            require_once $bglxFile;
        } catch (Throwable $bglxE) {
            error_log('[handwriting-lexicon] 引入失败：' . $bglxE->getMessage());
        }
        break;
    }
}
```

`lib/lexicon.php` 是唯一公共入口，内部已 `require_once` 全部依赖，并用 `BGLX_LEXICON_LOADED` 防止重复定义。多个插件在同一进程引入不会冲突。

---

## 3. 公共函数速查

| 函数 | 用途 | 典型值 |
| --- | --- | --- |
| `bglx_correct_partno($raw, $opts)` | 图号纠错（含字符形近归一） | `2L59711305` → `24971b05` |
| `bglx_norm_process($raw, $opts)` | 工序归一 | `铣2` → `钻孔`（suggest） |
| `bglx_norm_unit($raw, $opts)` | 单位归一 | `个` → `件`（suggest） |
| `bglx_correct_text($type, $raw, $opts)` | 通用入口 | 四种 type 均可用 |
| `bglx_query_all($type, $raw, $opts)` | 调试用逐条候选 | 不用于生产替换 |
| `bglx_store()` | 获取 Store 单例 | 供反馈沉淀词条用 |

`$opts` 支持：`plugin`（作用范围过滤）、`auto_min`、`suggest_min`。

---

## 4. 逐插件改造点

### 4.1 百度 OCR（`fixed-plugins/baidu-ocr/handler.php`）—— 本次已实际改造

| 序号 | 函数 | 位置 | 处理 |
| --- | --- | --- | --- |
| ① | `bg_baidu_norm()` | 函数定义之后 | 新增图号两级纠错辅助函数 `bg_baidu_lexicon_partno()` |
| ② | `bg_baidu_match_part()` | 函数入口 | 对整段图号先归一，再走原有 catalog 匹配 |
| ③ | `bg_baidu_match_process()` | 函数入口 | 对行内工序词归一（`铣2` → `钻孔`） |
| ④ | `bg_baidu_qty()` | 解析单位处 | 对识别到的单位词归一 |

参考片段见 `examples/integrate-baidu-ocr.php.txt`；完整改造见同目录 `handler.php`。

### 4.2 腾讯云 OCR（`tencent-ocr/handler.php` + `lib.php`）

引入位置：`require_once __DIR__ . '/lib.php';` 之后。改造点：

| 函数 | 处理 |
| --- | --- |
| 图号模糊匹配前（前缀 `bg_tc_*`，如 `bg_tc_match_part` / `bg_tc_has_no`） | `bglx_correct_partno()` |
| 工序匹配前（如 `bg_tc_proc_base` / `bg_tc_match_process`） | `bglx_norm_process()` |
| 数量单位解析前（如 `bg_tc_qty`） | `bglx_norm_unit()` |

片段见 `examples/integrate-tencent-ocr.php.txt`。

### 4.3 通义千问 Qwen3.5-OCR（`fixed-plugins/qwen35-ocr/handler.php`）

引入位置：`require_once __DIR__ . '/shared.php';` 之后。

改造点：`$items[] = array(...)` 组装**之前**，对 `$part_no` / `$process` 做后处理；`qwenocr_qty()` 之前归一单位。

**注意**：保留原 `confidence` 字段语义——词库纠错结果不改写它，只追加 `warnings`。

片段见 `examples/integrate-qwen35-ocr.php.txt`。

### 4.4 MiMo v2.6 Flash（`fixed-plugins/mimo-v26-flash/handler.php`）

引入位置：`require_once __DIR__ . '/shared.php';` 之后。

改造点：`$fields` 组装完成后、`return` 之前，遍历 `$fields['items']` 做后处理；`mimoocr_fallback()` 兜底分支同样加一层。

片段见 `examples/integrate-mimo-ocr.php.txt`。

---

## 5. 灰度、回归与回滚

| 阶段 | 操作 | 预期 |
| --- | --- | --- |
| 1. 零词库回归 | 不导入种子、不创建词条 | 四个插件识别结果与改造前**逐字段一致**，`warnings` 不增加 |
| 2. 种子灰度 | 导入 `fixtures/demo-entries.json`，先把高风险词条 `enabled=false` | 仅高置信度图号被纠正；低置信度只出 warnings |
| 3. 逐步放开 | 管理页逐条启用 / 审核 | 每次变更后下一次识别立即生效，无需重启 |
| 4. 回滚 | 停用对应词条（无需删插件）；极端情况删除 `require_once` 行 | 立即恢复原行为 |

**安全底线**：任何 `auto` 纠正的结果都可在报工界面被人工改写；改写应反馈为 `source=ocr_feedback, status=pending, confidence=0.75` 的候选词条，经管理员审核后生效。

---

## 6. 接入自检

确认改造成功的最小验证（无需主程序）：

```bash
cd public/plugins/handwriting-lexicon
cp fixtures/demo-entries.json ../../../data/handwriting_lexicon.json
php -r 'require "lib/lexicon.php"; $r = bglx_correct_partno("2L59711305"); var_export($r);'
# 期望 corrected=24971b05, action=auto, strategy=char_norm
```

自检失败时按以下顺序排查：词库文件是否存在且 JSON 合法 → `error_log` 中的降级原因 → 词条 `status` / `enabled` / `scope` → 阈值设置。

---

## 7. 使用连接层（推荐，OCR 生态重构后统一入口）

> **本次重构后**：所有 OCR 插件与融合引擎**统一通过 `bglx_bridge_*` 连接层**调用字库能力，不再各自 `require lib/lexicon.php`。上面的 §2–§4「各插件直接 require lexicon.php」写法为**兼容保留**（老插件仍可用），新接入一律走连接层。

### 7.1 为什么用连接层

1. **单一职责**：字库胶水只在一处维护，各插件零内嵌代码（G1）。
2. **静默降级**：词库缺失 / 损坏 / 异常 / 熔断，全部等价「未命中」，绝不阻断识别（Q5）。
3. **请求级熔断**：单请求连续失败 ≥3 次后短路，避免反复重试拖慢链路。
4. **批量归一**：一次调用处理整个引擎结果的多个字段。

### 7.2 一行引入 + 一行初始化

```php
$bglxBridge = dirname(__DIR__) . '/handwriting-lexicon/lib/bridge.php';
if (is_file($bglxBridge)) {
    require_once $bglxBridge;
    bglx_bridge_init('your-plugin-id');
}
```

### 7.3 五个接口

| 接口 | 用途 |
|---|---|
| `bglx_bridge_init($pluginId, $opts)` | 初始化（幂等），返回是否可用 |
| `bglx_bridge_norm($kind, $raw, $opts)` | 单值归一，`kind ∈ text\|partno\|process\|unit`，返回 LexiconResult |
| `bglx_bridge_norm_fields($fields, $opts)` | 批量归一引擎结果字段，返回 `{fields, changes, suggestions}` |
| `bglx_bridge_suggestions()` | 取本次请求的 suggest 提示（已去重），供 warnings |
| `bglx_bridge_status()` | 状态：`available/degraded/failCount/version` |

LexiconResult：`{value, action, rule, original, degraded, confidence, matched_id}`；`action ∈ auto|suggest|none`。

### 7.4 典型用法（批量归一 + 建议并入告警）

```php
if (function_exists('bglx_bridge_norm_fields')) {
    $n = bglx_bridge_norm_fields($fields, array('plugin' => 'your-plugin-id'));
    $fields = $n['fields'];                  // auto 档字段值已改写
    foreach (bglx_bridge_suggestions() as $sug) {
        $warnings[] = '字库建议：' . $sug . '，请人工确认';  // suggest 只提示
    }
}
```

### 7.5 降级行为（对应 Q5）

| 失败场景 | 行为 |
|---|---|
| 词库文件缺失 / 路径错误 | `degraded=1`，`norm` 原样返回 `action=none`，记告警 |
| `require_once` 抛异常 | `try/catch` 捕获，按未命中处理 |
| `bglx_*` 函数不存在 | `function_exists` 判定，不调用 |
| 查询内部异常 / 锁超时 | 该次返回 `action=none`，标记 `degraded` |
| 单请求连续失败 ≥3 次 | 熔断，后续调用直接短路返回 `none` |

**原则**：任何失败都等价于「词库未命中」，绝不改变字段语义、绝不抛给上层。单测见 `tests/bridge.test.php`。
