<div class="page-header flex items-center justify-between">
    <div>
        <h1 class="page-title">โน้ต</h1>
    </div>
    <div class="flex gap-3">
        <button class="btn btn-ghost btn-sm" onclick="openCreateNote(false)">โน้ตใหม่</button>
        <button class="btn btn-ghost btn-sm" onclick="openCreateNote(true)">โน้ตเข้ารหัส</button>
    </div>
</div>

<div class="notes-layout">
    <!-- Sidebar: Tags -->
    <aside class="notes-sidebar">
        <div class="notes-sidebar-title">แท็ก</div>
        <div id="tagList">
            <button class="tag active" data-tag-id="0" onclick="filterByTag(0, this)">ทั้งหมด</button>
            <?php foreach ($tags as $tag): ?>
            <button class="tag" data-tag-id="<?= (int)$tag['id'] ?>" onclick="filterByTag(<?= (int)$tag['id'] ?>, this)">
                <?= h($tag['name']) ?>
                <span class="text-xs text-muted"><?= (int)$tag['note_count'] ?></span>
            </button>
            <?php endforeach; ?>
        </div>
    </aside>

    <!-- Notes grid -->
    <div>
        <div class="flex items-center gap-3 mb-6">
            <input type="text" class="form-control" id="noteSearch"
                   placeholder="ค้นหาโน้ต..." style="max-width:320px"
                   oninput="searchNotes(this.value)">
        </div>

        <div id="notesGrid" class="notes-grid">
            <div class="empty-state">
                <div class="spinner"></div>
            </div>
        </div>
    </div>
</div>

<!-- Create Note Modal -->
<div class="modal-backdrop" id="createNoteModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title" id="createNoteModalTitle">สร้างโน้ตใหม่</span>
            <button class="modal-close" type="button">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="createNoteEncrypted" value="0">
            <div class="form-group">
                <label class="form-label">ชื่อโน้ต</label>
                <input type="text" class="form-control" id="createNoteTitle" placeholder="ชื่อโน้ต..." maxlength="255">
            </div>
            <div class="form-group" id="createNotePwGroup" style="display:none">
                <label class="form-label">รหัสผ่าน</label>
                <input type="password" class="form-control" id="createNotePw" placeholder="รหัสผ่านสำหรับโน้ตนี้">
                <p class="form-hint">โน้ตที่เข้ารหัสจะใช้ AES-256-CBC ปกป้องข้อมูล</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" onclick="submitCreateNote()">สร้าง</button>
        </div>
    </div>
</div>
