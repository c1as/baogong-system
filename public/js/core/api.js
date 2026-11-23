/* 模块: core/api
   职责: 统一请求封装、登录态与插件刷新、OCR 引擎选择
   依赖: core/store
   导出: api refreshState refreshPlugins ocrPlugins isFusionPlugin storedOcrEngine setStoredOcrEngine activeOcrPlugin
   注意: api() 内部统一处理 401 与错误提示 */
'use strict';

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
  const data = await api('GET', 'api.php?action=bootstrap');
  state.auth = !!data.auth;
  state.imageBase = data.imageBase || '/img/';
  state.counts = data.counts || { parts: 0, orders: 0, reports: 0, items: 0 };
  state.firstDate = data.firstDate || '';
  partCache.clear();
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

/* 融合引擎能力标记：优先作为默认识别引擎 */
function isFusionPlugin(p) {
  return !!(p && (p.capabilities || []).includes('report.ocr.fusion'));
}

const OCR_ENGINE_STORAGE = 'bg.defaultOcrEngine';

function storedOcrEngine() {
  try {
    return localStorage.getItem(OCR_ENGINE_STORAGE) || '';
  } catch {
    return '';
  }
}

function setStoredOcrEngine(id) {
  try {
    if (id) localStorage.setItem(OCR_ENGINE_STORAGE, id);
    else localStorage.removeItem(OCR_ENGINE_STORAGE);
  } catch {}
}

/* 当前使用的识别引擎：手动选择 > 融合引擎 > 第一个已启用引擎 */
function activeOcrPlugin() {
  const list = ocrPlugins();
  if (!list.length) return null;
  const saved = storedOcrEngine();
  const hit = saved ? list.find((p) => p.id === saved) : null;
  if (hit) return hit;
  return list.find(isFusionPlugin) || list[0];
}
