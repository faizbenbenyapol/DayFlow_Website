<div class="page-header habits-header">
    <div>
        <h1 class="page-title">นิสัยประจำวัน</h1>
        <p class="text-muted">สร้างความสม่ำเสมอทีละวัน ด้วยรายการเล็ก ๆ ที่ทำได้จริง</p>
    </div>
    <button class="btn btn-primary" id="habitAddBtn" type="button">+ เพิ่มนิสัย</button>
</div>

<div class="habits-summary" id="habitsSummary" role="status" aria-live="polite"></div>
<div class="habits-grid" id="habitsGrid">
    <div class="empty-state">กำลังโหลดนิสัย...</div>
</div>

<div class="modal-overlay" id="habitModal" hidden>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="habitModalTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="habitModalTitle">เพิ่มนิสัย</h2>
            <button class="modal-close" type="button" data-close-habit aria-label="ปิด">&times;</button>
        </div>
        <form id="habitForm" class="modal-body">
            <input type="hidden" id="habitId">
            <label class="form-label" for="habitName">ชื่อนิสัย</label>
            <input class="form-control" id="habitName" maxlength="160" placeholder="เช่น อ่านหนังสือ 20 นาที" required>
            <div class="habit-form-row">
                <div>
                    <label class="form-label" for="habitTarget">เป้าหมายต่อสัปดาห์</label>
                    <select class="form-control" id="habitTarget">
                        <?php for ($i = 1; $i <= 7; $i++): ?><option value="<?= $i ?>"><?= $i ?> วัน</option><?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="habitColor">สี</label>
                    <input class="form-control habit-color-input" id="habitColor" type="color" value="#6366f1" aria-label="สีของนิสัย">
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-ghost" type="button" data-close-habit>ยกเลิก</button>
                <button class="btn btn-primary" type="submit">บันทึก</button>
            </div>
        </form>
    </div>
</div>
