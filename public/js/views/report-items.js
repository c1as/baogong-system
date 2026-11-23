/* 模块: views/report-items
   职责: 报工表单草稿状态、订单选项缓存、明细行渲染
   依赖: core/* ui/*
   导出: draft orderOptionCache amountOf newItem orderCacheKey loadOrderOptions orderOptionsOf processCellHtml orderCellHtml ocrBarHtml procDescHtml itemRowHtml
   注意: 仅被 report-form 使用，改动需同步明细表头 */
'use strict';

let draft = null;
let orderOptionCache = new Map();

function amountOf(it) {
  return round2((Number(it.qty) || 0) * (Number(it.price) || 0));
}

function newItem(part) {
  const ps = partProcesses(part);
  return {
    key: uid(),
    partId: part ? part.id : '',
    process: ps.length ? ps[0].name : '',
    qty: 1,
    price: ps.length ? ps[0].price : '',
    orderId: '',
  };
}

/* 订单下拉：按零件 + 工序远程查询，已报/剩余由服务端计算 */
function orderCacheKey(partId, process) {
  return `${draft && draft.id ? draft.id : ''}|${partId}|${process || ''}`;
}

async function loadOrderOptions(partId, process) {
  const key = orderCacheKey(partId, process);
  if (orderOptionCache.has(key)) return orderOptionCache.get(key);
  const params = new URLSearchParams({ partId, limit: '50' });
  if (process) params.set('process', process);
  if (draft && draft.id) params.set('excludeReportId', draft.id);
  let items = [];
  try {
    const data = await api('GET', 'api.php?action=orders.search&' + params.toString());
    items = data.items || [];
  } catch (err) {
    if (err.status !== 401) toast(err.message, 'err');
  }
  orderOptionCache.set(key, items);
  return items;
}

function orderOptionsOf(it) {
  return orderOptionCache.get(orderCacheKey(it.partId, it.process)) || [];
}

function processCellHtml(it, part) {
  const ps = partProcesses(part);
  if (!ps.length) {
    return `<input class="input" data-f="process" value="${esc(it.process || '')}" placeholder="工序" autocomplete="off">`;
  }
  const names = ps.map((x) => x.name);
  if (it.process && !names.includes(it.process)) names.unshift(it.process);
  return `<select class="select" data-f="process">${names
    .map((n) => {
      const p = ps.find((x) => x.name === n);
      const label = p ? `${n}（${money(p.price)}）` : n;
      return `<option value="${esc(n)}" ${n === it.process ? 'selected' : ''}>${esc(label)}</option>`;
    })
    .join('')}</select>`;
}

function orderCellHtml(it) {
  const orders = orderOptionsOf(it);
  const selected = orders.find((o) => o.id === it.orderId);
  let hint = '保存后自动建单';
  let warn = false;
  if (selected) {
    const rem = orderRemaining(selected);
    hint = `剩余 ${fmtQty(rem)}`;
    if (Number(it.qty) > rem) {
      hint += ' · 超出';
      warn = true;
    }
  } else if (it.orderId) {
    hint = '订单不可用，保存后自动建单';
    warn = true;
  }
  const opts = ['<option value="">自动新建</option>'].concat(
    orders.map((o) => {
      const rem = orderRemaining(o);
      return `<option value="${o.id}" ${o.id === it.orderId ? 'selected' : ''}>${esc(o.no)}（剩 ${fmtQty(rem)}）</option>`;
    })
  );
  if (it.orderId && !selected) {
    opts.push(`<option value="${esc(it.orderId)}" selected>${esc(it.orderId)}</option>`);
  }
  return `<div class="order-cell"><select class="select" data-f="order">${opts.join('')}</select><div class="order-hint ${
    warn ? 'warn' : ''
  }">${esc(hint)}</div></div>`;
}

function ocrBarHtml() {
  const list = ocrPlugins();
  const plugin = activeOcrPlugin();
  const engineCtl =
    list.length > 1
      ? `<select class="select ocr-engine" id="ocrEngineSel" title="切换识别引擎">${list
          .map(
            (p) =>
              `<option value="${esc(p.id)}" ${plugin && p.id === plugin.id ? 'selected' : ''}>${esc(p.name)}${
                isFusionPlugin(p) ? ' · 融合' : ''
              }</option>`
          )
          .join('')}</select>`
      : `<span>${plugin ? esc(plugin.name) : '——'}</span>`;
  return `<div class="ocr-bar">
      <button type="button" class="ocr-main" id="ocrBtn" ${plugin ? '' : 'disabled'}>
        <span class="ocr-ic">${icon('camera', 22)}</span>
        <span class="ocr-txt">
          <b>拍照识别报工单</b>
          <i>${plugin ? '自动填充日期、零件、工序、数量' : '未启用识别插件，请先在「识别设置」中启用'}</i>
        </span>
      </button>
      <div class="ocr-meta">
        ${engineCtl}
        <a class="ocr-link" href="#/settings">识别设置</a>
      </div>
    </div>
    <div class="ocr-result" id="ocrResult" hidden></div>`;
}

function procDescHtml(part) {
  if (!part) return '';
  const ps = partProcesses(part).filter((x) => String(x.desc || '').trim() !== '');
  if (!ps.length) return '';
  return `<div class="pdc">
    <button type="button" class="pdc-toggle">
      <span class="pdc-t-left">${icon('clipboard', 15)}<b>该零件工序说明</b><span class="pdc-hint">· ${ps.length} 道 · 点击展开</span></span>
      <span class="pdc-caret">▾</span>
    </button>
    <div class="pdc-panel">${ps
      .map(
        (x) => `<div class="pdc-row"><div class="pdc-proc">${esc(x.name)}</div><div class="pdc-desc">${esc(x.desc)}</div></div>`
      )
      .join('')}</div>
  </div>`;
}

function itemRowHtml(it, idx) {
  const part = partCache.get(it.partId) || null;
  const label = part ? partLabel(part) : '';
  const qty = it.qty === '' || it.qty === null || it.qty === undefined ? '' : it.qty;
  const price = it.price === '' || it.price === null || it.price === undefined ? '' : it.price;
  return `<div class="item-row" data-idx="${idx}">
    <div class="f f-part"><div class="f-label">零件</div>${comboHtml(label)}</div>
    <div class="f f-proc"><div class="f-label">工序</div>${processCellHtml(it, part)}</div>
    <div class="f"><div class="f-label">数量</div>
      <input class="input num" data-f="qty" type="number" inputmode="decimal" min="0" step="any" value="${esc(qty)}" placeholder="0">
    </div>
    <div class="f"><div class="f-label">单价（元）</div>
      <input class="input num" data-f="price" type="number" inputmode="decimal" min="0" step="0.01" value="${esc(price)}" placeholder="0.00">
    </div>
    <div class="f f-amount"><div class="f-label">金额</div><div class="amount-val">${money(amountOf(it))}</div></div>
    <div class="f f-order"><div class="f-label">订单</div>${orderCellHtml(it)}</div>
    <button type="button" class="icon-btn danger del" data-del title="删除本行">${icon('x', 16)}</button>
  </div>${procDescHtml(part)}`;
}
