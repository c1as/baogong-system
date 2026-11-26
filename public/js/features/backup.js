/* 模块: features/backup
   职责: 快照列表、备份创建/恢复/删除、清空数据
   依赖: core/* ui/*
   导出: SNAP_REASON snapTime snapItemHtml loadSnapshots openBackup openClearData
   注意: 恢复与清空属高危操作，文案需保留警示 */
'use strict';

const SNAP_REASON = { manual: '手动', auto: '自动', 'pre-restore': '恢复前', 'pre-clear': '清除前' };

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
          <span class="snap-badge ${s.reason === 'auto' ? 'auto' : String(s.reason || '').indexOf('pre-') === 0 ? 'pre' : ''}">${esc(reason)}</span>
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
    <p class="hint">快照包含零件、订单、报工等全部数据；图片在 data/images/{parts,attachments,drawings} 里（零件图按零件名、附件按上传日期+序号命名），迁移服务器时请连同 data 文件夹一起复制。</p>
    <div class="snap-danger">
      <div class="snap-danger-head">${icon('alert', 15)}数据清除</div>
      <p>清空零件、订单、报工单及全部图片，同时清除识别日志与审查工作台的审查记录（手写字词库保留）；快照会保留，业务数据误清除后可恢复。</p>
      <button type="button" class="btn btn-danger" id="clearData">${icon('trash', 15)}清空全部业务数据…</button>
    </div>
    <div class="modal-actions"><button class="btn btn-ghost" data-cancel>关闭</button></div>
  `);

  root.querySelector('[data-cancel]').addEventListener('click', close);
  root.querySelector('#clearData').addEventListener('click', () => openClearData());
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

/* 数据清除：二次验证——已设密码须重输密码，否则须输入固定确认语句 */
function openClearData() {
  const needPwd = !!state.auth;
  const { root, close } = openModal(
    `
    <h3>清空全部业务数据？</h3>
    <p>零件、订单、报工单、全部图片、识别日志与审查工作台的审查记录都会被删除（手写字词库保留），且识别日志与审查记录无法恢复。清除前会自动创建一份业务数据快照，可在「已有快照」中恢复。</p>
    <div class="clear-verify">
      <label for="clearVerifyInput">${needPwd ? '请输入登录密码以继续' : '请输入「清除全部数据」以继续'}</label>
      <input class="input" id="clearVerifyInput" type="${needPwd ? 'password' : 'text'}"
        autocomplete="${needPwd ? 'current-password' : 'off'}"
        placeholder="${needPwd ? '登录密码' : '清除全部数据'}">
    </div>
    <div class="modal-actions">
      <button class="btn btn-ghost" data-cancel>取消</button>
      <button class="btn btn-danger" data-ok disabled>确认清除</button>
    </div>
  `,
    () => openBackup()
  );
  const input = root.querySelector('#clearVerifyInput');
  const ok = root.querySelector('[data-ok]');
  root.querySelector('[data-cancel]').addEventListener('click', () => {
    close();
    openBackup();
  });
  input.addEventListener('input', () => {
    ok.disabled = input.value.trim() === '';
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !ok.disabled) ok.click();
  });
  input.focus();

  ok.addEventListener('click', async () => {
    const value = input.value;
    if (!value.trim()) return;
    ok.disabled = true;
    input.disabled = true;
    try {
      const res = await api('POST', 'api.php?action=data.clear', needPwd ? { password: value } : { phrase: value.trim() });
      const n = (res.cleared && res.cleared.parts) || 0;
      toast(`已清除全部数据（零件 ${fmtQty(n)} 个、图片 ${fmtQty(res.images || 0)} 张、识别日志 ${fmtQty(res.logs || 0)} 条、审查记录 ${res.reviews ? '1 份' : '0 份'}）`, 'ok');
      await refreshState();
      route();
      openBackup();
    } catch (err) {
      ok.disabled = false;
      input.disabled = false;
      if (err.status === 403) input.select();
      if (err.status !== 401) toast(err.message || '清除失败', 'err');
    }
  });
}
