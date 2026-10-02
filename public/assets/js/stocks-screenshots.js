/* =====================================================
   stocks-screenshots.js — the portfolio screenshot
   Loaded after stocks.js on the stocks page; shares its state.
===================================================== */

// uploads/ is not served directly; the image comes through an owner-checked endpoint.
function screenshotUrl(s) {
    return BASE_URL + '/api/stocks/screenshots/' + encodeURIComponent(s.id) + '/image';
}

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
    renderSidebarScreenshot();

    const grid = document.getElementById('stkScreenshotsGrid');
    if (!grid) return;

    if (!stockScreenshots.length) {
        grid.innerHTML = '<p class="stk-empty"><strong>ยังไม่มีรูปภาพพอร์ต</strong>อัปโหลดภาพหน้าจอจากแอปโบรกเกอร์ไว้เทียบย้อนหลัง</p>';
        return;
    }

    grid.innerHTML = stockScreenshots.map(s => {
        const name = escHtml(s.name);
        return '<figure class="stk-shot">'
            + '<button type="button" class="stk-shot-img" data-act="viewLightbox" data-args="[' + s.id + ']" aria-label="ดูรูปเต็ม: ' + name + '">'
            + '<img src="' + screenshotUrl(s) + '" alt="" loading="lazy"></button>'
            + '<figcaption>'
            + '<span class="grow"><span class="title" title="' + name + '">' + name + '</span>'
            + (s.description ? '<span class="meta">' + escHtml(s.description) + '</span>' : '')
            + '<span class="meta">อัปโหลด ' + escHtml(formatDateTime(s.created_at)) + '</span></span>'
            + '<button type="button" class="icon-btn sm mode-readonly-hide" data-act="deleteScreenshot" data-args="[' + s.id + ']" aria-label="ลบรูป: ' + name + '"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg></button>'
            + '</figcaption></figure>';
    }).join('');
}

/** The newest screenshot, on the shared page. */
function renderSidebarScreenshot() {
    const container = document.getElementById('sideScreenshotContainer');
    if (!container) return;

    if (!stockScreenshots.length) {
        container.innerHTML = '<p class="stk-empty">ยังไม่มีรูปภาพพอร์ต</p>';
        return;
    }

    const s = stockScreenshots[0];
    const name = escHtml(s.name);
    container.innerHTML = '<img class="stk-latest-img" src="' + screenshotUrl(s) + '" alt="' + name + '">'
        + '<p class="stk-shot-cap"><strong>' + name + '</strong>'
        + (s.description ? escHtml(s.description) : '')
        + '<span>อัปโหลด ' + escHtml(formatDateTime(s.created_at)) + '</span></p>';
}

async function uploadScreenshot(input) {
    const file = input.files[0];
    if (!file) return;

    let description = '';
    if (window.Swal) {
        const { value: text } = await Swal.fire({
            title: 'คำอธิบายรูปภาพ',
            input: 'text',
            inputLabel: 'ใส่คำอธิบายสั้นๆ ไว้ค้นทีหลัง (ไม่ใส่ก็ได้)',
            inputPlaceholder: 'เช่น พอร์ตสิ้นเดือนมิถุนายน',
            showCancelButton: true,
            confirmButtonText: 'อัปโหลด',
            cancelButtonText: 'ยกเลิก',
        });
        if (text === undefined) {
            input.value = '';
            return;
        }
        description = text || '';
    }

    const formData = new FormData();
    formData.append('file', file);
    formData.append('description', description);

    try {
        await apiFetch(BASE_URL + '/api/stocks/screenshots', { method: 'POST', body: formData });
        toast('อัปโหลดรูปแล้ว');
        await loadScreenshots();
    } catch (err) {
        toast(err.message || 'อัปโหลดไม่สำเร็จ ตรวจชนิดและขนาดไฟล์แล้วลองอีกครั้ง', 'danger');
    } finally {
        input.value = '';
    }
}

async function deleteScreenshot(id) {
    if (!await confirmAction('ลบรูปนี้แล้วกู้คืนไม่ได้', 'ลบรูป', 'ลบรูปภาพนี้?')) return;
    try {
        await apiFetch(BASE_URL + '/api/stocks/screenshots/' + id, { method: 'DELETE' });
        await loadScreenshots();
        toast('ลบรูปแล้ว');
    } catch (err) {
        toast(err.message || 'ลบรูปไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}

function viewLightbox(id) {
    const s = stockScreenshots.find(x => x.id === id);
    if (!s) return;
    document.getElementById('lightboxTitle').textContent = s.name;
    const img = document.getElementById('lightboxImage');
    img.src = screenshotUrl(s);
    img.alt = s.name;
    document.getElementById('lightboxDesc').textContent = s.description || '';
    openModal('screenshotLightboxModal');
}

function initDropzone() {
    const dropzone = document.getElementById('stkDropzone');
    if (!dropzone) return;

    ['dragenter', 'dragover'].forEach(type => {
        dropzone.addEventListener(type, e => {
            e.preventDefault();
            dropzone.classList.add('active');
        });
    });

    ['dragleave', 'drop'].forEach(type => {
        dropzone.addEventListener(type, e => {
            e.preventDefault();
            dropzone.classList.remove('active');
        });
    });

    dropzone.addEventListener('drop', e => {
        const files = e.dataTransfer.files;
        const fileInput = document.getElementById('stkFileSelect');
        if (files.length && fileInput) {
            const transfer = new DataTransfer();
            transfer.items.add(files[0]);
            fileInput.files = transfer.files;
            uploadScreenshot(fileInput);
        }
    });
}
