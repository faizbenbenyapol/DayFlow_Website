<div class="page-header flex items-center justify-between">
    <div>
        <h1 class="page-title">บันทึกอาหาร / เครื่องดื่ม</h1>
        <p class="page-subtitle">รายการอาหารและเครื่องดื่มที่แพ้หรือควรหลีกเลี่ยง </p>
    </div>
    <button class="btn btn-ghost btn-sm" onclick="openAdd()">+ เพิ่ม</button>
</div>

<!-- Summary Badges -->
<div class="fn-summary" id="fnSummary">
    <div class="fn-badge fn-allergy">แพ้รุนแรง <span id="cntAllergy">0</span></div>
    <div class="fn-badge fn-intolerance">แพ้แฝง / อาการไม่รุนแรง <span id="cntIntolerance">0</span></div>
    <div class="fn-badge fn-avoid">ควรหลีกเลี่ยง <span id="cntAvoid">0</span></div>
    <div class="fn-badge fn-caution">ควรระวัง <span id="cntCaution">0</span></div>
</div>

<!-- Filters -->
<div class="fn-filters">
    <div class="fn-filter-group">
        <button class="fn-filter-btn active" data-filter="type" data-value="" onclick="setFilter('type','',this)">ทั้งหมด</button>
        <button class="fn-filter-btn" data-filter="type" data-value="food" onclick="setFilter('type','food',this)">อาหาร</button>
        <button class="fn-filter-btn" data-filter="type" data-value="drink" onclick="setFilter('type','drink',this)">เครื่องดื่ม</button>
    </div>
    <div class="fn-filter-group">
        <button class="fn-filter-btn active" data-filter="reaction" data-value="" onclick="setFilter('reaction','',this)">ทุกประเภท</button>
        <button class="fn-filter-btn" data-filter="reaction" data-value="allergy" onclick="setFilter('reaction','allergy',this)">แพ้รุนแรง</button>
        <button class="fn-filter-btn" data-filter="reaction" data-value="intolerance" onclick="setFilter('reaction','intolerance',this)">แพ้แฝง / อาการไม่รุนแรง</button>
        <button class="fn-filter-btn" data-filter="reaction" data-value="avoid" onclick="setFilter('reaction','avoid',this)">ควรหลีกเลี่ยง</button>
        <button class="fn-filter-btn" data-filter="reaction" data-value="caution" onclick="setFilter('reaction','caution',this)">ควรระวัง</button>
    </div>
</div>

<!-- List -->
<div id="fnList" class="fn-list">
    <div class="card" style="grid-column:1/-1;text-align:center;padding:3rem">
        <div class="spinner"></div>
    </div>
</div>

<!-- Modal -->
<div class="modal-backdrop" id="fnModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title" id="fnModalTitle">เพิ่มรายการ</span>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editId">

            <div class="form-group">
                <label class="form-label">ชื่ออาหาร / เครื่องดื่ม</label>
                <input type="text" class="form-control" id="fnName"
                       placeholder="เช่น นม, กลูเตน, ผักชี..." maxlength="255">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">ประเภท</label>
                    <select class="form-control" id="fnType">
                        <option value="food">อาหาร</option>
                        <option value="drink">เครื่องดื่ม</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">ปฏิกิริยา</label>
                    <select class="form-control" id="fnReaction">
                        <option value="allergy">แพ้รุนแรง</option>
                        <option value="intolerance">แพ้แฝง / อาการไม่รุนแรง</option>
                        <option value="avoid">ควรหลีกเลี่ยง</option>
                        <option value="caution">ควรระวัง</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">ระดับความรุนแรง</label>
                <div class="fn-severity-group" id="fnSeverityGroup">
                    <label class="fn-severity-opt">
                        <input type="radio" name="severity" value="mild"> เล็กน้อย
                    </label>
                    <label class="fn-severity-opt">
                        <input type="radio" name="severity" value="moderate" checked> ปานกลาง
                    </label>
                    <label class="fn-severity-opt">
                        <input type="radio" name="severity" value="severe"> รุนแรง
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">อาการที่เกิดขึ้น</label>
                <textarea class="form-control" id="fnSymptoms" rows="2"
                          placeholder="เช่น ท้องเสีย, ปวดท้อง, ท้องอืด..."></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">หมายเหตุ</label>
                <textarea class="form-control" id="fnNotes" rows="2"
                          placeholder="บันทึกเพิ่มเติม..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-danger btn-sm" id="fnDeleteBtn" style="margin-right:auto;display:none"
                    onclick="deleteItem()">ลบ</button>
            <button class="btn btn-ghost" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" onclick="saveItem()">บันทึก</button>
        </div>
    </div>
</div>
