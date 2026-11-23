/* 模块: features/pdf
   职责: 内置 PDF 查看器的打开/关闭与 iframe 复用
   依赖: 无
   导出: openPdfViewer closePdfViewer
   注意: 关闭时只隐藏不重置 iframe.src，避免中断加载 */
'use strict';

/* 内置 PDF 查看器（PDF.js）。path 为图纸相对路径，如 drawings/xxx.pdf */
function openPdfViewer(path, name) {
  if (!path) return;
  let ov = document.getElementById('pdfOverlay');
  if (!ov) {
    ov = document.createElement('div');
    ov.id = 'pdfOverlay';
    ov.className = 'pdf-overlay';
    ov.innerHTML = '<iframe id="pdfFrame" title="图纸查看" src="about:blank"></iframe>';
    document.body.appendChild(ov);
  }
  const frame = ov.querySelector('#pdfFrame');
  const next = 'pdf.html?v=2.8&f=' + encodeURIComponent(path) + '&name=' + encodeURIComponent(name || '图纸');
  // 同一文件重复打开时复用已加载的 iframe，避免中断正在进行的请求
  if (frame.dataset.src !== next) {
    frame.dataset.src = next;
    frame.src = next;
  }
  ov.hidden = false;
  document.body.classList.add('pdf-open');
}

function closePdfViewer() {
  const ov = document.getElementById('pdfOverlay');
  if (!ov) return;
  ov.hidden = true;
  document.body.classList.remove('pdf-open');
  // 只隐藏、不重置 iframe.src：重置会中断加载中的 PDF 请求（net::ERR_ABORTED）
}

window.addEventListener('message', (e) => {
  if (e.data === 'bg-pdf-close') closePdfViewer();
});
