/* =====================================================
   stocks-screenshots.js — the portfolio screenshot
   Loaded after stocks.js on the stocks page; shares its state.
===================================================== */

// uploads/ is not served directly; the image comes through an owner-checked endpoint.
function screenshotUrl(s) {
    return BASE_URL + '/api/stocks/screenshots/' + encodeURIComponent(s.id) + '/image';
}

/* ============================================================
   SCREENSHOTS
   ============================================================ */

async function loadScreenshots() {
    try {
        const data = await apiFetch(BASE_URL + '/api/stocks/screenshots');
        stockScreenshots = data.screenshots || [];
        renderScreenshotsGrid();
    } catch (err) {
        toast(err.message || 'โหลดรูปภาพไม่สำเร็จ', 'danger');
    }
}

function renderScreenshotsGrid() {
    const grid = document.getElementById('stkScreenshotsGrid');
    if (!grid) {
        renderSidebarScreenshot();
        return;
    }

    if (!stockScreenshots.length) {
        grid.innerHTML = '<div class="text-center text-muted" style="grid-column: 1/-1; padding:3rem 1rem;">ไม่มีรูปภาพพอร์ตแนบไว้</div>';
        renderSidebarScreenshot();
        return;
    }

    grid.innerHTML = stockScreenshots.map(s => {
        const dateStr = formatDateTime(s.created_at);
        const imgSrc = screenshotUrl(s);
        return `
            <div class="stk-screenshot-card">
                <div class="stk-screenshot-img-wrap" data-act="viewLightbox" data-args="[${s.id}]">
                    <img class="stk-screenshot-img" src="${imgSrc}" alt="${escHtml(s.name)}">
                    <div class="stk-screenshot-overlay">
                        <button class="stk-screenshot-btn btn-del mode-readonly-hide" data-act="deleteScreenshot" data-args="[${s.id}]" data-stop title="ลบรูปภาพ">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                        </button>
                    </div>
                </div>
                <div class="stk-screenshot-info">
                    <div class="stk-screenshot-name" title="${escHtml(s.name)}">${escHtml(s.name)}</div>
                    <div class="stk-screenshot-desc">${escHtml(s.description || 'ไม่มีคำอธิบาย')}</div>
                    <div class="stk-screenshot-date">อัปโหลดเมื่อ ${escHtml(dateStr)}</div>
                </div>
            </div>`;
    }).join('');

    renderSidebarScreenshot();
}

function renderSidebarScreenshot() {
    const container = document.getElementById('sideScreenshotContainer');
    if (!container) return;

    if (!stockScreenshots.length) {
        container.innerHTML = '<div class="text-center text-muted py-6">ไม่มีรูปภาพพอร์ตแนบไว้</div>';
        return;
    }

    const s = stockScreenshots[0];
    const imgSrc = screenshotUrl(s);
    const dateStr = formatDateTime(s.created_at);

    if (IS_READ_ONLY) {
        container.innerHTML = `
            <div class="stk-share-img-wrap">
                <img class="stk-share-img" src="${imgSrc}" alt="${escHtml(s.name)}">
            </div>
            <div class="mt-4" style="text-align: left;">
                <div class="stk-screenshot-name" style="font-size:0.9rem;" title="${escHtml(s.name)}">${escHtml(s.name)}</div>
                <div class="stk-screenshot-desc" style="font-size:0.8rem; height:auto; margin-bottom:4px; white-space: pre-wrap;">${escHtml(s.description || 'ไม่มีคำอธิบาย')}</div>
                <div class="text-xs text-muted">อัปโหลดเมื่อ ${escHtml(dateStr)}</div>
            </div>
        `;
    } else {
        container.innerHTML = `
            <div class="stk-sidebar-thumb-wrap" data-act="viewLightbox" data-args="[${s.id}]">
                <img class="stk-sidebar-thumb" src="${imgSrc}" alt="${escHtml(s.name)}">
                <div class="stk-sidebar-thumb-overlay">
                    <span>คลิกเพื่อดูรูปภาพขนาดเต็ม</span>
                </div>
            </div>
            <div class="mt-4">
                <div class="stk-screenshot-name text-sm" title="${escHtml(s.name)}">${escHtml(s.name)}</div>
                <div class="stk-screenshot-desc" style="font-size:0.75rem; height:auto; margin-bottom:4px;">${escHtml(s.description || 'ไม่มีคำอธิบาย')}</div>
                <div class="text-xs text-muted">อัปโหลดเมื่อ ${escHtml(dateStr)}</div>
            </div>
        `;
    }
}

async function uploadScreenshot(input) {
    const file = input.files[0];
    if (!file) return;

    let description = '';
    if (window.Swal) {
        const { value: text } = await Swal.fire({
            title: 'คำอธิบายรูปภาพ',
            input: 'text',
            inputLabel: 'กรอกคำอธิบายสำหรับภาพนี้ (ไม่ระบุก็ได้)',
            inputPlaceholder: 'เช่น พอร์ตประจำเดือนมิถุนายน, Dime พอร์ตแรก...',
            showCancelButton: true,
            confirmButtonText: 'อัปโหลด',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#8b5cf6',
        });
        if (text === undefined) {
            input.value = '';
            return;
        }
        description = text || '';
    } else {
        description = prompt('กรอกคำอธิบายสำหรับรูปภาพนี้:') || '';
    }

    const formData = new FormData();
    formData.append('file', file);
    formData.append('description', description);

    try {
        const res = await apiFetch(BASE_URL + '/api/stocks/screenshots', {
            method: 'POST',
            body: formData
        });
        toast('อัปโหลดสำเร็จ');
        await loadScreenshots();
    } catch (err) {
        toast(err.message || 'เกิดข้อผิดพลาดในการอัปโหลด', 'danger');
    } finally {
        input.value = '';
    }
}

async function deleteScreenshot(id) {
    if (!await confirmAction('ต้องการลบรูปภาพนี้?', 'ลบ')) return;
    try {
        await apiFetch(BASE_URL + '/api/stocks/screenshots/' + id, { method: 'DELETE' });
        await loadScreenshots();
        toast('ลบรูปภาพสำเร็จ');
    } catch (err) {
        toast(err.message || 'ลบรูปภาพไม่สำเร็จ', 'danger');
    }
}

function viewLightbox(id) {
    const s = stockScreenshots.find(x => x.id === id);
    if (!s) return;
    const title = document.getElementById('lightboxTitle');
    const img = document.getElementById('lightboxImage');
    const desc = document.getElementById('lightboxDesc');
    
    if (title) title.textContent = s.name;
    if (img) img.src = screenshotUrl(s);
    if (desc) desc.textContent = s.description || 'ไม่มีคำอธิบาย';
    
    openModal('screenshotLightboxModal');
}

function initDropzone() {
    const dropzone = document.getElementById('stkDropzone');
    if (!dropzone) return;

    ['dragenter', 'dragover'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropzone.classList.add('active');
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropzone.classList.remove('active');
        }, false);
    });

    dropzone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        const fileInput = document.getElementById('stkFileSelect');
        if (files.length && fileInput) {
            // Programmatically assign files to input
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(files[0]);
            fileInput.files = dataTransfer.files;
            uploadScreenshot(fileInput);
        }
    }, false);
}

