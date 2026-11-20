'use strict';

/* ==================================================================== 基础工具 */

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

const esc = (s) =>
  String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[c]));

const money = (n) => '¥' + (Number(n) || 0).toFixed(2);
const round2 = (n) => Math.round((Number(n) || 0) * 100) / 100;

function fmtQty(n) {
  const v = Number(n) || 0;
  return Number.isInteger(v)
    ? v.toLocaleString('zh-CN')
    : v.toLocaleString('zh-CN', { maximumFractionDigits: 2 });
}

function fmtBytes(n) {
  const v = Number(n) || 0;
  if (v < 1024) return Math.round(v) + ' B';
  if (v < 1024 * 1024) return (v / 1024).toFixed(1) + ' KB';
  if (v < 1024 * 1024 * 1024) return (v / 1024 / 1024).toFixed(1) + ' MB';
  return (v / 1024 / 1024 / 1024).toFixed(2) + ' GB';
}

/* 输入防抖：搜索框连续输入时只在停顿后渲染一次 */
function debounce(fn, wait = 220) {
  let timer = 0;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), wait);
  };
}

function todayStr() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/* ==================================================================== 日期范围 */

const RANGE_PRESETS = [
  { key: 'month', label: '本月' },
  { key: 'week', label: '近一周' },
  { key: 'month1', label: '近一个月' },
  { key: 'month3', label: '近三个月' },
  { key: 'year1', label: '近一年' },
  { key: 'all', label: '全部' },
  { key: 'custom', label: '自定义' },
];

function dstr(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function monthStartOf(dateStr) {
  return String(dateStr || todayStr()).slice(0, 7) + '-01';
}

function monthEndOf(dateStr) {
  const s = String(dateStr || todayStr());
  return dstr(new Date(Number(s.slice(0, 4)), Number(s.slice(5, 7)), 0));
}

function shiftDate(base, months, days) {
  const d = new Date(base + 'T00:00:00');
  d.setMonth(d.getMonth() + months);
  d.setDate(d.getDate() + days);
  return dstr(d);
}

function rangePresetValue(key) {
  const today = todayStr();
  if (key === 'month') return { from: monthStartOf(today), to: monthEndOf(today) };
  if (key === 'week') return { from: shiftDate(today, 0, -6), to: today };
  if (key === 'month1') return { from: shiftDate(today, -1, 1), to: today };
  if (key === 'month3') return { from: shiftDate(today, -3, 1), to: today };
  if (key === 'year1') return { from: shiftDate(today, -12, 1), to: today };
  return { from: '', to: '' };
}

/* 预置范围每次打开页面都按今天重算，自定义范围保持原样 */
function resolveRange(range) {
  if (!range) return { key: 'all', from: '', to: '' };
  if (range.key === 'custom') return range;
  const v = rangePresetValue(range.key);
  return { key: range.key, from: v.from, to: v.to };
}

function rangeLabel(range) {
  if (range.key === 'custom') {
    if (!range.from && !range.to) return '自定义';
    return `${range.from || '不限'} ~ ${range.to || '不限'}`;
  }
  const hit = RANGE_PRESETS.find((x) => x.key === range.key);
  return hit ? hit.label : '全部';
}

function rangeFilterHtml(id, range) {
  return `<div class="range-pick" id="${id}">
      <button type="button" class="range-trigger"><span>${esc(rangeLabel(range))}</span><i>▾</i></button>
      <div class="range-panel" hidden>
        <div class="range-quick">
          ${RANGE_PRESETS.map(
            (p) => `<button type="button" data-key="${p.key}"${p.key === range.key ? ' class="on"' : ''}>${p.label}</button>`
          ).join('')}
        </div>
        <div class="range-custom"${range.key === 'custom' ? '' : ' hidden'}>
          <label>开始<input class="input" type="date" data-w="from" value="${esc(range.from || '')}"></label>
          <label>结束<input class="input" type="date" data-w="to" value="${esc(range.to || '')}"></label>
          <button type="button" class="btn btn-primary" data-w="ok">确定</button>
        </div>
      </div>
    </div>`;
}

function setupRangeFilter(el, range, onChange) {
  if (!el) return;
  const panel = $('.range-panel', el);
  const trigger = $('.range-trigger', el);
  const custom = $('.range-custom', el);
  const fromInput = $('[data-w="from"]', el);
  const toInput = $('[data-w="to"]', el);
  let state = { key: range.key, from: range.from, to: range.to };

  function paint() {
    $('span', trigger).textContent = rangeLabel(state);
    $$('.range-quick [data-key]', el).forEach((b) => b.classList.toggle('on', b.dataset.key === state.key));
    fromInput.value = state.from || '';
    toInput.value = state.to || '';
    custom.hidden = state.key !== 'custom';
  }

  paint();

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    const show = panel.hidden;
    $$('.range-panel').forEach((p) => (p.hidden = true));
    panel.hidden = !show;
  });
  panel.addEventListener('click', (e) => e.stopPropagation());

  $$('.range-quick [data-key]', el).forEach((btn) => {
    btn.addEventListener('click', () => {
      const key = btn.dataset.key;
      if (key === 'custom') {
        custom.hidden = false;
        if (!fromInput.value) fromInput.value = monthStartOf(todayStr());
        if (!toInput.value) toInput.value = todayStr();
        return;
      }
      const v = rangePresetValue(key);
      state = { key, from: v.from, to: v.to };
      paint();
      panel.hidden = true;
      onChange(state);
    });
  });

  $('[data-w="ok"]', el).addEventListener('click', () => {
    const from = fromInput.value;
    const to = toInput.value;
    if (from && to && from > to) return toast('开始日期不能晚于结束日期', 'err');
    state = { key: 'custom', from, to };
    paint();
    panel.hidden = true;
    onChange(state);
  });
}

document.addEventListener('click', () => {
  $$('.range-panel').forEach((p) => (p.hidden = true));
});

const uid = () => Math.random().toString(36).slice(2, 10);

function imgUrl(name) {
  if (!name) return '';
  if (name.startsWith('data:') || name.startsWith('/') || /^[a-z][a-z0-9+.-]*:/i.test(name)) return name;
  return (state.imageBase || '/img/') + encodeURIComponent(name);
}

const state = {
  parts: [],
  reports: [],
  orders: [],
  plugins: [],
  auth: false,
  imageBase: '/img/',
};

let partQuery = '';
let partLimit = 100;
let reportQuery = '';
let reportLimit = 50;
let reportRange = { key: 'month', from: '', to: '' };

let statRange = { key: 'month', from: '', to: '' };
let statOrderIds = new Set();
let statPartIds = new Set();
let statQuery = '';
let statLimit = 100;

/* ==================================================================== 图标 */

const PATHS = {
  box: '<path d="M21 8v8a2 2 0 0 1-1 1.73l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 16V8a2 2 0 0 1 1-1.73l7-4a2 2 0 0 1 2 0l7 4A2 2 0 0 1 21 8z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
  clipboard:
    '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6"/><path d="M9 16h6"/>',
  list: '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>',
  package:
    '<path d="M16.5 9.4 7.5 4.24"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5"/><path d="M12 22V12"/>',
  chart: '<path d="M3 3v18h18"/><path d="m7 15 4-4 4 4 5-6"/>',
  plusCircle: '<circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="M8 12h8"/>',
  plusSquare: '<rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8"/><path d="M8 12h8"/>',
  filePlus: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15h6"/>',
  plus: '<path d="M12 5v14"/><path d="M5 12h14"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  database:
    '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
  logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
  image:
    '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.6-3.6a2 2 0 0 0-2.8 0L6 20"/>',
  trash: '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
  edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
  x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
  back: '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
  upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 8 5-5 5 5"/><path d="M12 3v12"/>',
  camera:
    '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/><circle cx="12" cy="13" r="3"/>',
  alert: '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
  file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
  check: '<path d="M20 6 9 17l-5-5"/>',
  settings:
    '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1.08-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.52-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.08a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
};

const icon = (name, size = 18) =>
  `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${PATHS[name] || ''}</svg>`;

/* ==================================================================== 数据辅助 */

function partProcesses(p) {
  return p && Array.isArray(p.processes) ? p.processes : [];
}

function partName(p) {
  if (!p) return '';
  return p.name || p.no || '';
}

function partLabel(p) {
  if (!p) return '';
  return p.name ? `${p.no} · ${p.name}` : `${p.no}`;
}

function partPriceLabel(p) {
  const ps = partProcesses(p);
  if (!ps.length) return '未定价';
  if (ps.length === 1) return money(ps[0].price);
  return money(ps[0].price) + ' 起';
}

function reportOrderLabel(r) {
  return r.orderNo ? `订单 ${r.orderNo}` : '未关联订单';
}

let orderUsageCache = null;

function ensureOrderUsage() {
  if (orderUsageCache) return orderUsageCache;
  const map = new Map();
  for (const r of state.reports) {
    for (const it of r.items || []) {
      const oid = it.orderId || r.orderId;
      if (!oid) continue;
      const u = map.get(oid) || { qty: 0, amount: 0 };
      u.qty += Number(it.qty) || 0;
      u.amount += Number(it.amount) || 0;
      map.set(oid, u);
    }
  }
  orderUsageCache = map;
  return map;
}

function orderUsedQty(orderId, excludeReportId) {
  const base = ensureOrderUsage().get(orderId) || { qty: 0, amount: 0 };
  if (!excludeReportId) return { qty: round2(base.qty), amount: round2(base.amount) };

  let qty = 0;
  let amount = 0;
  const report = state.reports.find((r) => r.id === excludeReportId);
  if (report) {
    for (const it of report.items || []) {
      if ((it.orderId || report.orderId) !== orderId) continue;
      qty += Number(it.qty) || 0;
      amount += Number(it.amount) || 0;
    }
  }
  return { qty: round2(base.qty - qty), amount: round2(base.amount - amount) };
}

function orderRemaining(order, excludeReportId) {
  if (!order) return 0;
  return round2(Number(order.qty) - orderUsedQty(order.id, excludeReportId).qty);
}

function orderReported(order) {
  if (!order) return { qty: 0, amount: 0 };
  return orderUsedQty(order.id);
}

function matchingOrders(part, process) {
  if (!part) return [];
  return state.orders.filter((o) => {
    if (o.partId !== part.id) return false;
    if (o.process && process && o.process !== process) return false;
    return true;
  });
}

/* ==================================================================== 接口 */

async function api(method, url, body) {
  const opt = { method, headers: {}, credentials: 'same-origin' };
  if (body !== undefined) {
    opt.headers['Content-Type'] = 'application/json';
    opt.body = JSON.stringify(body);
  }
  let res;
  try {
    res = await fetch(url, opt);
  } catch {
    throw new Error('无法连接服务器，请确认服务已启动');
  }
  const text = await res.text();
  let data = null;
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {}
  }
  if (res.status === 401) {
    if (url !== 'api.php?action=login') showLogin();
    const err = new Error('未登录');
    err.status = 401;
    throw err;
  }
  if (!res.ok) {
    const err = new Error((data && data.error) || `请求失败（${res.status}）`);
    err.status = res.status;
    throw err;
  }
  return data;
}

async function refreshState() {
  const data = await api('GET', 'api.php?action=state');
  state.parts = data.parts || [];
  state.reports = data.reports || [];
  state.orders = data.orders || [];
  state.auth = !!data.auth;
  state.imageBase = data.imageBase || '/img/';
  orderUsageCache = null;
}

async function refreshPlugins() {
  try {
    const data = await api('GET', 'api.php?action=plugins.list');
    state.plugins = data.plugins || [];
  } catch (err) {
    if (err.status === 401) throw err;
    state.plugins = [];
  }
}

/* 已启用且支持报工识别（report.ocr）的插件 */
function ocrPlugins() {
  return state.plugins.filter((p) => p.enabled && (p.capabilities || []).includes('report.ocr'));
}

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
        <button class="side-btn" data-action="backup">${icon('database', 16)}数据备份</button>
        <a class="side-btn" href="#/settings">${icon('settings', 16)}识别插件设置</a>
        ${state.auth ? `<button class="side-btn" data-action="logout">${icon('logout', 16)}退出登录</button>` : ''}
        <div class="side-ver">v2.1 · 数据保存在服务器</div>
      </div>
    </aside>

    <main class="main">
      <header class="topbar">
        <div>
          <h1 id="pageTitle">零件列表</h1>
          <p id="pageSub"></p>
        </div>
        <div class="actions" id="pageActions"></div>
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
}

function setHead(title, sub, actions = '') {
  $('#pageTitle').textContent = title;
  $('#pageSub').textContent = sub || '';
  $('#pageActions').innerHTML = actions;
}

/* ==================================================================== 路由 */

function route() {
  const raw = location.hash.replace(/^#\/?/, '');
  const [pathPart, queryPart] = raw.split('?');
  const seg = pathPart.split('/').filter(Boolean);
  const params = new URLSearchParams(queryPart || '');
  const key = seg[0] || 'parts';

  let navKey = key;
  if (key === 'parts' && seg[1] === 'new') navKey = 'part-new';
  if (key === 'reports' && seg[1] === 'new') navKey = 'report-new';
  if (key === 'orders' && seg[1] === 'new') navKey = 'order-new';
  if (seg[2] === 'edit') navKey = key;

  $$('[data-nav]').forEach((n) => n.classList.toggle('active', n.dataset.nav === navKey));

  if (key === 'parts' && seg.length === 1) return viewParts();
  if (key === 'parts' && seg[1] === 'new') return viewPartForm(null);
  if (key === 'parts' && seg[2] === 'edit') return viewPartForm(seg[1]);

  if (key === 'orders' && seg.length === 1) return viewOrders();
  if (key === 'orders' && seg[1] === 'new') return viewOrderForm(null, params.get('part'));
  if (key === 'orders' && seg[2] === 'edit') return viewOrderForm(seg[1]);

  if (key === 'reports' && seg.length === 1) return viewReports();
  if (key === 'reports' && seg[1] === 'new') return viewReportForm(null, params.get('part'));
  if (key === 'reports' && seg[2] === 'edit') return viewReportForm(seg[1]);

  if (key === 'stats') return viewStats();
  if (key === 'data') return viewDataStats();
  if (key === 'settings') return viewSettings();

  location.replace('#/parts');
}

/* ==================================================================== 通用组件 */

function emptyState(ic, title, text, btnText, action) {
  return `<div class="empty">
    <div class="empty-icon">${icon(ic, 26)}</div>
    <h3>${esc(title)}</h3>
    <p>${esc(text)}</p>
    ${btnText ? `<button class="btn btn-primary" data-action="${action}">${icon('plus', 16)}${esc(btnText)}</button>` : ''}
  </div>`;
}

function toast(msg, type = '') {
  const wrap = $('#toastWrap');
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.textContent = msg;
  wrap.appendChild(el);
  setTimeout(() => {
    el.style.transition = 'opacity .25s, transform .25s';
    el.style.opacity = '0';
    el.style.transform = 'translateY(6px)';
    setTimeout(() => el.remove(), 260);
  }, 2600);
}

function openModal(html, onDismiss) {
  const root = $('#modalRoot');
  root.innerHTML = `<div class="modal-mask"><div class="modal" role="dialog" aria-modal="true">${html}</div></div>`;
  const mask = root.firstElementChild;
  let closed = false;
  const onKey = (e) => {
    if (e.key === 'Escape') dismiss();
  };
  function close() {
    if (closed) return;
    closed = true;
    root.innerHTML = '';
    document.removeEventListener('keydown', onKey);
  }
  function dismiss() {
    if (closed) return;
    close();
    if (onDismiss) onDismiss();
  }
  document.addEventListener('keydown', onKey);
  mask.addEventListener('mousedown', (e) => {
    if (e.target === mask) dismiss();
  });
  return { root, close };
}

function confirmDialog({ title, message, okText = '确定', cancelText = '取消', danger = false }) {
  return new Promise((resolve) => {
    let settled = false;
    const { root, close } = openModal(
      `<h3>${esc(title)}</h3>
       <p>${esc(message)}</p>
       <div class="modal-actions">
         <button class="btn btn-ghost" data-cancel>${esc(cancelText)}</button>
         <button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-ok>${esc(okText)}</button>
       </div>`,
      () => {
        if (!settled) {
          settled = true;
          resolve(false);
        }
      }
    );
    root.querySelector('[data-ok]').addEventListener('click', () => {
      settled = true;
      close();
      resolve(true);
    });
    root.querySelector('[data-cancel]').addEventListener('click', () => {
      settled = true;
      close();
      resolve(false);
    });
  });
}

/* -------------------------------------------------------------------- 下拉选择 */

function comboHtml(value) {
  return `<div class="combo">
      <input class="combo-input" value="${esc(value)}" placeholder="搜索图号或名称" autocomplete="off" spellcheck="false">
      <div class="combo-menu" hidden></div>
    </div>`;
}

function closeAllCombos() {
  $$('.combo-menu').forEach((m) => (m.hidden = true));
}

function setupCombo(combo, onPick, onInput) {
  const input = combo.querySelector('.combo-input');
  const menu = combo.querySelector('.combo-menu');
  let matches = [];
  let active = -1;

  function draw(filter) {
    const q = String(filter || '').trim().toLowerCase();
    matches = state.parts
      .filter((p) => !q || `${p.name || ''} ${p.no} ${p.material || ''}`.toLowerCase().includes(q))
      .slice(0, 60);

    if (!state.parts.length) {
      menu.innerHTML = `<div class="combo-empty">还没有零件，<a href="#/parts/new" style="color:var(--brand)">去新增零件 →</a></div>`;
    } else if (!matches.length) {
      menu.innerHTML = `<div class="combo-empty">没有匹配的零件</div>`;
    } else {
      menu.innerHTML = matches
        .map(
          (p, i) => `<button type="button" class="combo-item" data-i="${i}">
            <span class="ci-img">${p.image ? `<img src="${esc(imgUrl(p.image))}" alt="" loading="lazy">` : icon('image', 16)}</span>
            <span class="ci-main">${esc(p.no)}</span>
            <span class="ci-sub">${esc(p.name || '')}${partProcesses(p).length ? `${p.name ? ' · ' : ''}${partProcesses(p).map((x) => esc(x.name)).join(' / ')}` : ''}</span>
            <span class="ci-price">${partPriceLabel(p)}</span>
          </button>`
        )
        .join('');
    }
    menu.hidden = false;
    active = matches.length ? 0 : -1;
    highlight();
  }

  function highlight() {
    $$('.combo-item', menu).forEach((el, i) => el.classList.toggle('active', i === active));
    const cur = menu.querySelector('.combo-item.active');
    if (cur) cur.scrollIntoView({ block: 'nearest' });
  }

  function pick(p) {
    input.value = partLabel(p);
    onPick(p);
    menu.hidden = true;
  }

  input.addEventListener('focus', () => draw(''));
  input.addEventListener('input', () => {
    draw(input.value);
    onPick(null);
    if (onInput) onInput(input.value);
  });
  input.addEventListener('keydown', (e) => {
    if (menu.hidden) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (!matches.length) return;
      active = (active + (e.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length;
      highlight();
    } else if (e.key === 'Enter') {
      if (active >= 0 && matches[active]) {
        e.preventDefault();
        pick(matches[active]);
      }
    } else if (e.key === 'Escape' || e.key === 'Tab') {
      menu.hidden = true;
    }
  });
  menu.addEventListener('pointerdown', (e) => {
    const btn = e.target.closest('.combo-item');
    if (!btn) return;
    e.preventDefault();
    const p = matches[Number(btn.dataset.i)];
    if (p) pick(p);
  });
}

/* ==================================================================== CSV */

function csvCell(v) {
  const s = v === undefined || v === null ? '' : String(v);
  return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

function csvText(rows) {
  return '\uFEFF' + rows.map((r) => r.map(csvCell).join(',')).join('\r\n') + '\r\n';
}

function downloadText(filename, text) {
  const blob = new Blob([text], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 2000);
}

function parseCsv(text) {
  const src = String(text || '').replace(/^\uFEFF/, '');
  const rows = [];
  let row = [];
  let cell = '';
  let quoted = false;
  for (let i = 0; i < src.length; i += 1) {
    const c = src[i];
    if (quoted) {
      if (c === '"') {
        if (src[i + 1] === '"') {
          cell += '"';
          i += 1;
        } else {
          quoted = false;
        }
      } else {
        cell += c;
      }
    } else if (c === '"') {
      quoted = true;
    } else if (c === ',') {
      row.push(cell);
      cell = '';
    } else if (c === '\n') {
      row.push(cell);
      rows.push(row);
      row = [];
      cell = '';
    } else if (c !== '\r') {
      cell += c;
    }
  }
  if (cell !== '' || row.length) {
    row.push(cell);
    rows.push(row);
  }
  return rows.filter((r) => r.some((x) => String(x).trim() !== ''));
}

/* ==================================================================== 图片处理 */

async function compressImage(file) {
  if (!file.type.startsWith('image/')) throw new Error('请选择图片文件');
  const url = URL.createObjectURL(file);
  try {
    const img = await new Promise((resolve, reject) => {
      const im = new Image();
      im.onload = () => resolve(im);
      im.onerror = () => reject(new Error('图片读取失败，请换一张试试'));
      im.src = url;
    });
    const max = 1100;
    const scale = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
    const w = Math.max(1, Math.round(img.naturalWidth * scale));
    const h = Math.max(1, Math.round(img.naturalHeight * scale));
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, w, h);
    ctx.drawImage(img, 0, 0, w, h);
    return canvas.toDataURL('image/jpeg', 0.82);
  } finally {
    URL.revokeObjectURL(url);
  }
}

/* ==================================================================== 零件列表 */

function buildUsage() {
  const map = new Map();
  for (const r of state.reports) {
    for (const it of r.items || []) {
      const u = map.get(it.partId) || { qty: 0, times: 0 };
      u.qty += Number(it.qty) || 0;
      u.times += 1;
      map.set(it.partId, u);
    }
  }
  return map;
}

let usageCache = new Map();

function viewParts() {
  setHead(
    '零件列表',
    state.parts.length ? `共 ${state.parts.length} 个零件 · 每个零件可设置多道工序和单价` : '先添加零件，再开始报工',
    `<div class="search">${icon('search', 16)}<input class="input" id="partSearch" placeholder="搜索图号 / 名称 / 材料" value="${esc(partQuery)}" autocomplete="off"></div>
     <button class="btn btn-ghost" data-action="parts-import">${icon('upload', 16)}导入</button>
     <button class="btn btn-ghost" data-action="parts-export">${icon('download', 16)}导出</button>
     <button class="btn btn-primary" data-action="new-part">${icon('plus', 16)}新增零件</button>`
  );

  const view = $('#view');
  if (!state.parts.length) {
    view.innerHTML = emptyState(
      'box',
      '还没有零件',
      '先添加零件：图号必填，名称、材料、库存、工序单价都可以后补。之后报工只要选零件、填数量，金额自动算好。',
      '新增第一个零件',
      'new-part'
    );
    return;
  }

  usageCache = buildUsage();
  view.innerHTML = `<div class="part-list" id="partList"></div>`;
  renderPartRows();

  $('#partSearch').addEventListener(
    'input',
    debounce((e) => {
      partQuery = e.target.value;
      partLimit = 100;
      renderPartRows();
    })
  );
}

function renderPartRows() {
  const box = $('#partList');
  if (!box) return;
  const q = partQuery.trim().toLowerCase();
  const list = state.parts
    .filter((p) => !q || [p.no, p.name, p.material].some((v) => String(v || '').toLowerCase().includes(q)))
    .sort((a, b) => String(b.updatedAt || '').localeCompare(String(a.updatedAt || '')));

  if (!list.length) {
    box.innerHTML = `<div class="empty" style="padding:40px 20px">
      <h3>没有匹配的零件</h3><p>换个关键词试试，或新增零件。</p>
      <button class="btn btn-ghost" data-action="new-part">新增零件</button></div>`;
    return;
  }

  const shown = list.slice(0, partLimit);

  box.innerHTML = shown
    .map((p) => {
      const u = usageCache.get(p.id) || { qty: 0, times: 0 };
      const ps = partProcesses(p);
      const chips = ps.length
        ? ps
            .map(
              (x) =>
                `<span class="chip">${esc(x.name || '工序')}<b>${money(x.price)}</b></span>`
            )
            .join('')
        : '<span class="chip muted">未设置工序</span>';
      const metaBits = [];
      if (p.material) metaBits.push(esc(p.material));
      if (u.times) {
        metaBits.push(`<span class="meta-usage">${p.material ? ' · ' : ''}已报 ${fmtQty(u.qty)} 件</span>`);
      }
      const meta = metaBits.join('');
      return `<div class="part-row">
        <div class="part-thumb">${p.image ? `<img src="${esc(imgUrl(p.image))}" alt="" loading="lazy">` : icon('image', 22)}</div>
        <div class="part-main">
          <div class="part-name"><span class="nm">${esc(p.no)}</span>${p.name ? `<span class="name-tag">${esc(p.name)}</span>` : ''}</div>
          ${meta ? `<div class="part-meta">${meta}</div>` : ''}
          <div class="proc-chips">${chips}</div>
        </div>
        <div class="part-stock">
          <span>库存</span>
          <input class="stock-input" type="number" min="0" step="any" inputmode="decimal" value="${esc(p.stock || 0)}" data-stock="${p.id}" aria-label="库存数量">
        </div>
        <div class="part-actions">
          <button class="icon-btn" data-act="part-report" data-id="${p.id}" title="用这个零件报工">${icon('plusCircle', 18)}</button>
          <button class="icon-btn" data-act="part-edit" data-id="${p.id}" title="编辑">${icon('edit', 17)}</button>
          <button class="icon-btn danger" data-act="part-del" data-id="${p.id}" title="删除">${icon('trash', 17)}</button>
        </div>
      </div>`;
    })
    .join('') +
    (list.length > shown.length
      ? `<div class="load-more"><button class="btn btn-ghost" id="loadMoreParts">加载更多（还有 ${list.length - shown.length} 个）</button></div>`
      : '');

  const more = $('#loadMoreParts');
  if (more) {
    more.addEventListener('click', () => {
      partLimit += 100;
      renderPartRows();
    });
  }

  $$('.stock-input', box).forEach((input) => {
    input.addEventListener('change', () => saveStock(input));
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') input.blur();
    });
  });
}

async function saveStock(input) {
  const id = input.dataset.stock;
  const part = state.parts.find((p) => p.id === id);
  const value = Number(input.value);
  if (!Number.isFinite(value) || value < 0) {
    toast('库存数量必须是不小于 0 的数字', 'err');
    if (part) input.value = part.stock || 0;
    return;
  }
  try {
    const res = await api('POST', 'api.php?action=part.stock&id=' + encodeURIComponent(id), { stock: value });
    if (part) part.stock = res.part.stock;
    input.value = res.part.stock;
    toast('库存已更新', 'ok');
  } catch (err) {
    if (err.status !== 401) toast(err.message, 'err');
    if (part) input.value = part.stock || 0;
  }
}

/* ==================================================================== 零件表单 */

function viewPartForm(id) {
  const part = id ? state.parts.find((p) => p.id === id) : null;
  if (id && !part) {
    setHead('零件不存在', '它可能已经被删除', `<a class="btn btn-ghost" href="#/parts">${icon('back', 16)}返回列表</a>`);
    $('#view').innerHTML = '';
    return;
  }

  setHead(
    part ? '编辑零件' : '新增零件',
    part ? '修改不会影响已经保存的报工记录' : '只有零件图号是必填的，其他都可以后补',
    `<a class="btn btn-ghost" href="#/parts">${icon('back', 16)}返回列表</a>`
  );

  let cover = part ? part.image || '' : '';
  let changed = false;
  const processes = part && partProcesses(part).length
    ? partProcesses(part).map((x) => ({ name: x.name, price: x.price }))
    : [];
  if (!processes.length) processes.push({ name: '', price: '' });

  $('#view').innerHTML = `
    <form class="form-card" id="partForm" novalidate>
      <div class="cover">
        <div>
          <div class="uploader" id="uploader" title="点击上传图片">
            <div class="up-empty">${icon('camera', 26)}<strong>添加照片</strong>点击或拖入图片<br>自动压缩</div>
          </div>
          <div class="hint" style="text-align:center">支持 JPG / PNG，自动压缩</div>
        </div>
        <div class="form-grid">
          <div class="field">
            <label>零件图号<span class="req">*</span></label>
            <input class="input" id="fNo" value="${esc(part ? part.no : '')}" placeholder="如 TD90535R183" autocomplete="off">
          </div>
          <div class="field">
            <label>零件名称</label>
            <input class="input" id="fName" value="${esc(part && part.name ? part.name : '')}" placeholder="选填" autocomplete="off">
          </div>
          <div class="field">
            <label>材料</label>
            <input class="input" id="fMaterial" value="${esc(part && part.material ? part.material : '')}" placeholder="选填，如 6063T6" autocomplete="off">
          </div>
          <div class="field">
            <label>库存数量</label>
            <input class="input num" id="fStock" type="number" min="0" step="any" inputmode="decimal" value="${esc(part ? part.stock || 0 : 0)}">
          </div>
          <div class="field span2">
            <label>备注</label>
            <input class="input" id="fNote" value="${esc(part && part.note ? part.note : '')}" placeholder="选填" autocomplete="off">
          </div>
        </div>
      </div>

      <div class="proc-editor">
        <div class="proc-title">工序与单价 <span>同一零件可以有多道工序，每道工序单独定价</span></div>
        <div id="procList" class="proc-list"></div>
        <button type="button" class="add-row" id="addProc">${icon('plus', 16)}添加工序</button>
      </div>

      <div class="form-actions">
        <a class="btn btn-ghost" href="#/parts">取消</a>
        <button class="btn btn-primary" type="submit" id="partSave">保存零件</button>
      </div>
    </form>`;

  const procList = $('#procList');

  function renderProcRows() {
    procList.innerHTML = processes
      .map(
        (x, i) => `<div class="proc-row">
          <input class="input" data-proc="name" data-i="${i}" value="${esc(x.name)}" placeholder="工序名称，如 铣加工">
          <input class="input num" data-proc="price" data-i="${i}" type="number" min="0" step="0.01" inputmode="decimal" value="${x.price === '' ? '' : esc(x.price)}" placeholder="单价">
          <button type="button" class="icon-btn danger" data-proc-del="${i}" title="删除">${icon('x', 16)}</button>
        </div>`
      )
      .join('');
  }

  function bindProcs() {
    $$('[data-proc]', procList).forEach((el) => {
      el.addEventListener('input', () => {
        const i = Number(el.dataset.i);
        processes[i][el.dataset.proc] = el.value;
      });
    });
    $$('[data-proc-del]', procList).forEach((btn) => {
      btn.addEventListener('click', () => {
        processes.splice(Number(btn.dataset.procDel), 1);
        if (!processes.length) processes.push({ name: '', price: '' });
        renderProcRows();
        bindProcs();
      });
    });
  }

  renderProcRows();
  bindProcs();

  $('#addProc').addEventListener('click', () => {
    processes.push({ name: '', price: '' });
    renderProcRows();
    bindProcs();
    const rows = $$('.proc-row', procList);
    const last = rows[rows.length - 1];
    if (last) $('[data-proc="name"]', last).focus();
  });

  const uploader = $('#uploader');
  const fileInput = document.createElement('input');
  fileInput.type = 'file';
  fileInput.accept = 'image/*';
  fileInput.hidden = true;
  $('#partForm').appendChild(fileInput);

  function paintCover() {
    if (cover) {
      uploader.innerHTML = `<img src="${esc(imgUrl(cover))}" alt="零件照片"><button type="button" class="up-remove" title="移除照片">${icon('x', 16)}</button>`;
      $('.up-remove', uploader).addEventListener('click', (e) => {
        e.stopPropagation();
        cover = '';
        changed = true;
        paintCover();
      });
    } else {
      uploader.innerHTML = `<div class="up-empty">${icon('camera', 26)}<strong>添加照片</strong>点击或拖入图片<br>自动压缩</div>`;
    }
  }

  async function loadFile(file) {
    try {
      const dataUrl = await compressImage(file);
      if (dataUrl.length > 7 * 1024 * 1024) throw new Error('图片太大，请换一张小一些的');
      cover = dataUrl;
      changed = true;
      paintCover();
    } catch (err) {
      toast(err.message, 'err');
    }
  }

  paintCover();
  uploader.addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', () => {
    if (fileInput.files && fileInput.files[0]) loadFile(fileInput.files[0]);
    fileInput.value = '';
  });
  uploader.addEventListener('dragover', (e) => {
    e.preventDefault();
    uploader.classList.add('dragging');
  });
  uploader.addEventListener('dragleave', () => uploader.classList.remove('dragging'));
  uploader.addEventListener('drop', (e) => {
    e.preventDefault();
    uploader.classList.remove('dragging');
    if (e.dataTransfer.files && e.dataTransfer.files[0]) loadFile(e.dataTransfer.files[0]);
  });

  $('#partForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const no = $('#fNo').value.trim();
    if (!no) return toast('请填写零件图号', 'err');

    const payload = {
      no,
      name: $('#fName').value.trim(),
      material: $('#fMaterial').value.trim(),
      stock: Number($('#fStock').value || 0),
      note: $('#fNote').value.trim(),
      processes: processes
        .filter((x) => String(x.name).trim() !== '')
        .map((x) => ({ name: String(x.name).trim(), price: Number(x.price) || 0 })),
      image: changed ? cover || '' : undefined,
      removeImage: changed && !cover,
    };

    const btn = $('#partSave');
    btn.disabled = true;
    btn.textContent = '保存中…';
    try {
      if (part) await api('POST', 'api.php?action=part.update&id=' + encodeURIComponent(part.id), payload);
      else await api('POST', 'api.php?action=part.create', payload);
      toast(part ? '零件已更新' : '零件已添加', 'ok');
      await refreshState();
      location.hash = '#/parts';
    } catch (err) {
      if (err.status !== 401) toast(err.message, 'err');
      btn.disabled = false;
      btn.textContent = '保存零件';
    }
  });
}

/* -------------------------------------------------------------------- 零件导入导出 */

const PART_HEADERS = ['零件图号', '零件名称', '材料', '库存数量', '备注'];
function partHeaderRow() {
  const head = PART_HEADERS.slice();
  for (let i = 1; i <= 6; i += 1) head.push(`工序${i}`, `单价${i}`);
  return head;
}

function exportParts() {
  if (!state.parts.length) return toast('还没有零件可导出', 'err');
  const rows = [partHeaderRow()];
  for (const p of state.parts) {
    const ps = partProcesses(p);
    const row = [p.no, p.name || '', p.material || '', p.stock || 0, p.note || ''];
    for (let i = 0; i < 6; i += 1) {
      const x = ps[i];
      row.push(x ? x.name : '', x ? x.price : '');
    }
    rows.push(row);
  }
  downloadText(`零件列表_${todayStr()}.csv`, csvText(rows));
}

function downloadPartsTemplate() {
  const rows = [partHeaderRow()];
  rows.push(['示例-001', '示例零件', '6063T6', 10, '这一行可以删除', '铣加工', 4.6, '钻孔', 1.2, '', '', '', '', '', '', '', '']);
  downloadText('零件导入模板.csv', csvText(rows));
}

function openPartsImport() {
  const { root, close } = openModal(`
    <h3>导入零件</h3>
    <p>支持 CSV 文件（Excel 另存为「CSV UTF-8」）。按「零件图号」匹配：已有则更新，没有则新增。图片不会通过 CSV 导入。</p>
    <ul class="modal-list">
      <li><button type="button" id="tplBtn">${icon('download', 16)}下载导入模板</button></li>
      <li><button type="button" id="pickBtn">${icon('upload', 16)}选择 CSV 文件…</button></li>
    </ul>
    <input type="file" id="csvFile" accept=".csv,text/csv" hidden>
    <div class="hint" id="importHint"></div>
    <div class="modal-actions"><button class="btn btn-ghost" data-cancel>关闭</button></div>
  `);

  root.querySelector('[data-cancel]').addEventListener('click', close);
  root.querySelector('#tplBtn').addEventListener('click', downloadPartsTemplate);
  const fileInput = root.querySelector('#csvFile');
  const hint = root.querySelector('#importHint');
  root.querySelector('#pickBtn').addEventListener('click', () => fileInput.click());

  fileInput.addEventListener('change', async () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) return;
    hint.textContent = '正在导入…';
    try {
      const text = await file.text();
      const rows = parseCsv(text);
      if (rows.length < 2) throw new Error('文件里没有数据行');
      const head = rows[0].map((h) => String(h).trim());
      const idx = {};
      head.forEach((h, i) => {
        idx[h] = i;
      });
      const cell = (row, name) => (idx[name] === undefined ? '' : String(row[idx[name]] || '').trim());
      if (idx['零件图号'] === undefined) throw new Error('表头缺少「零件图号」列，请使用模板');

      const parts = [];
      for (let r = 1; r < rows.length; r += 1) {
        const row = rows[r];
        const no = cell(row, '零件图号');
        if (!no) continue;
        const processes = [];
        for (let n = 1; n <= 6; n += 1) {
          const name = cell(row, `工序${n}`);
          if (!name) continue;
          const price = cell(row, `单价${n}`);
          processes.push({ name, price: price === '' ? 0 : Number(price) });
        }
        parts.push({
          no,
          name: cell(row, '零件名称'),
          material: cell(row, '材料'),
          stock: Number(cell(row, '库存数量') || 0),
          note: cell(row, '备注'),
          processes,
        });
      }
      if (!parts.length) throw new Error('没有解析到有效的数据行');

      const res = await api('POST', 'api.php?action=part.import', { parts });
      await refreshState();
      hint.textContent = '';
      close();
      toast(`导入完成：新增 ${res.added} 个，更新 ${res.updated} 个`, 'ok');
      if (res.errors && res.errors.length) {
        openModal(`<h3>部分行未导入</h3><p>${esc(res.errors.slice(0, 10).join('；'))}</p>
          <div class="modal-actions"><button class="btn btn-primary" data-cancel>知道了</button></div>`);
        const m = $('#modalRoot');
        m.querySelector('[data-cancel]').addEventListener('click', () => (m.innerHTML = ''));
      }
      route();
    } catch (err) {
      hint.textContent = '';
      toast(err.message || '导入失败', 'err');
    } finally {
      fileInput.value = '';
    }
  });
}

/* ==================================================================== 订单 */

let orderRange = { key: 'all', from: '', to: '' };
let orderQuery = '';
let orderLimit = 100;

function filteredOrders() {
  let list = state.orders.slice();
  if (orderRange.from) list = list.filter((o) => String(o.date || '') >= orderRange.from);
  if (orderRange.to) list = list.filter((o) => String(o.date || '') <= orderRange.to);
  const q = orderQuery.trim().toLowerCase();
  if (q) {
    list = list.filter((o) =>
      [o.no, o.partNo, o.partName, o.process, o.note].some((v) => String(v || '').toLowerCase().includes(q))
    );
  }
  return list;
}

function orderCardHtml(o) {
  const stat = orderReported(o);
  const remaining = orderRemaining(o);
  const pct = o.qty > 0 ? Math.min(100, Math.round((stat.qty / o.qty) * 100)) : 0;
  const part = state.parts.find((p) => p.id === o.partId);
  const title = (part && part.no) || o.partNo || partName(part);
  const subName = (part && part.name) || o.partName || '';
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

  const view = $('#view');
  if (!state.orders.length) {
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

  orderRange = resolveRange(orderRange);
  view.innerHTML = `
    <div class="toolbar order-filter">
      ${rangeFilterHtml('ordRange', orderRange)}
      <div class="search">${icon('search', 16)}<input class="input" id="ordSearch" placeholder="搜索订单号 / 零件 / 工序" value="${esc(orderQuery)}" autocomplete="off"></div>
    </div>
    <div class="order-list" id="orderList"></div>`;

  function renderList() {
    const list = filteredOrders();
    const shown = list.slice(0, orderLimit);
    $('#pageSub').textContent = `显示 ${shown.length} / ${list.length} 张订单（共 ${state.orders.length} 张）`;
    if (!list.length) {
      $('#orderList').innerHTML = `<div class="empty" style="padding:44px 20px"><h3>这个条件下没有订单</h3><p>换个日期范围，或清空搜索关键词。</p></div>`;
      return;
    }
    $('#orderList').innerHTML =
      shown.map(orderCardHtml).join('') +
      (list.length > shown.length
        ? `<div class="load-more"><button class="btn btn-ghost" id="loadMoreOrders">加载更多（还有 ${list.length - shown.length} 张）</button></div>`
        : '');
    const more = $('#loadMoreOrders');
    if (more) {
      more.addEventListener('click', () => {
        orderLimit += 100;
        renderList();
      });
    }
  }

  renderList();
  setupRangeFilter($('#ordRange'), orderRange, (r) => {
    orderRange = r;
    orderLimit = 100;
    renderList();
  });
  $('#ordSearch').addEventListener(
    'input',
    debounce((e) => {
      orderQuery = e.target.value;
      orderLimit = 100;
      renderList();
    })
  );
}

function viewOrderForm(id, presetPartId) {
  const order = id ? state.orders.find((o) => o.id === id) : null;
  if (id && !order) {
    setHead('订单不存在', '它可能已经被删除', `<a class="btn btn-ghost" href="#/orders">${icon('back', 16)}返回列表</a>`);
    $('#view').innerHTML = '';
    return;
  }

  const preset = presetPartId ? state.parts.find((p) => p.id === presetPartId) : null;
  const currentPart = order ? state.parts.find((p) => p.id === order.partId) : preset;

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

/* ==================================================================== 报工记录 */

function filteredReports() {
  let list = state.reports.slice();
  if (reportRange.from) list = list.filter((r) => String(r.date || '') >= reportRange.from);
  if (reportRange.to) list = list.filter((r) => String(r.date || '') <= reportRange.to);
  const q = reportQuery.trim().toLowerCase();
  if (q) {
    list = list.filter(
      (r) =>
        String(r.no || '').toLowerCase().includes(q) ||
        String(r.worker || '').toLowerCase().includes(q) ||
        String(r.orderNo || '').toLowerCase().includes(q) ||
        (r.items || []).some(
          (it) =>
            String(it.partNo || '').toLowerCase().includes(q) ||
            String(it.partName || '').toLowerCase().includes(q) ||
            String(it.orderNo || '').toLowerCase().includes(q)
        )
    );
  }
  return list;
}

function reportTotals(list) {
  let qty = 0;
  let amount = 0;
  for (const r of list) {
    for (const it of r.items || []) {
      qty += Number(it.qty) || 0;
      amount += Number(it.amount) || 0;
    }
  }
  return { qty: round2(qty), amount: round2(amount) };
}

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

  renderReportContent();
  setupRangeFilter($('#repRange'), reportRange, (r) => {
    reportRange = r;
    reportLimit = 50;
    renderReportContent();
  });
  $('#repSearch').addEventListener(
    'input',
    debounce((e) => {
      reportQuery = e.target.value;
      reportLimit = 50;
      renderReportContent();
    })
  );
}

function renderReportContent() {
  const list = filteredReports();
  const t = reportTotals(list);
  $('#pageSub').textContent = `${list.length} 张单 · 数量 ${fmtQty(t.qty)} 件 · 金额 ${money(t.amount)}`;

  $('#stats').innerHTML = `
    <div class="stat"><div class="stat-k">报工单</div><div class="stat-v">${list.length}<small>张</small></div></div>
    <div class="stat"><div class="stat-k">数量合计</div><div class="stat-v">${fmtQty(t.qty)}<small>件</small></div></div>
    <div class="stat"><div class="stat-k">金额合计</div><div class="stat-v">${money(t.amount)}</div></div>`;

  const box = $('#reportList');
  if (!list.length) {
    box.innerHTML = state.reports.length
      ? `<div class="empty" style="padding:44px 20px"><h3>这个条件下没有记录</h3><p>换个日期范围，或清空搜索关键词。</p></div>`
      : emptyState('clipboard', '还没有报工记录', '点击「新增报工」，选择零件、填写数量，一张报工单几秒钟就能录完。', '新增报工', 'new-report');
    return;
  }

  const shown = list.slice(0, reportLimit);
  box.innerHTML =
    shown.map(reportCard).join('') +
    (list.length > shown.length
      ? `<div class="load-more"><button class="btn btn-ghost" id="loadMoreReports">加载更多（还有 ${list.length - shown.length} 张）</button></div>`
      : '');

  const more = $('#loadMoreReports');
  if (more) {
    more.addEventListener('click', () => {
      reportLimit += 50;
      renderReportContent();
    });
  }
}

/* ==================================================================== 报工表单 */

let draft = null;

function amountOf(it) {
  return round2((Number(it.qty) || 0) * (Number(it.price) || 0));
}

function newItem(partId) {
  const part = partId ? state.parts.find((p) => p.id === partId) : null;
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

function orderCellHtml(it, part) {
  const orders = matchingOrders(part, it.process);
  const selected = state.orders.find((o) => o.id === it.orderId);
  let hint = '保存后自动建单';
  if (selected) {
    const rem = orderRemaining(selected, draft ? draft.id : null);
    hint = `剩余 ${fmtQty(rem)}`;
    if (Number(it.qty) > rem) hint += ' · 超出';
  }
  const opts = ['<option value="">自动新建</option>'].concat(
    orders.map((o) => {
      const rem = orderRemaining(o, draft ? draft.id : null);
      return `<option value="${o.id}" ${o.id === it.orderId ? 'selected' : ''}>${esc(o.no)}（剩 ${fmtQty(rem)}）</option>`;
    })
  );
  return `<div class="order-cell"><select class="select" data-f="order">${opts.join('')}</select><div class="order-hint ${
    selected && Number(it.qty) > orderRemaining(selected, draft ? draft.id : null) ? 'warn' : ''
  }">${esc(hint)}</div></div>`;
}

function ocrBarHtml() {
  const plugin = ocrPlugins()[0] || null;
  return `<div class="ocr-bar">
      <button type="button" class="ocr-main" id="ocrBtn" ${plugin ? '' : 'disabled'}>
        <span class="ocr-ic">${icon('camera', 22)}</span>
        <span class="ocr-txt">
          <b>拍照识别报工单</b>
          <i>${plugin ? '自动填充日期、零件、工序、数量' : '未启用识别插件，请先在「识别设置」中启用'}</i>
        </span>
      </button>
      <div class="ocr-meta">
        <span>${plugin ? esc(plugin.name) : '——'}</span>
        <a class="ocr-link" href="#/settings">识别设置</a>
      </div>
    </div>
    <div class="ocr-result" id="ocrResult" hidden></div>`;
}

function itemRowHtml(it, idx) {
  const part = state.parts.find((p) => p.id === it.partId);
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
    <div class="f f-order"><div class="f-label">订单</div>${orderCellHtml(it, part)}</div>
    <button type="button" class="icon-btn danger del" data-del title="删除本行">${icon('x', 16)}</button>
  </div>`;
}

function viewReportForm(id, presetPartId) {
  const rep = id ? state.reports.find((r) => r.id === id) : null;
  if (id && !rep) {
    setHead('报工单不存在', '它可能已经被删除', `<a class="btn btn-ghost" href="#/reports">${icon('back', 16)}返回记录</a>`);
    $('#view').innerHTML = '';
    return;
  }

  draft = {
    id: rep ? rep.id : null,
    no: rep ? rep.no : '',
    date: rep ? rep.date : todayStr(),
    worker: rep ? rep.worker : localStorage.getItem('bg.worker') || '',
    workshop: rep ? rep.workshop : localStorage.getItem('bg.workshop') || '机加工车间',
    note: rep ? rep.note : '',
    items: rep
      ? (rep.items || []).map((it) => ({
          key: uid(),
          partId: it.partId,
          process: it.process || '',
          qty: it.qty,
          price: it.price,
          orderId: it.orderId || rep.orderId || '',
        }))
      : [],
    images: rep && Array.isArray(rep.images) ? rep.images.slice() : [],
    newImages: [],
  };
  if (!draft.items.length) draft.items.push(newItem(presetPartId));

  setHead(
    rep ? '编辑报工单' : '新增报工',
    rep ? '修改后金额会重新计算' : '每行可选不同订单；不选订单会在保存时按该行自动新建',
    `<a class="btn btn-ghost" href="#/reports">${icon('back', 16)}返回记录</a>`
  );

  $('#view').innerHTML = `
    <form class="form-card wide" id="reportForm" novalidate>
      ${ocrBarHtml()}
      <div class="form-grid">
        <div class="field">
          <label>报工单号</label>
          <input class="input" id="rNo" value="${esc(draft.no)}" placeholder="留空自动生成" autocomplete="off">
          <div class="hint">留空会按日期自动生成，如 BG${todayStr().replace(/-/g, '')}-01</div>
        </div>
        <div class="field">
          <label>日期<span class="req">*</span></label>
          <input class="input" id="rDate" type="date" value="${esc(draft.date)}">
        </div>
        <div class="field">
          <label>报工人<span class="req">*</span></label>
          <input class="input" id="rWorker" value="${esc(draft.worker)}" placeholder="姓名" autocomplete="off">
        </div>
        <div class="field">
          <label>车间</label>
          <input class="input" id="rWorkshop" value="${esc(draft.workshop)}" placeholder="如 机加工车间" autocomplete="off">
        </div>
        <div class="field span2">
          <label>备注</label>
          <input class="input" id="rNote" value="${esc(draft.note)}" placeholder="选填，如补差价说明" autocomplete="off">
        </div>
      </div>

      <div style="margin-top:22px">
        <div class="items-head">
          <span>零件</span><span>工序</span><span>数量</span><span>单价（元）</span><span style="text-align:right">金额</span><span>订单</span><span></span>
        </div>
        <div class="item-list" id="itemList"></div>
        <button type="button" class="add-row" id="addRow">${icon('plus', 16)}添加一行</button>

        <div class="sum-bar">
          <div class="sum-item">明细项<b id="sumCount">0</b></div>
          <div class="sum-item">数量合计<b id="sumQty">0</b></div>
          <div class="grow"></div>
          <div class="sum-item" style="text-align:right">金额合计<b id="sumAmount" style="color:var(--brand)">¥0.00</b></div>
        </div>
      </div>

      <div class="attach-box">
        <div class="attach-head">${icon('camera', 16)} 图片附件 <span>最多 6 张，自动压缩</span></div>
        <div class="attach-list" id="attachList"></div>
        <button type="button" class="add-row" id="addAttach">${icon('plus', 16)}添加图片</button>
      </div>

      <div class="form-actions">
        <a class="btn btn-ghost" href="#/reports">取消</a>
        <button class="btn btn-primary" type="submit" id="repSave">保存报工单</button>
      </div>
    </form>`;

  const itemList = $('#itemList');
  const attachList = $('#attachList');

  function updateTotals() {
    const qty = draft.items.reduce((s, it) => s + (Number(it.qty) || 0), 0);
    const amount = draft.items.reduce((s, it) => s + amountOf(it), 0);
    $('#sumCount').textContent = draft.items.length;
    $('#sumQty').textContent = fmtQty(qty);
    $('#sumAmount').textContent = money(amount);
  }

  function updateRow(row, it) {
    const amt = $('.amount-val', row);
    if (amt) amt.textContent = money(amountOf(it));
  }

  function renderAttachments() {
    const items = [];
    draft.images.forEach((f, i) => {
      items.push(`<div class="attach-thumb"><img src="${esc(imgUrl(f))}" alt=""><button type="button" class="rm" data-attach="old" data-i="${i}">${icon('x', 13)}</button></div>`);
    });
    draft.newImages.forEach((d, i) => {
      items.push(`<div class="attach-thumb"><img src="${esc(d)}" alt=""><button type="button" class="rm" data-attach="new" data-i="${i}">${icon('x', 13)}</button></div>`);
    });
    attachList.innerHTML = items.join('');
    $$('[data-attach]', attachList).forEach((btn) => {
      btn.addEventListener('click', () => {
        const i = Number(btn.dataset.i);
        if (btn.dataset.attach === 'old') draft.images.splice(i, 1);
        else draft.newImages.splice(i, 1);
        renderAttachments();
      });
    });
  }

  function renderItems() {
    draft.items.forEach((it) => {
      if (!it.orderId) return;
      const o = state.orders.find((x) => x.id === it.orderId);
      const part = state.parts.find((p) => p.id === it.partId);
      if (!o || !part || o.partId !== part.id || (o.process && it.process && o.process !== it.process)) {
        it.orderId = '';
      }
    });
    itemList.innerHTML = draft.items.map(itemRowHtml).join('');
    $$('.item-row', itemList).forEach((row, idx) => {
      const it = draft.items[idx];
      setupCombo($('.combo', row), (part) => {
        if (!part) {
          it.partId = '';
          return;
        }
        it.partId = part.id;
        const ps = partProcesses(part);
        it.process = ps.length ? ps[0].name : '';
        it.price = ps.length ? ps[0].price : '';
        it.orderId = '';
        renderItems();
        const fresh = $$('.item-row', itemList)[idx];
        if (fresh) {
          const qty = $('[data-f="qty"]', fresh);
          setTimeout(() => {
            qty.focus();
            qty.select();
          }, 0);
        }
      });

      row.addEventListener('input', (e) => {
        const f = e.target.dataset.f;
        if (!f) return;
        if (f === 'process') return;
        it[f] = e.target.value;
        updateRow(row, it);
        updateTotals();
        if (f === 'qty' && it.orderId) {
          const hint = $('.order-hint', row);
          const sel = state.orders.find((o) => o.id === it.orderId);
          if (hint && sel) {
            const rem = orderRemaining(sel, draft.id);
            const over = Number(it.qty) > rem;
            hint.textContent = `剩余 ${fmtQty(rem)}${over ? ' · 超出' : ''}`;
            hint.classList.toggle('warn', over);
          }
        }
      });

      row.addEventListener('change', (e) => {
        const f = e.target.dataset.f;
        if (f === 'process') {
          it.process = e.target.value;
          const part = state.parts.find((p) => p.id === it.partId);
          const ps = partProcesses(part);
          const hit = ps.find((x) => x.name === it.process);
          if (hit) {
            it.price = hit.price;
            $('[data-f="price"]', row).value = hit.price;
          }
          if (it.orderId) {
            const o = state.orders.find((x) => x.id === it.orderId);
            if (!o || (o.process && o.process !== it.process)) it.orderId = '';
          }
          updateRow(row, it);
          updateTotals();
          renderItems();
        } else if (f === 'order') {
          it.orderId = e.target.value;
          renderItems();
        }
      });

      $('[data-del]', row).addEventListener('click', () => {
        if (draft.items.length <= 1) return toast('至少保留一行', 'err');
        draft.items.splice(idx, 1);
        renderItems();
      });
    });
    updateTotals();
  }

  renderItems();
  renderAttachments();

  /* ---------------- 拍照识别（插件） ---------------- */

  const ocrPlugin = ocrPlugins()[0] || null;

  function normKey(v) {
    return String(v === undefined || v === null ? '' : v)
      .replace(/[－—–‑　]/g, '-')
      .replace(/\s+/g, '')
      .toUpperCase();
  }

  /* 去掉空格、短横线、点、斜杠等分隔符，90535 R183 与 90535-R183 视为同一图号 */
  function compactKey(v) {
    return normKey(v).replace(/[^0-9A-Z\u4e00-\u9fa5]/g, '');
  }

  function simKey(a, b) {
    if (!a.length || !b.length) return 0;
    const m = a.length;
    const n = b.length;
    let prev = Array.from({ length: n + 1 }, (_, i) => i);
    for (let i = 1; i <= m; i++) {
      const cur = [i];
      for (let j = 1; j <= n; j++) {
        cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
      }
      prev = cur;
    }
    return 1 - prev[n] / Math.max(m, n);
  }

  /* 去掉日期写法，避免 10.11 与图号粘成一条长串 */
  function stripDate(s) {
    return String(s)
      .replace(/20\d{2}\s*[-\/年.]\s*\d{1,2}\s*[-\/月.]\s*\d{1,2}\s*日?/g, ' ')
      .replace(/(?<!\d)\d{1,2}\s*[-\/.]\s*\d{1,2}(?!\d)\s*日?/g, ' ');
  }

  /* 在文本中按图号模糊匹配零件：精确 → 去分隔符 → 图号段 → 相似度（容忍 1-2 个 OCR 错字） */
  function fuzzyPart(text) {
    const bare = stripDate(text);
    const raw = normKey(bare);
    const flat = compactKey(bare);
    if (!raw) return null;
    /* 最长字母数字段：手写常省略图号前缀，90535 R183 → TD90535R183 */
    const runs = flat.match(/[0-9A-Z]{4,}/g) || [];
    let maxRun = 0;
    runs.forEach((r) => (maxRun = Math.max(maxRun, r.length)));
    const longRuns = runs.filter((r) => r.length === maxRun);
    let best = null;
    let bestScore = 0;
    for (const p of state.parts) {
      const no = normKey(p.no);
      const noC = compactKey(p.no);
      const nm = normKey(p.name);
      let score = 0;
      if (no && raw.includes(no)) score = 1;
      else if (noC.length >= 4 && flat.includes(noC)) score = 0.96;
      else {
        for (const r of longRuns) {
          if (r.length < 4) continue;
          /* 图号尾部可能粘上数量：523R30130 → 取前缀 523R301 */
          for (let l = Math.min(r.length, noC.length); l >= 4; l--) {
            const pre = r.slice(0, l);
            if (noC.includes(pre)) {
              score = Math.max(score, 0.95);
              break;
            }
            if (pre.includes(noC)) {
              score = Math.max(score, 0.93);
              break;
            }
          }
          /* 缺字 / 多字：30607R22 ≈ T306077R22 */
          if (score < 0.9) {
            const sim = simKey(r, noC);
            if (sim >= 0.72) score = Math.max(score, 0.8 + (sim - 0.72) * 0.5);
          }
        }
        if (!score && noC.length >= 5) {
          const len = noC.length;
          let seg = 0;
          for (let i = 0; i + len <= flat.length; i++) {
            seg = Math.max(seg, simKey(flat.slice(i, i + len), noC));
          }
          if (seg >= 0.75) score = 0.72 + (seg - 0.75) * 0.5;
        }
      }
      if (!score && nm.length >= 2 && raw.includes(nm)) score = 0.9;
      if (score > bestScore) {
        bestScore = score;
        best = p;
      }
    }
    return best ? { part: best, score: bestScore } : null;
  }

  function matchPart(rec) {
    const no = normKey(rec.partNo);
    const name = normKey(rec.partName);
    let hit = no ? state.parts.find((p) => normKey(p.no) === no) : null;
    if (!hit && no) hit = state.parts.find((p) => compactKey(p.no) === compactKey(no));
    if (!hit && name) hit = state.parts.find((p) => normKey(p.name) === name);
    if (!hit && no) hit = state.parts.find((p) => p.name && normKey(p.name) === no);
    if (!hit) {
      const fz = fuzzyPart([rec.partNo, rec.partName].filter(Boolean).join(' '));
      if (fz) hit = fz.part;
    }
    return hit || null;
  }

  /* 工序同义字典：铣加工 ↔ 铣，钻孔 ↔ 钻，车外圆 ↔ 车 … */
  const PROC_ALIAS = {
    车: ['车削', '车加工', '车外圆', '车内孔', '车工', '车'],
    铣: ['铣削', '铣加工', '铣工', '铣面', '铣槽', '铣', '锐加工', '锐'],
    钻: ['钻孔', '钻削', '钻工', '打孔', '钻'],
    磨: ['磨削', '磨工', '平磨', '外圆磨', '内圆磨', '磨'],
    钳: ['钳工', '去毛刺', '攻丝', '倒角', '钳'],
    镗: ['镗孔', '镗削', '镗'],
    插: ['插齿', '插'],
    滚: ['滚齿', '滚'],
    线: ['线切割', '电火花', '线'],
    热: ['热处理', '淬火', '调质', '发黑', '热'],
    焊: ['焊接', '焊工', '焊'],
  };

  function procBase(text) {
    const s = normKey(text);
    for (const base of Object.keys(PROC_ALIAS)) {
      for (const w of PROC_ALIAS[base]) {
        if (s.includes(w)) return base;
      }
    }
    /* 设置页「工序同义字典」里的自定义别名 */
    const extra = customProcAliases();
    for (const base of Object.keys(extra)) {
      if (s.includes(normKey(base))) return base;
      for (const w of extra[base]) {
        if (s.includes(normKey(w))) return base;
      }
    }
    return '';
  }

  function customProcAliases() {
    const p = state.plugins.find((x) => x.id === 'baidu-ocr');
    const f = p && (p.settings || []).find((x) => x.key === 'procAlias');
    const raw = f && f.value ? String(f.value) : '';
    const map = {};
    raw.split(/\r?\n/).forEach((ln) => {
      const line = ln.trim();
      if (!line || /^[#;]/.test(line)) return;
      const m = line.match(/^([^:：=]+)[:：=](.+)$/);
      if (!m) return;
      const base = m[1].trim();
      if (!base) return;
      map[base] = m[2].split(/[,，、\s]+/).filter(Boolean);
    });
    return map;
  }

  function matchProcessName(part, guess) {
    const ps = partProcesses(part);
    const g = normKey(guess);
    if (!g) return '';
    const exact = ps.find((x) => normKey(x.name) === g);
    if (exact) return exact.name;
    const base = procBase(g);
    if (base) {
      const hit = ps.find((x) => procBase(x.name) === base);
      if (hit) return hit.name;
    }
    return '';
  }

  function pickProcess(part, guess) {
    const ps = partProcesses(part);
    if (!ps.length) return { name: guess || '', price: '' };
    const name = matchProcessName(part, guess) || ps[0].name;
    const hit = ps.find((x) => x.name === name);
    return { name, price: hit ? hit.price : '' };
  }

  function applyRecognized(res) {
    const f = res.fields || {};
    let dateFilled = false;
    if (f.date) {
      $('#rDate').value = f.date;
      dateFilled = true;
    }

    const recs = Array.isArray(f.items) ? f.items : [];
    const notes = [];
    let filled = 0;
    if (recs.length) {
      if (draft.items.every((it) => !it.partId)) draft.items = [];
      for (const rec of recs) {
        const part = matchPart(rec);
        if (!part) {
          notes.push(`零件库中没有「${rec.partNo || rec.partName || '未识别'}」，该行未填充`);
          continue;
        }
        const p = pickProcess(part, rec.process);
        if (rec.process && !matchProcessName(part, rec.process) && partProcesses(part).length) {
          notes.push(`${part.no} 的工序「${rec.process}」不在工序表，已改用「${p.name}」`);
        }
        draft.items.push({
          key: uid(),
          partId: part.id,
          process: p.name,
          qty: Number(rec.qty) > 0 ? rec.qty : 1,
          price: p.price,
          orderId: '',
        });
        filled += 1;
      }
      if (!draft.items.length) draft.items.push(newItem());
      renderItems();
    }
    return { filled, dateFilled, notes };
  }

  /* ---------- 手动填充：点选识别原文 ---------- */

  /* 从一行原文里解析日期 / 零件 / 工序 / 数量，供手动填充使用 */
  function parseOcrLine(text) {
    const out = { date: '', part: null, partScore: 0, process: '', qty: 0 };
    const s = String(text || '');

    let m = s.match(/(20\d{2})\s*[-\/年.]\s*(\d{1,2})\s*[-\/月.]\s*(\d{1,2})/);
    if (m) {
      const dt = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
      if (dt.getMonth() === Number(m[2]) - 1) out.date = fmtDate(dt);
    } else {
      m = s.match(/(?<!\d)(\d{1,2})\s*[-\/.月]\s*(\d{1,2})(?!\d)\s*日?/);
      if (m) {
        const y = new Date().getFullYear();
        const dt = new Date(y, Number(m[1]) - 1, Number(m[2]));
        if (dt.getMonth() === Number(m[1]) - 1 && dt.getDate() === Number(m[2])) out.date = fmtDate(dt);
      }
    }

    const fz = fuzzyPart(s);
    if (fz) {
      out.part = fz.part;
      out.partScore = fz.score;
      const base = procBase(s);
      if (base) {
        const hit = partProcesses(fz.part).find((x) => procBase(x.name) === base);
        if (hit) out.process = hit.name;
      }
    }
    /* 数量解析前先去掉日期写法，避免把 2026-11-20 里的 20 当数量 */
    const qtyText = s
      .replace(/20\d{2}\s*[-\/年.]\s*\d{1,2}\s*[-\/月.]\s*\d{1,2}\s*日?/g, ' ')
      .replace(/(?<!\d)\d{1,2}\s*[-\/.]\s*\d{1,2}(?!\d)\s*日?/g, ' ');
    out.qty = parseQty(qtyText, out.part);
    return out;
  }

  function fmtDate(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  function parseQty(text, part) {
    const partC = part ? compactKey(part.no) : '';
    const list = [];
    const re = /(\d{1,6}(?:\.\d{1,2})?)\s*(件|个|只|根|套|支|片)?/g;
    let m;
    while ((m = re.exec(text))) {
      const v = Number(m[1]);
      const unit = m[2] || '';
      if (!(v > 0) || v > 100000) continue;
      if (!unit && partC && partC.includes(m[1].replace('.', ''))) continue;
      list.push({ v, unit });
    }
    for (let i = list.length - 1; i >= 0; i--) {
      if (list[i].unit) return list[i].v;
    }
    return list.length ? list[list.length - 1].v : 0;
  }

  function partPriceOf(part, processName) {
    const hit = partProcesses(part).find((x) => x.name === processName);
    return hit ? hit.price : '';
  }

  /* 把识别面板里选好的零件 / 工序 / 数量追加成一条报工明细 */
  function pushItemRow(part, processName, qty) {
    const row = {
      key: uid(),
      partId: part.id,
      process: processName || '',
      qty: Number(qty) > 0 ? Number(qty) : 1,
      price: partPriceOf(part, processName),
      orderId: '',
    };
    if (draft.items.length === 1 && !draft.items[0].partId) draft.items[0] = row;
    else draft.items.push(row);
    renderItems();
  }

  function renderOcrResult(res, info) {
    const box = $('#ocrResult');
    const conf = Math.round((Number(res.confidence) || 0) * 100);
    const warns = (res.warnings || []).concat(info.notes);
    const bits = [];
    if (info.filled) bits.push(`填充 ${info.filled} 行`);
    if (info.dateFilled) bits.push('已更新日期');
    if (!bits.length) bits.push('未填充字段');

    const rawLines = String(res.raw || '')
      .split('\n')
      .map((s) => s.trim())
      .filter(Boolean)
      .slice(0, 40);
    const lineHtml = rawLines
      .map((ln, i) => {
        const p = parseOcrLine(ln);
        const tags = [];
        if (p.date) tags.push(`<i class="tag-date">${esc(p.date.slice(5))}</i>`);
        if (p.part) tags.push(`<i class="tag-part">${esc(p.part.no)}</i>`);
        if (p.process) tags.push(`<i class="tag-proc">${esc(p.process)}</i>`);
        if (p.qty > 0) tags.push(`<i class="tag-qty">${fmtQty(p.qty)}</i>`);
        return `<button type="button" class="ocr-line" data-i="${i}">
            <span class="ln-text">${esc(ln)}</span>
            <span class="ln-tags">${tags.join('')}</span>
          </button>`;
      })
      .join('');

    box.innerHTML = `<div class="ocr-sum">
        <span class="ocr-ok">${icon('check', 15)}识别完成</span>
        <span>${esc((res.plugin && res.plugin.name) || res.engine || '识别插件')} · 置信度 ${conf}% · ${esc(bits.join(' · '))}</span>
        <button type="button" class="ocr-close" id="ocrDismiss" aria-label="收起">${icon('x', 14)}</button>
      </div>
      ${warns.length ? `<ul class="ocr-warn">${warns.map((w) => `<li>${esc(w)}</li>`).join('')}</ul>` : ''}
      ${
        lineHtml
          ? `<div class="ocr-raw">
              <div class="ocr-raw-head">识别原文 <span>点一行，在下方自由选择零件和工序</span></div>
              <div class="ocr-raw-list">${lineHtml}</div>
              <div class="ocr-fill" id="ocrFill" hidden>
                <div class="ocr-fill-top">
                  <span class="ocr-fill-label" id="ocrFillText"></span>
                  <button type="button" class="ocr-fill-x" id="ocrFillHide" aria-label="收起">${icon('x', 13)}</button>
                </div>
                <div class="ocr-fill-grid">
                  <div class="mf-field"><span>日期</span><input class="input" type="date" id="mfDate"></div>
                  <div class="mf-field"><span>数量</span><input class="input" type="number" id="mfQty" min="0" step="any" inputmode="decimal" placeholder="件数"></div>
                  <div class="mf-field mf-part"><span>零件（可搜索整个零件库）</span><div id="mfPart">${comboHtml('')}</div></div>
                  <div class="mf-field"><span>工序</span><select class="select" id="mfProc"></select></div>
                </div>
                <div class="ocr-fill-actions">
                  <button type="button" class="btn btn-primary" id="mfApply">${icon('plus', 15)}填入报工单</button>
                  <span class="hint">识别不出的字段可留空，零件直接搜索选择即可</span>
                </div>
              </div>
            </div>`
          : ''
      }`;
    box.hidden = false;
    $('#ocrDismiss', box).addEventListener('click', () => (box.hidden = true));

    const fillBar = $('#ocrFill', box);
    if (!fillBar) return;

    const cur = { parsed: null, part: null };
    const partCombo = $('#mfPart .combo', fillBar);
    const dateInput = $('#mfDate', fillBar);
    const qtyInput = $('#mfQty', fillBar);
    const procSel = $('#mfProc', fillBar);

    function refreshProc(guess) {
      const part = cur.part;
      if (!part) {
        procSel.innerHTML = '<option value="">先选择零件</option>';
        procSel.disabled = true;
        return;
      }
      procSel.disabled = false;
      const ps = partProcesses(part);
      procSel.innerHTML = ps.length
        ? ps
            .map((x) => `<option value="${esc(x.name)}">${esc(x.name)}${x.price ? ` · ${money(x.price)}` : ''}</option>`)
            .join('')
        : '<option value="">无工序</option>';
      const hit = matchProcessName(part, guess || '');
      if (hit) procSel.value = hit;
    }

    setupCombo(partCombo, (p) => {
      cur.part = p;
      if (p) refreshProc(cur.parsed && cur.parsed.process);
      else refreshProc('');
    });

    $$('.ocr-line', box).forEach((btn) => {
      btn.addEventListener('click', () => {
        const i = Number(btn.dataset.i);
        const text = rawLines[i] || '';
        const parsed = parseOcrLine(text);
        cur.parsed = parsed;
        cur.part = parsed.part;
        $$('.ocr-line', box).forEach((b) => b.classList.toggle('on', b === btn));
        $('#ocrFillText', fillBar).textContent = text;
        dateInput.value = parsed.date || '';
        qtyInput.value = parsed.qty > 0 ? parsed.qty : '';
        $('.combo-input', partCombo).value = parsed.part ? partLabel(parsed.part) : '';
        refreshProc(parsed.process);
        fillBar.hidden = false;
        fillBar.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      });
    });

    $('#ocrFillHide', fillBar).addEventListener('click', () => {
      fillBar.hidden = true;
      $$('.ocr-line', box).forEach((b) => b.classList.remove('on'));
    });

    $('#mfApply', fillBar).addEventListener('click', () => {
      const date = dateInput.value;
      const part = cur.part;
      const qty = Number(qtyInput.value) || 0;
      if (!date && !part) return toast('请先选择零件或填写日期', 'err');
      const desc = [];
      if (date) {
        draft.date = date;
        $('#rDate').value = date;
        desc.push(`日期 ${date}`);
      }
      if (part) {
        pushItemRow(part, procSel.value, qty);
        desc.push(`${part.no}${procSel.value ? ' · ' + procSel.value : ''}${qty > 0 ? ' · ' + fmtQty(qty) : ''}`);
      }
      const onLine = $('.ocr-line.on', box);
      if (onLine) onLine.classList.add('done');
      toast('已填入：' + desc.join('，'), 'ok');
    });
  }

  async function recognize(file) {
    const btn = $('#ocrBtn');
    const label = $('.ocr-txt b', btn);
    const origin = label.textContent;
    btn.disabled = true;
    btn.classList.add('busy');
    label.textContent = '识别中…';
    try {
      const image = await compressImage(file);
      const res = await api('POST', `api.php?action=plugin.invoke&id=${encodeURIComponent(ocrPlugin.id)}`, { image });
      const info = applyRecognized(res);
      renderOcrResult(res, info);
      toast(info.filled ? `识别完成，已填充 ${info.filled} 行` : '识别完成，请核对后手动补全', info.filled ? 'ok' : '');
    } catch (err) {
      if (err.status !== 401) toast(err.message, 'err');
    } finally {
      label.textContent = origin;
      btn.disabled = false;
      btn.classList.remove('busy');
    }
  }

  if (ocrPlugin) {
    const ocrInput = document.createElement('input');
    ocrInput.type = 'file';
    ocrInput.accept = 'image/*';
    ocrInput.hidden = true;
    $('#reportForm').appendChild(ocrInput);
    $('#ocrBtn').addEventListener('click', () => ocrInput.click());
    ocrInput.addEventListener('change', () => {
      const file = ocrInput.files && ocrInput.files[0];
      ocrInput.value = '';
      if (file) recognize(file);
    });
  }

  $('#addRow').addEventListener('click', () => {
    draft.items.push(newItem());
    renderItems();
    const rows = $$('.item-row', itemList);
    const last = rows[rows.length - 1];
    if (last) {
      last.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      $('.combo-input', last).focus();
    }
  });

  const attachInput = document.createElement('input');
  attachInput.type = 'file';
  attachInput.accept = 'image/*';
  attachInput.multiple = true;
  attachInput.hidden = true;
  $('#reportForm').appendChild(attachInput);
  $('#addAttach').addEventListener('click', () => attachInput.click());
  attachInput.addEventListener('change', async () => {
    const files = Array.from(attachInput.files || []);
    attachInput.value = '';
    for (const file of files) {
      if (draft.images.length + draft.newImages.length >= 6) {
        toast('最多 6 张附件', 'err');
        break;
      }
      try {
        const d = await compressImage(file);
        if (d.length > 7 * 1024 * 1024) throw new Error('图片太大');
        draft.newImages.push(d);
        renderAttachments();
      } catch (err) {
        toast(err.message, 'err');
      }
    }
  });

  $('#reportForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = {
      no: $('#rNo').value.trim(),
      date: $('#rDate').value,
      worker: $('#rWorker').value.trim(),
      workshop: $('#rWorkshop').value.trim(),
      note: $('#rNote').value.trim(),
      items: draft.items.map((it) => ({
        partId: it.partId,
        process: it.process,
        qty: Number(it.qty),
        price: Number(it.price),
        orderId: it.orderId || '',
      })),
      images: draft.images,
      newImages: draft.newImages,
    };

    if (!payload.date) return toast('请选择日期', 'err');
    if (!payload.worker) return toast('请填写报工人', 'err');
    const bad = draft.items.findIndex((it) => !it.partId || !(Number(it.qty) > 0));
    if (bad >= 0) return toast(`第 ${bad + 1} 行：请选择零件并填写数量`, 'err');
    if (draft.items.some((it) => it.price === '' || Number.isNaN(Number(it.price)))) {
      return toast('请填写单价', 'err');
    }

    const btn = $('#repSave');
    btn.disabled = true;
    btn.textContent = '保存中…';
    try {
      if (draft.id) await api('POST', 'api.php?action=report.update&id=' + encodeURIComponent(draft.id), payload);
      else await api('POST', 'api.php?action=report.create', payload);
      localStorage.setItem('bg.worker', payload.worker);
      if (payload.workshop) localStorage.setItem('bg.workshop', payload.workshop);
      toast(draft.id ? '报工单已更新' : '报工单已保存', 'ok');
      await refreshState();
      location.hash = '#/reports';
    } catch (err) {
      if (err.status !== 401) toast(err.message, 'err');
      btn.disabled = false;
      btn.textContent = '保存报工单';
    }
  });
}

/* -------------------------------------------------------------------- 快速建订单 */

function openQuickOrder(presetPartId, date) {
  return new Promise((resolve) => {
    const part = presetPartId ? state.parts.find((p) => p.id === presetPartId) : null;
    const { root, close } = openModal(
      `<h3>新建订单</h3>
       <p>订单号会自动生成，创建后自动选中。</p>
       <div class="field" style="margin-top:16px">
         <label>零件<span class="req">*</span></label>
         <div id="qPart">${comboHtml(part ? partLabel(part) : '')}</div>
       </div>
       <div class="form-grid" style="margin-top:14px">
         <div class="field"><label>数量<span class="req">*</span></label><input class="input num" id="qQty" type="number" min="0" step="any" inputmode="decimal" placeholder="0"></div>
         <div class="field"><label>日期</label><input class="input" id="qDate" type="date" value="${esc(date || todayStr())}"></div>
       </div>
       <div class="modal-actions">
         <button class="btn btn-ghost" data-cancel>取消</button>
         <button class="btn btn-primary" id="qSave">创建并选中</button>
       </div>`,
      () => resolve(null)
    );

    let picked = part ? part.id : '';
    setupCombo($('#qPart .combo', root), (p) => {
      picked = p ? p.id : '';
      if (p) {
        const q = $('#qQty', root);
        if (!q.value) q.focus();
      }
    });

    root.querySelector('[data-cancel]').addEventListener('click', () => {
      close();
      resolve(null);
    });

    root.querySelector('#qSave').addEventListener('click', async () => {
      const qty = Number($('#qQty', root).value || 0);
      if (!picked) return toast('请选择零件', 'err');
      if (!(qty > 0)) return toast('请填写数量', 'err');
      const btn = root.querySelector('#qSave');
      btn.disabled = true;
      btn.textContent = '创建中…';
      try {
        const res = await api('POST', 'api.php?action=order.create', {
          partId: picked,
          qty,
          date: $('#qDate', root).value || todayStr(),
        });
        close();
        resolve(res.order);
      } catch (err) {
        if (err.status !== 401) toast(err.message, 'err');
        btn.disabled = false;
        btn.textContent = '创建并选中';
      }
    });
  });
}

/* ==================================================================== 报工统计 */

function multiSelectHtml(id, placeholder) {
  return `<div class="ms" id="${id}">
    <button type="button" class="ms-btn"><span class="ms-label">${esc(placeholder)}</span><span class="ms-caret">▾</span></button>
    <div class="ms-panel" hidden>
      <div class="ms-search"><input class="input" placeholder="搜索…" autocomplete="off"></div>
      <div class="ms-actions"><button type="button" data-all>全选</button><button type="button" data-none>清空</button><span class="ms-tip">可多选</span></div>
      <div class="ms-list"></div>
    </div>
  </div>`;
}

function setupMultiSelect(root, options, selected, onChange, placeholder) {
  const btn = root.querySelector('.ms-btn');
  const label = root.querySelector('.ms-label');
  const panel = root.querySelector('.ms-panel');
  const search = root.querySelector('.ms-search input');
  const list = root.querySelector('.ms-list');
  let keyword = '';

  function updateLabel() {
    const n = selected.size;
    label.textContent = n ? `已选 ${n} 项` : placeholder;
    btn.classList.toggle('has-value', n > 0);
  }

  function renderList() {
    const q = keyword.trim().toLowerCase();
    const visible = options.filter((o) => !q || `${o.label} ${o.sub || ''}`.toLowerCase().includes(q));
    if (!visible.length) {
      list.innerHTML = '<div class="ms-empty">没有匹配项</div>';
      return;
    }
    list.innerHTML = visible
      .map(
        (o) => `<label class="ms-item"><input type="checkbox" value="${esc(o.value)}" ${
          selected.has(o.value) ? 'checked' : ''
        }><span class="ms-text"><b>${esc(o.label)}</b>${o.sub ? `<i>${esc(o.sub)}</i>` : ''}</span></label>`
      )
      .join('');
    list.querySelectorAll('input').forEach((cb) => {
      cb.addEventListener('change', () => {
        if (cb.checked) selected.add(cb.value);
        else selected.delete(cb.value);
        updateLabel();
        onChange();
      });
    });
  }

  btn.addEventListener('click', () => {
    const willOpen = panel.hidden;
    $$('.ms-panel').forEach((p) => (p.hidden = true));
    panel.hidden = !willOpen;
    if (willOpen) {
      keyword = '';
      search.value = '';
      renderList();
      setTimeout(() => search.focus(), 0);
    }
  });

  search.addEventListener('input', () => {
    keyword = search.value;
    renderList();
  });

  root.querySelector('[data-all]').addEventListener('click', () => {
    options.forEach((o) => selected.add(o.value));
    renderList();
    updateLabel();
    onChange();
  });

  root.querySelector('[data-none]').addEventListener('click', () => {
    selected.clear();
    renderList();
    updateLabel();
    onChange();
  });

  updateLabel();
  renderList();
}

function viewStats() {
  setHead(
    '报工统计',
    '',
    `<button class="btn btn-ghost" data-action="stats-export">${icon('download', 16)}导出 CSV</button>`
  );

  statRange = resolveRange(statRange);
  const validOrderIds = new Set(state.orders.map((o) => o.id));
  statOrderIds.forEach((id) => {
    if (!validOrderIds.has(id)) statOrderIds.delete(id);
  });
  const validPartIds = new Set(state.parts.map((p) => p.id));
  statPartIds.forEach((id) => {
    if (!validPartIds.has(id)) statPartIds.delete(id);
  });

  const orderOpts = state.orders.map((o) => {
    const p = state.parts.find((x) => x.id === o.partId);
    const partNameText = (p && p.name) || o.partName || '';
    return {
      value: o.id,
      label: o.no,
      sub: [o.partNo || (p && p.no) || '', partNameText, o.process || '未指定工序', `数量 ${fmtQty(o.qty)}`]
        .filter(Boolean)
        .join(' · '),
    };
  });
  const partOpts = state.parts.map((p) => ({
    value: p.id,
    label: p.no,
    sub: `${p.name || ''}${partProcesses(p).length ? `${p.name ? ' · ' : ''}${partProcesses(p).map((x) => x.name).join(' / ')}` : ''}`,
  }));

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

  function collect() {
    const rows = [];
    const q = statQuery.trim().toLowerCase();
    for (const r of state.reports) {
      if (statRange.from && String(r.date) < statRange.from) continue;
      if (statRange.to && String(r.date) > statRange.to) continue;
      for (const it of r.items || []) {
        const oid = it.orderId || r.orderId;
        if (statOrderIds.size && !statOrderIds.has(oid)) continue;
        if (statPartIds.size && !statPartIds.has(it.partId)) continue;
        if (q) {
          const hay = [r.no, r.worker, r.orderNo, it.orderNo, it.partNo, it.partName, it.process]
            .join(' ')
            .toLowerCase();
          if (!hay.includes(q)) continue;
        }
        rows.push({ report: r, item: it });
      }
    }
    rows.sort((a, b) => String(b.report.date).localeCompare(String(a.report.date)) || String(b.report.no).localeCompare(String(a.report.no)));
    return rows;
  }

  function render() {
    const rows = collect();
    const qty = round2(rows.reduce((s, x) => s + (Number(x.item.qty) || 0), 0));
    const amount = round2(rows.reduce((s, x) => s + (Number(x.item.amount) || 0), 0));
    $('#pageSub').textContent = `${rows.length} 条明细 · 数量 ${fmtQty(qty)} 件 · 金额 ${money(amount)}`;
    $('#stCards').innerHTML = `
      <div class="stat"><div class="stat-k">明细条数</div><div class="stat-v">${rows.length}<small>条</small></div></div>
      <div class="stat"><div class="stat-k">数量合计</div><div class="stat-v">${fmtQty(qty)}<small>件</small></div></div>
      <div class="stat"><div class="stat-k">金额合计</div><div class="stat-v">${money(amount)}</div></div>`;

    const table = $('#stTable');
    const moreBox = $('#stMore');
    if (!rows.length) {
      table.innerHTML = `<tbody><tr><td class="empty-cell">没有符合条件的报工记录</td></tr></tbody>`;
      if (moreBox) moreBox.innerHTML = '';
      return;
    }
    const shown = rows.slice(0, statLimit);
    table.innerHTML = `<thead><tr>
        <th>日期</th><th>报工单</th><th>订单</th><th class="num">订单数量</th><th>零件</th><th>工序</th>
        <th class="num">数量</th><th class="num">单价</th><th class="num">金额</th><th>报工人</th>
      </tr></thead>
      <tbody>${shown
        .map((x) => {
          const oid = x.item.orderId || x.report.orderId;
          const order = state.orders.find((o) => o.id === oid);
          const orderQty = order ? fmtQty(order.qty) : '—';
          return `<tr>
            <td class="nowrap">${esc(x.report.date)}</td>
            <td class="nowrap">${esc(x.report.no)}</td>
            <td class="nowrap">${esc(x.item.orderNo || x.report.orderNo || '—')}</td>
            <td class="num">${orderQty}</td>
            <td><div class="pname">${esc(x.item.partNo)}</div><div class="pno">${esc(x.item.partName || '')}</div></td>
            <td>${esc(x.item.process || '—')}</td>
            <td class="num">${fmtQty(x.item.qty)}</td>
            <td class="num">${Number(x.item.price).toFixed(2)}</td>
            <td class="num">${Number(x.item.amount).toFixed(2)}</td>
            <td>${esc(x.report.worker)}</td>
          </tr>`;
        })
        .join('')}</tbody>`;

    if (moreBox) {
      moreBox.innerHTML =
        rows.length > shown.length
          ? `<div class="load-more"><button class="btn btn-ghost" id="loadMoreStats">加载更多（还有 ${rows.length - shown.length} 条）</button></div>`
          : '';
      const more = $('#loadMoreStats');
      if (more) {
        more.addEventListener('click', () => {
          statLimit += 100;
          render();
        });
      }
    }
  }

  render();

  setupMultiSelect($('#msOrder'), orderOpts, statOrderIds, () => {
    statLimit = 100;
    render();
  }, '全部订单');
  setupMultiSelect($('#msPart'), partOpts, statPartIds, () => {
    statLimit = 100;
    render();
  }, '全部零件');

  setupRangeFilter($('#stRange'), statRange, (r) => {
    statRange = r;
    statLimit = 100;
    render();
  });
  $('#stSearch').addEventListener(
    'input',
    debounce((e) => {
      statQuery = e.target.value;
      statLimit = 100;
      render();
    })
  );

  window.__statsCollect = collect;
}

async function viewDataStats() {
  const itemsCount = state.reports.reduce((s, r) => s + (r.items || []).length, 0);
  const years = new Map();
  for (const r of state.reports) {
    const y = String(r.date || '').slice(0, 4) || '未知';
    const e = years.get(y) || { reports: 0, items: 0, qty: 0, amount: 0 };
    e.reports++;
    for (const it of r.items || []) {
      e.items++;
      e.qty += Number(it.qty) || 0;
      e.amount += Number(it.amount) || 0;
    }
    years.set(y, e);
  }
  const yearRows = [...years.entries()].sort((a, b) => b[0].localeCompare(a[0]));
  const firstDate = state.reports.reduce((min, r) => (r.date && (!min || r.date < min) ? r.date : min), '');

  setHead('数据量统计', firstDate ? `数据从 ${firstDate} 开始` : '还没有报工数据');

  const yearTbody = yearRows.length
    ? yearRows
        .map(
          ([y, e]) => `<tr>
            <td>${esc(y)}</td>
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
      <div class="stat"><div class="stat-k">零件</div><div class="stat-v">${fmtQty(state.parts.length)}<small>个</small></div></div>
      <div class="stat"><div class="stat-k">订单</div><div class="stat-v">${fmtQty(state.orders.length)}<small>张</small></div></div>
      <div class="stat"><div class="stat-k">报工单</div><div class="stat-v">${fmtQty(state.reports.length)}<small>张</small></div></div>
      <div class="stat"><div class="stat-k">报工明细</div><div class="stat-v">${fmtQty(itemsCount)}<small>条</small></div></div>
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
  const rows = window.__statsCollect ? window.__statsCollect() : [];
  if (!rows.length) return toast('没有可导出的数据', 'err');
  const out = [['日期', '报工单号', '订单号', '订单数量', '报工人', '零件图号', '零件名称', '工序', '数量', '单价(元)', '金额(元)']];
  for (const x of rows) {
    const oid = x.item.orderId || x.report.orderId;
    const order = state.orders.find((o) => o.id === oid);
    out.push([
      x.report.date,
      x.report.no,
      x.item.orderNo || x.report.orderNo || '',
      order ? order.qty : '',
      x.report.worker,
      x.item.partNo,
      x.item.partName,
      x.item.process,
      x.item.qty,
      x.item.price,
      x.item.amount,
    ]);
  }
  downloadText(`报工统计_${statRange.from || '全部'}_${statRange.to || '全部'}.csv`, csvText(out));
}

/* ==================================================================== 操作 */

async function deletePart(id) {
  const part = state.parts.find((p) => p.id === id);
  if (!part) return;
  const u = buildUsage().get(id) || { times: 0, qty: 0 };
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
  const rep = state.reports.find((r) => r.id === id);
  if (!rep) return;
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
  const order = state.orders.find((o) => o.id === id);
  if (!order) return;
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

const SNAP_REASON = { manual: '手动', auto: '自动', 'pre-restore': '恢复前' };

function snapTime(s) {
  const v = String(s || '');
  return v.length >= 16 ? v.slice(0, 16) : v;
}

function snapItemHtml(s) {
  const reason = SNAP_REASON[s.reason] || '手动';
  const bits = [`零件 ${fmtQty(s.parts || 0)}`, `订单 ${fmtQty(s.orders || 0)}`, `报工单 ${fmtQty(s.reports || 0)}`];
  return `<div class="snap-item" data-file="${esc(s.file)}">
      <div class="snap-main">
        <div class="snap-title">
          <b>${esc(snapTime(s.createdAt))}</b>
          <span class="snap-badge ${s.reason === 'auto' ? 'auto' : s.reason === 'pre-restore' ? 'pre' : ''}">${esc(reason)}</span>
          <span class="snap-size">${fmtBytes(s.bytes)}</span>
        </div>
        <div class="snap-sub">${esc(bits.join(' · '))}${s.note ? ' · ' + esc(s.note) : ''}</div>
      </div>
      <div class="snap-ops">
        <button type="button" data-op="restore">恢复</button>
        <a href="api.php?action=backup.download&id=${encodeURIComponent(s.file)}">下载</a>
        <button type="button" data-op="delete" class="danger">删除</button>
      </div>
    </div>`;
}

async function loadSnapshots(box) {
  const list = $('#snapList', box);
  const meta = $('#snapMeta', box);
  try {
    const data = await api('GET', 'api.php?action=backup.list');
    const items = data.snapshots || [];
    meta.textContent = `${fmtQty(items.length)} 份 · ${fmtBytes(data.totalBytes || 0)} · 自动快照保留 ${fmtQty(
      data.autoKeep || 20
    )} 份`;
    list.innerHTML = items.length
      ? items.map(snapItemHtml).join('')
      : `<div class="snap-empty">还没有快照，点上方按钮创建第一份。</div>`;
  } catch (err) {
    meta.textContent = '';
    list.innerHTML = `<div class="snap-empty">读取快照失败：${esc(err.message || '请检查服务器')}</div>`;
  }
}

function openBackup() {
  const { root, close } = openModal(`
    <h3>数据备份</h3>
    <p>快照会整份保存当前数据库，可随时恢复到当时的状态；每次恢复前还会自动再存一份。</p>
    <div class="snap-create">
      <input class="input" id="snapNote" maxlength="100" placeholder="快照备注（可选，如：月末结账）">
      <button type="button" class="btn btn-primary" id="snapCreate">${icon('plus', 15)}创建快照</button>
    </div>
    <div class="snap-head"><span>已有快照</span><span id="snapMeta">加载中…</span></div>
    <div class="snap-list" id="snapList"><div class="snap-empty">正在读取…</div></div>
    <details class="snap-more">
      <summary>导出 / 导入文件</summary>
      <ul class="modal-list">
        <li><a href="api.php?action=export.json">${icon('download', 16)}导出全部数据（JSON，可回导）</a></li>
        <li><a href="api.php?action=export.csv">${icon('file', 16)}导出报工明细（CSV，Excel 可打开）</a></li>
        <li><button type="button" id="restoreBtn">${icon('upload', 16)}从 JSON 备份恢复…</button></li>
      </ul>
      <input type="file" id="restoreFile" accept="application/json,.json" hidden>
    </details>
    <p class="hint">快照包含零件、订单、报工等全部数据；图片在 data/images/{parts,attachments} 里（零件图按零件名、附件按上传日期+序号命名），迁移服务器时请连同 data 文件夹一起复制。</p>
    <div class="modal-actions"><button class="btn btn-ghost" data-cancel>关闭</button></div>
  `);

  root.querySelector('[data-cancel]').addEventListener('click', close);
  loadSnapshots(root);

  const noteInput = root.querySelector('#snapNote');
  const createBtn = root.querySelector('#snapCreate');
  createBtn.addEventListener('click', async () => {
    createBtn.disabled = true;
    try {
      await api('POST', 'api.php?action=backup.create', { note: noteInput.value.trim() });
      noteInput.value = '';
      toast('快照已创建', 'ok');
      await loadSnapshots(root);
    } catch (err) {
      if (err.status !== 401) toast(err.message || '创建快照失败', 'err');
    } finally {
      createBtn.disabled = false;
    }
  });

  root.querySelector('#snapList').addEventListener('click', async (e) => {
    const item = e.target.closest('.snap-item');
    if (!item) return;
    const file = item.dataset.file;
    const op = e.target.closest('[data-op]');
    if (!op) return;
    if (op.dataset.op === 'restore') {
      const ok = await confirmDialog({
        title: '恢复到这份快照？',
        message: `将用 ${snapTime(item.querySelector('.snap-title b').textContent)} 的快照覆盖当前数据，恢复前会自动保存当前状态。`,
        okText: '恢复',
        danger: true,
      });
      if (!ok) {
        openBackup();
        return;
      }
      try {
        await api('POST', 'api.php?action=backup.restore', { id: file });
        toast('已恢复到所选快照', 'ok');
        await refreshState();
        route();
        openBackup();
      } catch (err) {
        openBackup();
        if (err.status !== 401) toast(err.message || '恢复失败', 'err');
      }
    } else if (op.dataset.op === 'delete') {
      const ok = await confirmDialog({
        title: '删除这份快照？',
        message: '删除后无法找回，当前数据不受影响。',
        okText: '删除',
        danger: true,
      });
      if (!ok) {
        openBackup();
        return;
      }
      try {
        await api('POST', 'api.php?action=backup.delete', { id: file });
        toast('快照已删除', 'ok');
        openBackup();
      } catch (err) {
        openBackup();
        if (err.status !== 401) toast(err.message || '删除失败', 'err');
      }
    }
  });

  const fileInput = root.querySelector('#restoreFile');
  root.querySelector('#restoreBtn').addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', async () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) return;
    let data;
    try {
      const text = await file.text();
      data = JSON.parse(text);
      if (!data || !Array.isArray(data.parts) || !Array.isArray(data.reports)) throw new Error('文件不是有效的备份');
    } catch (err) {
      fileInput.value = '';
      if (err.status !== 401) toast(err.message || '备份文件读取失败', 'err');
      return;
    }
    const ok = await confirmDialog({
      title: '确认恢复备份？',
      message: `将用备份中的 ${data.parts.length} 个零件、${data.reports.length} 张报工单覆盖当前全部数据，当前数据会先自动备份。`,
      okText: '覆盖恢复',
      danger: true,
    });
    if (!ok) {
      openBackup();
      return;
    }
    try {
      await api('POST', 'api.php?action=restore', { data });
      toast('备份已恢复', 'ok');
      await refreshState();
      route();
      openBackup();
    } catch (err) {
      openBackup();
      if (err.status !== 401) toast(err.message || '备份恢复失败', 'err');
    } finally {
      fileInput.value = '';
    }
  });
}

async function logout() {
  try {
    await api('POST', 'api.php?action=logout', {});
  } catch {}
  state.parts = [];
  state.reports = [];
  state.orders = [];
  showLogin();
}

/* ==================================================================== 识别插件设置 */

/* 1x1 透明 PNG，用于设置页的“测试识别” */
const OCR_TEST_IMAGE =
  'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

function pluginFieldHtml(f) {
  const val = f.value === undefined || f.value === null ? '' : String(f.value);
  if (f.type === 'checkbox') {
    return `<label class="plugin-check"><input type="checkbox" data-field="${esc(f.key)}" ${
      val && val !== '0' ? 'checked' : ''
    }><span>${esc(f.label)}</span></label>`;
  }
  let ctrl;
  if (f.type === 'select') {
    ctrl = `<select class="select" data-field="${esc(f.key)}">${(f.options || [])
      .map((o) => `<option value="${esc(o.value)}" ${String(o.value) === val ? 'selected' : ''}>${esc(o.label)}</option>`)
      .join('')}</select>`;
  } else if (f.type === 'textarea') {
    ctrl = `<textarea class="input plugin-textarea" rows="${Number(f.rows) || 4}" data-field="${esc(f.key)}" placeholder="${esc(
      f.placeholder || ''
    )}" spellcheck="false">${esc(val)}</textarea>`;
  } else {
    const type = f.type === 'number' ? 'number' : f.type === 'password' ? 'password' : 'text';
    const step = f.type === 'number' ? ' step="any"' : '';
    ctrl = `<input class="input" type="${type}"${step} data-field="${esc(f.key)}" value="${esc(val)}" placeholder="${esc(
      f.placeholder || ''
    )}" autocomplete="off" spellcheck="false">`;
  }
  return `<div class="field plugin-field"><label>${esc(f.label)}</label>${ctrl}${
    f.hint ? `<div class="hint">${esc(f.hint)}</div>` : ''
  }</div>`;
}

function pluginCardHtml(p) {
  return `<div class="plugin-card" data-plugin="${esc(p.id)}">
    <div class="plugin-head">
      <div class="plugin-title">
        <div class="plugin-name">${esc(p.name)}<span class="plugin-ver">v${esc(p.version)}</span></div>
        <p class="plugin-desc">${esc(p.description || '未提供说明')}</p>
      </div>
      <label class="switch" title="${p.enabled ? '点击停用' : '点击启用'}">
        <input type="checkbox" data-plugin-enabled ${p.enabled ? 'checked' : ''}>
        <span class="switch-track"></span>
      </label>
    </div>
    <div class="plugin-tags">
      ${(p.capabilities || []).map((c) => `<span class="chip">${esc(c)}</span>`).join('')}
      <span class="plugin-author">${esc(p.author || '未署名')}</span>
      <span class="plugin-state ${p.enabled ? 'on' : ''}">${p.enabled ? '已启用' : '已停用'}</span>
    </div>
    <div class="plugin-fields">${(p.settings || []).map(pluginFieldHtml).join('')}</div>
    <div class="plugin-check-box" hidden></div>
    <div class="plugin-actions">
      ${p.hasCheck ? `<button type="button" class="btn btn-ghost" data-plugin-check="${esc(p.id)}">API 链接测试</button>` : ''}
      <button type="button" class="btn btn-ghost" data-plugin-test="${esc(p.id)}">测试识别</button>
      <button type="button" class="btn btn-primary" data-plugin-save="${esc(p.id)}">保存设置</button>
    </div>
  </div>`;
}

function netCardHtml() {
  return `<div class="plugin-card net-card">
    <div class="plugin-head">
      <div class="plugin-title">
        <div class="plugin-name">互联网连接测试<span class="plugin-ver">服务器出网</span></div>
        <p class="plugin-desc">由服务器发起请求，检测能否访问百度首页与百度云 OCR 接口（含 HTTPS 证书校验）。</p>
      </div>
    </div>
    <div class="plugin-actions">
      <button type="button" class="btn btn-ghost" data-net-test>开始测试</button>
      <span class="net-sum" id="netSum"></span>
    </div>
    <div class="net-result" id="netResult" hidden></div>
  </div>`;
}

function netResultHtml(res) {
  const rows = (res.results || [])
    .map(
      (r) => `<div class="check-row ${r.ok ? 'ok' : 'bad'}">
        <i class="check-dot"></i>
        <b>${esc(r.name)}</b>
        <span>${esc(r.detail)} · ${Number(r.ms) || 0} ms</span>
        <em>${esc(r.url)}</em>
      </div>`
    )
    .join('');
  return `${rows}<div class="check-note">curl 扩展：${res.curl ? '已启用' : '未启用'} · 测试时间 ${esc(res.time || '')}</div>`;
}

function viewSettings() {
  setHead(
    '识别插件',
    '拍照识别以插件形式接入，可在这里启用、配置与测试',
    `<a class="btn btn-ghost" href="#/reports/new">${icon('back', 16)}返回新增报工</a>`
  );

  const view = $('#view');
  const emptyHint = `<div class="plugin-card">
      <div class="plugin-head"><div class="plugin-title">
        <div class="plugin-name">还没有识别插件</div>
        <p class="plugin-desc">把插件目录放到服务器的 public/plugins/&lt;id&gt;/，包含 plugin.json 与 handler.php（可选 check.php），刷新本页即可识别到。</p>
      </div></div>
      <div class="plugin-actions"><button type="button" class="btn btn-ghost" data-action="reload-plugins">重新扫描插件</button></div>
    </div>`;

  view.innerHTML = `<div class="settings-list" id="settingsList">
      ${netCardHtml()}
      ${state.plugins.map(pluginCardHtml).join('') || emptyHint}
    </div>
    <p class="settings-note">插件目录 <code>public/plugins/&lt;id&gt;/</code> · 配置保存在 <code>data/plugins.json</code>。识别出的日期、零件、工序、数量会自动填入报工单，保存前请人工核对。</p>`;

  $('#settingsList').addEventListener('click', async (e) => {
    const netBtn = e.target.closest('[data-net-test]');
    if (netBtn) {
      const old = netBtn.textContent;
      const box = $('#netResult');
      netBtn.disabled = true;
      netBtn.textContent = '测试中…';
      try {
        const res = await api('GET', 'api.php?action=net.check');
        box.innerHTML = netResultHtml(res);
        box.hidden = false;
        const sum = $('#netSum');
        sum.textContent = res.ok ? '全部通过' : '存在失败项';
        sum.className = 'net-sum ' + (res.ok ? 'ok' : 'bad');
        toast(res.ok ? '互联网连接正常' : '部分地址无法访问，请查看结果', res.ok ? 'ok' : 'err');
      } catch (err) {
        if (err.status !== 401) toast(err.message, 'err');
      } finally {
        netBtn.disabled = false;
        netBtn.textContent = old;
      }
      return;
    }

    const checkBtn = e.target.closest('[data-plugin-check]');
    if (checkBtn) {
      const id = checkBtn.dataset.pluginCheck;
      const card = checkBtn.closest('.plugin-card');
      const box = card ? $('.plugin-check-box', card) : null;
      const old = checkBtn.textContent;
      checkBtn.disabled = true;
      checkBtn.textContent = '测试中…';
      if (box) box.hidden = true;
      try {
        const res = await api('POST', `api.php?action=plugin.check&id=${encodeURIComponent(id)}`, {});
        if (box) {
          const rows = (res.details || [])
            .map(
              (d) => `<div class="check-row ${d.ok ? 'ok' : 'bad'}">
                <i class="check-dot"></i>
                <b>${esc(d.label)}</b>
                <span>${esc(d.detail || '')}</span>
              </div>`
            )
            .join('');
          box.innerHTML = `<div class="check-head ${res.ok ? 'ok' : 'bad'}">${esc(
            res.message || (res.ok ? '接口连接正常' : '接口测试未通过')
          )}</div>${rows}`;
          box.hidden = false;
        }
        toast(res.ok ? 'API 链接测试通过' : res.message || 'API 链接测试未通过', res.ok ? 'ok' : 'err');
      } catch (err) {
        if (err.status !== 401) toast(err.message, 'err');
      } finally {
        checkBtn.disabled = false;
        checkBtn.textContent = old;
      }
      return;
    }

    const testBtn = e.target.closest('[data-plugin-test]');
    if (testBtn) {
      const id = testBtn.dataset.pluginTest;
      const old = testBtn.textContent;
      testBtn.disabled = true;
      testBtn.textContent = '测试中…';
      try {
        const res = await api('POST', `api.php?action=plugin.invoke&id=${encodeURIComponent(id)}`, { image: OCR_TEST_IMAGE });
        const n = res.fields && Array.isArray(res.fields.items) ? res.fields.items.length : 0;
        toast(`测试成功：识别到 ${n} 行明细，置信度 ${Math.round((Number(res.confidence) || 0) * 100)}%`, 'ok');
      } catch (err) {
        if (err.status !== 401) toast(err.message, 'err');
      } finally {
        testBtn.disabled = false;
        testBtn.textContent = old;
      }
      return;
    }

    const saveBtn = e.target.closest('[data-plugin-save]');
    if (!saveBtn) return;
    const id = saveBtn.dataset.pluginSave;
    const card = view.querySelector(`.plugin-card[data-plugin="${id}"]`);
    if (!card) return;
    const settings = {};
    $$('[data-field]', card).forEach((el) => {
      settings[el.dataset.field] = el.type === 'checkbox' ? el.checked : el.value;
    });
    const toggle = $('[data-plugin-enabled]', card);
    saveBtn.disabled = true;
    saveBtn.textContent = '保存中…';
    try {
      const res = await api('POST', `api.php?action=plugin.save&id=${encodeURIComponent(id)}`, {
        enabled: !!(toggle && toggle.checked),
        settings,
      });
      const idx = state.plugins.findIndex((p) => p.id === id);
      if (idx >= 0 && res.plugin) state.plugins[idx] = res.plugin;
      toast('插件设置已保存', 'ok');
      viewSettings();
    } catch (err) {
      if (err.status !== 401) toast(err.message, 'err');
      saveBtn.disabled = false;
      saveBtn.textContent = '保存设置';
    }
  });
}

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
    else if (act === 'part-del') deletePart(id);
    else if (act === 'order-edit') location.hash = `#/orders/${id}/edit`;
    else if (act === 'order-del') deleteOrder(id);
    else if (act === 'rep-edit') location.hash = `#/reports/${id}/edit`;
    else if (act === 'rep-del') deleteReport(id);
    return;
  }

  if (!e.target.closest('.combo')) closeAllCombos();
  if (!e.target.closest('.ms')) $$('.ms-panel').forEach((p) => (p.hidden = true));
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
