<?php
// =====================================================
// views/food_notes/index.php — foods and drinks to avoid
//
// What disagrees with the person, how badly, and what happens. Grouped from the
// most serious reaction down. assets/js/food_notes.js draws it from
// /api/food-notes.
// =====================================================
?>
<div class="page-head">
    <div>
        <h1>บันทึกอาหาร / เครื่องดื่ม</h1>
        <p class="sub" id="fnTally" aria-live="polite">กำลังโหลดรายการ…</p>
    </div>
    <div class="page-head-actions">
        <button class="btn btn-primary" type="button" data-act="openAdd"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>เพิ่มรายการ</button>
    </div>
</div>

<div class="fn-filters">
    <div class="seg-pair" role="group" aria-label="ประเภท" id="fnTypeFilter">
        <button type="button" data-type="" aria-pressed="true" data-act="setTypeFilter" data-args='[""]'>ทั้งหมด</button>
        <button type="button" data-type="food" aria-pressed="false" data-act="setTypeFilter" data-args='["food"]'>อาหาร</button>
        <button type="button" data-type="drink" aria-pressed="false" data-act="setTypeFilter" data-args='["drink"]'>เครื่องดื่ม</button>
    </div>
    <label class="sr-only" for="fnReactionFilter">ปฏิกิริยา</label>
    <select class="form-control fn-reaction-filter" id="fnReactionFilter" data-act="setReactionFilter" data-args='["$value"]' data-on="change">
        <option value="">ทุกระดับ</option>
        <option value="allergy">แพ้รุนแรง</option>
        <option value="intolerance">แพ้แฝง / อาการไม่รุนแรง</option>
        <option value="avoid">ควรหลีกเลี่ยง</option>
        <option value="caution">ควรระวัง</option>
    </select>
</div>

<div id="fnList" aria-busy="true">
    <div class="skel-row"><span class="skel skel-w-60"></span></div>
    <div class="skel-row"><span class="skel skel-w-45"></span></div>
    <div class="skel-row"><span class="skel skel-w-52"></span></div>
</div>

<!-- Add / edit -->
<div class="modal-backdrop" id="fnModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-labelledby="fnModalTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="fnModalTitle">เพิ่มรายการ</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editId">

            <div class="form-group">
                <label class="form-label" for="fnName">ชื่ออาหาร / เครื่องดื่ม</label>
                <input type="text" class="form-control" id="fnName" placeholder="เช่น นม, กลูเตน, ผักชี" maxlength="255" autocomplete="off">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="fnType">ประเภท</label>
                    <select class="form-control" id="fnType">
                        <option value="food">อาหาร</option>
                        <option value="drink">เครื่องดื่ม</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="fnReaction">ปฏิกิริยา</label>
                    <select class="form-control" id="fnReaction">
                        <option value="allergy">แพ้รุนแรง</option>
                        <option value="intolerance">แพ้แฝง / อาการไม่รุนแรง</option>
                        <option value="avoid">ควรหลีกเลี่ยง</option>
                        <option value="caution">ควรระวัง</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <span class="form-label" id="fnSeverityLabel">ระดับความรุนแรง</span>
                <div class="seg-pair" role="radiogroup" aria-labelledby="fnSeverityLabel">
                    <label><input type="radio" name="severity" value="mild"><span>เล็กน้อย</span></label>
                    <label><input type="radio" name="severity" value="moderate" checked><span>ปานกลาง</span></label>
                    <label><input type="radio" name="severity" value="severe"><span>รุนแรง</span></label>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="fnSymptoms">อาการที่เกิดขึ้น</label>
                <textarea class="form-control" id="fnSymptoms" rows="2" placeholder="เช่น ท้องเสีย, ปวดท้อง, ท้องอืด"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label" for="fnNotes">หมายเหตุ</label>
                <textarea class="form-control" id="fnNotes" rows="2" placeholder="บันทึกเพิ่มเติม"></textarea>
            </div>
            <p class="form-error" id="fnError" role="alert" hidden></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-danger mr-auto" type="button" id="fnDeleteBtn" data-act="deleteItem" hidden>ลบรายการ</button>
            <button class="btn" type="button" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" type="button" data-act="saveItem">บันทึกรายการ</button>
        </div>
    </div>
</div>
