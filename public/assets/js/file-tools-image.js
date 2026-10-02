/* =====================================================
   file-tools-image.js
   Image tools (server-side)
   Loaded after file-tools.js, which provides $, setStatus,
   apiPost, wireDropZone and the PDF helpers.
   ===================================================== */

'use strict';

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

