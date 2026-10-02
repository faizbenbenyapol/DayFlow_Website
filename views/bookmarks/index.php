<?php
// =====================================================
// views/bookmarks/index.php — links kept in one place
//
// Grouped by category, one ruled row per link. assets/js/bookmarks.js draws the
// list from /api/bookmarks and saves new links from the form below.
// =====================================================
?>
<div class="page-head">
    <div>
        <h1>ลิงก์สำคัญ</h1>
        <p class="sub" id="bookmarkTally" aria-live="polite">กำลังโหลดลิงก์…</p>
    </div>
    <div class="page-head-actions">
        <button class="btn btn-primary" type="button" data-act="openBookmark"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>เพิ่มลิงก์</button>
    </div>
</div>

<div id="bookmarksGrid" aria-busy="true">
    <div class="skel-row"><span class="skel skel-w-60"></span></div>
    <div class="skel-row"><span class="skel skel-w-45"></span></div>
    <div class="skel-row"><span class="skel skel-w-52"></span></div>
</div>

<div class="modal-backdrop" id="bookmarkModal" aria-hidden="true">
    <div class="modal modal-narrow" role="dialog" aria-labelledby="bookmarkTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="bookmarkTitle">เพิ่มลิงก์</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <form id="bookmarkForm" data-act="saveBookmark" novalidate>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="bookmarkName">ชื่อเว็บ</label>
                    <input id="bookmarkName" class="form-control" maxlength="180" autocomplete="off" placeholder="เช่น Google Drive">
                </div>
                <div class="form-group">
                    <label class="form-label" for="bookmarkUrl">ที่อยู่เว็บ (URL)</label>
                    <input id="bookmarkUrl" class="form-control" type="url" inputmode="url" autocomplete="off" placeholder="https://example.com">
                </div>
                <div class="form-group">
                    <label class="form-label" for="bookmarkCategory">หมวดหมู่</label>
                    <input id="bookmarkCategory" class="form-control" maxlength="80" value="ทั่วไป" list="bookmarkCategories" autocomplete="off">
                    <datalist id="bookmarkCategories"></datalist>
                </div>
                <p class="form-error" id="bookmarkError" role="alert" hidden></p>
            </div>
            <div class="modal-footer">
                <button class="btn" type="button" data-close-modal>ยกเลิก</button>
                <button class="btn btn-primary" type="submit">บันทึกลิงก์</button>
            </div>
        </form>
    </div>
</div>
