<div class="page-header flex items-center justify-between">
    <div>
        <h1 class="page-title">การแจ้งเตือน / นับถอยหลัง</h1>
        <p class="page-subtitle">ค่าใช้จ่ายประจำและ Subscription ต่าง ๆ</p>
    </div>
    <button class="btn btn-ghost btn-sm" onclick="openAddSub()">+ เพิ่ม</button>
</div>

<div id="subGrid" class="grid-2">
    <div class="card" style="grid-column:1/-1;text-align:center;padding:3rem">
        <div class="spinner"></div>
    </div>
</div>

<!-- Modal -->
<div class="modal-backdrop" id="subModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title" id="subModalTitle">เพิ่มรายการ</span>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editSubId">
            <div class="form-group">
                <label class="form-label">ชื่อรายการ</label>
                <input type="text" class="form-control" id="subName"
                       placeholder="เช่น ค่าไฟ, Netflix..." maxlength="150">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">จำนวนเงิน (บาท)</label>
                    <input type="number" class="form-control" id="subAmount" step="0.01" min="0">
                </div>
                <div class="form-group">
                    <label class="form-label">รอบชำระ</label>
                    <select class="form-control" id="subCycle">
                        <option value="monthly">รายเดือน</option>
                        <option value="yearly">รายปี</option>
                        <option value="weekly">รายสัปดาห์</option>
                        <option value="one_time">ครั้งเดียว</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">วันครบกำหนดถัดไป</label>
                    <input type="date" class="form-control" id="subDue">
                </div>
                <div class="form-group">
                    <label class="form-label">แจ้งเตือนก่อน (วัน)</label>
                    <input type="number" class="form-control" id="subAlert" value="3" min="0" max="30">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">หมายเหตุ</label>
                <textarea class="form-control" id="subNotes" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label class="flex items-center gap-3" style="cursor:pointer">
                    <input type="checkbox" id="subActive" checked>
                    <span>ใช้งานอยู่</span>
                </label>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-danger btn-sm" id="deleteSubBtn" style="margin-right:auto;display:none"
                    onclick="deleteSub()">ลบ</button>
            <button class="btn btn-ghost" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" onclick="saveSub()">บันทึก</button>
        </div>
    </div>
</div>
