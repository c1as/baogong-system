/* 模块: views/parts
   路由: #/parts
   职责: 零件列表、搜索、分页、图纸/图片入口
   依赖: core/* ui/* features/*
   导出: viewParts loadParts renderPartRows
   注意: 行内按钮通过 data-act 交给全局 click 处理 */
'use strict';

/* ==================================================================== 零件列表 */

function viewParts() {
  setHead(
    '零件列表',
    '每个零件可设置多道工序和单价',
    `<div class="search">${icon('search', 16)}<input class="input" id="partSearch" placeholder="搜索图号 / 名称 / 材料" value="${esc(partQuery)}" autocomplete="off"></div>
     <button class="btn btn-ghost" data-action="parts-import">${icon('upload', 16)}导入</button>
     <button class="btn btn-ghost" data-action="parts-export">${icon('download', 16)}导出</button>
     <button class="btn btn-primary" data-action="new-part">${icon('plus', 16)}新增零件</button>`
  );

  partStore = newStore();
  const view = $('#view');
  if (!state.counts.parts) {
    $('#pageSub').textContent = '先添加零件，再开始报工';
    view.innerHTML = emptyState(
      'box',
      '还没有零件',
      '先添加零件：图号必填，名称、材料、工序单价都可以后补。之后报工只要选零件、填数量，金额自动算好。',
      '新增第一个零件',
      'new-part'
    );
    return;
  }

  view.innerHTML = `<div class="part-list" id="partList"></div>`;
  loadParts(true);

  $('#partSearch').addEventListener(
    'input',
    debounce((e) => {
      partQuery = e.target.value;
      loadParts(true);
    })
  );
}

async function loadParts(reset) {
  const box = $('#partList');
  if (!box) return;
  if (reset) {
    partStore.items = [];
    partStore.page = 0;
    partStore.total = 0;
    partStore.hasMore = false;
    box.innerHTML = '<div class="empty" style="padding:40px 20px"><p>加载中…</p></div>';
  }
  const my = ++partStore.seq;
  try {
    const params = new URLSearchParams({
      page: String(partStore.page + 1),
      pageSize: '100',
      q: partQuery.trim(),
    });
    const data = await api('GET', 'api.php?action=parts.list&' + params.toString());
    if (my !== partStore.seq) return;
    const list = (data.items || []).map(cachePart);
    partStore.items = reset ? list : partStore.items.concat(list);
    partStore.total = Number(data.total) || 0;
    partStore.page = Number(data.page) || 1;
    partStore.hasMore = !!data.hasMore;
    renderPartRows();
  } catch (err) {
    if (my !== partStore.seq) return;
    if (err.status !== 401) toast(err.message, 'err');
    if (reset) {
      box.innerHTML = `<div class="empty" style="padding:40px 20px"><h3>读取零件失败</h3><p>${esc(
        err.message || '请稍后重试'
      )}</p></div>`;
    }
  }
}

function renderPartRows() {
  const box = $('#partList');
  if (!box) return;
  const list = partStore.items;
  $('#pageSub').textContent = `显示 ${fmtQty(list.length)} / ${fmtQty(partStore.total)} 个零件（共 ${fmtQty(
    state.counts.parts
  )} 个）`;

  if (!list.length) {
    box.innerHTML = `<div class="empty" style="padding:40px 20px">
      <h3>没有匹配的零件</h3><p>换个关键词试试，或新增零件。</p>
      <button class="btn btn-ghost" data-action="new-part">新增零件</button></div>`;
    return;
  }

  box.innerHTML =
    list
      .map((p) => {
        const u = p.usage || { qty: 0, times: 0 };
        const ps = partProcesses(p);
        const drawings = Array.isArray(p.drawings) ? p.drawings : [];
        const chips = ps.length
          ? ps
              .map((x) => {
                const chip = `<span class="chip">${esc(x.name || '工序')}<b>${money(x.price)}</b></span>`;
                return x.desc
                  ? `<div class="pu">${chip}<span class="pu-desc">${esc(x.desc)}</span></div>`
                  : `<div class="pu">${chip}</div>`;
              })
              .join('')
          : '<span class="chip muted">未设置工序</span>';
        const metaBits = [];
        if (p.material) metaBits.push(esc(p.material));
        if (u.times) {
          metaBits.push(`<span class="meta-usage">${p.material ? ' · ' : ''}已报 ${fmtQty(u.qty)} 件</span>`);
        }
        const meta = metaBits.join('');
        return `<div class="part-row">
        <button type="button" class="part-thumb" data-act="part-image" data-id="${p.id}" title="${p.image ? '点击放大零件图片' : '暂无零件图片'}">${p.image ? `<img src="${esc(imgUrl(p.image))}" alt="" loading="lazy">` : icon('image', 26)}</button>
        <div class="part-main">
          <div class="part-name"><span class="nm">${esc(p.no)}</span>${p.name ? `<span class="name-tag">${esc(p.name)}</span>` : ''}</div>
          ${meta ? `<div class="part-meta">${meta}</div>` : ''}
          <div class="proc-chips proc-chips-with-desc">${chips}</div>
        </div>
        <div class="part-actions">
          <div class="pa-btns">
            <button class="icon-btn" data-act="part-report" data-id="${p.id}" title="用这个零件报工">${icon('plusCircle', 18)}</button>
            <button class="icon-btn" data-act="part-edit" data-id="${p.id}" title="编辑">${icon('edit', 17)}</button>
            <button class="icon-btn danger" data-act="part-del" data-id="${p.id}" title="删除">${icon('trash', 17)}</button>
          </div>
          ${drawings.length ? `<button class="drawing-btn" data-act="part-drawings" data-id="${p.id}" title="查看零件图纸">${icon('file', 15)}图纸 (${drawings.length})</button>` : ''}
        </div>
      </div>`;
      })
      .join('') +
    (partStore.hasMore
      ? `<div class="load-more"><button class="btn btn-ghost" id="loadMoreParts">加载更多（还有 ${fmtQty(
          partStore.total - list.length
        )} 个）</button></div>`
      : '');

  const more = $('#loadMoreParts');
  if (more) {
    more.addEventListener('click', () => {
      if (partStore.hasMore) loadParts(false);
    });
  }

}
