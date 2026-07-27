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
document.querySelectorAll('.ft-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.ft-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.ft-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.querySelector(`.ft-panel[data-panel="${btn.dataset.tab}"]`)?.classList.add('active');
    });
});

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
    // Use CDN worker matching the loaded version
    lib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
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

// ── Merge PDF ──────────────────────────────────────────
(function () {
    let mergeFiles = [];

    const listEl  = $('mergeFileList');
    const btnMerge = $('btnMerge');

    function render() {
        listEl.innerHTML = '';
        mergeFiles.forEach((f, i) => {
            const item = document.createElement('div');
            item.className = 'ft-file-item';
            item.innerHTML = `<span>${f.name} <span class="text-muted">(${fmtBytes(f.size)})</span></span><span class="ft-remove" data-i="${i}">✕</span>`;
            listEl.appendChild(item);
        });
        btnMerge.disabled = mergeFiles.length < 2;
    }

    listEl.addEventListener('click', (e) => {
        const rm = e.target.closest('.ft-remove');
        if (rm) { mergeFiles.splice(+rm.dataset.i, 1); render(); }
    });

    wireDropZone('mergeDrop', 'mergeInput', (files) => {
        Array.from(files).forEach(f => {
            if (f.type === 'application/pdf' || f.name.endsWith('.pdf')) mergeFiles.push(f);
        });
        render();
    });

    btnMerge.addEventListener('click', async () => {
        if (!pdfLib()) { setStatus('mergeStatus', 'pdf-lib ยังโหลดไม่เสร็จ', 'err'); return; }
        setStatus('mergeStatus', 'กำลังรวม PDF…', 'info');
        btnMerge.disabled = true;
        try {
            const merged = await pdfLib().PDFDocument.create();
            for (const f of mergeFiles) {
                const buf = await readFileAsArrayBuffer(f);
                const doc = await pdfLib().PDFDocument.load(buf);
                const pages = await merged.copyPages(doc, doc.getPageIndices());
                pages.forEach(p => merged.addPage(p));
            }
            const bytes = await merged.save();
            triggerDownload(new Blob([bytes], { type: 'application/pdf' }), 'merged.pdf');
            setStatus('mergeStatus', `รวม ${mergeFiles.length} ไฟล์สำเร็จ`, 'ok');
        } catch (err) {
            setStatus('mergeStatus', 'เกิดข้อผิดพลาด: ' + err.message, 'err');
        } finally {
            btnMerge.disabled = mergeFiles.length < 2;
        }
    });
})();

// ── Split PDF ──────────────────────────────────────────
(function () {
    let splitFile = null;
    wireDropZone('splitDrop', 'splitInput', (files) => {
        splitFile = files[0];
        $('splitFileName').textContent = splitFile.name + ' (' + fmtBytes(splitFile.size) + ')';
        $('btnSplit').disabled = false;
    });

    $('btnSplit').addEventListener('click', async () => {
        if (!splitFile || !pdfLib()) return;
        const rangeStr = $('splitRange').value.trim();
        if (!rangeStr) { setStatus('splitStatus', 'กรุณาระบุช่วงหน้า', 'err'); return; }

        setStatus('splitStatus', 'กำลังแยกหน้า…', 'info');
        try {
            const buf  = await readFileAsArrayBuffer(splitFile);
            const src  = await pdfLib().PDFDocument.load(buf);
            const total = src.getPageCount();

            // Parse ranges like "1-3,5,7-9"
            const pages = new Set();
            rangeStr.split(',').forEach(part => {
                part = part.trim();
                if (part.includes('-')) {
                    const [a, b] = part.split('-').map(Number);
                    for (let i = a; i <= b; i++) if (i >= 1 && i <= total) pages.add(i);
                } else {
                    const n = parseInt(part);
                    if (n >= 1 && n <= total) pages.add(n);
                }
            });

            if (!pages.size) { setStatus('splitStatus', 'ไม่พบหน้าตามช่วงที่ระบุ', 'err'); return; }

            const out = await pdfLib().PDFDocument.create();
            const indices = [...pages].sort((a, b) => a - b).map(p => p - 1);
            const copied = await out.copyPages(src, indices);
            copied.forEach(p => out.addPage(p));

            const bytes = await out.save();
            triggerDownload(new Blob([bytes], { type: 'application/pdf' }), 'split.pdf');
            setStatus('splitStatus', `แยก ${pages.size} หน้าสำเร็จ`, 'ok');
        } catch (err) {
            setStatus('splitStatus', 'เกิดข้อผิดพลาด: ' + err.message, 'err');
        }
    });
})();

// ── Delete pages ───────────────────────────────────────
(function () {
    let delFile = null;
    let delBytes = null;

    wireDropZone('deleteDrop', 'deleteInput', async (files) => {
        delFile  = files[0];
        delBytes = await readFileAsArrayBuffer(delFile);
        $('deleteFileName').textContent = delFile.name;
        $('deleteStatus').textContent   = '';
        await renderPageThumbnails(new Uint8Array(delBytes), 'deletePageGrid', 'del-page', updateDelInfo);
        updateDelInfo();
    });

    function updateDelInfo() {
        const sel = getCheckedPages('deletePageGrid');
        const infoEl  = $('deleteSelInfo');
        const btnDel  = $('btnDeletePages');
        infoEl.hidden = false;
        infoEl.textContent = `เลือก ${sel.length} หน้าเพื่อลบ`;
        btnDel.disabled = sel.length === 0 || !delBytes;
    }

    $('btnDeletePages').addEventListener('click', async () => {
        const toDelete = new Set(getCheckedPages('deletePageGrid'));
        if (!toDelete.size || !delBytes) return;
        setStatus('deleteStatus', 'กำลังลบหน้า…', 'info');
        try {
            const src = await pdfLib().PDFDocument.load(delBytes.slice(0));
            const total = src.getPageCount();
            const keep  = [];
            for (let i = 1; i <= total; i++) if (!toDelete.has(i)) keep.push(i - 1);
            if (!keep.length) { setStatus('deleteStatus', 'ไม่สามารถลบทุกหน้าได้', 'err'); return; }
            const out = await pdfLib().PDFDocument.create();
            const pages = await out.copyPages(src, keep);
            pages.forEach(p => out.addPage(p));
            const bytes = await out.save();
            triggerDownload(new Blob([bytes], { type: 'application/pdf' }), 'deleted-pages.pdf');
            setStatus('deleteStatus', `ลบ ${toDelete.size} หน้าสำเร็จ`, 'ok');
        } catch (err) {
            setStatus('deleteStatus', 'เกิดข้อผิดพลาด: ' + err.message, 'err');
        }
    });
})();

// ── Rotate pages ───────────────────────────────────────
(function () {
    let rotFile  = null;
    let rotBytes = null;
    let rotAngle = 90;

    document.querySelectorAll('.ft-angle-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.ft-angle-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            rotAngle = parseInt(btn.dataset.angle);
        });
    });

    wireDropZone('rotateDrop', 'rotateInput', async (files) => {
        rotFile  = files[0];
        rotBytes = await readFileAsArrayBuffer(rotFile);
        $('rotateFileName').textContent = rotFile.name;
        $('rotateAngleRow').hidden = false;
        await renderPageThumbnails(new Uint8Array(rotBytes), 'rotatePageGrid', 'rot-page', updateRotInfo);
        updateRotInfo();
    });

    function updateRotInfo() {
        const sel = getCheckedPages('rotatePageGrid');
        $('btnRotate').disabled = sel.length === 0 || !rotBytes;
    }

    $('btnRotate').addEventListener('click', async () => {
        const sel = getCheckedPages('rotatePageGrid');
        if (!sel.length || !rotBytes) return;
        setStatus('rotateStatus', 'กำลังหมุน…', 'info');
        try {
            const doc = await pdfLib().PDFDocument.load(rotBytes.slice(0));
            const degMap = { 90: pdfLib().degrees(90), 180: pdfLib().degrees(180), 270: pdfLib().degrees(270) };
            sel.forEach(pg => {
                const page = doc.getPage(pg - 1);
                const cur  = page.getRotation().angle;
                page.setRotation(pdfLib().degrees((cur + rotAngle) % 360));
            });
            const bytes = await doc.save();
            triggerDownload(new Blob([bytes], { type: 'application/pdf' }), 'rotated.pdf');
            setStatus('rotateStatus', `หมุน ${sel.length} หน้าสำเร็จ`, 'ok');
        } catch (err) {
            setStatus('rotateStatus', 'เกิดข้อผิดพลาด: ' + err.message, 'err');
        }
    });
})();

// ── Watermark ──────────────────────────────────────────
(function () {
    let wmFile = null;
    wireDropZone('wmDrop', 'wmInput', (files) => {
        wmFile = files[0];
        $('wmFileName').textContent = wmFile.name;
        $('btnWatermark').disabled = false;
    });

    $('btnWatermark').addEventListener('click', async () => {
        if (!wmFile) return;
        const text    = $('wmText').value.trim() || 'WATERMARK';
        const size    = parseInt($('wmSize').value) || 60;
        const opacity = parseFloat($('wmOpacity').value) || 0.25;
        const hexColor = $('wmColor').value;
        const r = parseInt(hexColor.slice(1, 3), 16) / 255;
        const g = parseInt(hexColor.slice(3, 5), 16) / 255;
        const b = parseInt(hexColor.slice(5, 7), 16) / 255;

        setStatus('wmStatus', 'กำลังใส่ลายน้ำ…', 'info');
        try {
            const buf = await readFileAsArrayBuffer(wmFile);
            const doc = await pdfLib().PDFDocument.load(buf);
            const font = await doc.embedFont(pdfLib().StandardFonts.HelveticaBold);
            const pages = doc.getPages();

            pages.forEach(page => {
                const { width, height } = page.getSize();
                page.drawText(text, {
                    x: width / 2 - (font.widthOfTextAtSize(text, size) / 2),
                    y: height / 2 - size / 2,
                    size,
                    font,
                    color: pdfLib().rgb(r, g, b),
                    opacity,
                    rotate: pdfLib().degrees(45),
                });
            });

            const bytes = await doc.save();
            triggerDownload(new Blob([bytes], { type: 'application/pdf' }), 'watermarked.pdf');
            setStatus('wmStatus', 'ใส่ลายน้ำสำเร็จ', 'ok');
        } catch (err) {
            setStatus('wmStatus', 'เกิดข้อผิดพลาด: ' + err.message, 'err');
        }
    });
})();

// ── Images → PDF ───────────────────────────────────────
(function () {
    let img2pdfFiles = [];
    const listEl = $('img2pdfFileList');

    function render() {
        listEl.innerHTML = '';
        img2pdfFiles.forEach((f, i) => {
            const item = document.createElement('div');
            item.className = 'ft-file-item';
            item.innerHTML = `<span>${f.name}</span><span class="ft-remove" data-i="${i}">✕</span>`;
            listEl.appendChild(item);
        });
        $('btnImg2Pdf').disabled = img2pdfFiles.length === 0;
    }

    listEl.addEventListener('click', (e) => {
        const rm = e.target.closest('.ft-remove');
        if (rm) { img2pdfFiles.splice(+rm.dataset.i, 1); render(); }
    });

    wireDropZone('img2pdfDrop', 'img2pdfInput', (files) => {
        Array.from(files).forEach(f => {
            if (f.type.startsWith('image/')) img2pdfFiles.push(f);
        });
        render();
    });

    $('btnImg2Pdf').addEventListener('click', async () => {
        if (!img2pdfFiles.length) return;
        setStatus('img2pdfStatus', 'กำลังสร้าง PDF…', 'info');
        try {
            const doc = await pdfLib().PDFDocument.create();
            for (const f of img2pdfFiles) {
                const dataUrl = await readFileAsDataURL(f);
                const base64  = dataUrl.split(',')[1];
                let img;
                if (f.type === 'image/jpeg') {
                    img = await doc.embedJpg(Uint8Array.from(atob(base64), c => c.charCodeAt(0)));
                } else {
                    // Convert to PNG via canvas if not already PNG
                    const pngBase64 = await toPngBase64(f);
                    img = await doc.embedPng(Uint8Array.from(atob(pngBase64), c => c.charCodeAt(0)));
                }
                const page = doc.addPage([img.width, img.height]);
                page.drawImage(img, { x: 0, y: 0, width: img.width, height: img.height });
            }
            const bytes = await doc.save();
            triggerDownload(new Blob([bytes], { type: 'application/pdf' }), 'images.pdf');
            setStatus('img2pdfStatus', 'สร้าง PDF สำเร็จ', 'ok');
        } catch (err) {
            setStatus('img2pdfStatus', 'เกิดข้อผิดพลาด: ' + err.message, 'err');
        }
    });

    async function toPngBase64(file) {
        return new Promise((res, rej) => {
            const img = new Image();
            const url = URL.createObjectURL(file);
            img.onload = () => {
                const c = document.createElement('canvas');
                c.width = img.naturalWidth; c.height = img.naturalHeight;
                c.getContext('2d').drawImage(img, 0, 0);
                URL.revokeObjectURL(url);
                res(c.toDataURL('image/png').split(',')[1]);
            };
            img.onerror = rej;
            img.src = url;
        });
    }
})();

// ── PDF → Images ───────────────────────────────────────
(function () {
    let p2iFile = null;
    wireDropZone('pdf2imgDrop', 'pdf2imgInput', (files) => {
        p2iFile = files[0];
        $('pdf2imgFileName').textContent = p2iFile.name;
        $('btnPdf2Img').disabled = false;
    });

    $('btnPdf2Img').addEventListener('click', async () => {
        if (!p2iFile) return;
        ensurePdfWorker();
        const lib = pdfjsLib();
        if (!lib) { setStatus('pdf2imgStatus', 'PDF.js ยังโหลดไม่เสร็จ', 'err'); return; }

        const scale = parseInt($('pdf2imgDpi').value) || 2;
        const progressWrap = $('pdf2imgProgress');
        const fill  = $('pdf2imgFill');
        const label = $('pdf2imgLabel');

        progressWrap.hidden = false;
        setStatus('pdf2imgStatus', '', 'info');

        try {
            const buf = await readFileAsArrayBuffer(p2iFile);
            const pdf = await lib.getDocument({ data: buf }).promise;
            const total = pdf.numPages;

            const zip = new JSZip();
            for (let i = 1; i <= total; i++) {
                const page = await pdf.getPage(i);
                const vp   = page.getViewport({ scale });
                const canvas = document.createElement('canvas');
                canvas.width  = vp.width;
                canvas.height = vp.height;
                await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;

                const blob = await new Promise(r => canvas.toBlob(r, 'image/png'));
                const ab   = await blob.arrayBuffer();
                const padded = String(i).padStart(String(total).length, '0');
                zip.file(`page-${padded}.png`, ab);

                fill.style.width = Math.round((i / total) * 100) + '%';
                label.textContent = `หน้า ${i} / ${total}`;
            }

            const zipBlob = await zip.generateAsync({ type: 'blob' });
            triggerDownload(zipBlob, 'pdf-images.zip');
            setStatus('pdf2imgStatus', `แปลง ${total} หน้าสำเร็จ`, 'ok');
        } catch (err) {
            setStatus('pdf2imgStatus', 'เกิดข้อผิดพลาด: ' + err.message, 'err');
        } finally {
            progressWrap.hidden = true;
        }
    });
})();

// ── Password protect PDF ───────────────────────────────
(function () {
    let pwdFile = null;
    wireDropZone('pwdDrop', 'pwdInput', (files) => {
        pwdFile = files[0];
        $('pwdFileName').textContent = pwdFile.name;
        $('btnPwd').disabled = false;
    });

    $('btnPwd').addEventListener('click', async () => {
        if (!pwdFile) return;
        const userPwd  = $('pwdUser').value;
        const ownerPwd = $('pwdOwner').value || userPwd;
        if (!userPwd) { setStatus('pwdStatus', 'กรุณาใส่รหัสผ่าน', 'err'); return; }
        setStatus('pwdStatus', 'กำลังเข้ารหัส…', 'info');
        try {
            const buf = await readFileAsArrayBuffer(pwdFile);
            const doc = await pdfLib().PDFDocument.load(buf);
            const bytes = await doc.save({
                userPassword: userPwd,
                ownerPassword: ownerPwd,
                permissions: {
                    printing: 'highResolution',
                    modifying: false,
                    copying: false,
                    annotating: false,
                    fillingForms: false,
                    contentAccessibility: true,
                    documentAssembly: false,
                },
            });
            triggerDownload(new Blob([bytes], { type: 'application/pdf' }), 'protected.pdf');
            setStatus('pwdStatus', 'ใส่รหัสผ่านสำเร็จ', 'ok');
        } catch (err) {
            setStatus('pwdStatus', 'เกิดข้อผิดพลาด: ' + err.message, 'err');
        }
    });
})();

// ═══════════════════════════════════════════════════════
// IMAGE TOOLS  (server-side PHP GD)
// ═══════════════════════════════════════════════════════

function wireImageTool({ dropId, inputId, infoId, btnId, statusId, buildForm }) {
    let imgFile = null;

    wireDropZone(dropId, inputId, (files) => {
        imgFile = files[0];
        const el = $(infoId);
        if (el) el.textContent = imgFile.name + ' (' + fmtBytes(imgFile.size) + ')';

        // Show preview if card has one
        const prev = document.getElementById(dropId.replace('Drop', 'Preview'));
        if (prev) {
            prev.hidden = false;
            prev.innerHTML = '';
            const img = document.createElement('img');
            img.src = URL.createObjectURL(imgFile);
            prev.appendChild(img);
        }

        $(btnId).disabled = false;
    });

    $(btnId).addEventListener('click', async () => {
        if (!imgFile) return;
        const fd = buildForm(imgFile);
        $(btnId).disabled = true;
        const res = await apiPost('/api/file-tools/image', fd, statusId);
        $(btnId).disabled = false;
        if (!res) return;

        const cd   = res.headers.get('Content-Disposition') || '';
        const match = cd.match(/filename="?([^"]+)"?/);
        const fname = match ? match[1] : 'image';
        const blob  = await res.blob();
        triggerDownload(blob, fname);
        setStatus(statusId, 'ดาวน์โหลดสำเร็จ', 'ok');
    });
}

// Convert
wireImageTool({
    dropId: 'imgConvDrop', inputId: 'imgConvInput',
    infoId: null, btnId: 'btnImgConv', statusId: 'imgConvStatus',
    buildForm(file) {
        const fd = new FormData();
        fd.append('file', file);
        fd.append('action', 'convert');
        fd.append('format', $('imgConvFormat').value);
        return fd;
    },
});

// Resize
wireImageTool({
    dropId: 'imgResizeDrop', inputId: 'imgResizeInput',
    infoId: 'imgResizeInfo', btnId: 'btnImgResize', statusId: 'imgResizeStatus',
    buildForm(file) {
        const fd = new FormData();
        fd.append('file', file);
        fd.append('action', 'resize');
        fd.append('width',  $('resizeW').value);
        fd.append('height', $('resizeH').value);
        fd.append('ratio',  $('resizeRatio').checked ? '1' : '0');
        return fd;
    },
});

// Compress
$('cmpQuality').addEventListener('input', () => { $('cmpQualityVal').textContent = $('cmpQuality').value; });

wireImageTool({
    dropId: 'imgCmpDrop', inputId: 'imgCmpInput',
    infoId: 'imgCmpInfo', btnId: 'btnImgCmp', statusId: 'imgCmpStatus',
    buildForm(file) {
        const fd = new FormData();
        fd.append('file', file);
        fd.append('action', 'compress');
        fd.append('quality', $('cmpQuality').value);
        return fd;
    },
});

// Rotate / Flip
(function () {
    let rotOp = 'rotate90';
    document.querySelectorAll('.ft-op-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.ft-op-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            rotOp = btn.dataset.op;
        });
    });

    wireImageTool({
        dropId: 'imgRotDrop', inputId: 'imgRotInput',
        infoId: 'imgRotInfo', btnId: 'btnImgRot', statusId: 'imgRotStatus',
        buildForm(file) {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('action', 'transform');
            fd.append('op', rotOp);
            return fd;
        },
    });
})();

// Filter / FX
(function () {
    let fxOp = 'grayscale';

    $('fxLevel').addEventListener('input', () => { $('fxLevelVal').textContent = $('fxLevel').value; });
    $('fxBlurPasses').addEventListener('input', () => { $('fxBlurVal').textContent = $('fxBlurPasses').value; });

    document.querySelectorAll('.ft-fx-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.ft-fx-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            fxOp = btn.dataset.op;
            $('fxLevelRow').hidden = !['brightness', 'contrast'].includes(fxOp);
            $('fxBlurRow').hidden  = fxOp !== 'blur';
            if (fxOp === 'brightness') {
                $('fxLevelLabel').textContent = 'ระดับความสว่าง (-255 ถึง 255)';
                $('fxLevel').min = -255; $('fxLevel').max = 255; $('fxLevel').value = 50;
            } else if (fxOp === 'contrast') {
                $('fxLevelLabel').textContent = 'ระดับคอนทราสต์ (-100 ถึง 100)';
                $('fxLevel').min = -100; $('fxLevel').max = 100; $('fxLevel').value = -20;
            }
            $('fxLevelVal').textContent = $('fxLevel').value;
        });
    });

    wireImageTool({
        dropId: 'imgFxDrop', inputId: 'imgFxInput',
        infoId: 'imgFxInfo', btnId: 'btnImgFx', statusId: 'imgFxStatus',
        buildForm(file) {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('action', 'transform');
            fd.append('op', fxOp);
            if (fxOp === 'brightness' || fxOp === 'contrast') fd.append('level', $('fxLevel').value);
            if (fxOp === 'blur') fd.append('passes', $('fxBlurPasses').value);
            return fd;
        },
    });
})();

// ═══════════════════════════════════════════════════════
// DATA CONVERSION  (client-side)
// ═══════════════════════════════════════════════════════

// ── JSON ↔ CSV ─────────────────────────────────────────
(function () {
    let jcDir = 'json2csv';
    const jcCard = $('jcInput')?.closest('.card-body');
    const jcDirBtns = jcCard ? Array.from(jcCard.querySelectorAll('.ft-dir-btn')) : [];
    jcDirBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            jcDirBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            jcDir = btn.dataset.dir;
            $('jcInput').placeholder = jcDir === 'json2csv' ? '[{"name":"Alice","age":25}]' : 'name,age\nAlice,25';
        });
    });

    $('btnJC').addEventListener('click', () => {
        const input = $('jcInput').value.trim();
        if (!input) return;
        try {
            let out;
            if (jcDir === 'json2csv') {
                const arr = JSON.parse(input);
                const rows = Array.isArray(arr) ? arr : [arr];
                const keys = Object.keys(rows[0]);
                out = [keys.join(','), ...rows.map(r => keys.map(k => JSON.stringify(r[k] ?? '')).join(','))].join('\n');
            } else {
                const lines = input.split('\n').filter(Boolean);
                const headers = lines[0].split(',').map(h => h.trim().replace(/^"|"$/g, ''));
                const result = lines.slice(1).map(line => {
                    const vals = line.match(/(".*?"|[^,]+)/g) || [];
                    const obj = {};
                    headers.forEach((h, i) => { obj[h] = (vals[i] || '').replace(/^"|"$/g, ''); });
                    return obj;
                });
                out = JSON.stringify(result, null, 2);
            }
            $('jcOutput').value = out;
            setStatus('jcStatus', 'แปลงสำเร็จ', 'ok');
        } catch (e) {
            setStatus('jcStatus', 'แปลงไม่สำเร็จ: ' + e.message, 'err');
        }
    });
    $('btnJCCopy').addEventListener('click', () => copyToClipboard($('jcOutput').value));
    $('btnJCDownload').addEventListener('click', () => {
        const v = $('jcOutput').value; if (!v) return;
        const ext = jcDir === 'json2csv' ? 'csv' : 'json';
        triggerDownload(new Blob([v], { type: 'text/plain' }), 'converted.' + ext);
    });
})();

// ── JSON ↔ XML ─────────────────────────────────────────
(function () {
    let jxDir = 'json2xml';
    const jxDirBtns = Array.from(document.querySelectorAll('.ft-dir-btn')).filter(b => b.closest('.card-body') === $('jxInput')?.closest('.card-body'));
    jxDirBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            jxDirBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            jxDir = btn.dataset.dir;
        });
    });

    function jsonToXml(obj, tag = 'root') {
        if (typeof obj !== 'object' || obj === null) return `<${tag}>${String(obj)}</${tag}>`;
        if (Array.isArray(obj)) return obj.map(item => jsonToXml(item, 'item')).join('\n');
        const inner = Object.entries(obj).map(([k, v]) => jsonToXml(v, k)).join('\n');
        return `<${tag}>\n${inner}\n</${tag}>`;
    }

    function xmlToJson(xml) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(xml, 'text/xml');
        function nodeToObj(node) {
            if (node.nodeType === 3) return node.nodeValue.trim();
            const obj = {};
            Array.from(node.childNodes).forEach(child => {
                if (child.nodeType !== 1) return;
                const val = nodeToObj(child);
                if (obj[child.nodeName] !== undefined) {
                    if (!Array.isArray(obj[child.nodeName])) obj[child.nodeName] = [obj[child.nodeName]];
                    obj[child.nodeName].push(val);
                } else { obj[child.nodeName] = val; }
            });
            return obj;
        }
        return nodeToObj(doc.documentElement);
    }

    $('btnJX').addEventListener('click', () => {
        const input = $('jxInput').value.trim();
        if (!input) return;
        try {
            let out;
            if (jxDir === 'json2xml') {
                const obj = JSON.parse(input);
                out = '<?xml version="1.0" encoding="UTF-8"?>\n' + jsonToXml(obj);
            } else {
                const obj = xmlToJson(input);
                out = JSON.stringify(obj, null, 2);
            }
            $('jxOutput').value = out;
            setStatus('jxStatus', 'แปลงสำเร็จ', 'ok');
        } catch (e) {
            setStatus('jxStatus', 'แปลงไม่สำเร็จ: ' + e.message, 'err');
        }
    });
    $('btnJXCopy').addEventListener('click', () => copyToClipboard($('jxOutput').value));
    $('btnJXDownload').addEventListener('click', () => {
        const v = $('jxOutput').value; if (!v) return;
        const ext = jxDir === 'json2xml' ? 'xml' : 'json';
        triggerDownload(new Blob([v], { type: 'text/plain' }), 'converted.' + ext);
    });
})();

// ── JSON Prettify / Minify ─────────────────────────────
(function () {
    $('btnJsonPretty').addEventListener('click', () => {
        try {
            $('jsonFmtOutput').value = JSON.stringify(JSON.parse($('jsonFmtInput').value), null, 2);
        } catch (e) { $('jsonFmtOutput').value = 'JSON ไม่ถูกต้อง: ' + e.message; }
    });
    $('btnJsonMinify').addEventListener('click', () => {
        try {
            $('jsonFmtOutput').value = JSON.stringify(JSON.parse($('jsonFmtInput').value));
        } catch (e) { $('jsonFmtOutput').value = 'JSON ไม่ถูกต้อง: ' + e.message; }
    });
    $('btnJsonFmtCopy').addEventListener('click', () => copyToClipboard($('jsonFmtOutput').value));
})();

// ── Base64 ─────────────────────────────────────────────
(function () {
    let b64Mode = 'text';
    let b64FileData = null;

    document.querySelectorAll('.ft-b64-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.ft-b64-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            b64Mode = btn.dataset.b64;
            $('b64TextSection').hidden = b64Mode !== 'text';
            $('b64FileSection').hidden = b64Mode !== 'file';
        });
    });

    $('btnB64Enc').addEventListener('click', () => {
        $('b64Output').value = btoa(unescape(encodeURIComponent($('b64Input').value)));
    });
    $('btnB64Dec').addEventListener('click', () => {
        try { $('b64Output').value = decodeURIComponent(escape(atob($('b64Input').value))); }
        catch (e) { $('b64Output').value = 'ถอดรหัสไม่สำเร็จ'; }
    });
    $('btnB64Copy').addEventListener('click', () => copyToClipboard($('b64Output').value));

    wireDropZone('b64FileDrop', 'b64FileInput', (files) => {
        const f = files[0];
        const fr = new FileReader();
        fr.onload = () => {
            b64FileData = { name: f.name, type: f.type, data: fr.result.split(',')[1] };
            $('b64FileOutput').value = b64FileData.data;
        };
        fr.readAsDataURL(f);
    });

    $('btnB64FileCopy').addEventListener('click', () => copyToClipboard($('b64FileOutput').value));
    $('btnB64FileDownload').addEventListener('click', () => {
        if (!b64FileData) return;
        const bytes = Uint8Array.from(atob(b64FileData.data), c => c.charCodeAt(0));
        triggerDownload(new Blob([bytes], { type: b64FileData.type }), b64FileData.name);
    });
})();

// ── Hash ───────────────────────────────────────────────
(function () {
    let hashSrc = 'text';
    let hashFile = null;

    document.querySelectorAll('.ft-hash-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.ft-hash-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            hashSrc = btn.dataset.hsrc;
            $('hashTextSection').hidden = hashSrc !== 'text';
            $('hashFileSection').hidden = hashSrc !== 'file';
        });
    });

    wireDropZone('hashFileDrop', 'hashFileInput', (files) => {
        hashFile = files[0];
        $('hashFileInfo').textContent = hashFile.name + ' (' + fmtBytes(hashFile.size) + ')';
    });

    $('btnHash').addEventListener('click', async () => {
        let buffer;
        if (hashSrc === 'text') {
            const text = $('hashInput').value;
            buffer = new TextEncoder().encode(text).buffer;
        } else {
            if (!hashFile) return;
            buffer = await readFileAsArrayBuffer(hashFile);
        }

        const results = $('hashResults');
        results.innerHTML = '<div class="ft-status info">กำลังคำนวณ…</div>';

        async function digest(algo) {
            const ab = await crypto.subtle.digest(algo, buffer);
            return Array.from(new Uint8Array(ab)).map(b => b.toString(16).padStart(2, '0')).join('');
        }

        const [sha256, sha1] = await Promise.all([digest('SHA-256'), digest('SHA-1')]);
        // MD5 via SparkMD5
        const md5 = window.SparkMD5
            ? SparkMD5.ArrayBuffer.hash(buffer)
            : '(ต้องการ SparkMD5 CDN)';

        results.innerHTML = '';
        [['SHA-256', sha256], ['SHA-1', sha1], ['MD5', md5]].forEach(([algo, hash]) => {
            const row = document.createElement('div');
            row.className = 'ft-hash-row';
            row.innerHTML = `<label>${algo}</label><input type="text" readonly value="${hash}">`;
            row.querySelector('input').addEventListener('click', e => { e.target.select(); copyToClipboard(hash); });
            results.appendChild(row);
        });
    });
})();

// ── URL Encode / Decode ────────────────────────────────
(function () {
    $('btnUrlEnc').addEventListener('click', () => { $('urlOutput').value = encodeURIComponent($('urlInput').value); });
    $('btnUrlDec').addEventListener('click', () => {
        try { $('urlOutput').value = decodeURIComponent($('urlInput').value); }
        catch (e) { $('urlOutput').value = 'ถอดรหัสไม่สำเร็จ: ' + e.message; }
    });
    $('btnUrlCopy').addEventListener('click', () => copyToClipboard($('urlOutput').value));
})();

// ═══════════════════════════════════════════════════════
// ZIP TOOLS  (server-side PHP ZipArchive)
// ═══════════════════════════════════════════════════════

// ── Create ZIP ─────────────────────────────────────────
(function () {
    let zipFiles = [];
    const listEl = $('zipCreateFileList');

    function render() {
        listEl.innerHTML = '';
        zipFiles.forEach((f, i) => {
            const item = document.createElement('div');
            item.className = 'ft-file-item';
            item.innerHTML = `<span>${f.name} <span class="text-muted">(${fmtBytes(f.size)})</span></span><span class="ft-remove" data-i="${i}">✕</span>`;
            listEl.appendChild(item);
        });
        $('btnZipCreate').disabled = zipFiles.length === 0;
    }

    listEl.addEventListener('click', (e) => {
        const rm = e.target.closest('.ft-remove');
        if (rm) { zipFiles.splice(+rm.dataset.i, 1); render(); }
    });

    wireDropZone('zipCreateDrop', 'zipCreateInput', (files) => {
        Array.from(files).forEach(f => zipFiles.push(f));
        render();
    });

    $('btnZipCreate').addEventListener('click', async () => {
        if (!zipFiles.length) return;
        const fd = new FormData();
        zipFiles.forEach(f => fd.append('files[]', f));
        fd.append('name', $('zipCreateName').value || 'archive');

        $('btnZipCreate').disabled = true;
        const res = await apiPost('/api/file-tools/zip/create', fd, 'zipCreateStatus');
        $('btnZipCreate').disabled = false;
        if (!res) return;

        const blob = await res.blob();
        const name = ($('zipCreateName').value || 'archive') + '.zip';
        triggerDownload(blob, name);
        setStatus('zipCreateStatus', 'สร้าง ZIP สำเร็จ', 'ok');
    });
})();

// ── Inspect ZIP ────────────────────────────────────────
(function () {
    let inspFile = null;
    wireDropZone('zipInspDrop', 'zipInspInput', (files) => {
        inspFile = files[0];
        $('btnZipInsp').disabled = false;
        $('zipInspResults').hidden = true;
    });

    $('btnZipInsp').addEventListener('click', async () => {
        if (!inspFile) return;
        const fd = new FormData();
        fd.append('file', inspFile);
        const res = await apiPost('/api/file-tools/zip/inspect', fd, 'zipInspStatus');
        if (!res) return;
        const data = await res.json();

        const wrap = $('zipInspResults');
        wrap.hidden = false;
        wrap.innerHTML = `<table class="ft-zip-table">
            <thead><tr><th>#</th><th>ชื่อไฟล์</th><th>ขนาดจริง</th><th>ขนาดบีบ</th></tr></thead>
            <tbody>${data.entries.map(e => `
                <tr>
                    <td>${e.index + 1}</td>
                    <td>${e.name}</td>
                    <td>${fmtBytes(e.size)}</td>
                    <td>${fmtBytes(e.compressed_size)}</td>
                </tr>`).join('')}
            </tbody>
        </table>`;
        setStatus('zipInspStatus', `พบ ${data.total} ไฟล์`, 'ok');
    });
})();

// ── Extract ZIP ────────────────────────────────────────
(function () {
    let extFile   = null;
    let extEntries = [];

    wireDropZone('zipExtDrop', 'zipExtInput', async (files) => {
        extFile = files[0];
        extEntries = [];
        $('zipExtEntries').hidden = true;
        $('zipExtSelInfo').hidden = true;
        $('btnZipExtAll').disabled = false;
        $('btnZipExtSel').disabled = true;

        // Inspect to get entry list
        const fd = new FormData();
        fd.append('file', extFile);
        const res = await apiPost('/api/file-tools/zip/inspect', fd, 'zipExtStatus');
        if (!res) return;
        const data = await res.json();
        extEntries = data.entries;

        const wrap = $('zipExtEntries');
        wrap.hidden = false;
        wrap.innerHTML = `<table class="ft-zip-table">
            <thead><tr><th><input type="checkbox" id="zipExtAll"></th><th>ชื่อไฟล์</th><th>ขนาด</th></tr></thead>
            <tbody>${extEntries.map(e => `
                <tr>
                    <td><input type="checkbox" class="zip-ext-chk" value="${e.index}"></td>
                    <td>${e.name}</td>
                    <td>${fmtBytes(e.size)}</td>
                </tr>`).join('')}
            </tbody>
        </table>`;

        $('zipExtAll').addEventListener('change', (ev) => {
            wrap.querySelectorAll('.zip-ext-chk').forEach(c => { c.checked = ev.target.checked; });
            updateExtSel();
        });
        wrap.querySelectorAll('.zip-ext-chk').forEach(c => c.addEventListener('change', updateExtSel));
        setStatus('zipExtStatus', `พบ ${data.total} ไฟล์`, 'ok');
    });

    function updateExtSel() {
        const sel = Array.from(document.querySelectorAll('.zip-ext-chk:checked'));
        $('zipExtSelInfo').hidden = false;
        $('zipExtSelInfo').textContent = `เลือก ${sel.length} ไฟล์`;
        $('btnZipExtSel').disabled = sel.length === 0;
    }

    async function doExtract(indices) {
        if (!extFile) return;
        const fd = new FormData();
        fd.append('file', extFile);
        if (indices !== null) fd.append('indices', indices.join(','));
        const res = await apiPost('/api/file-tools/zip/extract', fd, 'zipExtStatus');
        if (!res) return;
        const cd = res.headers.get('Content-Disposition') || '';
        const match = cd.match(/filename="?([^"]+)"?/);
        const fname = match ? match[1] : 'extracted.zip';
        const blob  = await res.blob();
        triggerDownload(blob, fname);
        setStatus('zipExtStatus', 'ดาวน์โหลดสำเร็จ', 'ok');
    }

    $('btnZipExtAll').addEventListener('click', () => doExtract(null));
    $('btnZipExtSel').addEventListener('click', () => {
        const sel = Array.from(document.querySelectorAll('.zip-ext-chk:checked')).map(c => +c.value);
        doExtract(sel);
    });
})();
