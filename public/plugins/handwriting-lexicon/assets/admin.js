/*
 * 手写字词库管理页交互（原生 JS，无框架无构建）
 * 状态集中在 state，渲染函数按 state → DOM 的单向数据流组织。
 */
(function () {
  'use strict';

  var CFG = window.BGLX_CFG || { api: 'api.php', auto: 0.90, suggest: 0.75, token: '' };

  var TYPE_LABELS = {
    drawing_alias: '图号别名',
    process_synonym: '工序同义',
    unit_synonym: '单位同义',
    char_confusion: '形近字符'
  };
  var STATUS_LABELS = { active: '已生效', pending: '待审核', rejected: '已拒绝' };
  var SOURCE_LABELS = { manual: '手工', ocr_feedback: 'OCR反馈', import: '导入', learning: '学习' };

  var state = {
    items: [],
    total: 0,
    page: 1,
    pageSize: 20,
    stats: { total: 0, enabled: 0, pending: 0, hit_today: 0 },
    filters: { type: '', status: '', keyword: '' },
    editingId: '',
    tags: [],
    loading: false
  };

  /** @returns {HTMLElement|null} */
  function el(id) { return document.getElementById(id); }

  /** HTML 转义，防止词条内容注入页面 */
  function esc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /**
   * 统一接口请求。
   * @param {string} action action 名
   * @param {Object} params 参数
   * @param {string} method GET 或 POST
   * @returns {Promise<Object>} 响应 data
   */
  function api(action, params, method) {
    params = params || {};
    method = (method || 'GET').toUpperCase();
    var headers = { 'Accept': 'application/json' };
    var options = { method: method, headers: headers, credentials: 'same-origin' };
    if (CFG.token) { headers['X-Bglx-Token'] = CFG.token; }

    if (method === 'GET') {
      var query = new URLSearchParams();
      query.append('action', action);
      Object.keys(params).forEach(function (k) {
        if (params[k] !== undefined && params[k] !== null && params[k] !== '') {
          query.append(k, String(params[k]));
        }
      });
      options.url = CFG.api + '?' + query.toString();
    } else {
      headers['Content-Type'] = 'application/json';
      options.url = CFG.api;
      var body = Object.assign({}, params);
      body.action = action;
      options.body = JSON.stringify(body);
    }

    return fetch(options.url, options).then(function (resp) {
      return resp.json().catch(function () {
        throw new Error('接口返回内容不是 JSON（HTTP ' + resp.status + '）');
      });
    }).then(function (env) {
      if (!env || typeof env.code !== 'number') { throw new Error('接口响应格式错误'); }
      if (env.code !== 0) {
        var err = new Error(env.message || '请求失败（' + env.code + '）');
        err.code = env.code;
        err.data = env.data;
        throw err;
      }
      return env.data;
    });
  }

  /** 展示非阻塞提示 */
  function notify(message, isError) {
    var box = el('import-result');
    if (box) {
      box.textContent = message || '';
      box.style.color = isError ? '#dc2626' : '#16a34a';
    }
  }

  /** 置信度样式档位 */
  function confClass(conf) {
    var c = Number(conf) || 0;
    if (c >= CFG.auto) { return 'bglx-conf-auto'; }
    if (c >= CFG.suggest) { return 'bglx-conf-suggest'; }
    return 'bglx-conf-low';
  }

  /** 渲染统计卡片 */
  function renderStats() {
    el('stat-total').textContent = state.stats.total;
    el('stat-enabled').textContent = state.stats.enabled;
    el('stat-pending').textContent = state.stats.pending;
    el('stat-hit').textContent = state.stats.hit_today || 0;
  }

  /** 变体标签（超过 3 个折叠） */
  function variantTags(variants) {
    var list = Array.isArray(variants) ? variants : [];
    if (!list.length) { return '<span class="bglx-tag-more">—</span>'; }
    var html = list.slice(0, 3).map(function (v) {
      return '<span class="bglx-tag">' + esc(v) + '</span>';
    }).join('');
    if (list.length > 3) { html += '<span class="bglx-tag-more">+' + (list.length - 3) + '</span>'; }
    return html;
  }

  /** 渲染词条表格 */
  function renderTable() {
    var body = el('entries-body');
    if (!body) { return; }
    if (state.loading) {
      body.innerHTML = '<tr><td colspan="9" class="bglx-empty">正在加载词库…</td></tr>';
      return;
    }
    if (!state.items.length) {
      body.innerHTML = '<tr><td colspan="9" class="bglx-empty">暂无词条，点击右上角「+ 新增词条」开始维护</td></tr>';
      return;
    }
    body.innerHTML = state.items.map(function (e) {
      var badgeClass = e.type === 'process_synonym' ? 'bglx-badge-process'
        : (e.type === 'unit_synonym' ? 'bglx-badge-unit'
          : (e.type === 'char_confusion' ? 'bglx-badge-char' : ''));
      var statusClass = 'bglx-status-' + esc(e.status);
      var conf = Number(e.confidence) || 0;
      var stats = e.stats || {};
      var actions = '<button type="button" class="bglx-btn bglx-btn-mini" data-act="edit" data-id="' + esc(e.id) + '">编辑</button>';
      if (e.status === 'pending') {
        actions += '<button type="button" class="bglx-btn bglx-btn-mini" data-act="approve" data-id="' + esc(e.id) + '">通过</button>'
          + '<button type="button" class="bglx-btn bglx-btn-mini" data-act="reject" data-id="' + esc(e.id) + '">拒绝</button>';
      }
      actions += '<button type="button" class="bglx-btn bglx-btn-mini bglx-btn-danger" data-act="delete" data-id="' + esc(e.id) + '">删除</button>';
      return '<tr class="' + (e.status === 'pending' ? 'bglx-row-pending' : '') + '">'
        + '<td data-label="类型"><span class="bglx-badge ' + badgeClass + '">' + esc(TYPE_LABELS[e.type] || e.type) + '</span></td>'
        + '<td data-label="标准值"><strong>' + esc(e.standard) + '</strong></td>'
        + '<td data-label="变体"><div class="bglx-tags">' + variantTags(e.variants) + '</div></td>'
        + '<td data-label="置信度"><div class="bglx-conf ' + confClass(conf) + '"><span class="bglx-conf-bar"><span class="bglx-conf-fill" style="width:' + Math.round(conf * 100) + '%"></span></span><span class="bglx-conf-num">' + conf.toFixed(2) + '</span></div></td>'
        + '<td data-label="状态" class="' + statusClass + '">' + esc(STATUS_LABELS[e.status] || e.status) + '</td>'
        + '<td data-label="启用"><label class="bglx-switch"><input type="checkbox" data-act="enabled" data-id="' + esc(e.id) + '"' + (e.enabled ? ' checked' : '') + '><span class="bglx-slider"></span></label></td>'
        + '<td data-label="来源">' + esc(SOURCE_LABELS[e.source] || e.source) + '</td>'
        + '<td data-label="命中">' + (Number(stats.hit) || 0) + '</td>'
        + '<td data-label="操作"><div class="bglx-actions">' + actions + '</div></td>'
        + '</tr>';
    }).join('');
  }

  /** 渲染分页 */
  function renderPagination() {
    var box = el('pagination');
    if (!box) { return; }
    var pages = Math.max(1, Math.ceil(state.total / state.pageSize));
    if (state.page > pages) { state.page = pages; }
    box.innerHTML = '共 ' + state.total + ' 条 · 第 ' + state.page + ' / ' + pages + ' 页 '
      + '<button type="button" class="bglx-btn bglx-btn-mini" data-page="prev"' + (state.page <= 1 ? ' disabled' : '') + '>上一页</button>'
      + '<button type="button" class="bglx-btn bglx-btn-mini" data-page="next"' + (state.page >= pages ? ' disabled' : '') + '>下一页</button>';
  }

  /** 拉取列表 + 统计 */
  function load() {
    state.loading = true;
    renderTable();
    var params = Object.assign({}, state.filters, { page: state.page, page_size: state.pageSize });
    return api('list', params, 'GET').then(function (data) {
      state.items = data.items || [];
      state.total = Number(data.total) || 0;
      state.page = Number(data.page) || 1;
      state.pageSize = Number(data.page_size) || 20;
      state.stats = data.stats || state.stats;
      state.loading = false;
      renderStats();
      renderTable();
      renderPagination();
    }).catch(function (err) {
      state.loading = false;
      state.items = [];
      state.total = 0;
      renderTable();
      renderPagination();
      notify('加载失败：' + err.message, true);
    });
  }

  /** 渲染弹窗内的变体标签 */
  function renderTags() {
    var box = el('variants-tags');
    if (!box) { return; }
    if (!state.tags.length) {
      box.innerHTML = '<span class="bglx-tag-more">尚未添加变体</span>';
      return;
    }
    box.innerHTML = state.tags.map(function (v, i) {
      return '<span class="bglx-tag">' + esc(v)
        + '<button type="button" data-tag-index="' + i + '" aria-label="移除">×</button></span>';
    }).join('');
  }

  /** 打开新增/编辑弹窗 */
  function openModal(entry) {
    state.editingId = entry ? entry.id : '';
    state.tags = entry && Array.isArray(entry.variants) ? entry.variants.slice() : [];
    el('modal-title').textContent = entry ? '编辑词条 · ' + entry.id : '新增词条';
    el('f-type').value = entry ? entry.type : 'drawing_alias';
    el('f-source').value = entry ? entry.source : 'manual';
    el('f-standard').value = entry ? entry.standard : '';
    el('f-priority').value = entry ? entry.priority : 10;
    el('f-confidence').value = entry ? entry.confidence : CFG.auto;
    el('f-confidence-label').textContent = Number(el('f-confidence').value).toFixed(2);
    el('f-status').value = entry ? entry.status : 'active';
    el('f-enabled').checked = entry ? !!entry.enabled : true;
    el('f-note').value = entry ? (entry.note || '') : '';
    el('f-variant-input').value = '';
    el('form-error').hidden = true;
    renderTags();
    el('modal').hidden = false;
    el('f-standard').focus();
  }

  /** 关闭弹窗 */
  function closeModal() {
    el('modal').hidden = true;
    state.editingId = '';
    state.tags = [];
  }

  /** 保存词条（新建或更新） */
  function save() {
    var errBox = el('form-error');
    var standard = el('f-standard').value.trim();
    var confidence = Number(el('f-confidence').value);
    var message = '';
    if (!standard) { message = '标准值不能为空'; }
    else if (!state.tags.length) { message = '请至少添加 1 个变体'; }
    else if (!isFinite(confidence) || confidence < 0 || confidence > 1) { message = '置信度必须在 0 到 1 之间'; }
    else if (el('f-type').value === 'char_confusion' && standard.length !== 1) { message = '形近字符的标准值必须是单个字符'; }
    if (message) {
      errBox.textContent = message;
      errBox.hidden = false;
      return;
    }

    var payload = {
      type: el('f-type').value,
      standard: standard,
      variants: state.tags.slice(),
      confidence: confidence,
      enabled: el('f-enabled').checked ? 1 : 0,
      status: el('f-status').value,
      source: el('f-source').value,
      scope: ['*'],
      priority: Number(el('f-priority').value) || 0,
      note: el('f-note').value.trim()
    };
    var editing = !!state.editingId;
    var request = editing
      ? api('update', Object.assign({ id: state.editingId }, payload), 'POST')
      : api('create', payload, 'POST');

    el('btn-save').disabled = true;
    request.then(function () {
      el('btn-save').disabled = false;
      closeModal();
      notify(editing ? '词条已更新' : '词条已创建');
      return load();
    }).catch(function (err) {
      el('btn-save').disabled = false;
      errBox.textContent = '保存失败：' + err.message;
      errBox.hidden = false;
    });
  }

  /** 删除词条（二次确认） */
  function removeEntry(id) {
    if (!window.confirm('确认删除词条 ' + id + '？此操作不可恢复。')) { return; }
    api('delete', { id: id }, 'POST').then(function () {
      notify('已删除 ' + id);
      return load();
    }).catch(function (err) { notify('删除失败：' + err.message, true); });
  }

  /** 审核词条 */
  function review(id, status) {
    api('review', { id: id, status: status }, 'POST').then(function () {
      notify(id + (status === 'active' ? ' 已通过审核' : ' 已拒绝'));
      return load();
    }).catch(function (err) { notify('审核失败：' + err.message, true); });
  }

  /** 测试区查询 */
  function runTest() {
    var value = el('test-value').value.trim();
    var type = el('test-type').value;
    var plugin = el('test-plugin').value.trim();
    var out = el('test-result');
    if (!value) { out.textContent = '请输入原始值'; return; }
    out.textContent = '查询中…';
    api('test', { type: type, value: value, plugin: plugin }, 'POST').then(function (data) {
      var result = data.result || {};
      var trace = data.trace || {};
      out.textContent = [
        '原始值：' + (result.original || ''),
        '纠正值：' + (result.corrected || ''),
        '动作：' + (result.action || ''),
        '置信度：' + (result.confidence || 0),
        '命中词条：' + (result.matched_id || '（无）'),
        '命中层级：' + (result.strategy || ''),
        '说明：' + (result.reason || ''),
        '字符归一：' + (trace.char_normalized || ''),
        '匹配键：' + (trace.match_key || ''),
        '候选：' + JSON.stringify(trace.matched_entries || [], null, 2)
      ].join('\n');
    }).catch(function (err) { out.textContent = '查询失败：' + err.message; });
  }

  /** 绑定全部事件 */
  function bindEvents() {
    el('btn-search').addEventListener('click', function () {
      state.filters.type = el('filter-type').value;
      state.filters.status = el('filter-status').value;
      state.filters.keyword = el('keyword').value.trim();
      state.page = 1;
      load();
    });
    el('keyword').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { el('btn-search').click(); }
    });
    el('btn-reset').addEventListener('click', function () {
      el('filter-type').value = '';
      el('filter-status').value = '';
      el('keyword').value = '';
      state.filters = { type: '', status: '', keyword: '' };
      state.page = 1;
      load();
    });

    el('btn-create').addEventListener('click', function () { openModal(null); });
    el('btn-cancel').addEventListener('click', closeModal);
    el('btn-modal-close').addEventListener('click', closeModal);
    el('btn-save').addEventListener('click', save);
    el('f-confidence').addEventListener('input', function () {
      el('f-confidence-label').textContent = Number(this.value).toFixed(2);
    });

    el('f-variant-input').addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') { return; }
      e.preventDefault();
      var value = this.value.trim();
      if (value && state.tags.indexOf(value) < 0) { state.tags.push(value); }
      this.value = '';
      renderTags();
    });
    el('variants-tags').addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-tag-index]');
      if (!btn) { return; }
      state.tags.splice(Number(btn.getAttribute('data-tag-index')), 1);
      renderTags();
    });

    el('entries-body').addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-act]');
      if (!btn) { return; }
      var act = btn.getAttribute('data-act');
      var id = btn.getAttribute('data-id');
      if (act === 'edit') {
        var entry = state.items.filter(function (x) { return x.id === id; })[0];
        if (entry) { openModal(entry); }
      } else if (act === 'delete') {
        removeEntry(id);
      } else if (act === 'approve') {
        review(id, 'active');
      } else if (act === 'reject') {
        review(id, 'rejected');
      }
    });
    el('entries-body').addEventListener('change', function (e) {
      var input = e.target.closest('input[data-act="enabled"]');
      if (!input) { return; }
      var id = input.getAttribute('data-id');
      var on = input.checked ? 1 : 0;
      api('set_enabled', { id: id, enabled: on }, 'POST').then(function () {
        notify(id + (on ? ' 已启用' : ' 已停用'));
        return load();
      }).catch(function (err) {
        input.checked = !on;
        notify('启用状态更新失败：' + err.message, true);
      });
    });

    el('pagination').addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-page]');
      if (!btn || btn.disabled) { return; }
      state.page += (btn.getAttribute('data-page') === 'next' ? 1 : -1);
      load();
    });

    el('btn-import').addEventListener('click', function () {
      var format = el('import-format').value;
      var content = el('import-content').value.trim();
      if (!content) { notify('请先粘贴导入内容', true); return; }
      if (format === 'csv') { notify('CSV 导入将在后续版本开放（P1）', true); return; }
      el('btn-import').disabled = true;
      api('import', { format: format, content: content, mode: el('import-mode').value }, 'POST')
        .then(function (data) {
          el('btn-import').disabled = false;
          notify('导入完成：新增 ' + data.added + '，跳过 ' + data.skipped + '，错误 ' + data.errors.length);
          return load();
        }).catch(function (err) {
          el('btn-import').disabled = false;
          notify('导入失败：' + err.message, true);
        });
    });

    el('btn-export').addEventListener('click', function () {
      var params = new URLSearchParams();
      params.append('action', 'export');
      if (CFG.token) { params.append('token', CFG.token); }
      window.location.href = CFG.api + '?' + params.toString();
    });

    el('btn-test').addEventListener('click', runTest);
    el('test-value').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { runTest(); }
    });
  }

  /** 顶部 Tab 切换（词库管理 / 审查工作台），切换后广播事件供 review.js 延迟初始化 */
  function initTabs() {
    var tabs = document.querySelectorAll('.bglx-tab');
    var panels = document.querySelectorAll('.bglx-tabpanel');
    if (!tabs.length || !panels.length) { return; }
    function activate(name) {
      for (var i = 0; i < tabs.length; i++) {
        tabs[i].classList.toggle('is-active', tabs[i].getAttribute('data-tab') === name);
      }
      for (var j = 0; j < panels.length; j++) {
        panels[j].hidden = panels[j].getAttribute('data-panel') !== name;
      }
      document.dispatchEvent(new CustomEvent('bglx:tab', { detail: name }));
    }
    for (var k = 0; k < tabs.length; k++) {
      tabs[k].addEventListener('click', function () {
        activate(this.getAttribute('data-tab'));
      });
    }
    if (location.hash === '#review' && document.querySelector('.bglx-tab[data-tab="review"]')) {
      activate('review');
    }
  }

  initTabs();
  bindEvents();
  load();
})();
