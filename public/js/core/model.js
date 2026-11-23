/* 模块: core/model
   职责: 零件/订单/报工的数据辅助与展示文本
   依赖: core/util core/store
   导出: partProcesses partName partLabel partPriceLabel reportOrderLabel orderReported orderRemaining
   注意: 仅做纯计算，不发请求 */
'use strict';

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

/* 订单已报数量 / 剩余由服务端计算，可直接取用 */
function orderReported(order) {
  if (!order) return { qty: 0, amount: 0 };
  return { qty: Number(order.reportedQty) || 0, amount: Number(order.reportedAmount) || 0 };
}

function orderRemaining(order) {
  if (!order) return 0;
  if (order.remaining !== undefined) return round2(order.remaining);
  return round2((Number(order.qty) || 0) - orderReported(order).qty);
}
