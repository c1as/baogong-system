/* 模块: views/plugin-market
   路由: #/market
   职责: 插件市场列表、筛选、安装/升级/卸载、详情弹窗与来源设置
   依赖: core/api core/store core/icons shell/layout ui/components
   导出: viewMarket renderMarket bindMarketShell doMarketRefresh doMarketAction openMarketDetail openMarketSource marketStatusChip
   注意: 市场区 data/market-plugins 可写，必备区 public/plugins 只读 */
'use strict';

/* ==================================================================== 插件市场（v1.1） */

let marketFilter = 'all'; // all | upgradable | installed

const MARKET_STATUS_LABEL = {
  installable: '可安装',
  upgradable: '可升级',
  installed: '已安装',
  local_newer: '本地较新',
  unmanaged: '本地插件',
};

function marketStatusChip(s) {
  const cls = { upgradable: 'warn', installed: 'ok', installable: '', local_newer: 'muted', unmanaged: 'muted' }[s] || '';
  return `<span class="mk-chip ${cls}">${esc(MARKET_STATUS_LABEL[s] || s)}</span>`;
}

async function viewMarket() {
  setHead(
    '插件市场',
    '在线发现、安装与升级插件，无需重装 App',
    `<button class="btn btn-ghost" data-mk="source">${icon('settings', 16)}来源设置</button>
     <button class="btn btn-ghost" data-mk="refresh">${icon('refresh', 16)}刷新</button>`
  );
  const view = $('#view');
  view.innerHTML = `<div class="mk-loading">正在加载插件市场…</div>`;
  let data;
  try {
    data = await api('GET', 'api.php?action=plugin.market.list');
  } catch (err) {
    view.innerHTML = `<div class="empty"><div class="empty-icon">${icon('alert', 26)}</div>
      <div class="empty-title">无法加载插件市场</div><p>${esc(err.message)}</p>
      <button class="btn btn-primary" data-mk="source">配置来源</button></div>`;
    bindMarketShell(view, null);
    return;
  }
  renderMarket(view, data);
  bindMarketShell(view, data);
}

function renderMarket(view, data) {
  let items = (data.items || []).slice();
  if (marketFilter === 'upgradable') items = items.filter((x) => x.status === 'upgradable');
  else if (marketFilter === 'installed') items = items.filter((x) => ['installed', 'upgradable', 'local_newer'].includes(x.status));
  const src = data.source || {};

  const tabs = `<div class="mk-tabs">
    <button class="mk-tab ${marketFilter === 'all' ? 'active' : ''}" data-mk-filter="all">全部 <em>${(data.items || []).length}</em></button>
    <button class="mk-tab ${marketFilter === 'upgradable' ? 'active' : ''}" data-mk-filter="upgradable">可升级 <em>${Number(data.upgradeCount) || 0}</em></button>
    <button class="mk-tab ${marketFilter === 'installed' ? 'active' : ''}" data-mk-filter="installed">已安装</button>
  </div>`;

  const sourceBar = `<div class="mk-source">
    <div class="mk-source-info"><span class="mk-source-name">${esc(src.name || '未配置来源')}</span>
      <span class="mk-source-url">${esc(src.url || '')}</span></div>
    <span class="mk-meta">${data.cached ? '缓存 · ' : ''}${esc(data.fetchedAt || '')}</span>
  </div>`;

  const cards = items.map((it) => {
    const capsHtml = (it.capabilities || []).map((c) => `<span class="mk-cap">${esc(c)}</span>`).join('');
    let actions = '';
    if (it.status === 'installable') {
      actions = `<button class="btn btn-primary btn-sm" data-mk-install="${esc(it.id)}">${icon('download', 14)}安装</button>`;
    } else if (it.status === 'upgradable') {
      actions = `<button class="btn btn-primary btn-sm" data-mk-upgrade="${esc(it.id)}">${icon('download', 14)}升级到 ${esc(it.version)}</button>`;
    } else if (it.status === 'unmanaged') {
      actions = `<span class="mk-hint">本地插件（不在市场索引中）</span>`;
    } else {
      actions = `<span class="mk-hint">${it.status === 'local_newer' ? '本地版本较新' : '已是最新'}</span>`;
    }
    const canUninstall = it.source === 'market';
    const uninstallBtn = canUninstall
      ? `<button class="btn btn-ghost btn-sm" data-mk-uninstall="${esc(it.id)}">${icon('trash', 14)}卸载</button>` : '';
    const ver = it.localVersion ? `${esc(it.localVersion)} → ${esc(it.version)}` : esc(it.version);
    return `<div class="mk-card">
      <div class="mk-card-top">
        <div class="mk-card-title"><strong>${esc(it.name || it.id)}</strong><code>${esc(it.id)}</code></div>
        ${marketStatusChip(it.status)}
      </div>
      <p class="mk-desc">${esc(it.description || '暂无说明')}</p>
      <div class="mk-card-meta"><span>版本 ${ver}</span>${it.author ? `<span>作者 ${esc(it.author)}</span>` : ''}
        ${it.source ? `<span>来源 ${it.source === 'market' ? '市场副本' : '系统必备'}</span>` : ''}</div>
      ${capsHtml ? `<div class="mk-caps">${capsHtml}</div>` : ''}
      <div class="mk-actions">
        <button class="btn btn-ghost btn-sm" data-mk-detail="${esc(it.id)}">详情</button>
        ${actions}${uninstallBtn}
      </div>
    </div>`;
  }).join('');

  view.innerHTML = `${sourceBar}${tabs}${cards || `<div class="empty"><div class="empty-icon">${icon('store', 26)}</div>
    <div class="empty-title">没有匹配的插件</div><p>试试刷新索引或切换筛选条件</p></div>`}
    <p class="settings-note">市场插件安装到 <code>data/market-plugins/</code>，系统更新不会覆盖；必备插件留在 <code>public/plugins/</code> 随系统更新，市场只读。</p>`;
}

function bindMarketShell(view, data) {
  const headActions = $('#pageActions');
  if (headActions) {
    const r = headActions.querySelector('[data-mk="refresh"]');
    if (r) r.onclick = () => doMarketRefresh();
    const s = headActions.querySelector('[data-mk="source"]');
    if (s) s.onclick = () => openMarketSource();
  }
  view.querySelectorAll('[data-mk-filter]').forEach((b) => {
    b.onclick = () => { marketFilter = b.dataset.mkFilter; viewMarket(); };
  });
  view.addEventListener('click', async (e) => {
    const el = e.target.closest('[data-mk-detail],[data-mk-install],[data-mk-upgrade],[data-mk-uninstall]');
    if (!el) return;
    const id = el.dataset.mkDetail || el.dataset.mkInstall || el.dataset.mkUpgrade || el.dataset.mkUninstall;
    if (el.dataset.mkDetail) return openMarketDetail(id, data);
    if (el.dataset.mkInstall) return doMarketAction('install', id, '安装');
    if (el.dataset.mkUpgrade) return doMarketAction('upgrade', id, '升级');
    if (el.dataset.mkUninstall) return doMarketAction('uninstall', id, '卸载');
  });
  const srcBtn = view.querySelector('[data-mk="source"]');
  if (srcBtn) srcBtn.onclick = () => openMarketSource();
}

async function doMarketRefresh() {
  try {
    await api('GET', 'api.php?action=plugin.market.list&refresh=1');
    refreshMarketBadge();
    await viewMarket();
    toast('索引已刷新', 'ok');
  } catch (err) { toast(err.message, 'err'); }
}

async function doMarketAction(op, id, label) {
  if (op === 'uninstall') {
    const ok = await confirmDialog({
      title: '确认卸载插件',
      message: `确定要卸载「${id}」吗？若为系统必备插件的市场副本，卸载后将回落到系统基线。`,
      okText: '确认卸载', danger: true,
    });
    if (!ok) return;
  }
  const btn = document.querySelector(`[data-mk-${op}="${id}"]`);
  if (btn) { btn.disabled = true; btn.textContent = '处理中…'; }
  try {
    const body = op === 'uninstall' ? { id, confirm: true } : { id };
    const res = await api('POST', `api.php?action=plugin.market.${op}`, body);
    toast(`${label}成功${res.version ? ' · v' + esc(res.version) : ''}`, 'ok');
    await refreshPlugins();
    refreshMarketBadge();
    await viewMarket();
  } catch (err) {
    toast(`${label}失败：${err.message}`, 'err');
    if (btn) { btn.disabled = false; }
  }
}

function openMarketDetail(id, data) {
  const it = (data && data.items || []).find((x) => x.id === id);
  if (!it) return toast('插件信息不存在', 'err');
  const capHtml = (it.capabilities || []).map((c) => `<span class="mk-cap">${esc(c)}</span>`).join('') || '无';
  const settingRows = (it.settings || []).map((f) =>
    `<div class="mk-setting"><strong>${esc(f.label || f.key)}</strong><code>${esc(f.key)}</code><span>${esc(f.type)}</span></div>`
  ).join('') || '<p class="mk-hint">该插件无配置项</p>';
  const { close } = openModal(`<h3>${esc(it.name || it.id)} <small>v${esc(it.version)}</small></h3>
    <div class="mk-detail">
      <p>${esc(it.description || '暂无说明')}</p>
      <div class="mk-detail-grid">
        <div><span>标识</span><code>${esc(it.id)}</code></div>
        <div><span>状态</span>${marketStatusChip(it.status)}</div>
        <div><span>能力</span><div class="mk-caps">${capHtml}</div></div>
        <div><span>作者</span>${esc(it.author || '—')}</div>
        <div><span>包大小</span>${esc(fmtBytes(it.packageSize || 0))}</div>
        <div><span>最低系统</span>${esc(it.minSystemVersion || '不限')}</div>
      </div>
      ${it.changelog ? `<h4>更新说明</h4><p class="mk-changelog">${esc(it.changelog)}</p>` : ''}
      <h4>配置项</h4>${settingRows}
    </div>
    <div class="modal-actions"><button class="btn btn-primary" data-mk-close>关闭</button></div>`);
  const closeBtn = document.querySelector('[data-mk-close]');
  if (closeBtn && close) closeBtn.onclick = close;
}

function openMarketSource() {
  api('GET', 'api.php?action=plugin.source.get').then((d) => {
    const s = d.source || {};
    const { root, close } = openModal(`<h3>插件市场来源</h3>
      <div class="mk-source-form">
        <label>来源名称<input class="input" id="mkSrcName" value="${esc(s.name || '')}" placeholder="官方源"></label>
        <label>索引地址（https）<input class="input" id="mkSrcUrl" value="${esc(s.url || '')}" placeholder="https://api.github.com/..."></label>
        <label>访问令牌（可选，公开仓库留空）<input class="input" id="mkSrcToken" type="password" value="${esc(s.tokenMasked || '')}" placeholder="留空表示保持原值"></label>
        <p class="settings-note">令牌仅保存在本机 <code>data/plugin-market.json</code>，不会写入插件包或日志。</p>
      </div>
      <div class="modal-actions">
        <button class="btn btn-ghost" data-mk-test>测试连接</button>
        <button class="btn btn-ghost" data-mk-cancel>取消</button>
        <button class="btn btn-primary" data-mk-save>保存</button>
      </div>`);
    root.querySelector('[data-mk-cancel]').onclick = close;
    root.querySelector('[data-mk-test]').onclick = async () => {
      const btn = root.querySelector('[data-mk-test]');
      btn.disabled = true; btn.textContent = '测试中…';
      try {
        const r = await api('POST', 'api.php?action=plugin.source.test', {
          url: root.querySelector('#mkSrcUrl').value.trim(),
          token: root.querySelector('#mkSrcToken').value.trim(),
        });
        toast(`连接成功 · ${r.count} 个插件 · ${r.ms}ms`, 'ok');
      } catch (err) { toast(err.message, 'err'); }
      finally { btn.disabled = false; btn.textContent = '测试连接'; }
    };
    root.querySelector('[data-mk-save]').onclick = async () => {
      try {
        await api('POST', 'api.php?action=plugin.source.save', {
          name: root.querySelector('#mkSrcName').value.trim(),
          url: root.querySelector('#mkSrcUrl').value.trim(),
          token: root.querySelector('#mkSrcToken').value.trim(),
        });
        close(); toast('来源已保存', 'ok'); viewMarket();
      } catch (err) { toast(err.message, 'err'); }
    };
  }).catch((err) => toast(err.message, 'err'));
}
