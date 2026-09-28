// Visionneuse PDF intégrée (pdf.js) : toutes les pages, à la largeur de l'écran,
// avec un bouton Partager (iPhone / Android) ou Télécharger.
const viewer = document.getElementById('pdf-viewer');
const status = viewer.querySelector('[data-pdf-status]');
const fallback = viewer.querySelector('[data-pdf-fallback]');
const shareButton = document.querySelector('[data-pdf-share]');
let pdfDocument = null;
let renderedWidth = 0;
let blob = null;

async function loadBlob() {
  if (!blob) {
    const response = await fetch(viewer.dataset.src, { credentials: 'same-origin' });
    if (!response.ok) { throw new Error('HTTP ' + response.status); }
    blob = await response.blob();
  }
  return blob;
}

async function render() {
  const width = viewer.clientWidth - 16;
  if (!pdfDocument || Math.abs(width - renderedWidth) < 40) { return; }
  renderedWidth = width;
  viewer.querySelectorAll('canvas').forEach((canvas) => canvas.remove());
  const ratio = Math.min(window.devicePixelRatio || 1, 3);
  for (let number = 1; number <= pdfDocument.numPages; number++) {
    const page = await pdfDocument.getPage(number);
    const scale = width / page.getViewport({ scale: 1 }).width;
    const viewport = page.getViewport({ scale: scale * ratio });
    const canvas = document.createElement('canvas');
    canvas.width = Math.floor(viewport.width);
    canvas.height = Math.floor(viewport.height);
    canvas.style.width = width + 'px';
    canvas.setAttribute('aria-label', 'Page ' + number + ' sur ' + pdfDocument.numPages);
    canvas.setAttribute('role', 'img');
    viewer.appendChild(canvas);
    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
  }
  status.textContent = pdfDocument.numPages > 1 ? pdfDocument.numPages + ' pages' : '';
  status.hidden = pdfDocument.numPages < 2;
}

async function start() {
  try {
    const lib = await import(viewer.dataset.lib);
    lib.GlobalWorkerOptions.workerSrc = viewer.dataset.worker;
    const data = new Uint8Array(await (await loadBlob()).arrayBuffer());
    pdfDocument = await lib.getDocument({ data, isEvalSupported: false }).promise;
    await render();
  } catch (error) {
    status.hidden = true;
    fallback.hidden = false;
  }
}

let resizeTimer = null;
window.addEventListener('resize', () => {
  clearTimeout(resizeTimer);
  resizeTimer = setTimeout(render, 250);
});

shareButton.addEventListener('click', async () => {
  try {
    const file = new File([await loadBlob()], viewer.dataset.filename, { type: 'application/pdf' });
    if (navigator.canShare && navigator.canShare({ files: [file] })) {
      await navigator.share({ files: [file], title: viewer.dataset.filename });
      return;
    }
    const link = document.createElement('a');
    link.href = URL.createObjectURL(file);
    link.download = viewer.dataset.filename;
    document.body.appendChild(link);
    link.click();
    setTimeout(() => { URL.revokeObjectURL(link.href); link.remove(); }, 1000);
  } catch (error) {
    if (error && error.name === 'AbortError') { return; }
    window.open(viewer.dataset.src, '_blank');
  }
});

start();
