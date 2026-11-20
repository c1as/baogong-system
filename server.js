'use strict';

/**
 * 报工管理系统 —— 单文件 Node 服务端（零第三方依赖）
 * 数据保存在 DATA_DIR/db.json，图片保存在 DATA_DIR/images/
 * 适用于群晖（Node.js 套件 + 任务计划）/ 任何装有 Node.js 18+ 的机器
 */

const http = require('node:http');
const fs = require('node:fs');
const fsp = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');

const PORT = Number(process.env.PORT || 8099);
const HOST = process.env.HOST || '0.0.0.0';
const PUBLIC_DIR = path.join(__dirname, 'public');
const DATA_DIR = path.resolve(process.env.DATA_DIR || path.join(__dirname, 'data'));
const IMG_DIR = path.join(DATA_DIR, 'images');
const BACKUP_DIR = path.join(DATA_DIR, 'backups');
const DB_FILE = path.join(DATA_DIR, 'db.json');
const SECRET_FILE = path.join(DATA_DIR, '.secret');
const APP_PASSWORD = process.env.APP_PASSWORD || '';
const SESSION_TTL = 30 * 24 * 3600 * 1000; // 30 天
const MAX_BODY = 12 * 1024 * 1024;

fs.mkdirSync(IMG_DIR, { recursive: true });
fs.mkdirSync(BACKUP_DIR, { recursive: true });

const secret = (() => {
  try {
    return fs.readFileSync(SECRET_FILE);
  } catch {
    const s = crypto.randomBytes(32);
    fs.writeFileSync(SECRET_FILE, s, { mode: 0o600 });
    return s;
  }
})();

class HttpError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

/* ------------------------------------------------------------------ 数据层 */

let db = { parts: [], reports: [], orders: [], updatedAt: null };

function isPart(x) {
  return x && typeof x === 'object' && typeof x.id === 'string' && typeof x.no === 'string';
}
function isReport(x) {
  return x && typeof x === 'object' && typeof x.id === 'string' && Array.isArray(x.items);
}
function isOrder(x) {
  return x && typeof x === 'object' && typeof x.id === 'string' && typeof x.no === 'string';
}

function round2(n) {
  return Math.round((Number(n) || 0) * 100) / 100;
}

function normalizeStoredImage(v) {
  if (!v || typeof v !== 'string') return '';
  if (v.startsWith('data:')) return '';
  const clean = v.split('?')[0].split('#')[0];
  return path.basename(clean);
}

function migratePart(p) {
  const processes = [];
  if (Array.isArray(p.processes)) {
    for (const x of p.processes) {
      if (!x || typeof x !== 'object') continue;
      const name = typeof x.name === 'string' ? x.name : '';
      const price = round2(Number(x.price) || 0);
      if (!name && price === 0) continue;
      processes.push({ name, price });
    }
  }
  if (!processes.length) {
    const name = typeof p.process === 'string' ? p.process : '';
    const price = round2(Number(p.price) || 0);
    if (name || price) processes.push({ name: name || '默认工序', price });
  }
  return {
    ...p,
    image: normalizeStoredImage(p.image),
    stock: Number.isFinite(Number(p.stock)) ? Number(p.stock) : 0,
    processes,
    price: processes.length ? processes[0].price : 0,
    process: undefined,
  };
}

function migrateOrder(o) {
  return { ...o, process: typeof o.process === 'string' ? o.process : '' };
}

function migrateReport(r) {
  const items = Array.isArray(r.items)
    ? r.items.map((it) => ({
        ...it,
        orderId: typeof it.orderId === 'string' ? it.orderId : typeof r.orderId === 'string' ? r.orderId : '',
        orderNo: typeof it.orderNo === 'string' ? it.orderNo : typeof r.orderNo === 'string' ? r.orderNo : '',
      }))
    : [];
  return {
    ...r,
    items,
    orderId: typeof r.orderId === 'string' ? r.orderId : '',
    orderNo: typeof r.orderNo === 'string' ? r.orderNo : '',
    images: Array.isArray(r.images) ? r.images.map(normalizeStoredImage).filter(Boolean) : [],
  };
}

function loadDb() {
  try {
    const parsed = JSON.parse(fs.readFileSync(DB_FILE, 'utf8'));
    db = {
      parts: Array.isArray(parsed.parts) ? parsed.parts.filter(isPart).map(migratePart) : [],
      reports: Array.isArray(parsed.reports) ? parsed.reports.filter(isReport).map(migrateReport) : [],
      orders: Array.isArray(parsed.orders) ? parsed.orders.filter(isOrder).map(migrateOrder) : [],
      updatedAt: parsed.updatedAt || null,
    };
    console.log(
      `[db] 已载入 ${db.parts.length} 个零件、${db.reports.length} 张报工单、${db.orders.length} 张订单`
    );
  } catch (err) {
    if (err.code === 'ENOENT') {
      console.log('[db] 首次启动，创建空数据库');
      return;
    }
    console.error('[db] db.json 损坏：', err.message);
    const backup = latestBackupFile();
    if (backup) {
      try {
        const parsed = JSON.parse(fs.readFileSync(backup, 'utf8'));
        db = {
          parts: Array.isArray(parsed.parts) ? parsed.parts.filter(isPart).map(migratePart) : [],
          reports: Array.isArray(parsed.reports) ? parsed.reports.filter(isReport).map(migrateReport) : [],
          orders: Array.isArray(parsed.orders) ? parsed.orders.filter(isOrder).map(migrateOrder) : [],
          updatedAt: parsed.updatedAt || null,
        };
        try {
          fs.copyFileSync(DB_FILE, DB_FILE + '.corrupt-' + Date.now());
        } catch {}
        console.log('[db] 已从备份恢复：', path.basename(backup));
        return;
      } catch {}
    }
    try {
      fs.copyFileSync(DB_FILE, DB_FILE + '.corrupt-' + Date.now());
    } catch {}
    db = { parts: [], reports: [], orders: [], updatedAt: null };
  }
}

function latestBackupFile() {
  try {
    const files = fs
      .readdirSync(BACKUP_DIR)
      .filter((f) => f.startsWith('db-') && f.endsWith('.json'))
      .sort();
    return files.length ? path.join(BACKUP_DIR, files[files.length - 1]) : null;
  } catch {
    return null;
  }
}

let writeChain = Promise.resolve();
function persist() {
  db.updatedAt = new Date().toISOString();
  const payload = JSON.stringify(db, null, 2);
  writeChain = writeChain
    .then(async () => {
      const tmp = DB_FILE + '.tmp';
      await fsp.writeFile(tmp, payload, 'utf8');
      await fsp.rename(tmp, DB_FILE);
    })
    .catch((err) => console.error('[db] 保存失败：', err));
  return writeChain;
}

async function snapshot(reason) {
  try {
    if (!fs.existsSync(DB_FILE)) return;
    const ts = new Date().toISOString().replace(/[:]/g, '-').slice(0, 19);
    await fsp.copyFile(DB_FILE, path.join(BACKUP_DIR, `db-${ts}.json`));
    const files = (await fsp.readdir(BACKUP_DIR))
      .filter((f) => f.startsWith('db-') && f.endsWith('.json'))
      .sort();
    while (files.length > 30) {
      const victim = files.shift();
      await fsp.unlink(path.join(BACKUP_DIR, victim)).catch(() => {});
    }
    if (reason) console.log('[db] 已备份：', reason);
  } catch (err) {
    console.error('[db] 备份失败：', err.message);
  }
}

/* ------------------------------------------------------------------ 工具 */

function json(res, status, data) {
  const body = JSON.stringify(data);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff',
  });
  res.end(body);
}

function readBody(req, limit = MAX_BODY) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (c) => {
      size += c.length;
      if (size > limit) {
        reject(new HttpError(413, '提交的数据过大'));
        req.destroy();
        return;
      }
      chunks.push(c);
    });
    req.on('end', () => {
      if (!chunks.length) return resolve({});
      try {
        resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')));
      } catch {
        reject(new HttpError(400, '数据格式错误'));
      }
    });
    req.on('error', reject);
  });
}

function asString(v, max, field) {
  if (v === undefined || v === null) v = '';
  if (typeof v !== 'string') v = String(v);
  v = v.trim();
  if (v.length > max) throw new HttpError(400, `${field}最多 ${max} 个字符`);
  return v;
}

function asNumber(v, field, { min = 0, max = 1e9 } = {}) {
  const n = typeof v === 'number' ? v : Number(String(v ?? '').trim());
  if (!Number.isFinite(n)) throw new HttpError(400, `${field}必须是数字`);
  if (n < min) throw new HttpError(400, `${field}不能小于 ${min}`);
  if (n > max) throw new HttpError(400, `${field}不能大于 ${max}`);
  return n;
}

function parseCookies(req) {
  const out = {};
  const raw = req.headers.cookie;
  if (!raw) return out;
  for (const piece of raw.split(';')) {
    const i = piece.indexOf('=');
    if (i < 0) continue;
    out[piece.slice(0, i).trim()] = decodeURIComponent(piece.slice(i + 1).trim());
  }
  return out;
}

/* ------------------------------------------------------------------ 鉴权 */

function sign(payload) {
  return crypto.createHmac('sha256', secret).update(payload).digest('base64url');
}

function makeToken() {
  const payload = Buffer.from(JSON.stringify({ exp: Date.now() + SESSION_TTL })).toString('base64url');
  return payload + '.' + sign(payload);
}

function verifyToken(token) {
  if (!token || typeof token !== 'string' || !token.includes('.')) return false;
  const idx = token.lastIndexOf('.');
  const payload = token.slice(0, idx);
  const sig = token.slice(idx + 1);
  const expected = sign(payload);
  if (sig.length !== expected.length) return false;
  if (!crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected))) return false;
  try {
    const data = JSON.parse(Buffer.from(payload, 'base64url').toString('utf8'));
    return typeof data.exp === 'number' && data.exp > Date.now();
  } catch {
    return false;
  }
}

function isAuthed(req) {
  if (!APP_PASSWORD) return true;
  return verifyToken(parseCookies(req).bg_session);
}

const loginFails = new Map();
function loginBlocked(ip) {
  const rec = loginFails.get(ip);
  if (!rec) return false;
  if (rec.until && rec.until > Date.now()) return true;
  if (rec.until && rec.until <= Date.now()) loginFails.delete(ip);
  return false;
}
function noteLoginFail(ip) {
  const rec = loginFails.get(ip) || { count: 0, until: 0 };
  rec.count += 1;
  if (rec.count >= 5) {
    rec.until = Date.now() + 60 * 1000;
    rec.count = 0;
  }
  loginFails.set(ip, rec);
}

/* ------------------------------------------------------------------ 图片 */

function saveImageDataUrl(fileBase, dataUrl) {
  const m = /^data:(image\/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=\s]+)$/.exec(String(dataUrl || ''));
  if (!m) throw new HttpError(400, '图片格式不支持（请用 JPG / PNG / WebP）');
  const buf = Buffer.from(m[2].replace(/\s/g, ''), 'base64');
  if (!buf.length) throw new HttpError(400, '图片内容为空');
  if (buf.length > 8 * 1024 * 1024) throw new HttpError(400, '图片太大，请压缩后再上传');
  const ext = m[1] === 'image/png' ? '.png' : m[1] === 'image/webp' ? '.webp' : '.jpg';
  const file = fileBase + ext;
  fs.writeFileSync(path.join(IMG_DIR, file), buf);
  return file;
}

function removeImagesWithPrefix(prefix) {
  if (!/^[A-Za-z0-9-]+$/.test(String(prefix))) return;
  try {
    for (const f of fs.readdirSync(IMG_DIR)) {
      if (f.startsWith(prefix + '-')) fs.promises.unlink(path.join(IMG_DIR, f)).catch(() => {});
    }
  } catch {}
}

function removeImageFile(name) {
  const n = normalizeStoredImage(name);
  if (!n) return;
  fs.promises.unlink(path.join(IMG_DIR, n)).catch(() => {});
}

/* ------------------------------------------------------------------ 零件 */

function normalizeProcesses(input, existing) {
  let src = null;
  if (Array.isArray(input.processes)) src = input.processes;
  else if (existing && Array.isArray(existing.processes)) src = existing.processes;

  const out = [];
  if (src) {
    for (const raw of src.slice(0, 20)) {
      if (!raw || typeof raw !== 'object') continue;
      const name = asString(raw.name, 60, '工序名称');
      if (!name) continue;
      const priceRaw = raw.price;
      const price =
        priceRaw === '' || priceRaw === null || priceRaw === undefined
          ? 0
          : round2(asNumber(priceRaw, '工序单价', { min: 0, max: 1e6 }));
      out.push({ name, price });
    }
  }
  return out;
}

function normalizePart(input, existing) {
  const no = asString(input.no, 60, '零件图号');
  if (!no) throw new HttpError(400, '请填写零件图号');

  const name = asString(input.name, 60, '零件名称');
  const material = asString(input.material, 60, '材料');
  const note = asString(input.note, 500, '备注');
  const stock = round2(
    asNumber(input.stock === undefined || input.stock === null || input.stock === '' ? 0 : input.stock, '库存数量', {
      min: 0,
      max: 1e9,
    })
  );
  const processes = normalizeProcesses(input, existing);

  const now = new Date().toISOString();
  return {
    id: existing ? existing.id : crypto.randomUUID(),
    no,
    name,
    material,
    note,
    stock,
    image: existing ? existing.image || '' : '',
    processes,
    price: processes.length ? processes[0].price : 0,
    process: undefined,
    createdAt: existing ? existing.createdAt : now,
    updatedAt: now,
  };
}

/* ------------------------------------------------------------------ 订单 */

function nextOrderNo(dateStr) {
  const prefix = 'PO' + String(dateStr).replace(/-/g, '');
  let n = 1;
  while (true) {
    const no = `${prefix}-${String(n).padStart(2, '0')}`;
    if (!db.orders.some((o) => o.no === no)) return no;
    n += 1;
  }
}

function normalizeOrder(input, existing) {
  const date = validateDate(input.date);
  const partId = asString(input.partId, 60, '零件');
  const part = db.parts.find((p) => p.id === partId);
  if (!part) throw new HttpError(400, '请选择订单零件');
  const process = asString(input.process, 60, '工序');
  const qty = round2(asNumber(input.qty, '订单数量', { min: 0, max: 1e9 }));
  if (qty <= 0) throw new HttpError(400, '订单数量必须大于 0');
  const note = asString(input.note, 500, '备注');

  let no = asString(input.no, 40, '订单号');
  if (!no) no = nextOrderNo(date);
  const dup = db.orders.find((o) => o.no === no && (!existing || o.id !== existing.id));
  if (dup) throw new HttpError(400, `订单号「${no}」已存在`);

  const now = new Date().toISOString();
  return {
    id: existing ? existing.id : crypto.randomUUID(),
    no,
    date,
    partId: part.id,
    partNo: part.no,
    partName: part.name || '',
    process,
    qty,
    note,
    createdAt: existing ? existing.createdAt : now,
    updatedAt: now,
  };
}

function autoOrderForItem(report, item) {
  const now = new Date().toISOString();
  return {
    id: crypto.randomUUID(),
    no: nextOrderNo(report.date),
    date: report.date,
    partId: item.partId,
    partNo: item.partNo,
    partName: item.partName,
    process: item.process || '',
    qty: round2(item.qty),
    note: '报工自动创建',
    createdAt: now,
    updatedAt: now,
  };
}

/* ------------------------------------------------------------------ 报工 */

function validateDate(v) {
  const s = asString(v, 10, '日期');
  if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) throw new HttpError(400, '日期格式应为 YYYY-MM-DD');
  const [y, m, d] = s.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d));
  if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== m - 1 || dt.getUTCDate() !== d) {
    throw new HttpError(400, '日期不存在');
  }
  return s;
}

function nextReportNo(dateStr) {
  const prefix = 'BG' + dateStr.replace(/-/g, '');
  let n = 1;
  let no;
  do {
    no = `${prefix}-${String(n).padStart(2, '0')}`;
    n += 1;
  } while (db.reports.some((r) => r.no === no));
  return no;
}

function normalizeReport(input, existing, orders) {
  const date = validateDate(input.date);
  const worker = asString(input.worker, 40, '报工人');
  if (!worker) throw new HttpError(400, '请填写报工人');
  const workshop = asString(input.workshop, 60, '车间');
  const note = asString(input.note, 500, '备注');

  const rawItems = Array.isArray(input.items) ? input.items : [];
  if (!rawItems.length) throw new HttpError(400, '请至少添加一条报工明细');
  if (rawItems.length > 100) throw new HttpError(400, '单张报工单最多 100 条明细');

  const items = rawItems.map((it, i) => {
    const part = db.parts.find((p) => p.id === it.partId);
    if (!part) throw new HttpError(400, `第 ${i + 1} 条明细的零件不存在，请重新选择`);
    const qty = asNumber(it.qty, `第 ${i + 1} 条数量`, { min: 0, max: 1e6 });
    if (qty <= 0) throw new HttpError(400, `第 ${i + 1} 条数量必须大于 0`);
    const price = round2(asNumber(it.price, `第 ${i + 1} 条单价`, { min: 0, max: 1e6 }));
    const process = asString(it.process, 60, `第 ${i + 1} 条工序`) || (part.processes[0] ? part.processes[0].name : '');

    const orderId = asString(it.orderId, 60, `第 ${i + 1} 条订单`);
    let orderNo = '';
    if (orderId) {
      const order = orders.find((o) => o.id === orderId);
      if (!order) throw new HttpError(400, `第 ${i + 1} 条明细的订单不存在，请重新选择`);
      if (order.partId !== part.id) throw new HttpError(400, `第 ${i + 1} 条明细的订单与零件不匹配`);
      if (order.process && process && order.process !== process) {
        throw new HttpError(400, `第 ${i + 1} 条明细的订单与工序不匹配`);
      }
      orderNo = order.no;
    }

    return {
      partId: part.id,
      partNo: part.no,
      partName: part.name || '',
      process,
      qty,
      price,
      amount: round2(qty * price),
      orderId,
      orderNo,
    };
  });

  let no = asString(input.no, 40, '报工单号');
  if (!no) no = nextReportNo(date);
  const dup = db.reports.find((r) => r.no === no && (!existing || r.id !== existing.id));
  if (dup) throw new HttpError(400, `报工单号「${no}」已存在`);

  const now = new Date().toISOString();
  return {
    id: existing ? existing.id : crypto.randomUUID(),
    no,
    date,
    worker,
    workshop,
    note,
    orderId: existing ? existing.orderId || '' : '',
    orderNo: existing ? existing.orderNo || '' : '',
    items,
    images: existing ? existing.images || [] : [],
    createdAt: existing ? existing.createdAt : now,
    updatedAt: now,
  };
}

function sortedReports() {
  return db.reports
    .slice()
    .sort((a, b) => String(b.date).localeCompare(String(a.date)) || String(b.createdAt).localeCompare(String(a.createdAt)));
}

function sortedOrders() {
  return db.orders
    .slice()
    .sort((a, b) => String(b.date).localeCompare(String(a.date)) || String(b.createdAt).localeCompare(String(a.createdAt)));
}

/* ------------------------------------------------------------------ CSV */

function csvCell(v) {
  const s = v === undefined || v === null ? '' : String(v);
  return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

function buildCsv(reports) {
  const head = ['报工单号', '日期', '报工人', '车间', '订单号', '零件图号', '零件名称', '工序', '数量', '单价(元)', '金额(元)', '备注'];
  const lines = [head.map(csvCell).join(',')];
  let totalQty = 0;
  let totalAmount = 0;
  for (const r of reports) {
    for (const it of r.items || []) {
      totalQty += Number(it.qty) || 0;
      totalAmount += Number(it.amount) || 0;
      lines.push(
        [r.no, r.date, r.worker, r.workshop, it.orderNo || r.orderNo || '', it.partNo, it.partName, it.process, it.qty, it.price, it.amount, r.note]
          .map(csvCell)
          .join(',')
      );
    }
  }
  lines.push(['合计', '', '', '', '', '', '', '', round2(totalQty), '', round2(totalAmount), ''].map(csvCell).join(','));
  return '\uFEFF' + lines.join('\r\n') + '\r\n';
}

/* ------------------------------------------------------------------ 静态托管 */

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.txt': 'text/plain; charset=utf-8',
};

async function serveStatic(req, res, pathname) {
  let rel = pathname === '/' ? 'index.html' : pathname.replace(/^\/+/, '');
  let file = path.join(PUBLIC_DIR, rel);
  if (!file.startsWith(PUBLIC_DIR + path.sep)) return notFound(res);
  if (path.extname(file).toLowerCase() === '.php') return notFound(res);

  let stat;
  try {
    stat = await fsp.stat(file);
    if (stat.isDirectory()) {
      file = path.join(file, 'index.html');
      stat = await fsp.stat(file);
    }
  } catch {
    return notFound(res);
  }

  const ext = path.extname(file).toLowerCase();
  const type = MIME[ext] || 'application/octet-stream';
  const etag = `W/"${stat.size.toString(16)}-${Math.floor(stat.mtimeMs).toString(16)}"`;

  if (req.headers['if-none-match'] === etag) {
    res.writeHead(304, { ETag: etag, 'Cache-Control': 'no-cache' });
    return res.end();
  }

  res.writeHead(200, {
    'Content-Type': type,
    'Content-Length': stat.size,
    'Cache-Control': 'no-cache',
    ETag: etag,
    'X-Content-Type-Options': 'nosniff',
  });
  if (req.method === 'HEAD') return res.end();
  fs.createReadStream(file)
    .on('error', () => res.end())
    .pipe(res);
}

async function serveImage(req, res, pathname) {
  if (!isAuthed(req)) return json(res, 401, { error: '未登录' });
  const name = path.basename(pathname);
  if (!/^[A-Za-z0-9-]+\.(jpg|png|webp)$/.test(name)) return notFound(res);
  const file = path.join(IMG_DIR, name);
  let stat;
  try {
    stat = await fsp.stat(file);
  } catch {
    return notFound(res);
  }
  const ext = path.extname(name).toLowerCase();
  res.writeHead(200, {
    'Content-Type': MIME[ext] || 'application/octet-stream',
    'Content-Length': stat.size,
    'Cache-Control': 'public, max-age=31536000, immutable',
    'X-Content-Type-Options': 'nosniff',
  });
  fs.createReadStream(file).pipe(res);
}

function notFound(res) {
  res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
  res.end('Not Found');
}

/* ------------------------------------------------------------------ 接口 */

async function handleLogin(req, res) {
  const ip = (req.socket.remoteAddress || 'unknown').replace(/^::ffff:/, '');
  if (loginBlocked(ip)) return json(res, 429, { error: '尝试次数过多，请 1 分钟后再试' });
  const body = await readBody(req, 64 * 1024);
  const password = typeof body.password === 'string' ? body.password : '';
  const ok =
    APP_PASSWORD &&
    password.length === APP_PASSWORD.length &&
    crypto.timingSafeEqual(Buffer.from(password), Buffer.from(APP_PASSWORD));
  if (!ok) {
    noteLoginFail(ip);
    return json(res, 401, { error: '密码不正确' });
  }
  loginFails.delete(ip);
  res.writeHead(200, {
    'Content-Type': 'application/json; charset=utf-8',
    'Set-Cookie': `bg_session=${encodeURIComponent(makeToken())}; Path=/; HttpOnly; SameSite=Lax; Max-Age=${Math.floor(
      SESSION_TTL / 1000
    )}`,
  });
  res.end(JSON.stringify({ ok: true }));
}

async function handleRestore(req, res) {
  const body = await readBody(req);
  const data = body && body.data ? body.data : body;
  if (!data || !Array.isArray(data.parts) || !Array.isArray(data.reports)) {
    throw new HttpError(400, '备份文件格式不正确');
  }
  await snapshot('恢复前自动备份');
  const now = new Date().toISOString();
  db = {
    parts: data.parts.filter(isPart).map(migratePart),
    reports: data.reports.filter(isReport).map(migrateReport),
    orders: Array.isArray(data.orders) ? data.orders.filter(isOrder).map(migrateOrder) : [],
    updatedAt: now,
  };
  await persist();
  json(res, 200, { ok: true, parts: db.parts.length, reports: db.reports.length, orders: db.orders.length });
}

/* 与 Web Station 版保持一致的接口映射 */
const API_ACTIONS = {
  state: ['GET', '/api/state'],
  health: ['GET', '/api/health'],
  login: ['POST', '/api/login'],
  logout: ['POST', '/api/logout'],
  'data.stats': ['GET', '/api/data.stats'],
  'part.create': ['POST', '/api/parts'],
  'part.import': ['POST', '/api/parts/import'],
  'order.create': ['POST', '/api/orders'],
  'report.create': ['POST', '/api/reports'],
  restore: ['POST', '/api/restore'],
  'export.json': ['GET', '/api/export.json'],
  'export.csv': ['GET', '/api/export.csv'],
};

function resolveApiAction(action, id) {
  if (API_ACTIONS[action]) return { method: API_ACTIONS[action][0], path: API_ACTIONS[action][1] };
  if (['part.update', 'part.delete', 'part.stock', 'order.update', 'order.delete', 'report.update', 'report.delete'].includes(action)) {
    if (!/^[A-Za-z0-9-]+$/.test(id)) return null;
    if (action === 'part.update') return { method: 'PUT', path: '/api/parts/' + id };
    if (action === 'part.delete') return { method: 'DELETE', path: '/api/parts/' + id };
    if (action === 'part.stock') return { method: 'POST', path: '/api/parts/' + id + '/stock' };
    if (action === 'order.update') return { method: 'PUT', path: '/api/orders/' + id };
    if (action === 'order.delete') return { method: 'DELETE', path: '/api/orders/' + id };
    if (action === 'report.update') return { method: 'PUT', path: '/api/reports/' + id };
    return { method: 'DELETE', path: '/api/reports/' + id };
  }
  return null;
}

function dirUsage(dir, prefix = '') {
  let count = 0;
  let bytes = 0;
  try {
    for (const name of fs.readdirSync(dir)) {
      if (name === '.' || name === '..') continue;
      if (prefix && !name.startsWith(prefix)) continue;
      if (prefix && !name.endsWith('.json')) continue;
      const st = fs.statSync(path.join(dir, name));
      if (st.isFile()) {
        count++;
        bytes += st.size;
      }
    }
  } catch {
    // ignore
  }
  return { count, bytes };
}

function dataStats() {
  const dbBytes = fs.existsSync(DB_FILE) ? fs.statSync(DB_FILE).size : 0;
  const images = dirUsage(IMG_DIR);
  const backups = dirUsage(BACKUP_DIR, 'db-');
  return {
    storage: 'json',
    dbFile: 'db.json',
    dbBytes,
    dataDir: DATA_DIR,
    images,
    backups,
    totalBytes: dbBytes + images.bytes + backups.bytes,
  };
}

async function handle(req, res) {
  if (req.method === 'OPTIONS') {
    res.writeHead(204, { Allow: 'GET,POST,PUT,DELETE' });
    return res.end();
  }

  let pathname;
  try {
    pathname = decodeURIComponent(new URL(req.url, 'http://localhost').pathname);
  } catch {
    return notFound(res);
  }
  const url = new URL(req.url, 'http://localhost');

  // 兼容 Web Station 的接口形式：/api.php?action=xxx&id=yyy
  if (pathname === '/api.php') {
    const action = url.searchParams.get('action') || '';
    const id = url.searchParams.get('id') || '';
    const target = resolveApiAction(action, id);
    if (!target) return json(res, 404, { error: '接口不存在' });
    const qs = new URLSearchParams();
    if (action === 'export.csv' || action === 'export.json') {
      const month = url.searchParams.get('month');
      const q = url.searchParams.get('q');
      if (month) qs.set('month', month);
      if (q) qs.set('q', q);
    }
    req.method = target.method;
    req.url = target.path + (qs.toString() ? '?' + qs.toString() : '');
    return handle(req, res);
  }

  if (pathname === '/api/health') {
    return json(res, 200, {
      ok: true,
      time: new Date().toISOString(),
      parts: db.parts.length,
      reports: db.reports.length,
      orders: db.orders.length,
    });
  }

  if (pathname === '/api/login' && req.method === 'POST') return handleLogin(req, res);

  if (pathname.startsWith('/api/')) {
    if (!isAuthed(req)) return json(res, 401, { error: '未登录' });

    if (pathname === '/api/data.stats' && req.method === 'GET') {
      return json(res, 200, dataStats());
    }

    if (pathname === '/api/state' && req.method === 'GET') {
      return json(res, 200, {
        parts: db.parts,
        reports: sortedReports(),
        orders: sortedOrders(),
        auth: !!APP_PASSWORD,
        imageBase: '/img/',
      });
    }

    if (pathname === '/api/logout' && req.method === 'POST') {
      res.writeHead(200, {
        'Content-Type': 'application/json; charset=utf-8',
        'Set-Cookie': 'bg_session=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0',
      });
      return res.end('{"ok":true}');
    }

    if (pathname === '/api/parts' && req.method === 'POST') {
      const body = await readBody(req);
      const part = normalizePart(body.part || body, null);
      if (db.parts.some((p) => p.no === part.no)) throw new HttpError(400, `零件图号「${part.no}」已存在`);
      if (typeof body.image === 'string' && body.image.startsWith('data:')) {
        part.image = saveImageDataUrl(`${part.id}-${Date.now()}`, body.image);
      }
      db.parts.push(part);
      await persist();
      return json(res, 200, { part });
    }

    if (pathname === '/api/parts/import' && req.method === 'POST') {
      const body = await readBody(req);
      const rows = Array.isArray(body.parts) ? body.parts : [];
      if (!rows.length) throw new HttpError(400, '没有可导入的数据');
      if (rows.length > 500) throw new HttpError(400, '一次最多导入 500 个零件');
      let added = 0;
      let updated = 0;
      const errors = [];
      rows.forEach((row, i) => {
        try {
          const existing = db.parts.find((p) => p.no === String(row.no || '').trim());
          const part = normalizePart(row, existing || null);
          if (existing) {
            Object.assign(existing, part);
            updated += 1;
          } else {
            if (db.parts.some((p) => p.no === part.no)) throw new HttpError(400, `零件图号「${part.no}」重复`);
            db.parts.push(part);
            added += 1;
          }
        } catch (err) {
          const msg = err instanceof HttpError ? err.message : '数据格式错误';
          if (errors.length < 20) errors.push(`第 ${i + 1} 行：${msg}`);
        }
      });
      await persist();
      return json(res, 200, { added, updated, errors });
    }

    const partStock = /^\/api\/parts\/([A-Za-z0-9-]+)\/stock$/.exec(pathname);
    if (partStock && req.method === 'POST') {
      const part = db.parts.find((p) => p.id === partStock[1]);
      if (!part) throw new HttpError(404, '零件不存在');
      const body = await readBody(req);
      part.stock = round2(asNumber(body.stock, '库存数量', { min: 0, max: 1e9 }));
      part.updatedAt = new Date().toISOString();
      await persist();
      return json(res, 200, { part });
    }

    const partMatch = /^\/api\/parts\/([A-Za-z0-9-]+)$/.exec(pathname);
    if (partMatch && req.method === 'PUT') {
      const existing = db.parts.find((p) => p.id === partMatch[1]);
      if (!existing) throw new HttpError(404, '零件不存在');
      const body = await readBody(req);
      const part = normalizePart(body.part || body, existing);
      if (db.parts.some((p) => p.no === part.no && p.id !== existing.id)) {
        throw new HttpError(400, `零件图号「${part.no}」已存在`);
      }
      if (typeof body.image === 'string' && body.image.startsWith('data:')) {
        removeImagesWithPrefix(existing.id);
        part.image = saveImageDataUrl(`${part.id}-${Date.now()}`, body.image);
      } else if (body.removeImage) {
        removeImageFile(existing.image);
        part.image = '';
      }
      Object.assign(existing, part);
      await persist();
      return json(res, 200, { part: existing });
    }

    if (partMatch && req.method === 'DELETE') {
      const idx = db.parts.findIndex((p) => p.id === partMatch[1]);
      if (idx < 0) throw new HttpError(404, '零件不存在');
      const [part] = db.parts.splice(idx, 1);
      removeImagesWithPrefix(part.id);
      await persist();
      return json(res, 200, { ok: true });
    }

    if (pathname === '/api/orders' && req.method === 'POST') {
      const body = await readBody(req);
      const order = normalizeOrder(body.order || body, null);
      db.orders.push(order);
      await persist();
      return json(res, 200, { order });
    }

    const orderMatch = /^\/api\/orders\/([A-Za-z0-9-]+)$/.exec(pathname);
    if (orderMatch && req.method === 'PUT') {
      const existing = db.orders.find((o) => o.id === orderMatch[1]);
      if (!existing) throw new HttpError(404, '订单不存在');
      const body = await readBody(req);
      const order = normalizeOrder(body.order || body, existing);
      Object.assign(existing, order);
      await persist();
      return json(res, 200, { order: existing });
    }

    if (orderMatch && req.method === 'DELETE') {
      const idx = db.orders.findIndex((o) => o.id === orderMatch[1]);
      if (idx < 0) throw new HttpError(404, '订单不存在');
      db.orders.splice(idx, 1);
      await persist();
      return json(res, 200, { ok: true });
    }

    if (pathname === '/api/reports' && req.method === 'POST') {
      const body = await readBody(req);
      const report = normalizeReport(body.report || body, null, db.orders);
      for (const it of report.items) {
        if (!it.orderId) {
          const order = autoOrderForItem(report, it);
          db.orders.push(order);
          it.orderId = order.id;
          it.orderNo = order.no;
        }
      }
      report.orderId = report.items[0].orderId;
      report.orderNo = report.items[0].orderNo;
      const imgs = [];
      const newImgs = Array.isArray(body.newImages) ? body.newImages.slice(0, 6) : [];
      newImgs.forEach((dataUrl, i) => {
        if (typeof dataUrl === 'string' && dataUrl.startsWith('data:')) {
          imgs.push(saveImageDataUrl(`${report.id}-r${Date.now()}-${i}`, dataUrl));
        }
      });
      report.images = imgs;
      db.reports.push(report);
      await persist();
      return json(res, 200, { report });
    }

    const repMatch = /^\/api\/reports\/([A-Za-z0-9-]+)$/.exec(pathname);
    if (repMatch && req.method === 'PUT') {
      const existing = db.reports.find((r) => r.id === repMatch[1]);
      if (!existing) throw new HttpError(404, '报工单不存在');
      const body = await readBody(req);
      const report = normalizeReport(body.report || body, existing, db.orders);
      for (const it of report.items) {
        if (!it.orderId) {
          const order = autoOrderForItem(report, it);
          db.orders.push(order);
          it.orderId = order.id;
          it.orderNo = order.no;
        }
      }
      report.orderId = report.items[0].orderId;
      report.orderNo = report.items[0].orderNo;

      const allowed = existing.images || [];
      let kept = allowed.slice();
      if (Array.isArray(body.images)) {
        kept = allowed.filter((f) => body.images.includes(f));
      }
      for (const f of allowed) {
        if (!kept.includes(f)) removeImageFile(f);
      }
      const newImgs = Array.isArray(body.newImages) ? body.newImages.slice(0, 6) : [];
      newImgs.forEach((dataUrl, i) => {
        if (typeof dataUrl === 'string' && dataUrl.startsWith('data:')) {
          kept.push(saveImageDataUrl(`${report.id}-r${Date.now()}-${i}`, dataUrl));
        }
      });
      report.images = kept;
      Object.assign(existing, report);
      await persist();
      return json(res, 200, { report: existing });
    }

    if (repMatch && req.method === 'DELETE') {
      const idx = db.reports.findIndex((r) => r.id === repMatch[1]);
      if (idx < 0) throw new HttpError(404, '报工单不存在');
      const [report] = db.reports.splice(idx, 1);
      removeImagesWithPrefix(report.id);
      await persist();
      return json(res, 200, { ok: true });
    }

    if (pathname === '/api/export.csv' && req.method === 'GET') {
      const month = url.searchParams.get('month') || '';
      const q = (url.searchParams.get('q') || '').toLowerCase();
      let list = sortedReports();
      if (/^\d{4}-\d{2}$/.test(month)) list = list.filter((r) => String(r.date || '').startsWith(month));
      if (q) {
        list = list.filter((r) => {
          if ((r.no || '').toLowerCase().includes(q)) return true;
          if ((r.worker || '').toLowerCase().includes(q)) return true;
          if ((r.orderNo || '').toLowerCase().includes(q)) return true;
          return (r.items || []).some(
            (it) =>
              (it.partNo || '').toLowerCase().includes(q) ||
              (it.partName || '').toLowerCase().includes(q) ||
              (it.orderNo || '').toLowerCase().includes(q)
          );
        });
      }
      const csv = buildCsv(list);
      const fname = `报工明细_${month || '全部'}.csv`;
      res.writeHead(200, {
        'Content-Type': 'text/csv; charset=utf-8',
        'Content-Disposition': `attachment; filename="baogong.csv"; filename*=UTF-8''${encodeURIComponent(fname)}`,
        'Cache-Control': 'no-store',
      });
      return res.end(csv);
    }

    if (pathname === '/api/export.json' && req.method === 'GET') {
      const stamp = new Date().toISOString().slice(0, 10);
      res.writeHead(200, {
        'Content-Type': 'application/json; charset=utf-8',
        'Content-Disposition': `attachment; filename="baogong-backup-${stamp}.json"`,
        'Cache-Control': 'no-store',
      });
      return res.end(JSON.stringify(db, null, 2));
    }

    if (pathname === '/api/restore' && req.method === 'POST') return handleRestore(req, res);

    return json(res, 404, { error: '接口不存在' });
  }

  if (pathname.startsWith('/img/')) return serveImage(req, res, pathname);

  if (req.method === 'GET' || req.method === 'HEAD') return serveStatic(req, res, pathname);
  return notFound(res);
}

/* ------------------------------------------------------------------ 启动 */

const server = http.createServer((req, res) => {
  handle(req, res).catch((err) => {
    if (err instanceof HttpError) return json(res, err.status, { error: err.message });
    console.error('[err]', err);
    if (!res.headersSent) json(res, 500, { error: '服务器内部错误' });
    else res.end();
  });
});

loadDb();
snapshot('启动');
setInterval(() => snapshot('定时'), 12 * 3600 * 1000).unref();

server.listen(PORT, HOST, () => {
  console.log(`报工管理系统已启动: http://${HOST}:${PORT}`);
  console.log(`数据目录: ${DATA_DIR}`);
  console.log(APP_PASSWORD ? '已启用密码登录' : '未设置密码（APP_PASSWORD 为空，局域网内任何人可访问）');
});

process.on('SIGTERM', () => {
  console.log('收到 SIGTERM，正在退出…');
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 3000);
});
