/* 模块: views/stats
   路由: #/stats #/data
   职责: 报工统计、数据量统计与统计导出
   依赖: core/* ui/* features/csv
   导出: multiSelectHtml setupRemoteMultiSelect statsParams viewStats loadStats renderStats viewDataStats exportStats
   注意: 多选筛选器在移动端需可用 */
'use strict';

/* ==================================================================== 报工统计 */

function multiSelectHtml(id, placeholder) {
  return `<div class="ms" id="${id}">
    <button type="button" class="ms-btn"><span class="ms-label">${esc(placeholder)}</span><span class="ms-caret">▾</span></button>
    <div class="ms-panel" hidden>
      <div class="ms-search"><input class="input" placeholder="输入关键词搜索…" autocomplete="off"></div>
      <div class="ms-actions"><button type="button" data-none>清空</button><span class="ms-tip">输入即搜索，可多选</span></div>
      <div class="ms-list"></div>
    </div>
  </div>`;
}

/* 远程多选：只拉当前关键词的结果，已选项以标签保留 */
function setupRemoteMultiSelect(root, searchFn, selected, labels, onChange, placeholder) {
  const btn = root.querySelector('.ms-btn');
  const label = root.querySelector('.ms-label');
  const panel = root.querySelector('.ms-panel');
  const search = root.querySelector('.ms-search input');
  const list = root.querySelector('.ms-list');
  let seq = 0;

  function updateLabel() {
    const n = selected.size;
    label.textContent = n ? `已选 ${n} 项` : placeholder;
    btn.classList.toggle('has-value', n > 0);
  }

  function bindOptions(items) {
    list.querySelectorAll('input').forEach((cb) => {
      cb.addEventListener('change', () => {
        const hit = items.find((o) => o.value === cb.value);
        if (cb.checked) {
          selected.add(cb.value);
          if (hit) labels.set(cb.value, { label: hit.label, sub: hit.sub || '' });
        } else {
          selected.delete(cb.value);
        }
        updateLabel();
        onChange();
      });
    });
  }

  function renderSelectedOnly() {
    if (!selected.size) {
      list.innerHTML = '<div class="ms-empty">输入关键词搜索，或直接选择</div>';
      return;
    }
    const items = [...selected].map((id) => {
      const info = labels.get(id) || { label: id, sub: '' };
      return { value: id, label: info.label, sub: info.sub };
    });
    list.innerHTML = items
      .map(
        (o) => `<label class="ms-item"><input type="checkbox" value="${esc(o.value)}" checked><span class="ms-text"><b>${esc(
          o.label
        )}</b>${o.sub ? `<i>${esc(o.sub)}</i>` : ''}</span></label>`
      )
      .join('');
    bindOptions(items);
  }

  async function draw(keyword) {
    const my = ++seq;
    list.innerHTML = '<div class="ms-empty">搜索中…</div>';
    try {
      const res = await searchFn(keyword);
      if (my !== seq) return;
      const found = (res.items || []).map((o) => ({ value: o.value, label: o.label, sub: o.sub || '' }));
      const shownIds = new Set(found.map((o) => o.value));
      const extra = [...selected]
        .filter((id) => !shownIds.has(id))
        .map((id) => {
          const info = labels.get(id) || { label: id, sub: '' };
          return { value: id, label: info.label, sub: info.sub };
        });
      const items = extra.concat(found);
      if (!items.length) {
        list.innerHTML = '<div class="ms-empty">没有匹配项</div>';
        return;
      }
      list.innerHTML = items
        .map(
          (o) => `<label class="ms-item"><input type="checkbox" value="${esc(o.value)}" ${
            selected.has(o.value) ? 'checked' : ''
          }><span class="ms-text"><b>${esc(o.label)}</b>${o.sub ? `<i>${esc(o.sub)}</i>` : ''}</span></label>`
        )
        .join('');
      bindOptions(items);
    } catch (err) {
      if (my !== seq) return;
      list.innerHTML = `<div class="ms-empty">${esc(err.message || '搜索失败')}</div>`;
    }
  }

  btn.addEventListener('click', () => {
    const willOpen = panel.hidden;
    $$('.ms-panel').forEach((p) => (p.hidden = true));
    panel.hidden = !willOpen;
    if (willOpen) {
      search.value = '';
      draw('');
      setTimeout(() => search.focus(), 0);
    }
  });

  search.addEventListener(
    'input',
    debounce(() => draw(search.value))
  );

  root.querySelector('[data-none]').addEventListener('click', () => {
    selected.clear();
    renderSelectedOnly();
    updateLabel();
    onChange();
  });

  updateLabel();
}

function statsParams() {
  const params = new URLSearchParams();
  if (statRange.from) params.set('from', statRange.from);
  if (statRange.to) params.set('to', statRange.to);
  if (statQuery.trim()) params.set('q', statQuery.trim());
  if (statOrderIds.size) params.set('orderIds', [...statOrderIds].join(','));
  if (statPartIds.size) params.set('partIds', [...statPartIds].join(','));
  return params;
}

function viewStats() {
  setHead(
    '报工统计',
    '',
    `<button class="btn btn-ghost" data-action="stats-export">${icon('download', 16)}导出 CSV</button>`
  );

  statRange = resolveRange(statRange);
  statStore = newStore();

  $('#view').innerHTML = `
    <div class="toolbar stats-filter">
      ${rangeFilterHtml('stRange', statRange)}
      ${multiSelectHtml('msOrder', '全部订单')}
      ${multiSelectHtml('msPart', '全部零件')}
      <div class="search">${icon('search', 16)}<input class="input" id="stSearch" placeholder="搜索单号 / 人员 / 零件 / 工序" value="${esc(statQuery)}" autocomplete="off"></div>
    </div>
    <div class="stats" id="stCards"></div>
    <div class="table-card"><table class="mini-table stats-table" id="stTable"></table></div>
    <div id="stMore"></div>`;

  setupRemoteMultiSelect(
    $('#msOrder'),
    async (q) => {
      const params = new URLSearchParams({ limit: '100', q: String(q || '').trim() });
      const data = await api('GET', 'api.php?action=orders.search&' + params.toString());
      return {
        items: (data.items || []).map((o) => ({
          value: o.id,
          // 零件号作为主标题，订单号放到副标题，方便按零件查找
          label: o.partNo || o.no,
          sub: [
            o.partNo ? o.no : '',
            o.partName || '',
            o.process || '未指定工序',
            `数量 ${fmtQty(o.qty)}`,
          ]
            .filter(Boolean)
            .join(' · '),
        })),
      };
    },
    statOrderIds,
    statOrderLabels,
    () => loadStats(true),
    '全部订单'
  );

  setupRemoteMultiSelect(
    $('#msPart'),
    async (q) => {
      const params = new URLSearchParams({ limit: '50', q: String(q || '').trim() });
      const data = await api('GET', 'api.php?action=parts.search&' + params.toString());
      return {
        items: (data.items || []).map((p) => ({
          value: p.id,
          label: p.no,
          sub: `${p.name || ''}${
            partProcesses(p).length ? `${p.name ? ' · ' : ''}${partProcesses(p).map((x) => x.name).join(' / ')}` : ''
          }`,
        })),
      };
    },
    statPartIds,
    statPartLabels,
    () => loadStats(true),
    '全部零件'
  );

  setupRangeFilter($('#stRange'), statRange, (r) => {
    statRange = r;
    loadStats(true);
  });
  $('#stSearch').addEventListener(
    'input',
    debounce((e) => {
      statQuery = e.target.value;
      loadStats(true);
    })
  );

  loadStats(true);
}

async function loadStats(reset) {
  const table = $('#stTable');
  if (!table) return;
  if (reset) {
    statStore.items = [];
    statStore.page = 0;
    statStore.total = 0;
    statStore.hasMore = false;
    table.innerHTML = '<tbody><tr><td class="empty-cell">加载中…</td></tr></tbody>';
  }
  const my = ++statStore.seq;
  try {
    const params = statsParams();
    params.set('page', String(statStore.page + 1));
    params.set('pageSize', '100');
    const data = await api('GET', 'api.php?action=stats.list&' + params.toString());
    if (my !== statStore.seq) return;
    const list = data.items || [];
    statStore.items = reset ? list : statStore.items.concat(list);
    statStore.total = Number(data.total) || 0;
    statStore.page = Number(data.page) || 1;
    statStore.hasMore = !!data.hasMore;
    statStore.summary = data.summary || { rows: statStore.total, qty: 0, amount: 0 };
    renderStats();
  } catch (err) {
    if (my !== statStore.seq) return;
    if (err.status !== 401) toast(err.message, 'err');
    if (reset) {
      table.innerHTML = `<tbody><tr><td class="empty-cell">${esc(err.message || '读取统计失败')}</td></tr></tbody>`;
    }
  }
}

function renderStats() {
  const table = $('#stTable');
  if (!table) return;
  const rows = statStore.items;
  const sum = statStore.summary || { rows: 0, qty: 0, amount: 0 };
  $('#pageSub').textContent = `${fmtQty(sum.rows)} 条明细 · 数量 ${fmtQty(sum.qty)} 件 · 金额 ${money(sum.amount)}`;
  $('#stCards').innerHTML = `
    <div class="stat"><div class="stat-k">明细条数</div><div class="stat-v">${fmtQty(sum.rows)}<small>条</small></div></div>
    <div class="stat"><div class="stat-k">数量合计</div><div class="stat-v">${fmtQty(sum.qty)}<small>件</small></div></div>
    <div class="stat"><div class="stat-k">金额合计</div><div class="stat-v">${money(sum.amount)}</div></div>`;

  if (!rows.length) {
    table.innerHTML = `<tbody><tr><td class="empty-cell">没有符合条件的报工记录</td></tr></tbody>`;
    $('#stMore').innerHTML = '';
    return;
  }

  table.innerHTML = `<thead><tr>
      <th>日期</th><th>报工单</th><th>订单</th><th class="num">订单数量</th><th>零件</th><th>工序</th>
      <th class="num">数量</th><th class="num">单价</th><th class="num">金额</th><th>报工人</th>
    </tr></thead>
    <tbody>${rows
      .map(
        (x) => `<tr>
          <td class="nowrap">${esc(x.date)}</td>
          <td class="nowrap">${esc(x.reportNo)}</td>
          <td class="nowrap">${esc(x.orderNo || '—')}</td>
          <td class="num">${x.orderQty === '' ? '—' : fmtQty(x.orderQty)}</td>
          <td><div class="pname">${esc(x.partNo)}</div><div class="pno">${esc(x.partName || '')}</div></td>
          <td>${esc(x.process || '—')}</td>
          <td class="num">${fmtQty(x.qty)}</td>
          <td class="num">${Number(x.price).toFixed(2)}</td>
          <td class="num">${Number(x.amount).toFixed(2)}</td>
          <td>${esc(x.worker)}</td>
        </tr>`
      )
      .join('')}</tbody>`;

  $('#stMore').innerHTML = statStore.hasMore
    ? `<div class="load-more"><button class="btn btn-ghost" id="loadMoreStats">加载更多（还有 ${fmtQty(
        statStore.total - rows.length
      )} 条）</button></div>`
    : '';
  const more = $('#loadMoreStats');
  if (more) {
    more.addEventListener('click', () => {
      if (statStore.hasMore) loadStats(false);
    });
  }
}

async function viewDataStats() {
  setHead('数据量统计', '统计中…');
  $('#view').innerHTML = '<p class="hint">正在汇总数据…</p>';

  let overview;
  try {
    overview = await api('GET', 'api.php?action=stats.overview');
  } catch (err) {
    if (err.status === 401) return;
    $('#view').innerHTML = `<p class="hint">读取数据量失败：${esc(err.message || '请稍后重试')}</p>`;
    return;
  }
  if (location.hash !== '#/data') return;

  const counts = overview.counts || { parts: 0, orders: 0, reports: 0, items: 0 };
  const yearRows = overview.years || [];
  const firstDate = overview.firstDate || '';
  setHead('数据量统计', firstDate ? `数据从 ${firstDate} 开始` : '还没有报工数据');

  const yearTbody = yearRows.length
    ? yearRows
        .map(
          (e) => `<tr>
            <td>${esc(e.year)}</td>
            <td class="num">${fmtQty(e.reports)}</td>
            <td class="num">${fmtQty(e.items)}</td>
            <td class="num">${fmtQty(round2(e.qty))}</td>
            <td class="num">${money(e.amount)}</td>
          </tr>`
        )
        .join('')
    : `<tr><td colspan="5" class="empty-cell">还没有报工数据</td></tr>`;

  $('#view').innerHTML = `
    <div class="stats data-stats">
      <div class="stat"><div class="stat-k">零件</div><div class="stat-v">${fmtQty(counts.parts)}<small>个</small></div></div>
      <div class="stat"><div class="stat-k">订单</div><div class="stat-v">${fmtQty(counts.orders)}<small>张</small></div></div>
      <div class="stat"><div class="stat-k">报工单</div><div class="stat-v">${fmtQty(counts.reports)}<small>张</small></div></div>
      <div class="stat"><div class="stat-k">报工明细</div><div class="stat-v">${fmtQty(counts.items)}<small>条</small></div></div>
    </div>
    <div class="stats data-disk">
      <div class="stat"><div class="stat-k">数据文件</div><div class="stat-v" id="dsDb">—</div><div class="stat-sub" id="dsDbSub">统计中…</div></div>
      <div class="stat"><div class="stat-k">图片</div><div class="stat-v" id="dsImg">—</div><div class="stat-sub" id="dsImgSub">统计中…</div></div>
      <div class="stat"><div class="stat-k">备份</div><div class="stat-v" id="dsBackup">—</div><div class="stat-sub" id="dsBackupSub">统计中…</div></div>
      <div class="stat"><div class="stat-k">合计占用</div><div class="stat-v" id="dsTotal">—</div><div class="stat-sub" id="dsTotalSub">统计中…</div></div>
    </div>
    <div class="table-card">
      <table class="mini-table data-years">
        <thead><tr><th>年份</th><th class="num">报工单</th><th class="num">明细</th><th class="num">数量</th><th class="num">金额</th></tr></thead>
        <tbody>${yearTbody}</tbody>
      </table>
    </div>
    <p class="hint" id="dataHint">正在读取数据目录…</p>`;

  try {
    const s = await api('GET', 'api.php?action=data.stats');
    if (location.hash !== '#/data') return;
    const storageName = s.storage === 'sqlite' ? 'SQLite 数据库' : 'JSON 数据文件';
    $('#dsDb').textContent = fmtBytes(s.dbBytes);
    $('#dsDbSub').textContent = `${storageName} · ${s.dbFile || ''}`;
    $('#dsImg').textContent = `${fmtQty(s.images.count)} 张`;
    $('#dsImgSub').textContent = fmtBytes(s.images.bytes);
    $('#dsBackup').textContent = `${fmtQty(s.backups.count)} 份`;
    $('#dsBackupSub').textContent = fmtBytes(s.backups.bytes);
    $('#dsTotal').textContent = fmtBytes(s.totalBytes);
    $('#dsTotalSub').textContent = '数据 + 图片 + 备份';
    const big = Number(s.dbBytes) >= 8 * 1024 * 1024;
    $('#dataHint').innerHTML =
      `数据目录：<code>${esc(s.dataDir || '')}</code>。` +
      (big ? '数据文件已超过 8MB，建议按年导出归档旧数据。' : '当前数据量较小，继续正常使用没有问题。');
  } catch (err) {
    if (location.hash !== '#/data') return;
    $('#dataHint').textContent = '读取磁盘占用失败：' + (err.message || '请检查数据目录权限');
  }
}

function exportStats() {
  const params = statsParams();
  const a = document.createElement('a');
  a.href = 'api.php?action=export.stats' + (params.toString() ? '&' + params.toString() : '');
  a.download = '';
  document.body.appendChild(a);
  a.click();
  a.remove();
}
