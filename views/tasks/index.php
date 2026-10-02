<?php
// =====================================================
// views/tasks/index.php — the Eisenhower matrix
//
// Four ruled boxes: do now, plan, hand off, drop. Each ends with a line to type
// a task into. assets/js/tasks.js fills the lists from /api/tasks.
// =====================================================

$quadrants = [
    1 => ['ทำทันที', 'สำคัญ + เร่งด่วน'],
    2 => ['วางแผน', 'สำคัญ + ไม่เร่งด่วน'],
    3 => ['มอบหมาย', 'ไม่สำคัญ + เร่งด่วน'],
    4 => ['ตัดทิ้ง', 'ไม่สำคัญ + ไม่เร่งด่วน'],
];
$repeatOptions = [
    'none' => 'ไม่ทำซ้ำ', 'daily' => 'ทุกวัน', 'weekly' => 'ทุกสัปดาห์', 'monthly' => 'ทุกเดือน', 'yearly' => 'ทุกปี',
];
?>
<div class="page-head">
    <div>
        <h1>งาน</h1>
        <p class="sub" id="tasksTally" aria-live="polite">กำลังนับงาน…</p>
    </div>
    <div class="page-head-actions">
        <button type="button" class="btn btn-primary" data-act="openAddTask" data-args="[1]"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>เพิ่มงาน</button>
    </div>
</div>

<div class="matrix-grid" id="matrixGrid">
    <?php foreach ($quadrants as $q => [$label, $title]): ?>
    <section class="quadrant" id="q<?= $q ?>" aria-labelledby="q<?= $q ?>-label">
        <header class="quadrant-head">
            <div>
                <h2 class="quadrant-label" id="q<?= $q ?>-label"><?= h($label) ?></h2>
                <p class="quadrant-title"><?= h($title) ?></p>
            </div>
            <span class="quadrant-count" id="q<?= $q ?>-count"></span>
        </header>
        <div class="quadrant-body" id="q<?= $q ?>-list" data-quadrant="<?= $q ?>" aria-busy="true">
            <div class="skel-row"><span class="skel box"></span><span class="skel skel-w-60"></span></div>
            <div class="skel-row"><span class="skel box"></span><span class="skel skel-w-45"></span></div>
        </div>
        <label class="add-row">
            <span class="plus" aria-hidden="true">+</span>
            <span class="sr-only">เพิ่มงานใน <?= h($label) ?></span>
            <input type="text" class="quick-add" data-quadrant="<?= $q ?>" maxlength="255" autocomplete="off" placeholder="เพิ่มงาน แล้วกด Enter">
        </label>
    </section>
    <?php endforeach; ?>
</div>

<!-- Edit and add share one set of fields, written once. -->
<?php foreach (['edit' => ['แก้ไขงาน', 'บันทึกงาน', 'saveEditTask'], 'add' => ['เพิ่มงานใหม่', 'เพิ่มงาน', 'saveAddTask']] as $mode => [$heading, $button, $action]):
    $p = $mode; // id prefix: editTask…, addTask…
?>
<div class="modal-backdrop" id="<?= $p ?>TaskModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-labelledby="<?= $p ?>TaskHeading">
        <div class="modal-header">
            <h2 class="modal-title" id="<?= $p ?>TaskHeading"><?= h($heading) ?></h2>
            <button class="modal-close" type="button" aria-label="ปิด">&times;</button>
        </div>
        <div class="modal-body">
            <?php if ($mode === 'edit'): ?><input type="hidden" id="editTaskId"><?php endif; ?>
            <div class="form-group">
                <label class="form-label" for="<?= $p ?>TaskTitle">ชื่องาน</label>
                <input type="text" class="form-control" id="<?= $p ?>TaskTitle" maxlength="255" placeholder="เช่น ส่งใบเสนอราคาให้คุณสมชาย">
            </div>
            <div class="form-group">
                <label class="form-label" for="<?= $p ?>TaskDesc">รายละเอียด</label>
                <textarea class="form-control" id="<?= $p ?>TaskDesc" rows="3"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="<?= $p ?>TaskQuadrant">ความสำคัญ</label>
                    <select class="form-control" id="<?= $p ?>TaskQuadrant">
                        <?php foreach ($quadrants as $q => [$label, $title]): ?>
                        <option value="<?= $q ?>"><?= h($title) ?> (<?= h($label) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="<?= $p ?>TaskDue">วันครบกำหนด</label>
                    <input type="date" class="form-control" id="<?= $p ?>TaskDue">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="<?= $p ?>TaskRepeat">ทำซ้ำ</label>
                    <select class="form-control" id="<?= $p ?>TaskRepeat">
                        <?php foreach ($repeatOptions as $value => $text): ?>
                        <option value="<?= $value ?>"><?= h($text) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="<?= $p ?>TaskRepeatUntil">ทำซ้ำถึงวันที่</label>
                    <input type="date" class="form-control" id="<?= $p ?>TaskRepeatUntil">
                    <p class="form-hint">ไม่ใส่ = ไม่สิ้นสุด</p>
                </div>
            </div>
            <p class="form-hint">งานที่ทำซ้ำจะสร้างรอบถัดไปให้เองเมื่อติ๊กว่าเสร็จแล้ว</p>
        </div>
        <div class="modal-footer">
            <button class="btn" type="button" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" type="button" data-act="<?= $action ?>"><?= h($button) ?></button>
        </div>
    </div>
</div>
<?php endforeach; ?>
