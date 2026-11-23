/* 模块: shell/boot
   职责: 登录页、致命错误页、启动流程与全局事件绑定
   依赖: 全部 core/shell/ui/features/views
   导出: showLogin hideLogin showFatal boot
   注意: 必须最后加载，文件末尾调用 boot() */
'use strict';

/* ==================================================================== 登录 / 启动 */

function showLogin() {
  $('#app').hidden = true;
  const login = $('#login');
  login.hidden = false;
  $('#loginMark').innerHTML = icon('box', 24);
  setTimeout(() => $('#loginPassword').focus(), 50);
}

function hideLogin() {
  $('#login').hidden = true;
}

function showFatal(err) {
  $('#app').hidden = false;
  $('#app').innerHTML = `<div style="margin:auto;padding:40px;text-align:center;max-width:420px">
    <div class="empty-icon" style="margin:0 auto 16px">${icon('alert', 26)}</div>
    <h3>无法加载数据</h3>
    <p style="font-size:13px;color:#64748b;line-height:1.7">${esc(err.message || '请稍后重试')}</p>
    <button class="btn btn-primary" onclick="location.reload()">重新加载</button>
  </div>`;
}

async function boot() {
  try {
    await refreshState();
    await refreshPlugins();
    hideLogin();
    renderShell();
    if (!location.hash) history.replaceState(null, '', '#/parts');
    route();
  } catch (err) {
    if (err.status === 401) return;
    showFatal(err);
  }
}

/* -------------------------------------------------------------------- 全局事件 */

document.addEventListener('click', async (e) => {
  const actionEl = e.target.closest('[data-action]');
  if (actionEl) {
    const action = actionEl.dataset.action;
    if (action === 'backup') return openBackup();
    if (action === 'logout') return logout();
    if (action === 'new-part') {
      location.hash = '#/parts/new';
      return;
    }
    if (action === 'new-order') {
      location.hash = '#/orders/new';
      return;
    }
    if (action === 'new-report') {
      location.hash = '#/reports/new';
      return;
    }
    if (action === 'parts-export') return exportParts();
    if (action === 'parts-import') return openPartsImport();
    if (action === 'export-csv') return exportCsv();
    if (action === 'stats-export') return exportStats();
    if (action === 'reload-plugins') {
      try {
        await refreshPlugins();
      } catch (err) {
        if (err.status !== 401) toast(err.message, 'err');
        return;
      }
      if (location.hash === '#/settings') viewSettings();
      return;
    }
  }

  const actEl = e.target.closest('[data-act]');
  if (actEl) {
    const act = actEl.dataset.act;
    const id = actEl.dataset.id;
    if (act === 'part-edit') location.hash = `#/parts/${id}/edit`;
    else if (act === 'part-report') location.hash = `#/reports/new?part=${id}`;
    else if (act === 'part-image') openPartImage(id);
    else if (act === 'part-drawings') openPartDrawings(id);
    else if (act === 'part-del') deletePart(id);
    else if (act === 'order-edit') location.hash = `#/orders/${id}/edit`;
    else if (act === 'order-del') deleteOrder(id);
    else if (act === 'rep-edit') location.hash = `#/reports/${id}/edit`;
    else if (act === 'rep-del') deleteReport(id);
    return;
  }

  if (!e.target.closest('.combo')) closeAllCombos();
  if (!e.target.closest('.ms')) $$('.ms-panel').forEach((p) => (p.hidden = true));
  if (!e.target.closest('.settings-wrap')) {
    const menu = document.getElementById('settingsMenu');
    if (menu) menu.hidden = true;
  }
});

document.addEventListener('pointerdown', (e) => {
  if (!e.target.closest('.combo')) closeAllCombos();
  if (!e.target.closest('.ms')) $$('.ms-panel').forEach((p) => (p.hidden = true));
});

window.addEventListener('hashchange', () => {
  route();
  window.scrollTo({ top: 0 });
});

$('#loginForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = $('#loginBtn');
  const errBox = $('#loginErr');
  const password = $('#loginPassword').value;
  errBox.textContent = '';
  btn.disabled = true;
  btn.textContent = '登录中…';
  try {
    await api('POST', 'api.php?action=login', { password });
    await boot();
  } catch (err) {
    errBox.textContent = err.message === '未登录' ? '密码不正确' : err.message;
    $('#loginPassword').select();
  } finally {
    btn.disabled = false;
    btn.textContent = '进入系统';
  }
});

boot();
