<?php
// =====================================================
// views/notes/index.php — the notes
//
// Pinned notes first, then the rest by when they were last touched; narrowed by
// tag or a word. $tags comes from NoteController. assets/js/notes.js draws the
// list from /api/notes. The editor is views/notes/editor.php.
// =====================================================
?>
<div class="page-head">
    <div>
        <h1>โน้ต</h1>
        <p class="sub" id="noteTally" aria-live="polite">กำลังโหลดโน้ต…</p>
    </div>
    <div class="page-head-actions">
        <button class="btn btn-ghost" type="button" data-act="openCreateNote" data-args="[true]"><svg class="icon" aria-hidden="true"><use href="#i-lock"/></svg>โน้ตเข้ารหัส</button>
        <button class="btn btn-primary" type="button" data-act="openCreateNote" data-args="[false]"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>โน้ตใหม่</button>
    </div>
</div>

<div class="note-filters">
    <label class="sr-only" for="noteSearch">ค้นหาโน้ต</label>
    <input type="search" class="form-control note-search" id="noteSearch" placeholder="ค้นหาโน้ต" autocomplete="off"
           data-act="searchNotes" data-args='["$value"]' data-on="input">
    <?php if ($tags): ?>
    <div class="note-tags" id="tagList" role="group" aria-label="กรองด้วยแท็ก">
        <button type="button" class="tag active" aria-pressed="true" data-tag-id="0" data-act="filterByTag" data-args='[0,"$el"]'>ทั้งหมด</button>
        <?php foreach ($tags as $tag): ?>
        <button type="button" class="tag" aria-pressed="false" data-tag-id="<?= (int)$tag['id'] ?>" data-act="filterByTag" data-args='[<?= (int)$tag['id'] ?>,"$el"]'><?= h($tag['name']) ?> <span class="tag-count"><?= (int)$tag['note_count'] ?></span></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div id="notesGrid" aria-busy="true">
    <div class="skel-row"><span class="skel skel-w-60"></span></div>
    <div class="skel-row"><span class="skel skel-w-45"></span></div>
    <div class="skel-row"><span class="skel skel-w-52"></span></div>
</div>

<!-- Create -->
<div class="modal-backdrop" id="createNoteModal" aria-hidden="true">
    <div class="modal modal-narrow" role="dialog" aria-labelledby="createNoteModalTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="createNoteModalTitle">โน้ตใหม่</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <form id="createNoteForm" data-act="submitCreateNote" novalidate>
            <div class="modal-body">
                <input type="hidden" id="createNoteEncrypted" value="0">
                <div class="form-group">
                    <label class="form-label" for="createNoteTitle">ชื่อโน้ต</label>
                    <input type="text" class="form-control" id="createNoteTitle" placeholder="เช่น ไอเดียโปรเจกต์" maxlength="255" autocomplete="off">
                </div>
                <div class="form-group" id="createNotePwGroup" hidden>
                    <label class="form-label" for="createNotePw">รหัสผ่านของโน้ตนี้</label>
                    <input type="password" class="form-control" id="createNotePw" autocomplete="new-password">
                    <p class="form-hint">ต้องใช้รหัสนี้เปิดโน้ตทุกครั้ง ถ้าลืมจะกู้เนื้อหาคืนไม่ได้ ระบบไม่เก็บรหัสไว้ให้ดู</p>
                </div>
                <p class="form-error" id="createNoteError" role="alert" hidden></p>
            </div>
            <div class="modal-footer">
                <button class="btn" type="button" data-close-modal>ยกเลิก</button>
                <button class="btn btn-primary" type="submit">สร้างโน้ต</button>
            </div>
        </form>
    </div>
</div>
