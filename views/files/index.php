<?php
// =====================================================
// views/files/index.php — the file manager
//
// Folders and files in one list (or grid): upload, rename, move, share, delete,
// and select several at once. $parentId is the open folder. assets/js/files.js
// draws it from /api/files.
// =====================================================
?>
<div class="page-head">
    <div>
        <h1>ไฟล์</h1>
        <p class="sub" id="filesTally" aria-live="polite">กำลังโหลดไฟล์…</p>
    </div>
    <div class="page-head-actions">
        <button class="btn btn-ghost" type="button" id="btnCreateFolder"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>โฟลเดอร์ใหม่</button>
        <button class="btn btn-primary" type="button" data-click="#fileInput"><svg class="icon" aria-hidden="true"><use href="#i-transfer"/></svg>อัปโหลด</button>
    </div>
</div>

<nav class="breadcrumb" id="breadcrumb" aria-label="ตำแหน่งโฟลเดอร์"></nav>

<div class="files-tools">
    <label class="sr-only" for="filesSearch">ค้นหาไฟล์</label>
    <input type="search" class="form-control files-search" id="filesSearch" placeholder="ค้นหาในโฟลเดอร์นี้" autocomplete="off">
    <label class="sr-only" for="sortSelect">เรียงลำดับ</label>
    <select class="form-control files-sort" id="sortSelect">
        <option value="type-name">โฟลเดอร์ก่อน</option>
        <option value="name">ชื่อ ก→ฮ</option>
        <option value="name-desc">ชื่อ ฮ→ก</option>
        <option value="size">ขนาดน้อย→มาก</option>
        <option value="size-desc">ขนาดมาก→น้อย</option>
        <option value="date">เก่า→ใหม่</option>
        <option value="date-desc">ใหม่→เก่า</option>
    </select>
    <div class="seg-pair" role="group" aria-label="มุมมอง">
        <button type="button" id="btnListView" aria-pressed="false">รายการ</button>
        <button type="button" id="btnGridView" aria-pressed="false">ไอคอน</button>
    </div>
</div>

<div class="files-filters" id="categoryFilters" role="group" aria-label="กรองตามประเภท">
    <button type="button" class="tag active" aria-pressed="true" data-category="all">ทั้งหมด</button>
    <button type="button" class="tag" aria-pressed="false" data-category="folder">โฟลเดอร์</button>
    <button type="button" class="tag" aria-pressed="false" data-category="image">รูปภาพ</button>
    <button type="button" class="tag" aria-pressed="false" data-category="document">เอกสาร</button>
    <button type="button" class="tag" aria-pressed="false" data-category="media">สื่อมีเดีย</button>
    <button type="button" class="tag" aria-pressed="false" data-category="archive">ไฟล์บีบอัด</button>
</div>

<div id="uploadQueue" class="upload-queue" aria-live="polite" hidden></div>
<input type="file" id="fileInput" hidden multiple aria-label="เลือกไฟล์ที่จะอัปโหลด" data-act="uploadFiles" data-args='["$files"]' data-on="change">

<div class="files-grid" id="filesGrid" aria-busy="true">
    <div class="skel-row"><span class="skel skel-w-60"></span></div>
    <div class="skel-row"><span class="skel skel-w-45"></span></div>
    <div class="skel-row"><span class="skel skel-w-52"></span></div>
</div>

<!-- Selected items -->
<div class="batch-toolbar" id="batchToolbar" role="region" aria-label="รายการที่เลือก" hidden>
    <span class="batch-count"><span id="batchCountBadge">0</span> รายการที่เลือก</span>
    <button class="btn btn-sm" type="button" id="btnBatchMove">ย้าย</button>
    <button class="btn btn-sm btn-danger" type="button" id="btnBatchDelete">ลบ</button>
    <button class="btn btn-sm btn-ghost" type="button" id="btnBatchClear">ยกเลิก</button>
</div>

<!-- Right-click / "more" menu -->
<div class="ctx-menu" id="ctxMenu" role="menu" hidden>
    <button type="button" class="ctx-item" role="menuitem" id="ctxOpen"><span>ดาวน์โหลด</span></button>
    <button type="button" class="ctx-item" role="menuitem" id="ctxPreview">ดูตัวอย่าง</button>
    <button type="button" class="ctx-item" role="menuitem" id="ctxRename">เปลี่ยนชื่อ</button>
    <button type="button" class="ctx-item" role="menuitem" id="ctxMove">ย้ายไปยัง…</button>
    <button type="button" class="ctx-item" role="menuitem" id="ctxShare">แชร์ลิงก์</button>
    <button type="button" class="ctx-item danger" role="menuitem" id="ctxDelete">ลบ</button>
</div>

<!-- Image preview -->
<div class="modal-backdrop" id="previewModal" aria-hidden="true">
    <div class="modal modal-wide" role="dialog" aria-labelledby="previewCaption">
        <div class="modal-header">
            <h2 class="modal-title" id="previewCaption">ตัวอย่างรูปภาพ</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <div class="modal-body"><img id="previewImg" class="preview-img" src="" alt=""></div>
    </div>
</div>

<!-- New folder / rename -->
<div class="modal-backdrop" id="nameModal" aria-hidden="true">
    <div class="modal modal-narrow" role="dialog" aria-labelledby="nameModalTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="nameModalTitle">โฟลเดอร์ใหม่</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <form id="nameForm" novalidate>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="nameInput" id="nameLabel">ชื่อโฟลเดอร์</label>
                    <input type="text" class="form-control" id="nameInput" maxlength="255" autocomplete="off">
                </div>
                <p class="form-error" id="nameError" role="alert" hidden></p>
            </div>
            <div class="modal-footer">
                <button class="btn" type="button" data-close-modal>ยกเลิก</button>
                <button class="btn btn-primary" type="submit" id="nameSubmit">สร้างโฟลเดอร์</button>
            </div>
        </form>
    </div>
</div>

<!-- Move -->
<div class="modal-backdrop" id="moveOverlay" aria-hidden="true">
    <div class="modal modal-narrow" role="dialog" aria-labelledby="moveTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="moveTitle">ย้ายไปยังโฟลเดอร์</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <div class="modal-body">
            <div class="move-folder-list" id="moveFolderList" role="group" aria-label="โฟลเดอร์ปลายทาง"></div>
        </div>
        <div class="modal-footer">
            <button class="btn" type="button" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" type="button" id="btnConfirmMove">ย้ายมาที่นี่</button>
        </div>
    </div>
</div>

<!-- Share link -->
<div class="modal-backdrop" id="shareQuickOverlay" aria-hidden="true">
    <div class="modal modal-narrow" role="dialog" aria-labelledby="shareTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="shareTitle">สร้างลิงก์แชร์</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label" for="sqLabel">ชื่อลิงก์ (ไว้จำ)</label>
                <input type="text" class="form-control" id="sqLabel" placeholder="เช่น ส่งให้ทีม" autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-label" for="sqPermission">สิทธิ์</label>
                <select class="form-control" id="sqPermission">
                    <option value="view">ดูอย่างเดียว</option>
                    <option value="download">ดาวน์โหลดได้</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="sqExpires">หมดอายุเมื่อ (เว้นว่าง = ไม่มีกำหนด)</label>
                <input type="datetime-local" class="form-control" id="sqExpires">
            </div>
            <p class="form-error" id="shareError" role="alert" hidden></p>
        </div>
        <div class="modal-footer">
            <button class="btn" type="button" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" type="button" id="btnCreateShare">สร้างลิงก์</button>
        </div>
    </div>
</div>

<!-- The link just made -->
<div class="share-result" id="shareResultBar" role="status" hidden>
    <label class="sr-only" for="shareResultUrl">ลิงก์แชร์</label>
    <input type="text" class="form-control" id="shareResultUrl" readonly>
    <button class="btn btn-primary btn-sm" type="button" id="btnCopyShareUrl">คัดลอก</button>
    <button class="icon-btn sm" type="button" id="btnHideShare" aria-label="ปิดแถบลิงก์"><svg class="icon" aria-hidden="true"><use href="#i-close"/></svg></button>
</div>

<!-- Dropping a file anywhere on the page -->
<div class="full-drop-overlay" id="fullDropOverlay" aria-hidden="true">
    <p class="full-drop-title">วางไฟล์เพื่ออัปโหลดลงโฟลเดอร์นี้</p>
</div>

<script nonce="<?= h(Security::nonce()) ?>">
window.INITIAL_PARENT_ID = <?= jsonForScript($parentId) ?>;
window.FILES_MAX_BYTES = <?= (int)MAX_UPLOAD_BYTES ?>;
</script>
