/* 模块: views/data-panel
   职责: 零件图片/图纸查看、零件/订单/报工删除、CSV 导出、退出登录
   依赖: core/* ui/* features/*
   导出: openPartImage openPartDrawings deletePart deleteReport deleteOrder exportCsv logout
   注意: 删除前必须二次确认 */
'use strict';

/* ==================================================================== 操作 */

function openPartImage(id) {
  const part = partCache.get(id) || partStore.items.find((p) => p.id === id);
  if (!part || !part.image) return;
  const { root, close } = openModal(`<h3>${esc(part.no)}${part.name ? ` · ${esc(part.name)}` : ''}</h3>
    <div class="part-zoom"><img src="${esc(imgUrl(part.image))}" alt="${esc(part.name || part.no)}"></div>
    <div class="modal-actions"><button class="btn btn-ghost" data-cancel>关闭</button></div>`);
  root.querySelector('[data-cancel]').addEventListener('click', () => close());
}

async function openPartDrawings(id) {
  let part = partCache.get(id) || null;
  if (!part) {
    try {
      const data = await api('GET', 'api.php?action=parts.get&id=' + encodeURIComponent(id));
      part = cachePart(data.part);
    } catch (err) {
      if (err.status !== 401) toast(err.message, 'err');
      return;
    }
  }
  const ds = Array.isArray(part.drawings) ? part.drawings.slice() : [];
  if (!ds.length) {
    toast('这个零件还没有图纸', 'err');
    return;
  }
  const { close } = openModal(`<h3>${esc(partLabel(part) || part.no)} 图纸（${ds.length}）</h3>
    <div class="drawing-modal-list">${ds
      .map((d, i) => {
        const thumb =
          d.type === 'pdf'
            ? '<span class="dthumb pdf">PDF</span>'
            : `<img class="dthumb" src="${esc(imgUrl(d.path))}" alt="">`;
        return `<div class="drawing-item">${thumb}<span class="dname">${esc(d.name)}</span>
          <span class="dtype">${d.type === 'pdf' ? 'PDF' : '图片'}</span>
          <button type="button" class="btn btn-ghost btn-sm" data-draw-view="${i}">查看</button>
          <button type="button" class="icon-btn danger" data-draw-remove="${i}" title="删除">${icon('trash', 15)}</button></div>`;
      })
      .join('')}</div>
    <div class="drawing-preview" id="drawingPreview" hidden></div>
    <div class="modal-actions"><button class="btn btn-ghost" data-cancel>关闭</button></div>`);

  const root = $('#modalRoot');
  root.querySelector('[data-cancel]').addEventListener('click', () => close());
  root.querySelectorAll('[data-draw-view]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const d = ds[Number(btn.dataset.drawView)];
      if (!d) return;
      if (d.type === 'pdf') {
        openPdfViewer(d.path, d.name);
        return;
      }
      const box = $('#drawingPreview');
      box.hidden = false;
      box.innerHTML = `<img src="${esc(imgUrl(d.path))}" alt="${esc(d.name)}"><div class="hint">${esc(d.name)}</div>`;
      box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  });
  root.querySelectorAll('[data-draw-remove]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const d = ds[Number(btn.dataset.drawRemove)];
      if (!d) return;
      const ok = await confirmDialog({
        title: '删除这张图纸？',
        message: `「${d.name}」会从服务器删除，无法恢复。`,
        okText: '删除图纸',
        danger: true,
      });
      if (!ok) return;
      try {
        const data = await api('POST', 'api.php?action=part.drawing.remove&id=' + encodeURIComponent(id), { path: d.path });
        cachePart(data.part);
        toast('图纸已删除', 'ok');
        close();
        await openPartDrawings(id);
        if (location.hash.indexOf('#/parts') === 0) route();
      } catch (err) {
        if (err.status !== 401) toast(err.message, 'err');
      }
    });
  });
}

async function deletePart(id) {
  let part;
  try {
    const data = await api('GET', 'api.php?action=parts.get&id=' + encodeURIComponent(id));
    part = data.part;
  } catch (err) {
    if (err.status !== 401) toast(err.message, 'err');
    return;
  }
  const u = part.usage || { times: 0, qty: 0 };
  const ok = await confirmDialog({
    title: '删除这个零件？',
    message: u.times
      ? `「${partName(part)}」已有 ${u.times} 条报工记录（共 ${fmtQty(u.qty)} 件）。删除零件不会删除这些记录，记录里仍保留当时的名称和单价。`
      : `「${partName(part)}」还没有报工记录，可以放心删除。`,
    okText: '删除零件',
    danger: true,
  });
  if (!ok) return;
  try {
    await api('POST', 'api.php?action=part.delete&id=' + encodeURIComponent(id));
    toast('零件已删除', 'ok');
    await refreshState();
    route();
  } catch (err) {
    if (err.status !== 401) toast(err.message, 'err');
  }
}

async function deleteReport(id) {
  let rep;
  try {
    const data = await api('GET', 'api.php?action=reports.get&id=' + encodeURIComponent(id));
    rep = data.report;
  } catch (err) {
    if (err.status !== 401) toast(err.message, 'err');
    return;
  }
  const ok = await confirmDialog({
    title: '删除这张报工单？',
    message: `${rep.no}（${rep.date}，${rep.worker}）将被删除，删除后无法恢复。`,
    okText: '删除',
    danger: true,
  });
  if (!ok) return;
  try {
    await api('POST', 'api.php?action=report.delete&id=' + encodeURIComponent(id));
    toast('报工单已删除', 'ok');
    await refreshState();
    route();
  } catch (err) {
    if (err.status !== 401) toast(err.message, 'err');
  }
}

async function deleteOrder(id) {
  let order;
  try {
    const data = await api('GET', 'api.php?action=orders.get&id=' + encodeURIComponent(id));
    order = data.order;
  } catch (err) {
    if (err.status !== 401) toast(err.message, 'err');
    return;
  }
  const stat = orderReported(order);
  const ok = await confirmDialog({
    title: '删除这张订单？',
    message:
      stat.qty > 0
        ? `订单 ${order.no} 已有报工记录（${fmtQty(stat.qty)} 件）。删除订单不会删除报工记录，统计里仍能看到这些报工。`
        : `订单 ${order.no} 还没有报工记录，可以放心删除。`,
    okText: '删除订单',
    danger: true,
  });
  if (!ok) return;
  try {
    await api('POST', 'api.php?action=order.delete&id=' + encodeURIComponent(id));
    toast('订单已删除', 'ok');
    await refreshState();
    route();
  } catch (err) {
    if (err.status !== 401) toast(err.message, 'err');
  }
}

function exportCsv() {
  const p = new URLSearchParams();
  if (reportRange.from) p.set('from', reportRange.from);
  if (reportRange.to) p.set('to', reportRange.to);
  if (reportQuery.trim()) p.set('q', reportQuery.trim());
  const a = document.createElement('a');
  a.href = 'api.php?action=export.csv' + (p.toString() ? '&' + p.toString() : '');
  a.download = '';
  document.body.appendChild(a);
  a.click();
  a.remove();
}

async function logout() {
  try {
    await api('POST', 'api.php?action=logout', {});
  } catch {}
  state.counts = { parts: 0, orders: 0, reports: 0, items: 0 };
  partCache.clear();
  showLogin();
}
