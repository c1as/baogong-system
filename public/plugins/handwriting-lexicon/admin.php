<?php
/**
 * 手写字词库管理页（独立后台页面）
 *
 * 由于目标主程序尚无「插件管理页」注册能力，本页通过独立 URL 直接访问：
 *   /plugins/handwriting-lexicon/admin.php
 * 未登录时渲染登录表单；登录成功后输出「词库管理 / 审查工作台」双 Tab。
 *
 * 使用独立 session 名 BGLXSESSID，不与主程序会话冲突。
 */

require_once __DIR__ . '/lib/lexicon.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/review.php';
bglx_rev_bootstrap();

$auth = new BglxAuth();

/* 处理登录 / 退出 */
$notice = '';
if (bglx_is_post()) {
    $act = isset($_POST['bglx_action']) ? (string)$_POST['bglx_action'] : '';
    if ($act === 'login') {
        $token = isset($_POST['bglx_token']) ? (string)$_POST['bglx_token'] : '';
        if ($auth->login($token)) {
            header('Location: ' . basename(__FILE__));
            exit;
        }
        $notice = '管理令牌无效，请重新输入';
    } elseif ($act === 'logout') {
        $auth->logout();
        header('Location: ' . basename(__FILE__));
        exit;
    }
}

$loggedIn = $auth->check();

/**
 * 安全输出 HTML 文本。
 *
 * @param string $s 原始文本
 * @return string
 */
function bglx_h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ---------- 未登录：渲染登录表单（零改动） ---------- */
if (!$loggedIn) {
    $modeText = $auth->hasToken() ? '已配置管理令牌，请输入令牌登录' : '未配置令牌，仅本机（127.0.0.1 / ::1）可访问';
    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2563eb">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="手写词库">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<title>手写字词库 · 登录</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="bglx-login-page">
<div class="bglx-login-box">
  <h1>手写字词库</h1>
  <p class="bglx-login-tip"><?php echo bglx_h($modeText); ?></p>
  <?php if ($notice !== ''): ?><p class="bglx-login-error"><?php echo bglx_h($notice); ?></p><?php endif; ?>
  <form method="post" action="">
    <input type="hidden" name="bglx_action" value="login">
    <label for="bglx_token">管理令牌</label>
    <input type="password" id="bglx_token" name="bglx_token" autocomplete="current-password" placeholder="请输入 plugin.json 中配置的 adminToken" required>
    <button type="submit">登录管理页</button>
  </form>
  <div class="bglx-shortcut-tip">
    <strong>手机上快速打开</strong>
    <p>点浏览器菜单「添加到主屏幕」，即可生成桌面图标，以后一键进入，无需再从插件设置里复制地址。</p>
    <button type="button" class="bglx-btn" id="btn-copy-url">复制本页地址</button>
    <span class="bglx-copy-status" id="copy-status" aria-live="polite"></span>
  </div>
  <p class="bglx-login-foot">令牌也可通过请求头 X-Bglx-Token 或 URL 参数 token 传入。</p>
</div>
<script>
(function () {
  var btn = document.getElementById('btn-copy-url');
  var status = document.getElementById('copy-status');
  if (!btn) { return; }
  btn.addEventListener('click', function () {
    var url = window.location.href.split('?')[0].split('#')[0];
    var done = function () { status.textContent = '已复制'; };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(done, function () { fallback(); });
    } else { fallback(); }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); }
      catch (e) { status.textContent = '复制失败，请手动复制地址栏'; }
      document.body.removeChild(ta);
    }
  });
})();
</script>
</body>
</html>
<?php
    exit;
}

/* ---------- 已登录：渲染管理页 ---------- */
$thresholds = bglx_thresholds();
$reviewEnabled = bglx_rev_setting_bool('reviewEnabled', false);
$reviewCsrf = bglx_rev_csrf_token();
$dirInfo = bglx_rev_attachment_dirs();
$logBase = bglx_rev_resolve_base(bglx_rev_setting('logDir', 'data/ocr-logs/'));
$logReal = $logBase !== '' ? realpath($logBase) : false;
$capabilities = array(
    'gd' => function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled'),
    'curl' => function_exists('curl_init'),
    'dirs' => count($dirInfo['dirs']) > 0,
    'log' => $logReal !== false && is_dir($logReal) && is_readable($logReal),
    'ai' => bglx_rev_setting_bool('aiEnabled', false),
);
$cfg = array(
    'api' => 'api.php',
    'auto' => $thresholds['auto'],
    'suggest' => $thresholds['suggest'],
    'mode' => $auth->mode(),
    'token' => $auth->providedToken(),
    'review' => array(
        'enabled' => $reviewEnabled,
        'page_size' => min(BGLX_REV_MAX_PAGE, max(1, bglx_rev_setting_int('reviewPageSize', 50))),
    ),
    'csrf' => $reviewCsrf,
    'capabilities' => $capabilities,
);
$cfgJson = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($cfgJson === false) {
    $cfgJson = '{}';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2563eb">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="手写词库">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<title>手写字词库管理</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="bglx-wrap">

  <!-- ① 顶部工具栏与退出 -->
  <header class="bglx-header">
    <div class="bglx-title">
      <h1>手写字词库</h1>
      <span class="bglx-subtitle">统一词库 · 供全部 OCR 插件共享</span>
    </div>
    <form method="post" action="" class="bglx-logout-form">
      <input type="hidden" name="bglx_action" value="logout">
      <button type="submit" class="bglx-btn-ghost">退出登录</button>
    </form>
  </header>

  <!-- ② Tab 导航：审查入口由 reviewEnabled 控制 -->
  <nav class="bglx-tabs" id="bglx-tabs" aria-label="功能切换">
    <button type="button" class="bglx-tab is-active" data-tab="lexicon">词库管理</button>
    <?php if ($reviewEnabled): ?>
    <button type="button" class="bglx-tab" data-tab="review">审查工作台</button>
    <?php endif; ?>
  </nav>

  <!-- ③ 词库管理面板（既有五个区域，零改动） -->
  <div class="bglx-tabpanel" data-panel="lexicon">
  <section class="bglx-stats" id="stats">
    <div class="bglx-stat"><span class="bglx-stat-label">词条总数</span><strong id="stat-total">—</strong></div>
    <div class="bglx-stat"><span class="bglx-stat-label">已启用</span><strong id="stat-enabled">—</strong></div>
    <div class="bglx-stat"><span class="bglx-stat-label">待审核</span><strong id="stat-pending">—</strong></div>
    <div class="bglx-stat"><span class="bglx-stat-label">今日命中（P1）</span><strong id="stat-hit">0</strong></div>
  </section>

  <section class="bglx-toolbar">
    <label>类型
      <select id="filter-type">
        <option value="">全部类型</option>
        <option value="drawing_alias">图号别名</option>
        <option value="process_synonym">工序同义</option>
        <option value="unit_synonym">单位同义</option>
        <option value="char_confusion">形近字符</option>
      </select>
    </label>
    <label>状态
      <select id="filter-status">
        <option value="">全部状态</option>
        <option value="active">已生效</option>
        <option value="pending">待审核</option>
        <option value="rejected">已拒绝</option>
      </select>
    </label>
    <label class="bglx-search">关键词
      <input type="text" id="keyword" placeholder="搜索标准值或变体，回车搜索">
    </label>
    <button type="button" id="btn-search" class="bglx-btn">搜索</button>
    <button type="button" id="btn-reset" class="bglx-btn-ghost">重置</button>
    <span class="bglx-spacer"></span>
    <button type="button" id="btn-create" class="bglx-btn bglx-btn-primary">+ 新增词条</button>
  </section>

  <section class="bglx-table-wrap">
    <table class="bglx-table">
      <thead>
        <tr>
          <th>类型</th><th>标准值</th><th>变体</th><th>置信度</th>
          <th>状态</th><th>启用</th><th>来源</th><th>命中</th><th>操作</th>
        </tr>
      </thead>
      <tbody id="entries-body">
        <tr><td colspan="9" class="bglx-empty">正在加载词库…</td></tr>
      </tbody>
    </table>
    <div class="bglx-pagination" id="pagination"></div>
  </section>

  <div class="bglx-modal-mask" id="modal" hidden>
    <div class="bglx-modal">
      <div class="bglx-modal-head">
        <h2 id="modal-title">新增词条</h2>
        <button type="button" class="bglx-modal-close" id="btn-modal-close" aria-label="关闭">×</button>
      </div>
      <div class="bglx-modal-body">
        <p class="bglx-form-error" id="form-error" hidden></p>
        <div class="bglx-form-row">
          <label>类型 <span class="bglx-req">*</span>
            <select id="f-type">
              <option value="drawing_alias">图号别名</option>
              <option value="process_synonym">工序同义</option>
              <option value="unit_synonym">单位同义</option>
              <option value="char_confusion">形近字符（单字符）</option>
            </select>
          </label>
          <label>来源
            <select id="f-source">
              <option value="manual">手工录入</option>
              <option value="ocr_feedback">OCR 反馈</option>
              <option value="import">批量导入</option>
              <option value="learning">学习生成</option>
            </select>
          </label>
        </div>
        <div class="bglx-form-row">
          <label>标准值 <span class="bglx-req">*</span>
            <input type="text" id="f-standard" placeholder="如 24971b05 或 钻孔">
          </label>
          <label>优先级（0~100）
            <input type="number" id="f-priority" value="10" min="0" max="100">
          </label>
        </div>
        <label class="bglx-block">变体 <span class="bglx-req">*</span>（输入后回车添加，可多个）
          <input type="text" id="f-variant-input" placeholder="输入变体后按回车">
        </label>
        <div class="bglx-tags" id="variants-tags"></div>
        <div class="bglx-form-row">
          <label>置信度 <span id="f-confidence-label">0.90</span>
            <input type="range" id="f-confidence" min="0" max="1" step="0.01" value="0.90">
          </label>
          <label>审核态
            <select id="f-status">
              <option value="active">已生效</option>
              <option value="pending">待审核</option>
              <option value="rejected">已拒绝</option>
            </select>
          </label>
        </div>
        <div class="bglx-form-row">
          <label class="bglx-checkline"><input type="checkbox" id="f-enabled" checked> 启用该词条</label>
          <label>作用范围（P0 固定全局）
            <input type="text" id="f-scope" value="*" disabled>
          </label>
        </div>
        <label class="bglx-block">备注
          <textarea id="f-note" rows="2" placeholder="记录混淆原因，如：数字1/l 混淆"></textarea>
        </label>
      </div>
      <div class="bglx-modal-foot">
        <button type="button" class="bglx-btn-ghost" id="btn-cancel">取消</button>
        <button type="button" class="bglx-btn bglx-btn-primary" id="btn-save">保存</button>
      </div>
    </div>
  </div>

  <section class="bglx-bottom">
    <div class="bglx-panel">
      <h3>批量导入 / 导出</h3>
      <p class="bglx-hint">支持 JSON 词条数组与 procAlias 文本（<code>铣 = 线, 绕, 鉄</code>）。CSV 格式将在后续版本开放。</p>
      <div class="bglx-form-row">
        <label>格式
          <select id="import-format">
            <option value="json">JSON</option>
            <option value="procAlias">procAlias 文本</option>
            <option value="csv">CSV（P1 未开放）</option>
          </select>
        </label>
        <label>模式
          <select id="import-mode">
            <option value="merge">合并（跳过重复）</option>
            <option value="replace">替换全部</option>
          </select>
        </label>
      </div>
      <textarea id="import-content" rows="3" placeholder="粘贴 JSON 数组或 procAlias 文本"></textarea>
      <div class="bglx-panel-actions">
        <button type="button" id="btn-import" class="bglx-btn">导入</button>
        <button type="button" id="btn-export" class="bglx-btn-ghost">导出全部</button>
      </div>
      <p class="bglx-result" id="import-result"></p>
    </div>

    <div class="bglx-panel">
      <h3>纠错测试区</h3>
      <p class="bglx-hint">与 OCR 插件调用同一查询接口，便于上线前验证词条效果。</p>
      <div class="bglx-form-row">
        <label>类型
          <select id="test-type">
            <option value="drawing_alias">图号别名</option>
            <option value="process_synonym">工序同义</option>
            <option value="unit_synonym">单位同义</option>
          </select>
        </label>
        <label>原始值
          <input type="text" id="test-value" placeholder="如 2L59711305">
        </label>
      </div>
      <div class="bglx-form-row">
        <label>插件范围（可选）
          <input type="text" id="test-plugin" placeholder="如 baidu-ocr，留空表示不限">
        </label>
        <button type="button" id="btn-test" class="bglx-btn bglx-btn-primary">查询</button>
      </div>
      <pre class="bglx-result bglx-trace" id="test-result">等待查询…</pre>
    </div>
  </section>
  </div><!-- /词库管理面板 -->

  <?php if ($reviewEnabled): ?>
  <!-- ④ 审查工作台面板 -->
  <div class="bglx-tabpanel" data-panel="review" hidden>
    <section class="bglx-stats bglx-review-stats" id="rv-stats">
      <div class="bglx-stat"><span class="bglx-stat-label">待审</span><strong id="rv-stat-unreviewed">—</strong></div>
      <div class="bglx-stat"><span class="bglx-stat-label">正确</span><strong id="rv-stat-correct">—</strong></div>
      <div class="bglx-stat"><span class="bglx-stat-label">错误</span><strong id="rv-stat-wrong">—</strong></div>
      <div class="bglx-stat"><span class="bglx-stat-label">草稿 / 已入库</span><strong id="rv-stat-flow">—</strong></div>
    </section>

    <section class="bglx-toolbar bglx-review-toolbar">
      <label>起始日期<input type="date" id="rv-date-from"></label>
      <label>结束日期<input type="date" id="rv-date-to"></label>
      <label>引擎<input type="text" id="rv-engine" placeholder="如 baidu-ocr"></label>
      <label>判定
        <select id="rv-judgment">
          <option value="">全部</option>
          <option value="unreviewed">未审</option>
          <option value="correct">正确</option>
          <option value="wrong">错误</option>
        </select>
      </label>
      <label class="bglx-search">关键词<input type="text" id="rv-keyword" placeholder="图号 / 备注 / 原文"></label>
      <button type="button" id="rv-search" class="bglx-btn">筛选</button>
      <button type="button" id="rv-reset" class="bglx-btn-ghost">重置</button>
    </section>

    <div class="bglx-review-layout">
      <section class="bglx-review-list-wrap">
        <div class="bglx-review-list" id="rv-list">
          <p class="bglx-empty">正在加载识别日志…</p>
        </div>
        <div class="bglx-pagination" id="rv-pagination"></div>
      </section>
      <section class="bglx-review-detail" id="rv-detail">
        <p class="bglx-empty">请选择左侧一条日志，查看原图与识别结果对照。</p>
      </section>
    </div>
    <p class="bglx-result" id="rv-result" role="status" aria-live="polite"></p>
  </div>
  <?php endif; ?>

</div>

<script>window.BGLX_CFG = <?php echo $cfgJson; ?>;</script>
<script src="assets/admin.js?v=1.1.0"></script>
<?php if ($reviewEnabled): ?><script src="assets/review.js?v=1.1.0"></script><?php endif; ?>
</body>
</html>
