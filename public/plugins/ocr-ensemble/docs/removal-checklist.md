# 各 OCR 插件「移除内嵌字库」改造清单与验收表（G1）

> 目标：字库规则一处维护、全局生效。所有 OCR 插件不再内嵌字库/同义/形近归一代码，统一经 `handwriting-lexicon/lib/bridge.php` 连接层调用。
> 改造日期：2026-11-24

---

## 1. 验收口径

对每个插件 `handler.php` 执行：

```bash
grep -nE "lexicon\.php|bglx_correct|bglx_norm|ALIAS_EXTRA|BG_BGLX_WARNINGS|bg_baidu_lexicon_" \
  public/plugins/<plugin>/handler.php
```

**期望：0 命中**（连接层 bridge.php 自身除外）。

---

## 2. 逐插件改造表

| 插件 | 已移除的内嵌逻辑 | 替换为 | grep 结果 |
|---|---|---|---|
| ~~`baidu-ocr-lexicon-demo`~~（已移除） | 原内嵌 6 处字库胶水；该 demo 仅为旧版"字词库接入参考"反模式样板，字库解耦到 bridge 后已无存在必要 | 整个插件目录已删除，百度 OCR 只保留生产版 `baidu-ocr` 一个 | — 已删除 |
| `baidu-ocr` | 同 demo（生产版，内嵌逻辑完全一致） | `bglx_bridge_init('baidu-ocr')` + 批量归一 + suggestions | 0 ✅ |
| `qwen35-ocr` | 原无内嵌字库函数（仅文档预留改造点）；本次统一接入连接层 | `require bridge` + `bglx_bridge_init('qwen35-ocr')` + items 批量归一 + suggestions | 0 ✅ |
| `mimo-v26-flash` | 原无内嵌字库函数；本次统一接入连接层（normal / fallback 两分支共用一处归一） | `require bridge` + `bglx_bridge_init('mimo-v26-flash')` + fields 批量归一 + suggestions | 0 ✅ |
| `mock-ocr` | 原无内嵌字库；作为最小接入示例 | `require bridge` + `bglx_bridge_init('mock-ocr')` + items 批量归一 + suggestions | 0 ✅ |

---

## 3. 内嵌逻辑移除明细（demo / baidu）

改造前每个百度系 handler 含以下胶水代码，现已全部删除：

1. **候选路径引入**（旧 L28–41）：
   ```php
   $bglxCandidates = array(dirname(__DIR__).'/handwriting-lexicon/lib/lexicon.php', ...);
   foreach (...) { require_once $bglxFile; }
   ```
2. **suggest 全局缓存**（旧 L42）：`$GLOBALS['BG_BGLX_WARNINGS'] = array();`
3. **同义字典解析**（旧 L53–80）：读取 `$c['procAlias']` 填充 `$GLOBALS['BG_PROC_ALIAS_EXTRA']`。
4. **四个胶水函数**（旧 L164–264）：`bg_baidu_lexicon_collect / partno / process / unit`。
5. **三处调用点**：`bg_baidu_match_part()` 入口的图号归一、`bg_baidu_match_process()` 入口的工序归一、`bg_baidu_qty()` 的单位归一。
6. **proc_base 内的 extra 合并**：引用 `BG_PROC_ALIAS_EXTRA` 的分支移除，保留最小内置匹配表作为识别启发式。
7. **suggest 合并块**（旧 L789–799）：改为连接层 `bglx_bridge_suggestions()`。

统一替代（入口 2 行 + 返回前一段）：

```php
$bglxBridgeFile = dirname(__DIR__) . '/handwriting-lexicon/lib/bridge.php';
if (is_file($bglxBridgeFile)) {
    require_once $bglxBridgeFile;
    bglx_bridge_init('<plugin-id>');
}
// 返回前：
$n = bglx_bridge_norm_fields(array('items' => $items), array('plugin' => '<plugin-id>'));
$items = $n['fields']['items'];
foreach (bglx_bridge_suggestions() as $sug) { $warnings[] = '字库建议：' . $sug . '，请人工确认'; }
```

---

## 4. 行为等价与回退

- **零词库回归**：词库文件不存在或空 → 连接层 `norm_fields` 原样返回，`suggestions` 为空，各插件识别结果与改造前逐字段一致，`warnings` 不增加。
- **静默降级**：词库缺失 / 函数不存在 / 查询异常 / 单请求连续失败 ≥3 次熔断 → 全部等价「未命中」，识别流程不中断。
- **回滚**：删除 handler 中 `require bridge` + `init` 两行即可恢复无字库行为（所有调用都在 `function_exists` 保护内）。

---

## 5. 单测证据

```bash
php public/plugins/handwriting-lexicon/tests/bridge.test.php
```

覆盖：auto 命中 / suggest 提示 / none 未命中 / 批量归一 / 词库文件缺失降级 / 请求级熔断。全绿即证明连接层降级契约成立。
