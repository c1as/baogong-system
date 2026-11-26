/* 模块: views/orders
   路由: #/orders #/orders/new #/orders/:id/edit
   职责: 订单列表、卡片、搜索与订单表单
   依赖: core/* ui/*
   导出: orderCardHtml viewOrders loadOrders renderOrders viewOrderForm
   注意: 已报量来自后端冗余字段 */
'use strict';

/* ==================================================================== 订单 */

function orderCardHtml(o) {
  const stat = orderReported(o);
  const remaining = orderRemaining(o);
  const pct = o.qty > 0 ? Math.min(100, Math.round((stat.qty / o.qty) * 100)) : 0;
  const title = o.partNo || '未关联零件';
  const subName = o.partName || '';
  return `<article class="order-card">
    <div class="order-head">
      <div class="order-main" style="min-width:0">
        <div class="order-title">${esc(title)}<span class="order-process">${esc(o.process || '未指定工序')}</span></div>
        <div class="order-sub">${subName ? esc(subName) + ' · ' : ''}${esc(o.no)} · ${esc(o.date)}</div>
      </div>
      <div class="order-qty"><small>数量</small>${fmtQty(o.qty)}</div>
      <div class="order-actions">
        <button class="icon-btn" data-act="order-edit" data-id="${o.id}" title="编辑">${icon('edit', 17)}</button>
        <button class="icon-btn danger" data-act="order-del" data-id="${o.id}" title="删除">${icon('trash', 17)}</button>
      </div>
    </div>
    <div class="order-progress"><div class="bar"><i style="width:${pct}%"></i></div><span>已报 ${fmtQty(stat.qty)} / ${fmtQty(o.qty)} · 剩余 ${fmtQty(remaining)}</span></div>
    <div class="order-foot">
      ${o.note ? `<span>备注：${esc(o.note)}</span>` : '<span></span>'}
      <span class="spacer"></span>
      <span>报工金额 <strong>${money(stat.amount)}</strong></span>
    </div>
  </article>`;
}

function viewOrders() {
  setHead(
    '订单记录',
    '',
    `<button class="btn btn-primary" data-action="new-order">${icon('plus', 16)}新建订单</button>`
  );

  orderStore = newStore();
  orderRange = resolveRange(orderRange);
  const view = $('#view');
  if (!state.counts.orders) {
    $('#pageSub').textContent = '订单可以在这里新建，也可以在报工时自动创建';
    view.innerHTML = emptyState(
      'package',
      '还没有订单',
      '新建订单：选零件、工序，填数量和日期，订单号会自动生成。报工时如果不选订单，系统也会自动建一张。',
      '新建订单',
      'new-order'
    );
    return;
  }

  view.innerHTML = `
    <div class="toolbar order-filter">
      ${rangeFilterHtml('ordRange', orderRange)}
      <div class="search">${icon('search', 16)}<input class="input" id="ordSearch" placeholder="搜索订单号 / 零件 / 工序" value="${esc(orderQuery)}" autocomplete="off"></div>
    </div>
    <div class="order-list" id="orderList"></div>`;

  loadOrders(true);
  setupRangeFilter($('#ordRange'), orderRange, (r) => {
    orderRange = r;
    loadOrders(true);
  });
  $('#ordSearch').addEventListener(
    'input',
    debounce((e) => {
      orderQuery = e.target.value;
      loadOrders(true);
    })
  );
}

async function loadOrders(reset) {
  const box = $('#orderList');
  if (!box) return;
  if (reset) {
    orderStore.items = [];
    orderStore.page = 0;
    orderStore.total = 0;
    orderStore.hasMore = false;
    box.innerHTML = '<div class="empty" style="padding:44px 20px"><p>加载中…</p></div>';
  }
  const my = ++orderStore.seq;
  try {
    const params = new URLSearchParams({
      page: String(orderStore.page + 1),
      pageSize: '100',
      q: orderQuery.trim(),
    });
    if (orderRange.from) params.set('from', orderRange.from);
    if (orderRange.to) params.set('to', orderRange.to);
    const data = await api('GET', 'api.php?action=orders.list&' + params.toString());
    if (my !== orderStore.seq) return;
    const list = data.items || [];
    orderStore.items = reset ? list : orderStore.items.concat(list);
    orderStore.total = Number(data.total) || 0;
    orderStore.page = Number(data.page) || 1;
    orderStore.hasMore = !!data.hasMore;
    renderOrders();
  } catch (err) {
    if (my !== orderStore.seq) return;
    if (err.status !== 401) toast(err.message, 'err');
    if (reset) {
      box.innerHTML = `<div class="empty" style="padding:44px 20px"><h3>读取订单失败</h3><p>${esc(
        err.message || '请稍后重试'
      )}</p></div>`;
    }
  }
}

function renderOrders() {
  const box = $('#orderList');
  if (!box) return;
  const list = orderStore.items;
  $('#pageSub').textContent = `显示 ${fmtQty(list.length)} / ${fmtQty(orderStore.total)} 张订单（共 ${fmtQty(
    state.counts.orders
  )} 张）`;
  if (!list.length) {
    box.innerHTML = `<div class="empty" style="padding:44px 20px"><h3>这个条件下没有订单</h3><p>换个日期范围，或清空搜索关键词。</p></div>`;
    return;
  }
  box.innerHTML =
    list.map(orderCardHtml).join('') +
    (orderStore.hasMore
      ? `<div class="load-more"><button class="btn btn-ghost" id="loadMoreOrders">加载更多（还有 ${fmtQty(
          orderStore.total - list.length
        )} 张）</button></div>`
      : '');
  const more = $('#loadMoreOrders');
  if (more) {
    more.addEventListener('click', () => {
      if (orderStore.hasMore) loadOrders(false);
    });
  }
}

async function viewOrderForm(id, presetPartId) {
  let order = null;
  if (id) {
    try {
      const data = await api('GET', 'api.php?action=orders.get&id=' + encodeURIComponent(id));
      order = data.order;
    } catch (err) {
      if (err.status === 401) return;
      order = null;
    }
  }
  if (id && !order) {
    setHead('订单不存在', '它可能已经被删除', `<a class="btn btn-ghost" href="#/orders">${icon('back', 16)}返回列表</a>`);
    $('#view').innerHTML = '';
    return;
  }

  let preset = presetPartId ? partCache.get(presetPartId) : null;
  if (presetPartId && !preset) {
    try {
      const data = await api('GET', 'api.php?action=parts.get&id=' + encodeURIComponent(presetPartId));
      preset = cachePart(data.part);
    } catch (err) {
      if (err.status === 401) return;
      preset = null;
    }
  }
  let currentPart = preset;
  if (order && order.partId) {
    currentPart = partCache.get(order.partId) || null;
    if (!currentPart) {
      try {
        const data = await api('GET', 'api.php?action=parts.get&id=' + encodeURIComponent(order.partId));
        currentPart = cachePart(data.part);
      } catch (err) {
        if (err.status === 401) return;
      }
    }
  }

  setHead(
    order ? '编辑订单' : '新建订单',
    order ? '修改订单信息和数量' : '订单号留空会自动生成',
    `<a class="btn btn-ghost" href="#/orders">${icon('back', 16)}返回列表</a>`
  );

  $('#view').innerHTML = `
    <form class="form-card" id="orderForm" novalidate>
      <div class="form-grid">
        <div class="field">
          <label>订单号</label>
          <input class="input" id="oNo" value="${esc(order ? order.no : '')}" placeholder="留空自动生成" autocomplete="off">
          <div class="hint">留空会生成 PO${todayStr().replace(/-/g, '')}-01 这样的单号</div>
        </div>
        <div class="field">
          <label>日期<span class="req">*</span></label>
          <input class="input" id="oDate" type="date" value="${esc(order ? order.date : todayStr())}">
        </div>
        <div class="field span2">
          <label>零件<span class="req">*</span></label>
          <div id="orderPartCombo">${comboHtml(currentPart ? partLabel(currentPart) : '')}</div>
        </div>
        <div class="field">
          <label>工序</label>
          <select class="select" id="oProcess"></select>
        </div>
        <div class="field">
          <label>数量<span class="req">*</span></label>
          <input class="input num" id="oQty" type="number" min="0" step="any" inputmode="decimal" value="${esc(order ? order.qty : '')}" placeholder="0">
        </div>
        <div class="field span2">
          <label>备注</label>
          <input class="input" id="oNote" value="${esc(order && order.note ? order.note : '')}" placeholder="选填" autocomplete="off">
        </div>
      </div>
      <div class="form-actions">
        <a class="btn btn-ghost" href="#/orders">取消</a>
        <button class="btn btn-primary" type="submit" id="orderSave">保存订单</button>
      </div>
    </form>`;

  let picked = currentPart ? currentPart.id : '';
  const combo = $('#orderPartCombo .combo');

  function fillProcesses(part, selected) {
    const ps = partProcesses(part);
    const cur = selected || '';
    let html = '<option value="">未指定工序</option>';
    ps.forEach((x) => {
      html += `<option value="${esc(x.name)}" ${x.name === cur ? 'selected' : ''}>${esc(x.name)}（${money(x.price)}）</option>`;
    });
    if (cur && !ps.some((x) => x.name === cur)) {
      html += `<option value="${esc(cur)}" selected>${esc(cur)}</option>`;
    }
    $('#oProcess').innerHTML = html;
  }

  fillProcesses(
    currentPart,
    order ? order.process || '' : currentPart && partProcesses(currentPart).length ? partProcesses(currentPart)[0].name : ''
  );

  setupCombo(combo, (p) => {
    picked = p ? p.id : '';
    const ps = partProcesses(p);
    fillProcesses(p, ps.length ? ps[0].name : '');
  });

  $('#orderForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!picked) return toast('请选择订单零件', 'err');
    const qty = Number($('#oQty').value || 0);
    if (!(qty > 0)) return toast('请填写订单数量', 'err');
    const payload = {
      updatedAt: order ? order.updatedAt || '' : '',
      no: $('#oNo').value.trim(),
      date: $('#oDate').value,
      partId: picked,
      process: $('#oProcess').value,
      qty,
      note: $('#oNote').value.trim(),
    };
    const btn = $('#orderSave');
    btn.disabled = true;
    btn.textContent = '保存中…';
    try {
      if (order) await api('POST', 'api.php?action=order.update&id=' + encodeURIComponent(order.id), payload);
      else await api('POST', 'api.php?action=order.create', payload);
      toast(order ? '订单已更新' : '订单已创建', 'ok');
      await refreshState();
      location.hash = '#/orders';
    } catch (err) {
      if (err.status !== 401) toast(err.message, 'err');
      btn.disabled = false;
      btn.textContent = '保存订单';
    }
  });
}
