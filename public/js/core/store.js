/* 模块: core/store
   职责: 全局 state、零件缓存、分页 store 工厂、各视图查询状态与图片地址
   依赖: core/api
   导出: state partCache cachePart fetchParts newStore imgUrl 及各视图状态变量
   注意: 顶层 let 状态为全应用共享，勿在模块内复制 */
'use strict';

const state = {
  plugins: [],
  auth: false,
  imageBase: '/img/',
  counts: { parts: 0, orders: 0, reports: 0, items: 0 },
  firstDate: '',
  marketUpgradeCount: 0,
};

/* 远程数据：零件对象按 id 缓存，供工序、名称、单价回显 */
const partCache = new Map();

function cachePart(p) {
  if (p && p.id) partCache.set(p.id, p);
  return p;
}

async function fetchParts(ids) {
  const list = Array.from(new Set((ids || []).filter(Boolean)));
  const missing = list.filter((id) => !partCache.has(id));
  if (missing.length) {
    const data = await api('GET', 'api.php?action=parts.get&ids=' + encodeURIComponent(missing.join(',')));
    (data.items || []).forEach(cachePart);
  }
  return list.map((id) => partCache.get(id)).filter(Boolean);
}

function newStore() {
  return { items: [], total: 0, page: 0, hasMore: false, loading: false, seq: 0 };
}

let partQuery = '';
let partStore = newStore();
let orderQuery = '';
let orderStore = newStore();
let orderRange = { key: 'all', from: '', to: '' };
let reportQuery = '';
let reportStore = newStore();
let reportRange = { key: 'month', from: '', to: '' };

let statRange = { key: 'month', from: '', to: '' };
let statOrderIds = new Set();
let statPartIds = new Set();
let statOrderLabels = new Map();
let statPartLabels = new Map();
let statQuery = '';
let statStore = newStore();

function imgUrl(name) {
  if (!name) return '';
  if (name.startsWith('data:') || name.startsWith('/') || /^[a-z][a-z0-9+.-]*:/i.test(name)) return name;
  return (state.imageBase || '/img/') + encodeURIComponent(name);
}
