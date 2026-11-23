/* 模块: views/report-form
   路由: #/reports/new #/reports/:id/edit
   职责: 报工单表单（明细增删、识别填单、快速建单）
   依赖: core/* ui/* features/* views/report-items
   导出: viewReportForm openQuickOrder
   注意: viewReportForm 为单一大函数，拆内部需单独评审 */
'use strict';

async function viewReportForm(id, presetPartId) {
  let rep = null;
  if (id) {
    try {
      const data = await api('GET', 'api.php?action=reports.get&id=' + encodeURIComponent(id));
      rep = data.report;
    } catch (err) {
      if (err.status === 401) return;
      rep = null;
    }
  }
  if (id && !rep) {
    setHead('报工单不存在', '它可能已经被删除', `<a class="btn btn-ghost" href="#/reports">${icon('back', 16)}返回记录</a>`);
    $('#view').innerHTML = '';
    return;
  }

  let presetPart = null;
  if (presetPartId) {
    presetPart = partCache.get(presetPartId) || null;
    if (!presetPart) {
      try {
        const data = await api('GET', 'api.php?action=parts.get&id=' + encodeURIComponent(presetPartId));
        presetPart = cachePart(data.part);
      } catch (err) {
        if (err.status === 401) return;
      }
    }
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
  if (!draft.items.length) draft.items.push(newItem(presetPart));
  orderOptionCache = new Map();
  if (rep) {
    try {
      await fetchParts(draft.items.map((it) => it.partId));
    } catch (err) {
      if (err.status === 401) return;
    }
  }

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

  itemList.addEventListener('click', (e) => {
    const btn = e.target.closest('.pdc-toggle');
    if (!btn) return;
    const box = btn.closest('.pdc');
    if (box) box.classList.toggle('open');
  });

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

  async function ensureItemData() {
    const ids = draft.items.map((it) => it.partId).filter(Boolean);
    const tasks = [];
    if (ids.length) tasks.push(fetchParts(ids));
    draft.items.forEach((it) => {
      if (it.partId) tasks.push(loadOrderOptions(it.partId, it.process));
    });
    await Promise.all(tasks);
  }

  async function renderItems() {
    await ensureItemData();
    draft.items.forEach((it) => {
      if (!it.orderId) return;
      if (!orderOptionsOf(it).some((o) => o.id === it.orderId)) it.orderId = '';
    });
    itemList.innerHTML = draft.items.map(itemRowHtml).join('');
    $$('.item-row', itemList).forEach((row, idx) => {
      const it = draft.items[idx];
      setupCombo($('.combo', row), (part) => {
        if (!part) {
          it.partId = '';
          return;
        }
        cachePart(part);
        it.partId = part.id;
        const ps = partProcesses(part);
        it.process = ps.length ? ps[0].name : '';
        it.price = ps.length ? ps[0].price : '';
        it.orderId = '';
        renderItems().then(() => {
          const fresh = $$('.item-row', itemList)[idx];
          if (!fresh) return;
          const qty = $('[data-f="qty"]', fresh);
          if (qty) {
            qty.focus();
            qty.select();
          }
        });
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
          const sel = orderOptionsOf(it).find((o) => o.id === it.orderId);
          if (hint && sel) {
            const rem = orderRemaining(sel);
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
          const part = partCache.get(it.partId) || null;
          const ps = partProcesses(part);
          const hit = ps.find((x) => x.name === it.process);
          if (hit) {
            it.price = hit.price;
            $('[data-f="price"]', row).value = hit.price;
          }
          it.orderId = '';
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

  const ocrPlugin = ocrPlugins().length ? activeOcrPlugin() : null;

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

  /* 图号 / 名称匹配改由服务端 parts.match 完成，前端只保留工序归一化 */
  async function matchParts(recs) {
    if (!recs.length) return [];
    try {
      const data = await api('POST', 'api.php?action=parts.match', {
        queries: recs.map((r) => ({ no: r.partNo || '', name: r.partName || '' })),
      });
      return (data.matches || []).map((m) => (m && m.part ? cachePart(m.part) : null));
    } catch (err) {
      if (err.status !== 401) toast(err.message, 'err');
      return recs.map(() => null);
    }
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

  /* 工序尾部编号：铣1 → 1，车加工（3） → 3，用于区分同工序的多个工步 */
  function procNumber(text) {
    const m = String(text || '').match(/(?:[（(]\s*)?([0-9]{1,2})\s*[)）]?\s*$/);
    return m ? Number(m[1]) : 0;
  }

  function matchProcessName(part, guess) {
    const ps = partProcesses(part);
    const g = normKey(guess);
    if (!g) return '';
    const exact = ps.find((x) => normKey(x.name) === g);
    if (exact) return exact.name;
    const base = procBase(g);
    if (base) {
      const cands = ps.filter((x) => procBase(x.name) === base);
      const num = procNumber(g);
      if (num) {
        const same = cands.find((x) => procNumber(normKey(x.name)) === num);
        if (same) return same.name;
      }
      if (cands.length) return cands[0].name;
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

  async function applyRecognized(res) {
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
      const matched = await matchParts(recs);
      recs.forEach((rec, i) => {
        const part = matched[i];
        if (!part) {
          notes.push(`零件库中没有「${rec.partNo || rec.partName || '未识别'}」，该行未填充`);
          return;
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
      });
      if (!draft.items.length) draft.items.push(newItem());
      renderItems();
    }
    return { filled, dateFilled, notes };
  }

  /* ---------- 手动填充：点选识别原文 ---------- */

  /* 从一行原文里解析日期 / 零件 / 工序 / 数量，供手动填充使用 */
  function parseOcrLine(text, matchedPart) {
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

    if (matchedPart) {
      out.part = matchedPart;
      out.partScore = 1;
      const base = procBase(s);
      if (base) {
        const hit = partProcesses(matchedPart).find((x) => procBase(x.name) === base);
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
    let lineParts = [];

    function tagsOf(p) {
      const tags = [];
      if (p.date) tags.push(`<i class="tag-date">${esc(p.date.slice(5))}</i>`);
      if (p.part) tags.push(`<i class="tag-part">${esc(p.part.no)}</i>`);
      if (p.process) tags.push(`<i class="tag-proc">${esc(p.process)}</i>`);
      if (p.qty > 0) tags.push(`<i class="tag-qty">${fmtQty(p.qty)}</i>`);
      return tags.join('');
    }

    const lineHtml = rawLines
      .map((ln, i) => {
        const p = parseOcrLine(ln, null);
        return `<button type="button" class="ocr-line" data-i="${i}">
            <span class="ln-text">${esc(ln)}</span>
            <span class="ln-tags">${tagsOf(p)}</span>
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

    if (rawLines.length) {
      api('POST', 'api.php?action=parts.match', { texts: rawLines })
        .then((data) => {
          lineParts = (data.matches || []).map((m) => (m && m.part ? cachePart(m.part) : null));
          $$('.ocr-line', box).forEach((btn) => {
            const i = Number(btn.dataset.i);
            const tags = $('.ln-tags', btn);
            if (tags) tags.innerHTML = tagsOf(parseOcrLine(rawLines[i] || '', lineParts[i]));
          });
        })
        .catch(() => {});
    }

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
        const parsed = parseOcrLine(text, lineParts[i]);
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
    const engine = activeOcrPlugin();
    if (!engine) return toast('没有可用的识别引擎，请先在识别设置中启用', 'err');
    const btn = $('#ocrBtn');
    const label = $('.ocr-txt b', btn);
    const origin = label.textContent;
    btn.disabled = true;
    btn.classList.add('busy');
    label.textContent = '识别中…';
    try {
      const image = await compressImage(file);
      /* 识别用的原图一并存入图片附件 */
      if (draft.images.length + draft.newImages.length < 6) {
        draft.newImages.push(image);
        renderAttachments();
      } else {
        toast('图片附件已达 6 张，本次识别图片未保存', 'err');
      }
      const res = await api('POST', `api.php?action=plugin.invoke&id=${encodeURIComponent(engine.id)}`, { image });
      const info = await applyRecognized(res);
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

  const engineSel = $('#ocrEngineSel');
  if (engineSel) {
    engineSel.addEventListener('change', () => {
      setStoredOcrEngine(engineSel.value);
      const p = state.plugins.find((x) => x.id === engineSel.value);
      toast('识别引擎已切换为 ' + (p ? p.name : engineSel.value), 'ok');
    });
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
  return (async () => {
    let part = presetPartId ? partCache.get(presetPartId) : null;
    if (presetPartId && !part) {
      try {
        const data = await api('GET', 'api.php?action=parts.get&id=' + encodeURIComponent(presetPartId));
        part = cachePart(data.part);
      } catch (err) {
        if (err.status === 401) return null;
      }
    }
    return new Promise((resolve) => {
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
  })();
}
