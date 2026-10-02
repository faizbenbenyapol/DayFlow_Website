/* =====================================================
   file-tools.js
   ===================================================== */

'use strict';

// ─── Helpers ────────────────────────────────────────
const $ = (id) => document.getElementById(id);
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function setStatus(id, msg, type = 'info') {
    const el = $(id);
    if (!el) return;
    el.textContent = msg;
    el.className = 'ft-status ' + type;
}

function fmtBytes(b) {
    if (b < 1024) return b + ' B';
    if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
    return (b / 1024 / 1024).toFixed(2) + ' MB';
}

function triggerDownload(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 2000);
}

function copyToClipboard(text) {
    navigator.clipboard?.writeText(text).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy'); ta.remove();
    });
}

// ─── Tab switching ────────────────────────────────────
{
    const tabs = Array.from(document.querySelectorAll('.ft-tab'));
    const select = (tab, focus) => {
        tabs.forEach(b => {
            b.setAttribute('aria-selected', String(b === tab));
            b.tabIndex = b === tab ? 0 : -1;
        });
        document.querySelectorAll('.ft-panel').forEach(p => p.classList.toggle('active', p.dataset.panel === tab.dataset.tab));
        if (focus) tab.focus();
    };
    tabs.forEach((tab, i) => {
        tab.tabIndex = tab.getAttribute('aria-selected') === 'true' ? 0 : -1;
        tab.addEventListener('click', () => select(tab));
        tab.addEventListener('keydown', e => {
            const step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
            if (!step) return;
            e.preventDefault();
            select(tabs[(i + step + tabs.length) % tabs.length], true);
        });
    });

    // Ties each label to the field that follows it, so a click on the label reaches the field.
    let n = 0;
    document.querySelectorAll('.form-group').forEach(group => {
        const label = group.querySelector('label.form-label:not([for])');
        const field = group.querySelector('input:not([type="hidden"]), select, textarea');
        if (!label || !field) return;
        if (!field.id) field.id = 'ft-field-' + (++n);
        label.htmlFor = field.id;
    });
};

// ─── Drop zone wiring ────────────────────────────────
function wireDropZone(dropId, inputId, onFiles) {
    const zone  = $(dropId);
    const input = $(inputId);
    if (!zone || !input) return;

    zone.addEventListener('click', (e) => {
        if (e.target.tagName !== 'LABEL') input.click();
    });
    input.addEventListener('change', () => { if (input.files.length) onFiles(input.files); });

    zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
    zone.addEventListener('drop', (e) => {
        e.preventDefault();
        zone.classList.remove('dragover');
        if (e.dataTransfer.files.length) onFiles(e.dataTransfer.files);
    });
}

// ─── Server API call helper ───────────────────────────
async function apiPost(path, formData, statusId) {
    setStatus(statusId, 'กำลังประมวลผล…', 'info');
    const res = await fetch(BASE_URL + path, {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken() },
        body: formData,
    });
    if (!res.ok) {
        let msg = 'เกิดข้อผิดพลาด';
        try { const j = await res.json(); msg = j.error || msg; } catch (_) {}
        setStatus(statusId, msg, 'err');
        return null;
    }
    return res;
}

// ═══════════════════════════════════════════════════════
// PDF TOOLS  (pdf-lib + PDF.js)
// ═══════════════════════════════════════════════════════

// Wait for libs to load
function pdfLib() { return window.PDFLib; }
function pdfjsLib() {
    const lib = window['pdfjs-dist/build/pdf'] || window.pdfjsLib || window['pdfjs'];
    return lib;
}

// Set up PDF.js worker lazily
let workerSet = false;
function ensurePdfWorker() {
    const lib = pdfjsLib();
    if (!lib || workerSet) return;
    // Same CDN and version as the pdf.min.js in footer.php. A cross-origin
    // worker is started through a blob: wrapper, which the CSP allows.
    lib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
    workerSet = true;
}

async function readFileAsArrayBuffer(file) {
    return new Promise((res, rej) => {
        const fr = new FileReader();
        fr.onload = () => res(fr.result);
        fr.onerror = rej;
        fr.readAsArrayBuffer(file);
    });
}

async function readFileAsDataURL(file) {
    return new Promise((res, rej) => {
        const fr = new FileReader();
        fr.onload = () => res(fr.result);
        fr.onerror = rej;
        fr.readAsDataURL(file);
    });
}

// ── Render PDF page thumbnails ─────────────────────────
async function renderPageThumbnails(pdfBytes, gridId, checkboxName, onRendered) {
    ensurePdfWorker();
    const lib = pdfjsLib();
    if (!lib) { toast('PDF.js ยังโหลดไม่เสร็จ กรุณารอสักครู่แล้วลองใหม่', 'danger'); return; }

    const grid = $(gridId);
    grid.innerHTML = '';
    grid.hidden = false;

    const pdf = await lib.getDocument({ data: pdfBytes.slice(0) }).promise;
    const scale = 0.5;

    for (let i = 1; i <= pdf.numPages; i++) {
        const page    = await pdf.getPage(i);
        const vp      = page.getViewport({ scale });
        const canvas  = document.createElement('canvas');
        canvas.width  = vp.width;
        canvas.height = vp.height;
        await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;

        const thumb = document.createElement('div');
        thumb.className = 'ft-page-thumb';
        thumb.dataset.page = i;

        const chk = document.createElement('input');
        chk.type  = 'checkbox';
        chk.name  = checkboxName;
        chk.value = i;
        chk.className = 'ft-page-check';

        const lbl = document.createElement('div');
        lbl.className = 'ft-page-num';
        lbl.textContent = 'หน้า ' + i;

        thumb.appendChild(canvas);
        thumb.appendChild(chk);
        thumb.appendChild(lbl);

        thumb.addEventListener('click', (e) => {
            if (e.target === chk) return;
            chk.checked = !chk.checked;
            thumb.classList.toggle('selected', chk.checked);
            if (onRendered) onRendered();
        });
        chk.addEventListener('change', () => {
            thumb.classList.toggle('selected', chk.checked);
            if (onRendered) onRendered();
        });

        grid.appendChild(thumb);
    }
}

function getCheckedPages(gridId) {
    return Array.from(document.querySelectorAll(`#${gridId} input[type=checkbox]:checked`))
        .map(c => parseInt(c.value));
}

