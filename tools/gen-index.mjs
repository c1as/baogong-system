/**
 * 生成 docs/模块索引.md
 *
 * 两种模式自动切换：
 * 1) 拆分后：扫描 public/js/**、public/lib/*.php、public/actions/*.php 的头部注释
 * 2) 拆分前：扫描 app.js 的分节注释与 api.php 的 action 分支，生成"基线索引"
 *
 * 用法：node tools/gen-index.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PUB = path.join(ROOT, 'public');
const DOC = path.join(ROOT, 'docs', '模块索引.md');

const has = (p) => fs.existsSync(p);
const read = (p) => fs.readFileSync(p, 'utf8');

function walk(dir, ext, acc = []) {
  if (!has(dir)) return acc;
  for (const name of fs.readdirSync(dir)) {
    const p = path.join(dir, name);
    const st = fs.statSync(p);
    if (st.isDirectory()) walk(p, ext, acc);
    else if (name.endsWith(ext)) acc.push(p);
  }
  return acc;
}

function parseHeader(text) {
  const body = text.replace(/^<\?php[^\n]*\n/, '');
  const m = body.match(/^\s*\/\*([\s\S]*?)\*\//);
  if (!m || !/模块\s*[:：]/.test(m[1])) return null;
  const out = {};
  for (const raw of m[1].split(/\r?\n/)) {
    const line = raw.replace(/^\s*\*?\s?/, '');
    const kv = line.match(/^(模块|路由|Action|职责|依赖|导出|注意)\s*[:：]\s*(.*)$/);
    if (kv) out[kv[1]] = kv[2].trim();
  }
  return out;
}

const rel = (p) => path.relative(ROOT, p).replace(/\\/g, '/');
const lines = [];

lines.push('# 模块索引（自动生成）', '');
lines.push('> 由 `node tools/gen-index.mjs` 生成，请勿手工修改。', '');

// ---------- 前端 ----------
const jsFiles = walk(path.join(PUB, 'js'), '.js');
lines.push('## 前端', '');
if (jsFiles.length) {
  lines.push('| 文件 | 路由 | 职责 | 依赖 |', '| --- | --- | --- | --- |');
  for (const f of jsFiles.sort()) {
    const h = parseHeader(read(f)) || {};
    lines.push(`| ${rel(f)} | ${h['路由'] || '-'} | ${h['职责'] || '-'} | ${h['依赖'] || '-'} |`);
  }
} else {
  const app = path.join(PUB, 'app.js');
  const text = has(app) ? read(app) : '';
  const all = text.split(/\r?\n/);
  const secs = [];
  all.forEach((l, i) => {
    const m = l.match(/^\s*\/\*\s*=+\s*(.+?)\s*\*\/\s*$/);
    if (m) secs.push({ name: m[1].trim(), line: i + 1 });
  });
  lines.push('当前为单文件基线（未拆分），分节如下：', '');
  lines.push('| 分节 | 位置 | 约行数 |', '| --- | --- | --- |');
  secs.forEach((s, i) => {
    const end = i + 1 < secs.length ? secs[i + 1].line : all.length;
    lines.push(`| ${s.name} | public/app.js:${s.line} | ${end - s.line} |`);
  });
  lines.push('', `> 共 ${secs.length} 个分节，${all.length} 行。`);
}

// ---------- 后端 ----------
lines.push('', '## 后端', '');
const libFiles = walk(path.join(PUB, 'lib'), '.php').filter((f) => parseHeader(read(f)));
const actFiles = walk(path.join(PUB, 'actions'), '.php');
if (libFiles.length || actFiles.length) {
  lines.push('| 文件 | Action | 职责 | 依赖 |', '| --- | --- | --- | --- |');
  for (const f of [...libFiles, ...actFiles].sort()) {
    const h = parseHeader(read(f)) || {};
    lines.push(`| ${rel(f)} | ${h['Action'] || '-'} | ${h['职责'] || '-'} | ${h['依赖'] || '-'} |`);
  }
} else {
  const api = path.join(PUB, 'api.php');
  const text = has(api) ? read(api) : '';
  const acts = [...text.matchAll(/^\s*case '([^']+)':/gm)].map((m) => m[1]);
  const groups = {};
  for (const a of acts) {
    const g = a.includes('.') ? a.split('.')[0] : a;
    (groups[g] = groups[g] || []).push(a);
  }
  lines.push(`当前为单文件基线：public/api.php（${text.split(/\r?\n/).length} 行，${acts.length} 个 action）。`, '');
  lines.push('| 分组 | Action 数 | Action |', '| --- | --- | --- |');
  for (const [g, list] of Object.entries(groups).sort()) {
    lines.push(`| ${g} | ${list.length} | ${list.join(' ')} |`);
  }
}

// ---------- 插件 ----------
const pluginRoot = path.join(PUB, 'plugins');
if (has(pluginRoot)) {
  lines.push('', '## 插件', '', '| 插件 | 文件 |', '| --- | --- |');
  for (const d of fs.readdirSync(pluginRoot).sort()) {
    const dir = path.join(pluginRoot, d);
    if (!fs.statSync(dir).isDirectory()) continue;
    const meta = path.join(dir, 'plugin.json');
    let name = d;
    if (has(meta)) {
      try { name = JSON.parse(read(meta)).name || d; } catch { /* 保持目录名 */ }
    }
    lines.push(`| ${name} | ${rel(dir).replace(/^public\/plugins\//, '')} |`);
  }
}

fs.mkdirSync(path.dirname(DOC), { recursive: true });
fs.writeFileSync(DOC, lines.join('\n') + '\n', 'utf8');
console.log(`已生成 ${rel(DOC)}（${lines.length} 行）`);
