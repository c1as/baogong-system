/* 模块: views/plugin-settings
   路由: #/settings
   职责: OCR 插件配置、联网测试、引擎总览
   依赖: core/* ui/*
   导出: OCR_TEST_IMAGE pluginFieldHtml pluginCardHtml netCardHtml netResultHtml engineOverviewHtml viewSettings
   注意: 插件协议字段不可改名 */
'use strict';

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
  if (f.type === 'multiselect') {
    const selected = val.split(/[,，\s]+/).filter(Boolean);
    const opts = (f.options || [])
      .map(
        (o) => `<label class="plugin-check"><input type="checkbox" data-field="${esc(f.key)}" data-multi value="${esc(
          o.value
        )}" ${selected.includes(String(o.value)) ? 'checked' : ''} ${
          o.disabled && !selected.includes(String(o.value)) ? 'disabled' : ''
        }><span>${esc(o.label)}</span></label>`
      )
      .join('');
    return `<div class="field plugin-field plugin-field-wide"><label>${esc(f.label)}</label><div class="plugin-multi">${
      opts || '<span class="hint">没有其它已安装的识别引擎</span>'
    }</div>${f.hint ? `<div class="hint">${esc(f.hint)}</div>` : ''}</div>`;
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
    let attrs = '';
    if (f.type === 'number') {
      attrs += ` step="${f.step === undefined ? 'any' : Number(f.step)}"`;
      if (f.min !== undefined) attrs += ` min="${Number(f.min)}"`;
      if (f.max !== undefined) attrs += ` max="${Number(f.max)}"`;
    }
    ctrl = `<input class="input" type="${type}"${attrs} data-field="${esc(f.key)}" value="${esc(
      val
    )}" placeholder="${esc(f.placeholder || '')}" autocomplete="off" spellcheck="false">`;
  }
  return `<div class="field plugin-field"><label>${esc(f.label)}</label>${ctrl}${
    f.hint ? `<div class="hint">${esc(f.hint)}</div>` : ''
  }</div>`;
}

function pluginCardHtml(p) {
  const caps = p.capabilities || [];
  const isOcr = caps.includes('report.ocr');
  const checkLabel = caps.includes('report.ocr.log') ? '查看日志' : 'API 链接测试';
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
      ${p.hasCheck ? `<button type="button" class="btn btn-ghost" data-plugin-check="${esc(p.id)}">${checkLabel}</button>` : ''}
      ${isOcr ? `<button type="button" class="btn btn-ghost" data-plugin-test="${esc(p.id)}">测试识别</button>` : ''}
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

function engineOverviewHtml() {
  const list = state.plugins.filter((p) => (p.capabilities || []).includes('report.ocr'));
  const enabled = list.filter((p) => p.enabled);
  const fusion = list.find(isFusionPlugin);
  const stored = storedOcrEngine();
  let fusionNote = '未安装融合引擎';
  if (fusion) {
    const f = (fusion.settings || []).find((x) => x.key === 'engines');
    const ids = f && f.value ? String(f.value).split(/[,，\s]+/).filter(Boolean) : [];
    const usable = ids.length
      ? ids.filter((id) => enabled.some((p) => p.id === id)).length
      : enabled.filter((p) => !isFusionPlugin(p)).length;
    fusionNote = fusion.enabled
      ? `融合引擎已启用，参与引擎 ${usable} 路`
      : '融合引擎未启用';
  }
  const chips = list
    .map(
      (p) => `<span class="engine-chip ${p.enabled ? 'on' : ''}">${esc(p.name)}<i>${
        p.enabled ? '已启用' : '已停用'
      }</i>${isFusionPlugin(p) ? '<b>融合</b>' : ''}</span>`
    )
    .join('');
  const opts = enabled
    .map(
      (p) =>
        `<option value="${esc(p.id)}" ${stored === p.id ? 'selected' : ''}>${esc(p.name)}${
          isFusionPlugin(p) ? ' · 融合' : ''
        }</option>`
    )
    .join('');
  return `<div class="plugin-card engine-card">
    <div class="plugin-head">
      <div class="plugin-title">
        <div class="plugin-name">识别引擎<span class="plugin-ver">默认使用</span></div>
        <p class="plugin-desc">拍照识别默认使用的引擎；多路融合会交叉比对并给出综合置信度与分歧告警。</p>
      </div>
    </div>
    <div class="plugin-fields">
      <div class="field plugin-field">
        <label>默认识别引擎</label>
        <select class="select" id="defaultEngineSel" ${enabled.length ? '' : 'disabled'}>
          <option value="" ${stored ? '' : 'selected'}>自动（优先融合引擎）</option>
          ${opts}
        </select>
        <div class="hint">${
          enabled.length
            ? '只列出已启用的引擎；切换后立即生效，也可以在拍照识别处临时切换'
            : '没有已启用的识别引擎，请先在下方启用插件'
        }</div>
      </div>
    </div>
    <div class="engine-chips">${chips || '<span class="hint">还没有安装识别插件</span>'}</div>
    ${
      fusion
        ? `<div class="engine-note">${esc(fusionNote)} · 综合置信度低于「最低置信度」时将提示人工复核</div>`
        : ''
    }
  </div>`;
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
      ${engineOverviewHtml()}
      ${state.plugins.map(pluginCardHtml).join('') || emptyHint}
    </div>
    <p class="settings-note">插件目录 <code>public/plugins/&lt;id&gt;/</code> · 配置保存在 <code>data/plugins.json</code>。识别出的日期、零件、工序、数量会自动填入报工单，保存前请人工核对。</p>`;

  $('#settingsList').addEventListener('change', (e) => {
    if (!e.target || e.target.id !== 'defaultEngineSel') return;
    setStoredOcrEngine(e.target.value);
    const p = state.plugins.find((x) => x.id === e.target.value);
    toast(
      e.target.value ? '默认识别引擎已切换为 ' + (p ? p.name : e.target.value) : '已改为自动选择识别引擎',
      'ok'
    );
  });

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
      const key = el.dataset.field;
      if (el.hasAttribute('data-multi')) {
        if (!Array.isArray(settings[key])) settings[key] = [];
        if (el.checked) settings[key].push(el.value);
      } else {
        settings[key] = el.type === 'checkbox' ? el.checked : el.value;
      }
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
