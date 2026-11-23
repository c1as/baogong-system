/* 模块: shell/router
   职责: hash 路由解析与页面切换
   依赖: shell/layout views/*
   导出: route
   注意: 新增页面需在此登记 */
'use strict';

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
