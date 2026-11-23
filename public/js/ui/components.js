/* 模块: ui/components
   职责: 通用组件：空态、toast、弹窗、确认框、下拉组合框
   依赖: core/util core/icons
   导出: emptyState toast openModal confirmDialog comboHtml closeAllCombos setupCombo
   注意: 弹窗挂在 #modalRoot 下 */
'use strict';

/* ==================================================================== 通用组件 */

function emptyState(ic, title, text, btnText, action) {
  return `<div class="empty">
    <div class="empty-icon">${icon(ic, 26)}</div>
    <h3>${esc(title)}</h3>
    <p>${esc(text)}</p>
    ${btnText ? `<button class="btn btn-primary" data-action="${action}">${icon('plus', 16)}${esc(btnText)}</button>` : ''}
  </div>`;
}

function toast(msg, type = '') {
  const wrap = $('#toastWrap');
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.textContent = msg;
  wrap.appendChild(el);
  setTimeout(() => {
    el.style.transition = 'opacity .25s, transform .25s';
    el.style.opacity = '0';
    el.style.transform = 'translateY(6px)';
    setTimeout(() => el.remove(), 260);
  }, 2600);
}

function openModal(html, onDismiss) {
  const root = $('#modalRoot');
  root.innerHTML = `<div class="modal-mask"><div class="modal" role="dialog" aria-modal="true">${html}</div></div>`;
  const mask = root.firstElementChild;
  let closed = false;
  const onKey = (e) => {
    if (e.key === 'Escape') dismiss();
  };
  function close() {
    if (closed) return;
    closed = true;
    root.innerHTML = '';
    document.removeEventListener('keydown', onKey);
  }
  function dismiss() {
    if (closed) return;
    close();
    if (onDismiss) onDismiss();
  }
  document.addEventListener('keydown', onKey);
  mask.addEventListener('mousedown', (e) => {
    if (e.target === mask) dismiss();
  });
  return { root, close };
}

function confirmDialog({ title, message, okText = '确定', cancelText = '取消', danger = false }) {
  return new Promise((resolve) => {
    let settled = false;
    const { root, close } = openModal(
      `<h3>${esc(title)}</h3>
       <p>${esc(message)}</p>
       <div class="modal-actions">
         <button class="btn btn-ghost" data-cancel>${esc(cancelText)}</button>
         <button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-ok>${esc(okText)}</button>
       </div>`,
      () => {
        if (!settled) {
          settled = true;
          resolve(false);
        }
      }
    );
    root.querySelector('[data-ok]').addEventListener('click', () => {
      settled = true;
      close();
      resolve(true);
    });
    root.querySelector('[data-cancel]').addEventListener('click', () => {
      settled = true;
      close();
      resolve(false);
    });
  });
}

/* -------------------------------------------------------------------- 下拉选择 */

function comboHtml(value) {
  return `<div class="combo">
      <input class="combo-input" value="${esc(value)}" placeholder="搜索图号或名称" autocomplete="off" spellcheck="false">
      <div class="combo-menu" hidden></div>
    </div>`;
}

function closeAllCombos() {
  $$('.combo-menu').forEach((m) => (m.hidden = true));
}

function setupCombo(combo, onPick, onInput) {
  const input = combo.querySelector('.combo-input');
  const menu = combo.querySelector('.combo-menu');
  let matches = [];
  let active = -1;
  let seq = 0;

  function paint(loading) {
    if (loading && !matches.length) {
      menu.innerHTML = '<div class="combo-empty">搜索中…</div>';
    } else if (!matches.length) {
      menu.innerHTML = state.counts.parts
        ? '<div class="combo-empty">没有匹配的零件</div>'
        : '<div class="combo-empty">还没有零件，<a href="#/parts/new" style="color:var(--brand)">去新增零件 →</a></div>';
    } else {
      menu.innerHTML = matches
        .map(
          (p, i) => `<button type="button" class="combo-item" data-i="${i}">
            <span class="ci-img">${p.image ? `<img src="${esc(imgUrl(p.image))}" alt="" loading="lazy">` : icon('image', 16)}</span>
            <span class="ci-main">${esc(p.no)}</span>
            <span class="ci-sub">${esc(p.name || '')}${partProcesses(p).length ? `${p.name ? ' · ' : ''}${partProcesses(p).map((x) => esc(x.name)).join(' / ')}` : ''}</span>
            <span class="ci-price">${partPriceLabel(p)}</span>
          </button>`
        )
        .join('');
    }
    menu.hidden = false;
    active = matches.length ? 0 : -1;
    highlight();
  }

  async function draw(filter) {
    const my = ++seq;
    paint(true);
    try {
      const data = await api(
        'GET',
        'api.php?action=parts.search&limit=40&q=' + encodeURIComponent(String(filter || '').trim())
      );
      if (my !== seq) return;
      matches = (data.items || []).map(cachePart);
    } catch (err) {
      if (my !== seq) return;
      matches = [];
      if (err.status === 401) return;
    }
    paint(false);
  }

  function highlight() {
    $$('.combo-item', menu).forEach((el, i) => el.classList.toggle('active', i === active));
    const cur = menu.querySelector('.combo-item.active');
    if (cur) cur.scrollIntoView({ block: 'nearest' });
  }

  function pick(p) {
    input.value = partLabel(p);
    onPick(p);
    menu.hidden = true;
  }

  input.addEventListener('focus', () => draw(''));
  input.addEventListener('input', () => {
    draw(input.value);
    onPick(null);
    if (onInput) onInput(input.value);
  });
  input.addEventListener('keydown', (e) => {
    if (menu.hidden) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (!matches.length) return;
      active = (active + (e.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length;
      highlight();
    } else if (e.key === 'Enter') {
      if (active >= 0 && matches[active]) {
        e.preventDefault();
        pick(matches[active]);
      }
    } else if (e.key === 'Escape' || e.key === 'Tab') {
      menu.hidden = true;
    }
  });
  menu.addEventListener('pointerdown', (e) => {
    const btn = e.target.closest('.combo-item');
    if (!btn) return;
    e.preventDefault();
    const p = matches[Number(btn.dataset.i)];
    if (p) pick(p);
  });
}
