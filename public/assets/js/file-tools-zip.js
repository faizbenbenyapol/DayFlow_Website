/* =====================================================
   file-tools-zip.js
   ZIP tools
   Loaded after file-tools.js, which provides $, setStatus,
   apiPost, wireDropZone and the PDF helpers.
   ===================================================== */

'use strict';

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
            item.innerHTML = `<span>${escHtml(f.name)} <span class="text-muted">(${fmtBytes(f.size)})</span></span><span class="ft-remove" data-i="${i}">✕</span>`;
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
                    <td>${escHtml(e.name)}</td>
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
                    <td>${escHtml(e.name)}</td>
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
