/* 模块: views/part-form
   路由: #/parts/new #/parts/:id/edit
   职责: 零件表单、零件 CSV 导出与批量导入
   依赖: core/* ui/* features/csv
   导出: viewPartForm PART_HEADERS exportParts downloadPartsTemplate openPartsImport
   注意: 图片与图纸在保存时上传 */
'use strict';

/* ==================================================================== 零件表单 */

async function viewPartForm(id) {
  let part = id ? partCache.get(id) : null;
  if (id && !part) {
    try {
      const data = await api('GET', 'api.php?action=parts.get&id=' + encodeURIComponent(id));
      part = cachePart(data.part);
    } catch (err) {
      if (err.status === 401) return;
      part = null;
    }
  }
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
    ? partProcesses(part).map((x) => ({ name: x.name, desc: x.desc || '', price: x.price }))
    : [];
  if (!processes.length) processes.push({ name: '', desc: '', price: '' });
  const drawings = part && Array.isArray(part.drawings) ? part.drawings.slice() : [];
  const newDrawings = [];

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
          <div class="field span2">
            <label>备注</label>
            <input class="input" id="fNote" value="${esc(part && part.note ? part.note : '')}" placeholder="选填" autocomplete="off">
          </div>
        </div>
      </div>

      <div class="proc-editor">
        <div class="proc-title">工序与单价 <span>同一零件可以有多道工序，每道工序单独定价；工序说明会在报工时展示给工人</span></div>
        <div id="procList" class="proc-list"></div>
        <button type="button" class="add-row" id="addProc">${icon('plus', 16)}添加工序</button>
      </div>

      <div class="drawing-box">
        <div class="proc-title">零件图纸 <span>支持图片与 PDF，一个零件可上传多张；只在「图纸」按钮中查看，不影响零件主图</span></div>
        <div class="drawing-list" id="drawingList"></div>
        <button type="button" class="add-row" id="addDrawing">${icon('plus', 16)}上传图纸</button>
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
          <input class="input" data-proc="desc" data-i="${i}" value="${esc(x.desc || '')}" placeholder="工序说明（选填）">
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
        if (!processes.length) processes.push({ name: '', desc: '', price: '' });
        renderProcRows();
        bindProcs();
      });
    });
  }

  renderProcRows();
  bindProcs();

  $('#addProc').addEventListener('click', () => {
    processes.push({ name: '', desc: '', price: '' });
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

  /* ---------- 零件图纸：图片 + PDF，多张 ---------- */
  const drawingList = $('#drawingList');
  const drawInput = document.createElement('input');
  drawInput.type = 'file';
  drawInput.accept = 'image/*,application/pdf';
  drawInput.multiple = true;
  drawInput.hidden = true;
  $('#partForm').appendChild(drawInput);
  $('#addDrawing').addEventListener('click', () => drawInput.click());

  function renderDrawings() {
    const rows = [];
    drawings.forEach((d, i) => {
      const thumb =
        d.type === 'pdf'
          ? '<span class="dthumb pdf">PDF</span>'
          : `<img class="dthumb" src="${esc(imgUrl(d.path))}" alt="">`;
      rows.push(`<div class="drawing-item">${thumb}<span class="dname">${esc(d.name)}</span>
        <button type="button" class="icon-btn danger" data-draw-del="old" data-i="${i}" title="移除">${icon('x', 15)}</button></div>`);
    });
    newDrawings.forEach((d, i) => {
      const thumb =
        d.type === 'pdf' ? '<span class="dthumb pdf">PDF</span>' : `<img class="dthumb" src="${esc(d.data)}" alt="">`;
      rows.push(`<div class="drawing-item">${thumb}<span class="dname">${esc(d.name)}</span><span class="hint">待上传</span>
        <button type="button" class="icon-btn danger" data-draw-del="new" data-i="${i}" title="移除">${icon('x', 15)}</button></div>`);
    });
    drawingList.innerHTML = rows.length ? rows.join('') : '<div class="hint" style="padding:4px 0">还没有图纸</div>';
    $$('[data-draw-del]', drawingList).forEach((btn) => {
      btn.addEventListener('click', () => {
        const i = Number(btn.dataset.i);
        if (btn.dataset.drawDel === 'old') drawings.splice(i, 1);
        else newDrawings.splice(i, 1);
        renderDrawings();
      });
    });
  }

  function readDataUrl(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result);
      reader.onerror = () => reject(new Error('文件读取失败'));
      reader.readAsDataURL(file);
    });
  }

  drawInput.addEventListener('change', async () => {
    const files = Array.from(drawInput.files || []);
    drawInput.value = '';
    for (const file of files) {
      if (drawings.length + newDrawings.length >= 20) {
        toast('最多 20 张图纸', 'err');
        break;
      }
      const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
      if (isPdf && file.size > 20 * 1024 * 1024) {
        toast('PDF 图纸不能超过 20MB', 'err');
        continue;
      }
      if (!isPdf && file.size > 8 * 1024 * 1024) {
        toast('图片图纸不能超过 8MB', 'err');
        continue;
      }
      try {
        const data = await readDataUrl(file);
        newDrawings.push({ name: file.name, type: isPdf ? 'pdf' : 'image', data });
        renderDrawings();
      } catch (err) {
        toast(err.message || '读取失败', 'err');
      }
    }
  });
  renderDrawings();

  $('#partForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const no = $('#fNo').value.trim();
    if (!no) return toast('请填写零件图号', 'err');

    const payload = {
      no,
      name: $('#fName').value.trim(),
      material: $('#fMaterial').value.trim(),
      note: $('#fNote').value.trim(),
      processes: processes
        .filter((x) => String(x.name).trim() !== '')
        .map((x) => ({ name: String(x.name).trim(), desc: String(x.desc || '').trim(), price: Number(x.price) || 0 })),
      drawings,
      newDrawings,
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

const PART_HEADERS = ['零件图号', '零件名称', '材料', '备注'];
function partHeaderRow() {
  const head = PART_HEADERS.slice();
  for (let i = 1; i <= 9; i += 1) head.push(`工序${i}`, `工序说明${i}`, `单价${i}`);
  return head;
}

function exportParts() {
  if (!state.counts.parts) return toast('还没有零件可导出', 'err');
  const params = new URLSearchParams();
  if (partQuery.trim()) params.set('q', partQuery.trim());
  const a = document.createElement('a');
  a.href = 'api.php?action=export.parts' + (params.toString() ? '&' + params.toString() : '');
  a.download = '';
  document.body.appendChild(a);
  a.click();
  a.remove();
}

function downloadPartsTemplate() {
  const rows = [partHeaderRow()];
  const sample = ['示例-001', '示例零件', '6063T6', '这一行可以删除'];
  for (let i = 1; i <= 9; i += 1) {
    sample.push(i === 1 ? '铣加工' : i === 2 ? '钻孔' : '');
    sample.push(i === 1 ? '先铣外形，留 0.2mm 余量' : i === 2 ? '按图纸孔位钻孔' : '');
    sample.push(i === 1 ? 4.6 : i === 2 ? 1.2 : '');
  }
  rows.push(sample);
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
        for (let n = 1; n <= 9; n += 1) {
          const name = cell(row, `工序${n}`);
          if (!name) continue;
          const price = cell(row, `单价${n}`);
          processes.push({
            name,
            desc: cell(row, `工序说明${n}`),
            price: price === '' ? 0 : Number(price),
          });
        }
        parts.push({
          no,
          name: cell(row, '零件名称'),
          material: cell(row, '材料'),
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
