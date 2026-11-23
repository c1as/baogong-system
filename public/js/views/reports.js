/* 模块: views/reports
   路由: #/reports
   职责: 报工单列表、卡片与分页
   依赖: core/* ui/*
   导出: reportCard viewReports loadReports renderReports
   注意: 明细汇总在卡片内展开 */
'use strict';

/* ==================================================================== 报工记录 */

function reportCard(r) {
  const total = round2((r.items || []).reduce((s, it) => s + (Number(it.amount) || 0), 0));
  const qty = round2((r.items || []).reduce((s, it) => s + (Number(it.qty) || 0), 0));
  const rows = (r.items || [])
    .map(
      (it) => `<tr>
        <td><div class="pname">${esc(it.partNo)}</div><div class="pno">${esc(it.partName || '')}${it.process ? `<span class="m-only">${it.partName ? ' · ' : ''}${esc(it.process)}</span>` : ''}${it.orderNo || r.orderNo ? `<span class="m-only">${it.partName || it.process ? ' · ' : ''}${esc(it.orderNo || r.orderNo)}</span>` : ''}</div></td>
        <td>${esc(it.process || '—')}</td>
        <td class="order-col">${esc(it.orderNo || r.orderNo || '—')}</td>
        <td class="num">${fmtQty(it.qty)}</td>
        <td class="num">${Number(it.price).toFixed(2)}</td>
        <td class="num">${Number(it.amount).toFixed(2)}</td>
      </tr>`
    )
    .join('');

  const imgs = (r.images || []).slice(0, 4);
  const imgHtml = imgs.length
    ? `<div class="report-imgs">${imgs
        .map(
          (f) =>
            `<a href="${esc(imgUrl(f))}" target="_blank" rel="noopener"><img src="${esc(imgUrl(f))}" alt="报工附件" loading="lazy"></a>`
        )
        .join('')}${(r.images || []).length > 4 ? `<span class="more">+${(r.images || []).length - 4}</span>` : ''}</div>`
    : '';

  return `<article class="report-card">
    <div class="rep-head">
      <div style="min-width:0">
        <div class="rep-no">${esc(r.no)}</div>
        <div class="rep-meta">
          <span>${esc(r.date)}</span><span class="dot"></span><span>${esc(r.worker)}</span>
          ${r.workshop ? `<span class="dot"></span><span>${esc(r.workshop)}</span>` : ''}
        </div>
      </div>
      <div class="rep-total"><small>¥</small>${total.toFixed(2)}</div>
      <div class="rep-actions">
        <button class="icon-btn" data-act="rep-edit" data-id="${r.id}" title="编辑">${icon('edit', 17)}</button>
        <button class="icon-btn danger" data-act="rep-del" data-id="${r.id}" title="删除">${icon('trash', 17)}</button>
      </div>
    </div>
    <table class="mini-table">
      <thead><tr><th>零件</th><th>工序</th><th>订单</th><th class="num">数量</th><th class="num">单价</th><th class="num">金额</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>
    ${imgHtml}
    <div class="rep-foot">
      ${r.note ? `<span>备注：${esc(r.note)}</span>` : `<span>共 ${(r.items || []).length} 项</span>`}
      <span class="spacer"></span>
      <span>数量 <strong>${fmtQty(qty)}</strong></span>
      <span>金额 <strong>¥${total.toFixed(2)}</strong></span>
    </div>
  </article>`;
}

function viewReports() {
  reportRange = resolveRange(reportRange);
  reportStore = newStore();
  setHead(
    '报工单记录',
    '',
    `<button class="btn btn-ghost" data-action="export-csv">${icon('download', 16)}导出 CSV</button>
     <button class="btn btn-primary" data-action="new-report">${icon('plus', 16)}新增报工</button>`
  );

  $('#view').innerHTML = `
    <div class="toolbar">
      ${rangeFilterHtml('repRange', reportRange)}
      <div class="search">${icon('search', 16)}<input class="input" id="repSearch" placeholder="搜索单号 / 人员 / 订单 / 零件" value="${esc(reportQuery)}" autocomplete="off"></div>
    </div>
    <div class="stats" id="stats"></div>
    <div class="report-list" id="reportList"></div>`;

  loadReports(true);
  setupRangeFilter($('#repRange'), reportRange, (r) => {
    reportRange = r;
    loadReports(true);
  });
  $('#repSearch').addEventListener(
    'input',
    debounce((e) => {
      reportQuery = e.target.value;
      loadReports(true);
    })
  );
}

async function loadReports(reset) {
  const box = $('#reportList');
  if (!box) return;
  if (reset) {
    reportStore.items = [];
    reportStore.page = 0;
    reportStore.total = 0;
    reportStore.hasMore = false;
    box.innerHTML = '<div class="empty" style="padding:44px 20px"><p>加载中…</p></div>';
  }
  const my = ++reportStore.seq;
  try {
    const params = new URLSearchParams({
      page: String(reportStore.page + 1),
      pageSize: '50',
      q: reportQuery.trim(),
    });
    if (reportRange.from) params.set('from', reportRange.from);
    if (reportRange.to) params.set('to', reportRange.to);
    const data = await api('GET', 'api.php?action=reports.list&' + params.toString());
    if (my !== reportStore.seq) return;
    const list = data.items || [];
    reportStore.items = reset ? list : reportStore.items.concat(list);
    reportStore.total = Number(data.total) || 0;
    reportStore.page = Number(data.page) || 1;
    reportStore.hasMore = !!data.hasMore;
    reportStore.summary = data.summary || { count: reportStore.total, qty: 0, amount: 0 };
    renderReports();
  } catch (err) {
    if (my !== reportStore.seq) return;
    if (err.status !== 401) toast(err.message, 'err');
    if (reset) {
      box.innerHTML = `<div class="empty" style="padding:44px 20px"><h3>读取报工记录失败</h3><p>${esc(
        err.message || '请稍后重试'
      )}</p></div>`;
    }
  }
}

function renderReports() {
  const box = $('#reportList');
  if (!box) return;
  const list = reportStore.items;
  const sum = reportStore.summary || { count: 0, qty: 0, amount: 0 };
  $('#pageSub').textContent = `${fmtQty(sum.count)} 张单 · 数量 ${fmtQty(sum.qty)} 件 · 金额 ${money(sum.amount)}`;

  $('#stats').innerHTML = `
    <div class="stat"><div class="stat-k">报工单</div><div class="stat-v">${fmtQty(sum.count)}<small>张</small></div></div>
    <div class="stat"><div class="stat-k">数量合计</div><div class="stat-v">${fmtQty(sum.qty)}<small>件</small></div></div>
    <div class="stat"><div class="stat-k">金额合计</div><div class="stat-v">${money(sum.amount)}</div></div>`;

  if (!list.length) {
    box.innerHTML = state.counts.reports
      ? `<div class="empty" style="padding:44px 20px"><h3>这个条件下没有记录</h3><p>换个日期范围，或清空搜索关键词。</p></div>`
      : emptyState('clipboard', '还没有报工记录', '点击「新增报工」，选择零件、填写数量，一张报工单几秒钟就能录完。', '新增报工', 'new-report');
    return;
  }

  box.innerHTML =
    list.map(reportCard).join('') +
    (reportStore.hasMore
      ? `<div class="load-more"><button class="btn btn-ghost" id="loadMoreReports">加载更多（还有 ${fmtQty(
          reportStore.total - list.length
        )} 张）</button></div>`
      : '');

  const more = $('#loadMoreReports');
  if (more) {
    more.addEventListener('click', () => {
      if (reportStore.hasMore) loadReports(false);
    });
  }
}
