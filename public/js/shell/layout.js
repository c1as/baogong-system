/* 模块: shell/layout
   职责: 应用外壳、顶栏、导航与页头渲染
   依赖: core/util core/icons core/store
   导出: navItem tabItem renderShell setHead
   注意: 只负责骨架，不写业务 */
'use strict';

/* ==================================================================== 外壳 */

function navItem(key, hash, ic, label) {
  return `<a class="nav-item" data-nav="${key}" href="${hash}">${icon(ic, 18)}<span>${label}</span></a>`;
}

function tabItem(key, hash, ic, label) {
  return `<a class="tab-item" data-nav="${key}" href="${hash}">${icon(ic, 21)}<span>${label}</span></a>`;
}

function renderShell() {
  const app = $('#app');
  app.innerHTML = `
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-mark">${icon('box', 20)}</div>
        <div>
          <div class="brand-name">报工管理</div>
          <div class="brand-sub">计件工资 · 单机版</div>
        </div>
      </div>
      <nav class="nav">
        ${navItem('parts', '#/parts', 'box', '零件列表')}
        ${navItem('orders', '#/orders', 'package', '订单记录')}
        ${navItem('reports', '#/reports', 'clipboard', '报工单记录')}
        ${navItem('stats', '#/stats', 'chart', '报工统计')}
        ${navItem('data', '#/data', 'database', '数据量统计')}
        <div class="nav-gap"></div>
        ${navItem('report-new', '#/reports/new', 'plusCircle', '新增报工')}
        ${navItem('part-new', '#/parts/new', 'plusSquare', '新增零件')}
        ${navItem('order-new', '#/orders/new', 'filePlus', '新增订单')}
      </nav>
      <div class="side-foot">
        ${state.auth ? `<button class="side-btn" data-action="logout">${icon('logout', 16)}退出登录</button>` : ''}
      </div>
    </aside>

    <main class="main">
      <header class="topbar">
        <div>
          <h1 id="pageTitle">零件列表</h1>
          <p id="pageSub"></p>
        </div>
        <div class="actions" id="pageActions"></div>
        <div class="settings-wrap">
          <button class="icon-btn settings-btn" type="button" id="settingsBtn" title="设置" aria-label="设置" aria-haspopup="true">
            ${icon('settings', 18)}
          </button>
          <div class="settings-menu" id="settingsMenu" hidden>
            <button type="button" data-action="backup">${icon('database', 16)}<span>数据备份</span></button>
            <a href="#/settings">${icon('settings', 16)}<span>识别插件设置</span></a>
            <a href="#/market">${icon('store', 16)}<span>插件市场</span><span class="nav-badge" id="marketBadge"${
              state.marketUpgradeCount > 0 ? '' : ' hidden'
            }>${state.marketUpgradeCount}</span></a>
            <a href="/plugins/handwriting-lexicon/admin.php" target="_blank" rel="noopener">${icon('list', 16)}<span>手写字词库管理</span></a>
            <div class="settings-ver">v${state.version || '3.1'} · 数据保存在服务器</div>
          </div>
        </div>
      </header>
      <div class="view" id="view"></div>
    </main>

    <nav class="tabbar">
      ${tabItem('parts', '#/parts', 'box', '零件')}
      ${tabItem('orders', '#/orders', 'package', '订单')}
      ${tabItem('reports', '#/reports', 'clipboard', '报工')}
      ${tabItem('stats', '#/stats', 'chart', '统计')}
      ${tabItem('data', '#/data', 'database', '数据')}
    </nav>`;
  app.hidden = false;

  const sBtn = $('#settingsBtn');
  const sMenu = $('#settingsMenu');
  if (sBtn && sMenu) {
    sBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      sMenu.hidden = !sMenu.hidden;
    });
    sMenu.addEventListener('click', (e) => {
      if (e.target.closest('[data-action], a')) sMenu.hidden = true;
    });
  }
}

function setHead(title, sub, actions = '') {
  $('#pageTitle').textContent = title;
  $('#pageSub').textContent = sub || '';
  $('#pageActions').innerHTML = actions;
}
