<?php
$noteData = json_encode($note, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$tags = Note::getTagsForNote($note['id']);
$tagsData = json_encode($tags, JSON_UNESCAPED_UNICODE);
?>

<div class="note-editor" id="noteEditor"
     data-note-id="<?= (int)$note['id'] ?>"
     data-encrypted="<?= (int)$note['is_encrypted'] ?>">

    <div class="note-editor-toolbar">
        <a href="<?= APP_URL ?>/notes" class="btn btn-ghost btn-sm">← กลับ</a>

        <!-- Tags -->
        <div class="note-tag-input-wrap" id="tagWrap">
            <?php foreach ($tags as $t): ?>
            <span class="tag active" data-tag="<?= h($t['name']) ?>">
                <?= h($t['name']) ?>
                <button onclick="removeTag('<?= h($t['name']) ?>')" style="background:none;border:none;cursor:pointer;margin-left:2px;font-size:0.7rem">&#10005;</button>
            </span>
            <?php endforeach; ?>
            <div class="tag-input-container" style="display:inline-flex;align-items:center;position:relative;">
                <input type="text" id="tagInput" placeholder="+ แท็ก"
                       style="border:none;outline:none;background:transparent;font-size:0.85rem;color:var(--color-muted);width:80px;font-family:inherit"
                       onkeydown="handleTagInput(event)"
                       oninput="handleTagOnInput(this)"
                       onblur="setTimeout(() => submitTagInput(this), 200)">
                <button id="tagAddBtn" style="display:none;background:#6366f1;color:white;border:none;border-radius:50%;width:18px;height:18px;font-size:0.75rem;cursor:pointer;align-items:center;justify-content:center;margin-left:4px;padding:0;line-height:1;font-weight:bold;box-shadow:var(--shadow-sm);transition:transform 0.1s ease;"
                        onclick="event.preventDefault(); submitTagInput(document.getElementById('tagInput'));"
                        type="button">+</button>
            </div>
        </div>

        <?php if (!$note['is_encrypted']): ?>
        <span class="save-status" id="saveStatus">บันทึกอัตโนมัติ</span>
        <?php else: ?>
        <span class="badge badge-dark" style="margin-left:auto">เข้ารหัสแล้ว</span>
        <?php endif; ?>
    </div>

    <!-- Encrypted: password prompt -->
    <?php if ($note['is_encrypted']): ?>
    <div id="encryptedPrompt" class="card" style="max-width:400px;margin:0 auto">
        <div class="card-header">
            <span class="card-title">โน้ตนี้เข้ารหัสอยู่</span>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">รหัสผ่าน</label>
                <input type="password" class="form-control" id="notePassword" placeholder="กรอกรหัสผ่าน...">
            </div>
        </div>
        <div style="padding:0 var(--space-6) var(--space-6)">
            <button class="btn btn-primary btn-block" onclick="unlockNote()">ปลดล็อก</button>
        </div>
    </div>
    <div id="editorBody" style="display:none">
    <?php else: ?>
    <div id="editorBody">
    <?php endif; ?>

        <!-- Title -->
        <textarea class="note-editor-title" id="noteTitle"
                  placeholder="ชื่อโน้ต..."
                  rows="1"
                  oninput="autoResize(this); debouncedSaveTitle()"><?= h($note['title']) ?></textarea>

        <!-- Blocks container -->
        <div class="blocks-container" id="blocksContainer"></div>

        <!-- Add block -->
        <button class="add-block-btn" id="addBlockBtn">
            + เพิ่มบล็อก
        </button>

        <!-- Add block type menu -->
        <div id="blockTypeMenu" style="display:none;padding:var(--space-3);background:var(--color-surface);border:1px solid var(--color-border);border-radius:var(--radius-lg);margin-top:var(--space-2);display:none">
            <div class="flex gap-3">
                <button class="btn btn-ghost btn-sm" onclick="addBlock('text')">ข้อความ</button>
                <button class="btn btn-ghost btn-sm" onclick="addBlock('link')">ลิงก์</button>
                <button class="btn btn-ghost btn-sm" onclick="addBlock('checklist')">Checklist</button>
            </div>
        </div>

    </div>
</div>

<script>
window.NOTE_DATA = <?= $noteData ?>;
window.NOTE_TAGS = <?= $tagsData ?>;
</script>
