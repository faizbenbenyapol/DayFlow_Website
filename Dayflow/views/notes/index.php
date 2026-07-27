<div class="page-header flex items-center justify-between" style="flex-wrap:wrap;gap:var(--space-3)">
    <div>
        <h1 class="page-title">โน้ต</h1>
    </div>
    <div class="flex gap-2" style="flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm" onclick="openCreateNote(false)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            โน้ตใหม่
        </button>
        <button class="btn btn-ghost btn-sm" onclick="openCreateNote(true)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            โน้ตเข้ารหัส
        </button>
    </div>
</div>

<div class="notes-layout">
    <!-- Sidebar: Tags (scrollable strip on mobile) -->
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
        <div class="notes-search-wrap">
            <input type="text" class="form-control" id="noteSearch"
                   placeholder="ค้นหาโน้ต..."
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
                <p class="form-hint">โน้ตที่เข้ารหัสใช้ authenticated encryption เพื่อช่วยตรวจจับข้อมูลที่ถูกแก้ไข</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" onclick="submitCreateNote()">สร้าง</button>
        </div>
    </div>
</div>
