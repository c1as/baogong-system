/* 模块: core/range
   职责: 日期范围预设、范围解析与范围筛选条渲染
   依赖: core/util
   导出: RANGE_PRESETS dstr monthStartOf monthEndOf shiftDate rangePresetValue resolveRange rangeLabel rangeFilterHtml setupRangeFilter
   注意: 文件末尾有范围预设的全局 click 监听 */
'use strict';

/* ==================================================================== 日期范围 */

const RANGE_PRESETS = [
  { key: 'month', label: '本月' },
  { key: 'week', label: '近一周' },
  { key: 'month1', label: '近一个月' },
  { key: 'month3', label: '近三个月' },
  { key: 'year1', label: '近一年' },
  { key: 'all', label: '全部' },
  { key: 'custom', label: '自定义' },
];

function dstr(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function monthStartOf(dateStr) {
  return String(dateStr || todayStr()).slice(0, 7) + '-01';
}

function monthEndOf(dateStr) {
  const s = String(dateStr || todayStr());
  return dstr(new Date(Number(s.slice(0, 4)), Number(s.slice(5, 7)), 0));
}

function shiftDate(base, months, days) {
  const d = new Date(base + 'T00:00:00');
  d.setMonth(d.getMonth() + months);
  d.setDate(d.getDate() + days);
  return dstr(d);
}

function rangePresetValue(key) {
  const today = todayStr();
  if (key === 'month') return { from: monthStartOf(today), to: monthEndOf(today) };
  if (key === 'week') return { from: shiftDate(today, 0, -6), to: today };
  if (key === 'month1') return { from: shiftDate(today, -1, 1), to: today };
  if (key === 'month3') return { from: shiftDate(today, -3, 1), to: today };
  if (key === 'year1') return { from: shiftDate(today, -12, 1), to: today };
  return { from: '', to: '' };
}

/* 预置范围每次打开页面都按今天重算，自定义范围保持原样 */
function resolveRange(range) {
  if (!range) return { key: 'all', from: '', to: '' };
  if (range.key === 'custom') return range;
  const v = rangePresetValue(range.key);
  return { key: range.key, from: v.from, to: v.to };
}

function rangeLabel(range) {
  if (range.key === 'custom') {
    if (!range.from && !range.to) return '自定义';
    return `${range.from || '不限'} ~ ${range.to || '不限'}`;
  }
  const hit = RANGE_PRESETS.find((x) => x.key === range.key);
  return hit ? hit.label : '全部';
}

function rangeFilterHtml(id, range) {
  return `<div class="range-pick" id="${id}">
      <button type="button" class="range-trigger"><span>${esc(rangeLabel(range))}</span><i>▾</i></button>
      <div class="range-panel" hidden>
        <div class="range-quick">
          ${RANGE_PRESETS.map(
            (p) => `<button type="button" data-key="${p.key}"${p.key === range.key ? ' class="on"' : ''}>${p.label}</button>`
          ).join('')}
        </div>
        <div class="range-custom"${range.key === 'custom' ? '' : ' hidden'}>
          <label>开始<input class="input" type="date" data-w="from" value="${esc(range.from || '')}"></label>
          <label>结束<input class="input" type="date" data-w="to" value="${esc(range.to || '')}"></label>
          <button type="button" class="btn btn-primary" data-w="ok">确定</button>
        </div>
      </div>
    </div>`;
}

function setupRangeFilter(el, range, onChange) {
  if (!el) return;
  const panel = $('.range-panel', el);
  const trigger = $('.range-trigger', el);
  const custom = $('.range-custom', el);
  const fromInput = $('[data-w="from"]', el);
  const toInput = $('[data-w="to"]', el);
  let state = { key: range.key, from: range.from, to: range.to };

  function paint() {
    $('span', trigger).textContent = rangeLabel(state);
    $$('.range-quick [data-key]', el).forEach((b) => b.classList.toggle('on', b.dataset.key === state.key));
    fromInput.value = state.from || '';
    toInput.value = state.to || '';
    custom.hidden = state.key !== 'custom';
  }

  paint();

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    const show = panel.hidden;
    $$('.range-panel').forEach((p) => (p.hidden = true));
    panel.hidden = !show;
  });
  panel.addEventListener('click', (e) => e.stopPropagation());

  $$('.range-quick [data-key]', el).forEach((btn) => {
    btn.addEventListener('click', () => {
      const key = btn.dataset.key;
      if (key === 'custom') {
        custom.hidden = false;
        if (!fromInput.value) fromInput.value = monthStartOf(todayStr());
        if (!toInput.value) toInput.value = todayStr();
        return;
      }
      const v = rangePresetValue(key);
      state = { key, from: v.from, to: v.to };
      paint();
      panel.hidden = true;
      onChange(state);
    });
  });

  $('[data-w="ok"]', el).addEventListener('click', () => {
    const from = fromInput.value;
    const to = toInput.value;
    if (from && to && from > to) return toast('开始日期不能晚于结束日期', 'err');
    state = { key: 'custom', from, to };
    paint();
    panel.hidden = true;
    onChange(state);
  });
}

document.addEventListener('click', () => {
  $$('.range-panel').forEach((p) => (p.hidden = true));
});
