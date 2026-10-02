<?php
// =====================================================
// views/habits/index.php — a sheet of habits against the days of one week
//
// Rows are habits, columns are the seven days; tick the box for the day it was
// done. assets/js/habits.js draws the table for whichever week is showing.
// =====================================================
?>
<div class="page-head">
    <div>
        <h1>นิสัย</h1>
        <p class="sub" id="habitsSummary" role="status" aria-live="polite">กำลังโหลดนิสัย…</p>
    </div>
    <div class="page-head-actions">
        <button class="btn btn-primary" id="habitAddBtn" type="button"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>เพิ่มนิสัย</button>
    </div>
</div>

<div class="week-nav">
    <button class="icon-btn" type="button" id="weekPrev" aria-label="สัปดาห์ก่อน">&lsaquo;</button>
    <h2 class="week-label" id="weekLabel"></h2>
    <button class="icon-btn" type="button" id="weekNext" aria-label="สัปดาห์ถัดไป">&rsaquo;</button>
    <button class="btn btn-sm" type="button" id="weekToday" hidden>กลับมาสัปดาห์นี้</button>
</div>

<div id="habitsGrid" aria-busy="true">
    <div class="skel-row"><span class="skel skel-w-45"></span></div>
    <div class="skel-row"><span class="skel skel-w-60"></span></div>
    <div class="skel-row"><span class="skel skel-w-52"></span></div>
</div>

<div class="modal-overlay" id="habitModal" hidden>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="habitModalTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="habitModalTitle">เพิ่มนิสัย</h2>
            <button class="modal-close" type="button" data-close-habit aria-label="ปิด">&times;</button>
        </div>
        <form id="habitForm" class="modal-body">
            <input type="hidden" id="habitId">
            <div class="form-group">
                <label class="form-label" for="habitName">ชื่อนิสัย</label>
                <input class="form-control" id="habitName" maxlength="160" placeholder="เช่น อ่านหนังสือ 20 นาที" required>
            </div>
            <div class="habit-form-row">
                <div class="form-group">
                    <label class="form-label" for="habitTarget">เป้าหมายต่อสัปดาห์</label>
                    <select class="form-control" id="habitTarget">
                        <?php for ($i = 1; $i <= 7; $i++): ?><option value="<?= $i ?>"><?= $i ?> วัน</option><?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="habitColor">สีประจำนิสัย</label>
                    <input class="form-control habit-color-input" id="habitColor" type="color" value="#6366f1">
                </div>
            </div>
            <p class="form-error" id="habitError" role="alert" hidden></p>
            <div class="form-actions">
                <button class="btn btn-danger mr-auto" type="button" id="habitDelete" hidden>ลบนิสัยนี้</button>
                <button class="btn" type="button" data-close-habit>ยกเลิก</button>
                <button class="btn btn-primary" type="submit">บันทึกนิสัย</button>
            </div>
        </form>
    </div>
</div>
