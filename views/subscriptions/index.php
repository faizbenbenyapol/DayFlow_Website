<?php
// =====================================================
// views/subscriptions/index.php — what is charged, and when
//
// Sorted by how soon each is due, with what it costs a month and a year.
// assets/js/subscriptions.js draws the list from /api/subscriptions.
// =====================================================
$cycles = ['monthly' => 'รายเดือน', 'yearly' => 'รายปี', 'weekly' => 'รายสัปดาห์', 'one_time' => 'ครั้งเดียว'];
?>
<div class="page-head">
    <div>
        <h1>รายจ่ายประจำ</h1>
        <p class="sub" id="subTally" aria-live="polite">กำลังโหลดรายการ…</p>
    </div>
    <div class="page-head-actions">
        <button class="btn btn-primary" type="button" data-act="openAddSub"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>เพิ่มรายการ</button>
    </div>
</div>

<div class="facts sub-facts" id="subFacts" hidden>
    <div class="fact"><div class="k">ต่อเดือนประมาณ</div><div class="v" id="subPerMonth">—</div></div>
    <div class="fact"><div class="k">ต่อปีประมาณ</div><div class="v" id="subPerYear">—</div></div>
    <div class="fact"><div class="k">ตัดเงินใน 7 วัน</div><div class="v" id="subNextWeek">—</div></div>
</div>

<div id="subGrid" aria-busy="true">
    <div class="skel-row"><span class="skel skel-w-60"></span></div>
    <div class="skel-row"><span class="skel skel-w-45"></span></div>
    <div class="skel-row"><span class="skel skel-w-52"></span></div>
</div>

<!-- Modal -->
<div class="modal-backdrop" id="subModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-labelledby="subModalTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="subModalTitle">เพิ่มรายการ</h2>
            <button class="modal-close" type="button" aria-label="ปิด">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editSubId">
            <div class="form-group">
                <label class="form-label" for="subName">ชื่อรายการ</label>
                <input type="text" class="form-control" id="subName" placeholder="เช่น ค่าไฟ, Netflix" maxlength="150">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="subAmount">จำนวนเงิน (บาท)</label>
                    <input type="text" inputmode="decimal" class="form-control" id="subAmount" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label" for="subCycle">รอบชำระ</label>
                    <select class="form-control" id="subCycle">
                        <?php foreach ($cycles as $value => $label): ?>
                        <option value="<?= $value ?>"><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="subDue">ครบกำหนดครั้งถัดไป</label>
                    <input type="date" class="form-control" id="subDue">
                </div>
                <div class="form-group">
                    <label class="form-label" for="subAlert">แจ้งเตือนก่อน (วัน)</label>
                    <input type="number" class="form-control" id="subAlert" value="3" min="0" max="30">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="subNotes">หมายเหตุ</label>
                <textarea class="form-control" id="subNotes" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" id="subActive" checked>
                    <span>ยังใช้งานอยู่ (ปิดไว้ถ้ายกเลิกแล้ว จะไม่นับในยอดรวม)</span>
                </label>
            </div>
            <p class="form-error" id="subError" role="alert" hidden></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-danger mr-auto" type="button" id="deleteSubBtn" data-act="deleteSub" hidden>ลบรายการ</button>
            <button class="btn" type="button" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" type="button" data-act="saveSub">บันทึกรายการ</button>
        </div>
    </div>
</div>
