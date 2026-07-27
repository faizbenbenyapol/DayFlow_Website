<div class="page-header flex items-center justify-between">
    <h1 class="page-title">จัดการไฟล์</h1>
    <div class="flex gap-2">
        <button class="btn btn-ghost btn-sm" id="btnListView" title="มุมมองรายการ" aria-label="List view">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        </button>
        <button class="btn btn-ghost btn-sm" id="btnGridView" title="มุมมองกริด" aria-label="Grid view">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
        </button>
        <div class="files-sort-wrap">
            <select class="form-control form-control-sm" id="sortSelect">
                <option value="type-name">โฟลเดอร์ก่อน</option>
                <option value="name">ชื่อ A→Z</option>
                <option value="name-desc">ชื่อ Z→A</option>
                <option value="size">ขนาดน้อย→มาก</option>
                <option value="size-desc">ขนาดมาก→น้อย</option>
                <option value="date">วันที่เก่า→ใหม่</option>
                <option value="date-desc">วันที่ใหม่→เก่า</option>
            </select>
        </div>
        <div class="files-search-wrap">
            <input type="search" class="form-control form-control-sm" id="filesSearch" placeholder="ค้นหาไฟล์...">
        </div>
        <button class="btn btn-ghost btn-sm" id="btnCreateFolder" title="สร้างโฟลเดอร์">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
            <span class="btn-label">โฟลเดอร์</span>
        </button>
    </div>
</div>

<!-- Storage overview widget -->
<div class="storage-overview-card" id="storageOverview">
    <div class="storage-overview-stats">
        <div class="storage-title-wrap">
            <span class="storage-title">ความจุพื้นที่ใช้งานในโฟลเดอร์นี้</span>
            <span class="storage-usage-text" id="storageUsageText">กำลังคำนวณ...</span>
        </div>
        <div class="storage-progress-track">
            <div class="storage-progress-bar" id="storageProgressBar"></div>
        </div>
        <div class="storage-info-row">
            <div class="storage-info-item">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                <span id="folderCountText">0 โฟลเดอร์</span>
            </div>
            <div class="storage-info-item">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span id="fileCountText">0 ไฟล์</span>
            </div>
        </div>
    </div>
    <div class="storage-quick-stats" id="storageQuickStats">
        <!-- Dynamically filled badges -->
    </div>
</div>

<!-- Breadcrumb -->
<div class="breadcrumb" id="breadcrumb">
    <span class="breadcrumb-item" data-navigate="root">หน้าหลัก</span>
</div>

<!-- Category filter chips -->
<div class="category-filters" id="categoryFilters">
    <button class="filter-chip active" data-category="all">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
        ทั้งหมด
    </button>
    <button class="filter-chip" data-category="folder">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
        โฟลเดอร์
    </button>
    <button class="filter-chip" data-category="image">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        รูปภาพ
    </button>
    <button class="filter-chip" data-category="document">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
        เอกสาร
    </button>
    <button class="filter-chip" data-category="media">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
        สื่อมีเดีย
    </button>
    <button class="filter-chip" data-category="archive">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        ไฟล์บีบอัด
    </button>
</div>

<!-- Upload zone -->
<div class="upload-zone" id="uploadZone"
     ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDrop(event)"
     onclick="document.getElementById('fileInput').click()">
    <div class="upload-zone-inner">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--color-muted);margin-bottom:.5rem"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <div class="upload-zone-text">ลากไฟล์มาวางที่นี่ หรือ <span class="upload-zone-click">คลิกเพื่อเลือก</span></div>
        <div class="upload-zone-hint">รองรับทุกประเภทไฟล์ · สูงสุด 20 MB</div>
    </div>
    <div class="upload-progress" id="uploadProgress">
        <div class="upload-progress-bar" id="uploadBar"></div>
    </div>
</div>
<input type="file" id="fileInput" style="display:none" multiple onchange="uploadFiles(this.files)">

<!-- Upload queue -->
<div id="uploadQueue" class="upload-queue" style="display:none"></div>

<!-- Files grid/list -->
<div class="files-grid" id="filesGrid">
    <div class="files-loading">
        <div class="spinner"></div>
    </div>
</div>

<!-- Bottom batch action panel -->
<div class="batch-toolbar" id="batchToolbar">
    <div class="batch-count-wrap">
        <span class="batch-count-badge" id="batchCountBadge">0</span>
        <span>เลือก<span class="hidden-mobile">อยู่</span></span>
    </div>
    <div class="batch-actions-wrap">
        <button class="batch-btn" id="btnBatchMove" title="ย้ายรายการที่เลือก">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
            <span>ย้าย<span class="hidden-mobile">รายการ</span></span>
        </button>
        <button class="batch-btn danger-btn" id="btnBatchDelete" title="ลบรายการที่เลือก">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
            <span>ลบ<span class="hidden-mobile">รายการ</span></span>
        </button>
        <button class="batch-btn" id="btnBatchClear">
            ยกเลิก
        </button>
    </div>
</div>

<!-- Context menu -->
<div class="ctx-menu" id="ctxMenu" style="display:none">
    <div class="ctx-item" id="ctxOpen">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        <span>เปิด / ดาวน์โหลด</span>
    </div>
    <div class="ctx-item" id="ctxPreview">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        <span>ดูตัวอย่าง</span>
    </div>
    <div class="ctx-item" id="ctxRename">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        <span>เปลี่ยนชื่อ</span>
    </div>
    <div class="ctx-item" id="ctxMove">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
        <span>ย้ายไปยัง...</span>
    </div>
    <div class="ctx-item" id="ctxShare">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
        <span>แชร์ลิงก์</span>
    </div>
    <div class="ctx-divider"></div>
    <div class="ctx-item danger" id="ctxDelete">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
        <span>ลบ</span>
    </div>
</div>

<!-- Image Preview Modal -->
<div class="preview-overlay" id="previewOverlay" style="display:none" onclick="closePreview()">
    <div class="preview-box" onclick="event.stopPropagation()">
        <button class="preview-close" onclick="closePreview()" aria-label="ปิด">&times;</button>
        <img id="previewImg" src="" alt="" class="preview-img">
        <div class="preview-caption" id="previewCaption"></div>
    </div>
</div>

<!-- Move Dialog -->
<div class="modal-overlay" id="moveOverlay" style="display:none">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title">ย้ายไปยังโฟลเดอร์</span>
            <button class="modal-close" onclick="closeMoveDialog()" aria-label="ปิด">&times;</button>
        </div>
        <div class="modal-body">
            <div class="move-folder-list" id="moveFolderList"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeMoveDialog()">ยกเลิก</button>
            <button class="btn btn-primary" id="btnConfirmMove">ย้ายที่นี่</button>
        </div>
    </div>
</div>

<!-- Share Quick Dialog (create share from file context) -->
<div class="modal-overlay" id="shareQuickOverlay" style="display:none">
    <div class="modal-box" style="max-width:480px">
        <div class="modal-header">
            <span class="modal-title">สร้างลิงก์แชร์</span>
            <button class="modal-close" onclick="closeShareQuick()" aria-label="ปิด">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">ชื่อลิงก์ (สำหรับจำ)</label>
                <input type="text" class="form-control" id="sqLabel" placeholder="เช่น ส่งให้ทีม">
            </div>
            <div class="form-group">
                <label class="form-label">สิทธิ์</label>
                <select class="form-control" id="sqPermission">
                    <option value="view">ดูอย่างเดียว</option>
                    <option value="download">ดาวน์โหลดได้</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">วันหมดอายุ (ไม่กรอก = ไม่มีกำหนด)</label>
                <input type="datetime-local" class="form-control" id="sqExpires">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeShareQuick()">ยกเลิก</button>
            <button class="btn btn-primary" id="btnCreateShare">สร้างลิงก์</button>
        </div>
    </div>
</div>

<!-- Share Result -->
<div class="share-result-bar" id="shareResultBar" style="display:none">
    <div class="share-result-inner">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
        <input type="text" class="share-result-input" id="shareResultUrl" readonly>
        <button class="btn btn-primary btn-sm" id="btnCopyShareUrl">คัดลอก</button>
        <button class="btn btn-ghost btn-sm" onclick="document.getElementById('shareResultBar').style.display='none'">&#10005;</button>
    </div>
</div>

<!-- Fullpage drag drop overlay -->
<div class="full-drop-overlay" id="fullDropOverlay">
    <div class="full-drop-container">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <div class="full-drop-title">วางไฟล์เพื่ออัปโหลดทันที</div>
        <div class="full-drop-subtitle">รองรับการอัปโหลดหลายไฟล์พร้อมกัน</div>
    </div>
</div>

<script>
window.INITIAL_PARENT_ID = <?= json_encode($parentId) ?>;
</script>
