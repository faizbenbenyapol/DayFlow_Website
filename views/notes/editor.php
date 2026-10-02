<?php
// =====================================================
// views/notes/editor.php — one note
//
// A title, tags, and blocks (text, link, checklist) that save as they are
// typed. An encrypted note asks for its password before showing anything.
// $note comes from NoteController; assets/js/notes.js runs the editor.
// =====================================================
$noteData = jsonForScript($note);
$tags = Note::getTagsForNote($note['id']);
$tagsData = jsonForScript($tags);
$encrypted = (bool)$note['is_encrypted'];
?>
<div class="note-editor" id="noteEditor"
     data-note-id="<?= (int)$note['id'] ?>"
     data-encrypted="<?= (int)$note['is_encrypted'] ?>">

    <div class="note-bar">
        <a href="<?= APP_URL ?>/notes" class="btn btn-ghost btn-sm"><svg class="icon" aria-hidden="true"><use href="#i-back"/></svg>โน้ตทั้งหมด</a>
        <?php if ($encrypted): ?>
        <span class="badge badge-dark">เข้ารหัสแล้ว</span>
        <?php endif; ?>
        <span class="note-status" id="saveStatus" role="status" aria-live="polite">บันทึกอัตโนมัติ</span>
    </div>

    <?php if ($encrypted): ?>
    <form class="note-unlock" id="encryptedPrompt" data-act="unlockNote" novalidate>
        <h2>โน้ตนี้เข้ารหัสอยู่</h2>
        <div class="form-group">
            <label class="form-label" for="notePassword">รหัสผ่านของโน้ต</label>
            <input type="password" class="form-control" id="notePassword" autocomplete="off">
            <p class="form-error" id="unlockError" role="alert" hidden></p>
        </div>
        <button class="btn btn-primary" type="submit">ปลดล็อก</button>
    </form>
    <?php endif; ?>

    <div id="editorBody"<?= $encrypted ? ' hidden' : '' ?>>
        <label class="sr-only" for="noteTitle">ชื่อโน้ต</label>
        <textarea class="note-title" id="noteTitle" placeholder="ชื่อโน้ต" rows="1" maxlength="255"
                  data-act="autoResizeAndSave" data-args='["$el"]' data-on="input"><?= h($note['title']) ?></textarea>

        <div class="note-tagbar" id="tagWrap">
            <ul class="note-taglist" id="noteTagList" aria-label="แท็กของโน้ตนี้"></ul>
            <label class="sr-only" for="tagInput">เพิ่มแท็ก</label>
            <input type="text" class="note-taginput" id="tagInput" placeholder="เพิ่มแท็ก แล้วกด Enter" maxlength="50" autocomplete="off">
        </div>

        <div class="blocks" id="blocksContainer" aria-busy="true"></div>

        <div class="note-add" role="group" aria-label="เพิ่มบล็อก">
            <span class="note-add-label">เพิ่ม</span>
            <button class="btn btn-sm" type="button" data-act="addBlock" data-args='["text"]'><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>ข้อความ</button>
            <button class="btn btn-sm" type="button" data-act="addBlock" data-args='["link"]'><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>ลิงก์</button>
            <button class="btn btn-sm" type="button" data-act="addBlock" data-args='["checklist"]'><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>เช็กลิสต์</button>
        </div>
    </div>
</div>

<script nonce="<?= h(Security::nonce()) ?>">
window.NOTE_DATA = <?= $noteData ?>;
window.NOTE_TAGS = <?= $tagsData ?>;
</script>
