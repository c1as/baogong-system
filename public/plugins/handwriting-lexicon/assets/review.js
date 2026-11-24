/*
 * 人工判断审查工作台（原生 JS，无框架无构建）
 * 安全约定：所有来自日志/纠错/候选的文本一律用 textContent 输出，
 * 不用 innerHTML 拼接用户数据，防止存储型 XSS。
 */
(function () {
  'use strict';

  var CFG = window.BGLX_CFG || {};
  var REV = CFG.review || { enabled: false, page_size: 50 };
  var CSRF = CFG.csrf || '';
  var CAP = CFG.capabilities || {};
  if (!REV.enabled) { return; }

  var FIELD_LABELS = {
    partNo: '图号', process: '工序', unit: '单位', char: '形近字'
  };
  var JUDGE_LABELS = { unreviewed: '未审', correct: '正确', wrong: '错误' };

  var state = {
    items: [],
    total: 0,
    page: 1,
    pageSize: Number(REV.page_size) || 50,
    filters: { date_from: '', date_to: '', engine: '', judgment: '', keyword: '' },
    selectedInvoke: '',
    selected: null,
    loaded: false,
    loading: false,
    corrections: [],
    candidates: [],
    warnings: []
  };

  /** @returns {HTMLElement|null} */
  function el(id) { return document.getElementById(id); }

  /**
   * 创建元素：文本一律 textContent。
   * @returns {HTMLElement}
   */
  function node(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = String(text); }
    return n;
  }

  /** @returns {HTMLButtonElement} */
  function button(label, cls, onClick) {
    var b = node('button', cls || 'bglx-btn', label);
    b.type = 'button';
    if (onClick) { b.addEventListener('click', onClick); }
    return b;
  }

  /** 非阻塞提示 */
  function notify(message, isError) {
    var box = el('rv-result');
    if (box) {
      box.textContent = message || '';
      box.style.color = isError ? '#dc2626' : '#16a34a';
    }
  }

  /** 统一接口请求；POST 自动带 CSRF 与令牌 */
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
      if (CSRF) { headers['X-Bglx-CSRF'] = CSRF; }
      var body = Object.assign({}, params);
      body.action = action;
      body.csrf = CSRF;
      options.url = CFG.api;
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
        err.data = env.data || {};
        throw err;
      }
      return env.data;
    });
  }

  /** 图片 URL：<img> 无法设置请求头，因此把令牌作为查询参数带上（与导出一致） */
  function imageUrl(path, thumb) {
    if (!path) { return ''; }
    var q = new URLSearchParams();
    q.append('action', 'review_image');
    q.append('path', path);
    if (thumb) { q.append('thumb', '1'); }
    if (CFG.token) { q.append('token', CFG.token); }
    return CFG.api + '?' + q.toString();
  }

  /** 统计卡 */
  function loadStats() {
    return api('review_stats', {}, 'GET').then(function (data) {
      el('rv-stat-unreviewed').textContent = data.unreviewed || 0;
      el('rv-stat-correct').textContent = data.correct || 0;
      el('rv-stat-wrong').textContent = data.wrong || 0;
      el('rv-stat-flow').textContent = (data.draft || 0) + ' / ' + (data.imported || 0);
    }).catch(function () { /* 统计失败不阻断列表 */ });
  }

  /** 识别结果摘要：优先 items[].partNo */
  function partNoOf(item) {
    if (item.fields && item.fields.partNo) { return String(item.fields.partNo); }
    if (Array.isArray(item.items)) {
      for (var i = 0; i < item.items.length; i++) {
        if (item.items[i] && item.items[i].partNo) { return String(item.items[i].partNo); }
      }
    }
    return '（无图号）';
  }

  /** 日志标识展示：合成键（log-*）不显示原始哈希 */
  function invokeLabel(item) {
    var inv = item && item.invoke_id ? String(item.invoke_id) : '';
    if (inv === '') { return '无 invokeId'; }
    return inv.indexOf('log-') === 0 ? '日志记录' : inv;
  }

  /** 日志列表卡片 */
  function renderList() {
    var box = el('rv-list');
    if (!box) { return; }
    box.textContent = '';
    if (state.loading) {
      box.appendChild(node('p', 'bglx-empty', '正在加载识别日志…'));
      return;
    }
    if (!state.items.length) {
      box.appendChild(node('p', 'bglx-empty', '没有符合条件的日志。'));
      return;
    }
    state.items.forEach(function (item) {
      var card = node('button', 'bglx-review-card');
      card.type = 'button';
      if (item.invoke_id === state.selectedInvoke) { card.classList.add('is-active'); }

      var head = node('div', 'bglx-review-card-head');
      head.appendChild(node('span', 'bglx-review-date', item.date || '—'));
      head.appendChild(node('span', 'bglx-badge', JUDGE_LABELS[item.judgment] || item.judgment || '未审'));
      card.appendChild(head);

      var body = node('div', 'bglx-review-card-body');
      var path = (item.link && item.link.image_path) || item.manual_image_path || '';
      if (path) {
        var img = node('img', 'bglx-review-thumb');
        img.src = imageUrl(path, true);
        img.alt = '识别图片缩略图';
        img.loading = 'lazy';
        body.appendChild(img);
      } else {
        body.appendChild(node('div', 'bglx-review-thumb bglx-review-thumb-empty', '无关联图片'));
      }
      var info = node('div', 'bglx-review-card-info');
      info.appendChild(node('strong', 'bglx-review-partno', partNoOf(item)));
      info.appendChild(node('span', 'bglx-review-engine', item.engine || '未知引擎'));
      info.appendChild(node('span', 'bglx-review-invoke', invokeLabel(item)));
      var strategy = item.link && item.link.strategy ? item.link.strategy : 'none';
      var conf = item.link && item.link.confidence ? Number(item.link.confidence).toFixed(2) : '0.00';
      info.appendChild(node('span', 'bglx-review-link', '关联：' + strategy + ' / ' + conf
        + ((item.link && item.link.ambiguous) ? '（多张候选，请手动指定）' : '')));
      body.appendChild(info);
      card.appendChild(body);
      card.addEventListener('click', function () { select(item); });
      box.appendChild(card);
    });
  }

  /** 分页 */
  function renderPagination() {
    var box = el('rv-pagination');
    if (!box) { return; }
    box.textContent = '共约 ' + state.total + ' 条 · 第 ' + state.page + ' 页 ';
    box.appendChild(button('上一页', 'bglx-btn bglx-btn-mini', function () {
      if (state.page > 1) { state.page--; loadLogs(); }
    }));
    box.appendChild(button('下一页', 'bglx-btn bglx-btn-mini', function () {
      state.page++; loadLogs();
    }));
  }

  /** 拉取日志列表 */
  function loadLogs() {
    state.loading = true;
    renderList();
    return api('review_list_logs', Object.assign({}, state.filters, {
      page: state.page, page_size: state.pageSize, with_trace: 1
    }), 'GET').then(function (data) {
      state.loading = false;
      state.items = data.items || [];
      state.total = Number(data.total) || 0;
      state.warnings = data.warnings || [];
      renderList();
      renderPagination();
      if (state.warnings.length) { notify('提示：' + state.warnings.join('；'), true); }
      // 刷新后按 invokeId 重新选中，保留正在审查的条目
      if (state.selectedInvoke) {
        var found = state.items.filter(function (x) { return x.invoke_id === state.selectedInvoke; })[0];
        if (found) { select(found); }
      }
    }).catch(function (err) {
      state.loading = false;
      state.items = [];
      renderList();
      renderPagination();
      notify('日志加载失败：' + err.message, true);
    });
  }

  /** 刷新统计与列表 */
  function refresh() {
    loadStats();
    return loadLogs();
  }

  /** 识别字段对照表 */
  function appendCompareTable(parent, item) {
    var pairs = [];
    var fields = item.fields || {};
    ['date', 'worker', 'partNo', 'process', 'unit', 'note'].forEach(function (k) {
      if (fields[k] !== undefined && fields[k] !== '') { pairs.push([k, fields[k]]); }
    });
    if (Array.isArray(item.items)) {
      item.items.forEach(function (row, idx) {
        if (!row || typeof row !== 'object') { return; }
        ['partNo', 'partName', 'process', 'unit', 'qty'].forEach(function (k) {
          if (row[k] !== undefined && row[k] !== '') {
            pairs.push(['明细' + (idx + 1) + '.' + k, row[k]]);
          }
        });
      });
    }
    if (!pairs.length) { parent.appendChild(node('p', 'bglx-hint', '日志未提供可展示字段。')); return; }
    var table = node('table', 'bglx-review-compare');
    pairs.forEach(function (p) {
      var tr = node('tr');
      tr.appendChild(node('th', '', p[0]));
      tr.appendChild(node('td', '', typeof p[1] === 'object' ? JSON.stringify(p[1]) : p[1]));
      table.appendChild(tr);
    });
    parent.appendChild(table);
  }

  /** 手工新增行的稳定键序号，避免与「明细序号:字段」自动行冲突 */
  var manualRowSeq = 0;

  /** 逐条纠错行：字段 / 错误值 / 正确值 / 变体 / 备注 + 行级判定按钮与徽标 */
  function appendCorrectionRow(parent, correction) {
    var c = correction;
    if (!c) {
      manualRowSeq++;
      c = newCorrectionRow('manual:' + manualRowSeq, -1, 'partNo', 'drawing_alias', '');
    }
    var judgment = (c.judgment === 'correct' || c.judgment === 'wrong') ? c.judgment : 'unreviewed';
    var row = node('div', 'bglx-review-correction-row');
    row.setAttribute('data-field', c.field || 'partNo');
    row.setAttribute('data-row-key', c.row_key || '');
    row.setAttribute('data-detail-index', String(c.detail_index === undefined ? -1 : c.detail_index));
    row.setAttribute('data-type', c.type || '');
    row.setAttribute('data-judgment', judgment);
    row.setAttribute('data-judged-at', c.judged_at || '');
    if (judgment === 'wrong') { row.classList.add('bglx-review-row-highlight'); }
    if (judgment !== 'unreviewed') { row.classList.add('bglx-review-row-judged'); }

    var select = document.createElement('select');
    Object.keys(FIELD_LABELS).forEach(function (k) {
      var opt = document.createElement('option');
      opt.value = k;
      opt.textContent = FIELD_LABELS[k];
      if (k === c.field) { opt.selected = true; }
      select.appendChild(opt);
    });
    row.appendChild(select);

    var wrongNode = node('span', 'bglx-review-wrong', c.wrong_value || '—');
    wrongNode.setAttribute('data-wrong', c.wrong_value || '');
    row.appendChild(wrongNode);
    var correct = node('input', 'bglx-review-correct');
    correct.type = 'text';
    if (c.field === 'char') { correct.placeholder = '正确字（单字符）'; }
    else if (judgment === 'correct') { correct.placeholder = '判正确，无需填写'; }
    else { correct.placeholder = '正确值'; }
    correct.value = judgment === 'wrong' ? (c.correct_value || '') : '';
    row.appendChild(correct);

    var extra = node('input', 'bglx-review-extra');
    extra.type = 'text';
    extra.value = Array.isArray(c.extra_variants) ? c.extra_variants.join('，') : '';
    extra.placeholder = '补充变体（逗号分隔）';
    row.appendChild(extra);

    var note = node('input', 'bglx-review-note');
    note.type = 'text';
    note.value = c.note || '';
    note.placeholder = '备注（可选）';
    row.appendChild(note);

    // 行级判定区：徽标 + 正确/错误/撤销三按钮（data-row-key 与行键一致）
    var judgeBox = node('div', 'bglx-review-row-judge');
    judgeBox.appendChild(node('span', 'bglx-review-judge-badge bglx-badge-' + judgment,
      JUDGE_LABELS[judgment]));
    [['correct', '正确'], ['wrong', '错误'], ['unreviewed', '撤销']].forEach(function (pair) {
      var cls = 'bglx-btn bglx-btn-mini bglx-row-judge-btn'
        + (judgment === pair[0] ? ' is-active' : '');
      var b = button(pair[1], cls, function () {
        judgeCorrectionRow(state.selected, row, pair[0]);
      });
      b.setAttribute('data-row-key', c.row_key || '');
      b.setAttribute('data-judgment', pair[0]);
      judgeBox.appendChild(b);
    });
    row.appendChild(judgeBox);

    row.appendChild(button('删除', 'bglx-btn bglx-btn-mini bglx-btn-danger', function () {
      parent.removeChild(row);
    }));
    parent.appendChild(row);
  }

  /** 收集纠错行：输出 row_key / judgment 供服务端持久化与派生 */
  function collectCorrections() {
    var box = el('rv-corrections');
    var out = [];
    if (!box) { return out; }
    Array.prototype.forEach.call(box.querySelectorAll('.bglx-review-correction-row'), function (row) {
      var inputs = row.querySelectorAll('input');
      var judgment = row.getAttribute('data-judgment') || 'unreviewed';
      out.push({
        row_key: row.getAttribute('data-row-key') || '',
        detail_index: parseInt(row.getAttribute('data-detail-index') || '-1', 10),
        field: row.querySelector('select').value,
        type: row.getAttribute('data-type') || '',
        wrong_value: row.querySelector('.bglx-review-wrong').getAttribute('data-wrong') || '',
        judgment: judgment,
        judged_at: row.getAttribute('data-judged-at') || '',
        correct_value: judgment === 'wrong' ? inputs[0].value.trim() : '',
        extra_variants: inputs[1].value.trim(),
        note: inputs[2].value.trim(),
        confirmed: true
      });
    });
    return out;
  }

  /** 新建一条未审纠错行；row_key 前后端同构，是幂等合并键 */
  function newCorrectionRow(key, index, field, type, value) {
    return {
      row_key: key,
      detail_index: index,
      field: field,
      type: type,
      wrong_value: String(value === undefined || value === null ? '' : value).trim(),
      judgment: 'unreviewed',
      judged_at: '',
      correct_value: '',
      extra_variants: [],
      confirmed: false,
      note: ''
    };
  }

  /**
   * 服务端同构的四类全覆盖行生成器（架构 §6.1）。
   * 遍历全部明细行按图号/工序/单位生成，形近字取记录级 note 单行；qty 明确不生成。
   * 以 row_key 幂等合并存量：已判结果优先，手工添加行与历史行不丢。
   */
  function inferCorrectionRows(item) {
    var saved = Array.isArray(item.corrections) ? item.corrections : [];
    var seed = {};
    saved.forEach(function (c) { if (c && c.row_key) { seed[c.row_key] = c; } });
    var out = [];
    var used = {};
    var fields = item.fields || {};
    var defs = [
      ['partNo', 'drawing_alias'],
      ['process', 'process_synonym'],
      ['unit', 'unit_synonym']
    ];
    var items = Array.isArray(item.items) ? item.items.slice(0, 200) : [];
    items.forEach(function (detail, i) {
      if (!detail || typeof detail !== 'object') { return; }
      defs.forEach(function (def) {
        var field = def[0];
        var type = def[1];
        var val = (detail[field] !== undefined && detail[field] !== null && String(detail[field]).trim() !== '')
          ? detail[field] : (fields[field] !== undefined && fields[field] !== null ? fields[field] : '');
        if (String(val === null ? '' : val).trim() === '') { return; }
        var key = i + ':' + field;
        out.push(seed[key] || newCorrectionRow(key, i, field, type, val));
        used[key] = true;
      });
    });
    var note = fields.note === undefined || fields.note === null ? '' : String(fields.note).trim();
    if (note !== '') {
      out.push(seed['note:char'] || newCorrectionRow('note:char', -1, 'char', 'char_confusion', note));
      used['note:char'] = true;
    }
    saved.forEach(function (c) {
      if (!c || !c.row_key || used[c.row_key]) { return; }
      out.push(c);
      used[c.row_key] = true;
    });
    return out.slice(0, 200);
  }

  /** 所有纠错行均已判定（correct / wrong），用于「审查完成」态 */
  function allJudged(rows) {
    if (!Array.isArray(rows) || !rows.length) { return false; }
    return rows.every(function (r) {
      return !!r && (r.judgment === 'correct' || r.judgment === 'wrong');
    });
  }

  /** 逐条判定：调 review_judge_row，判错必须填值，判对服务端强清空正确值 */
  function judgeCorrectionRow(item, row, judgment) {
    var key = row.getAttribute('data-row-key') || '';
    if (!key) { notify('该行没有稳定键，请先保存纠错草稿', true); return; }
    var input = row.querySelector('.bglx-review-correct');
    var value = input ? input.value.trim() : '';
    if (judgment === 'wrong' && value === '') {
      notify('判为错误时必须填写正确值', true);
      return;
    }
    if (judgment !== 'wrong') { value = ''; }
    api('review_judge_row', {
      item_id: item.review_id || '', invoke_id: item.invoke_id || '',
      row_key: key, judgment: judgment, correct_value: value,
      version: item.version || 1,
      snapshot: { date: item.date, engine: item.engine, duration: item.duration,
                  fields: item.fields, items: item.items }
    }, 'POST').then(function () {
      notify('逐条判定已保存：' + (JUDGE_LABELS[judgment] || judgment));
      return refresh();
    }).catch(function (err) {
      notify('逐条判定失败：' + err.message + (err.code === 40901 ? '（请刷新后重试）' : ''), true);
    });
  }

  /** AI 候选卡片 */
  function appendCandidateCard(parent, candidate, index) {
    var card = node('div', 'bglx-review-candidate');
    var top = node('div', 'bglx-review-candidate-top');
    var accept = document.createElement('input');
    accept.type = 'checkbox';
    accept.className = 'bglx-review-accept';
    accept.checked = !!candidate.accepted;
    top.appendChild(accept);
    top.appendChild(node('span', 'bglx-badge', candidate.type || ''));
    top.appendChild(node('span', 'bglx-review-conf', '置信度 ' + Number(candidate.confidence || 0).toFixed(2)
      + '（上限 0.80，待人工确认）'));
    card.appendChild(top);

    var std = node('input', 'bglx-review-cand-standard');
    std.type = 'text';
    std.value = candidate.standard || '';
    std.placeholder = '标准值（可修改）';
    card.appendChild(std);

    var vars = node('input', 'bglx-review-cand-variants');
    vars.type = 'text';
    vars.value = Array.isArray(candidate.variants) ? candidate.variants.join('，') : '';
    vars.placeholder = '变体（逗号分隔，可修改）';
    card.appendChild(vars);

    card.appendChild(node('p', 'bglx-hint', candidate.note || ''));
    card.setAttribute('data-type', candidate.type || '');
    card.setAttribute('data-index', String(index));
    parent.appendChild(card);
  }

  /** 收集 AI 候选决策：accepted 为 true 才会被服务端纳入导入 */
  function collectCandidateDecisions() {
    var box = el('rv-candidates');
    var out = [];
    if (!box) { return out; }
    Array.prototype.forEach.call(box.querySelectorAll('.bglx-review-candidate'), function (card) {
      out.push({
        type: card.getAttribute('data-type'),
        standard: card.querySelector('.bglx-review-cand-standard').value.trim(),
        variants: card.querySelector('.bglx-review-cand-variants').value.trim(),
        accepted: card.querySelector('.bglx-review-accept').checked,
        source: 'ai',
        confidence: 0.8,
        item_id: state.selected ? (state.selected.review_id || '') : ''
      });
    });
    return out;
  }

  /** 渲染右侧详情：图片/字段对照 + 判定 + 纠错 + AI 候选 */
  function select(item) {
    state.selected = item;
    state.selectedInvoke = item.invoke_id || '';
    state.corrections = inferCorrectionRows(item);
    state.candidates = Array.isArray(item.ai_candidates) ? item.ai_candidates : [];
    var detail = el('rv-detail');
    if (!detail) { return; }
    detail.textContent = '';
    renderList();

    detail.appendChild(node('h2', 'bglx-review-detail-title', partNoOf(item)));
    var meta = node('p', 'bglx-review-meta', (item.date || '—') + ' · ' + (item.engine || '未知引擎')
      + ' · ' + invokeLabel(item));
    detail.appendChild(meta);
    // Q4：审查记录独立保留 90 天，不与日志/图片 14 天联动；原图缺失时仍可审文字
    detail.appendChild(node('p', 'bglx-hint', '提示：超过 14 天的原图如已被主程序清理，此处仅可审查文字，不影响逐条判定。'));

    var link = item.link || {};
    detail.appendChild(node('p', 'bglx-review-linkinfo', '关联策略：' + (link.strategy || 'none')
      + ' · 置信度 ' + Number(link.confidence || 0).toFixed(2)
      + (link.ambiguous ? ' · 多张候选，请手动指定' : '')));

    // 原图
    var path = link.image_path || item.manual_image_path || '';
    if (path) {
      var img = node('img', 'bglx-review-img');
      img.src = imageUrl(path, false);
      img.alt = '识别图片';
      detail.appendChild(img);
    }
    var bindRow = node('div', 'bglx-review-bind');
    var bindInput = node('input', 'bglx-review-bind-input');
    bindInput.type = 'text';
    bindInput.value = item.manual_image_path || path;
    bindInput.placeholder = '图片相对路径（人工指定）';
    bindRow.appendChild(bindInput);
    bindRow.appendChild(button('应用关联', 'bglx-btn bglx-btn-mini', function () {
      var value = bindInput.value.trim();
      if (!value) { notify('请先填写图片路径', true); return; }
      api('review_link', {
        item_id: item.review_id || '', invoke_id: item.invoke_id || '',
        image_path: value, version: item.version || 1,
        snapshot: { date: item.date, engine: item.engine, duration: item.duration,
                    fields: item.fields, items: item.items }
      }, 'POST').then(function () {
        notify('人工关联已保存'); return refresh();
      }).catch(function (err) { notify('关联失败：' + err.message, true); });
    }));
    detail.appendChild(bindRow);

    // 字段对照
    detail.appendChild(node('h3', 'bglx-review-subtitle', '识别结果（只读）'));
    appendCompareTable(detail, item);
    if (item.trace) {
      var trace = node('pre', 'bglx-trace bglx-review-trace');
      trace.textContent = JSON.stringify(item.trace, null, 2);
      detail.appendChild(node('h3', 'bglx-review-subtitle', '融合裁决 trace'));
      detail.appendChild(trace);
    }

    // 整图三按钮：默认关闭（Q1），开启时降级为批量快捷，本质仍逐行写 judgment
    if (REV.whole_image_judge) {
      detail.appendChild(node('h3', 'bglx-review-subtitle', '人工判定（整图批量 · 快捷）'));
      var judgeBar = node('div', 'bglx-review-judge');
      [['correct', '全部正确'], ['wrong', '全部错误'], ['unreviewed', '全部撤销']].forEach(function (pair) {
        judgeBar.appendChild(button(pair[1], 'bglx-btn bglx-btn-mini', function () {
          var rows = collectCorrections().filter(function (c) { return c.row_key; }).map(function (c) {
            return {
              row_key: c.row_key,
              judgment: pair[0],
              correct_value: pair[0] === 'wrong' ? (c.correct_value || '') : ''
            };
          });
          if (!rows.length) { notify('没有可判定的逐条纠错行', true); return; }
          if (pair[0] === 'wrong' && rows.some(function (r) { return !r.correct_value; })) {
            notify('批量判错误时每行都需先填写正确值', true);
            return;
          }
          api('review_judge', {
            item_id: item.review_id || '', invoke_id: item.invoke_id || '',
            rows: rows, version: item.version || 1,
            snapshot: { date: item.date, engine: item.engine, duration: item.duration,
                        fields: item.fields, items: item.items }
          }, 'POST').then(function () {
            notify('批量判定已保存：' + pair[1]); return refresh();
          }).catch(function (err) {
            notify('批量判定失败：' + err.message + (err.code === 40901 ? '（请刷新后重试）' : ''), true);
          });
        }));
      });
      detail.appendChild(judgeBar);
    }

    // 全部判完 → 审查完成态（顶层 status 由服务端派生，这里只做只读展示）
    if (allJudged(state.corrections)) {
      var done = node('div', 'bglx-review-done');
      done.appendChild(node('strong', '', '✓ 审查完成'));
      done.appendChild(node('span', '', '全部 ' + state.corrections.length
        + ' 条纠错行均已逐条判定，状态已自动派生为 reviewed。'));
      detail.appendChild(done);
    }

    // 逐条纠错
    detail.appendChild(node('h3', 'bglx-review-subtitle', '逐条纠错（错误值 → 正确值，判定请在每行右侧完成）'));
    var corrBox = node('div', 'bglx-review-corrections');
    corrBox.id = 'rv-corrections';
    state.corrections.forEach(function (c) { appendCorrectionRow(corrBox, c); });
    detail.appendChild(corrBox);
    var corrBar = node('div', 'bglx-review-actions');
    corrBar.appendChild(button('+ 添加纠错行', 'bglx-btn bglx-btn-mini', function () {
      appendCorrectionRow(corrBox, null);
    }));
    corrBar.appendChild(button('保存纠错草稿', 'bglx-btn bglx-btn-mini', function () {
      api('review_save_corrections', {
        item_id: item.review_id || '', invoke_id: item.invoke_id || '',
        corrections: collectCorrections(), version: item.version || 1,
        snapshot: { date: item.date, engine: item.engine, duration: item.duration,
                    fields: item.fields, items: item.items }
      }, 'POST').then(function () {
        notify('纠错草稿已保存'); return refresh();
      }).catch(function (err) { notify('保存失败：' + err.message, true); });
    }));
    detail.appendChild(corrBar);

    // 冲突策略
    var policyRow = node('label', 'bglx-review-policy');
    policyRow.appendChild(document.createTextNode('冲突策略 '));
    var policy = document.createElement('select');
    [['ask', '提示（冲突转待审）'], ['skip', '跳过冲突'], ['merge', '合并（冲突转待审）'], ['overwrite', '覆盖（冲突转待审）']]
      .forEach(function (p) {
        var opt = document.createElement('option');
        opt.value = p[0]; opt.textContent = p[1];
        policy.appendChild(opt);
      });
    policy.id = 'rv-conflict-policy';
    policyRow.appendChild(policy);
    detail.appendChild(policyRow);

    // 人工直接导入
    detail.appendChild(node('h3', 'bglx-review-subtitle', '入库'));
    var importBar = node('div', 'bglx-review-actions');
    importBar.appendChild(button('人工直接导入', 'bglx-btn bglx-btn-primary', function () {
      var decisions = collectCorrections();
      decisions.forEach(function (d) { d.source = 'manual'; d.confirmed = true; });
      if (!decisions.some(function (d) { return d.correct_value; })) {
        notify('请至少填写一条正确值', true); return;
      }
      api('review_import_decisions', {
        item_id: item.review_id || '', invoke_id: item.invoke_id || '', source: 'manual',
        decisions: decisions, conflict_policy: policy.value,
        snapshot: { date: item.date, engine: item.engine, duration: item.duration,
                    fields: item.fields, items: item.items }
      }, 'POST').then(function (data) {
        notify('导入完成：新增 ' + data.added + '，待审 ' + data.pending + '，跳过 ' + data.skipped);
        return refresh();
      }).catch(function (err) { notify('导入失败：' + err.message, true); });
    }));
    detail.appendChild(importBar);

    // AI 整理路径
    if (CAP.ai) {
      detail.appendChild(node('h3', 'bglx-review-subtitle', 'AI 整理（候选须人工勾选后导入）'));
      var aiBar = node('div', 'bglx-review-actions');
      aiBar.appendChild(button('AI 整理纠错', 'bglx-btn', function () {
        api('review_ai_organize', {
          item_id: item.review_id || '', invoke_id: item.invoke_id || '',
          version: item.version || 1
        }, 'POST').then(function (data) {
          state.candidates = data.candidates || [];
          renderCandidates(detail);
          notify('AI 候选已生成，请逐条勾选采纳（不会自动入库）');
        }).catch(function (err) {
          notify('AI 整理失败：' + err.message
            + (err.data && err.data.fallback ? '，已降级为人工导入（见上方按钮）' : ''), true);
        });
      }));
      detail.appendChild(aiBar);
      renderCandidates(detail);
    }
  }

  /** 渲染候选区与「导入 AI 候选」按钮 */
  function renderCandidates(detail) {
    var old = el('rv-candidates');
    if (old && old.parentNode) { old.parentNode.removeChild(old); }
    var oldBar = el('rv-ai-import-bar');
    if (oldBar && oldBar.parentNode) { oldBar.parentNode.removeChild(oldBar); }
    var box = node('div', 'bglx-review-candidates');
    box.id = 'rv-candidates';
    state.candidates.forEach(function (c, i) { appendCandidateCard(box, c, i); });
    if (!state.candidates.length) {
      box.appendChild(node('p', 'bglx-hint', '暂无 AI 候选。可先保存纠错草稿，再点「AI 整理纠错」。'));
    }
    detail.appendChild(box);
    var bar = node('div', 'bglx-review-actions');
    bar.id = 'rv-ai-import-bar';
    var item = state.selected;
    bar.appendChild(button('导入已勾选的 AI 候选', 'bglx-btn bglx-btn-primary', function () {
      var decisions = collectCandidateDecisions().filter(function (d) { return d.accepted; });
      if (!decisions.length) { notify('请至少勾选一条 AI 候选', true); return; }
      api('review_import_decisions', {
        item_id: item.review_id || '', invoke_id: item.invoke_id || '', source: 'ai',
        decisions: decisions, conflict_policy: el('rv-conflict-policy') ? el('rv-conflict-policy').value : 'ask'
      }, 'POST').then(function (data) {
        notify('AI 候选导入完成：新增 ' + data.added + '，待审 ' + data.pending);
        return refresh();
      }).catch(function (err) { notify('AI 候选导入失败：' + err.message, true); });
    }));
    detail.appendChild(bar);
  }

  /** 绑定筛选事件 */
  function bindEvents() {
    el('rv-search').addEventListener('click', function () {
      state.filters = {
        date_from: el('rv-date-from').value,
        date_to: el('rv-date-to').value,
        engine: el('rv-engine').value.trim(),
        judgment: el('rv-judgment').value,
        keyword: el('rv-keyword').value.trim()
      };
      state.page = 1;
      loadLogs();
    });
    el('rv-keyword').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { el('rv-search').click(); }
    });
    el('rv-reset').addEventListener('click', function () {
      ['rv-date-from', 'rv-date-to', 'rv-engine', 'rv-keyword'].forEach(function (id) { el(id).value = ''; });
      el('rv-judgment').value = '';
      state.filters = { date_from: '', date_to: '', engine: '', judgment: '', keyword: '' };
      state.page = 1;
      loadLogs();
    });
  }

  /** 首次进入审查 Tab 时加载 */
  function initOnce() {
    if (state.loaded) { return; }
    state.loaded = true;
    bindEvents();
    loadStats();
    loadLogs();
  }

  document.addEventListener('bglx:tab', function (e) {
    if (e && e.detail === 'review') { initOnce(); }
  });
  // admin.js 可能先于本脚本派发事件，这里兜底检查当前激活面板
  var active = document.querySelector('.bglx-tabpanel[data-panel="review"]:not([hidden])');
  if (active) { initOnce(); }
})();
