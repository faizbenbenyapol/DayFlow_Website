<?php
// =====================================================
// views/exercise/index.php — the workout log
//
// This month in three figures, the log for a chosen month, and how often each
// kind of workout has been done. assets/js/exercise.js draws it from
// /api/exercise and /api/exercise/stats.
// =====================================================
?>
<div class="page-head">
    <div>
        <h1>ออกกำลังกาย</h1>
        <p class="sub" id="exTally" aria-live="polite">กำลังโหลดบันทึก…</p>
    </div>
    <div class="page-head-actions">
        <button class="btn btn-primary" type="button" data-act="openAddWorkout"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>บันทึกการออกกำลังกาย</button>
    </div>
</div>

<div class="facts ex-facts">
    <div class="fact"><div class="k">ครั้งในเดือนนี้</div><div class="v" id="statSessions">—</div></div>
    <div class="fact"><div class="k">นาทีในเดือนนี้</div><div class="v" id="statMinutes">—</div></div>
    <div class="fact"><div class="k">ทำบ่อยที่สุด (ทั้งหมด)</div><div class="v ex-top" id="statTopType">—</div></div>
</div>

<div class="cols">
    <div class="col-main">
        <section class="sec" aria-labelledby="exHistoryTitle">
            <div class="sec-head">
                <h2 id="exHistoryTitle">ประวัติ<span class="count" id="exCount"></span></h2>
                <div class="ex-monthnav">
                    <button type="button" class="icon-btn sm" data-act="moveMonth" data-args="[-1]" aria-label="เดือนก่อน">&lsaquo;</button>
                    <span class="ex-monthlabel" id="monthLabel" aria-live="polite"></span>
                    <button type="button" class="icon-btn sm" data-act="moveMonth" data-args="[1]" aria-label="เดือนถัดไป">&rsaquo;</button>
                </div>
            </div>
            <div id="workoutList" aria-busy="true">
                <div class="skel-row"><span class="skel skel-w-60"></span></div>
                <div class="skel-row"><span class="skel skel-w-45"></span></div>
                <div class="skel-row"><span class="skel skel-w-52"></span></div>
            </div>
        </section>
    </div>
    <aside class="col-side">
        <section class="sec" aria-labelledby="exTypesTitle">
            <div class="sec-head"><h2 id="exTypesTitle">แยกตามประเภท</h2></div>
            <div id="typeStats" aria-busy="true"><div class="skel-row"><span class="skel skel-w-52"></span></div></div>
        </section>
    </aside>
</div>

<!-- Add / edit -->
<div class="modal-backdrop" id="workoutModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-labelledby="workoutModalTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="workoutModalTitle">บันทึกการออกกำลังกาย</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editWorkoutId">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="workoutType">ประเภท</label>
                    <input type="text" class="form-control" id="workoutType" list="workoutTypes" placeholder="เช่น วิ่ง ยกน้ำหนัก" maxlength="100" autocomplete="off">
                    <datalist id="workoutTypes"></datalist>
                </div>
                <div class="form-group">
                    <label class="form-label" for="workoutDate">วันที่</label>
                    <input type="date" class="form-control" id="workoutDate" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="workoutDuration">ระยะเวลา (นาที)</label>
                    <input type="number" class="form-control" id="workoutDuration" min="1" max="999" inputmode="numeric">
                </div>
                <div class="form-group">
                    <label class="form-label" for="workoutWeight">น้ำหนัก (กก.)</label>
                    <input type="number" class="form-control" id="workoutWeight" step="0.5" min="0" inputmode="decimal">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="workoutSets">เซต</label>
                    <input type="number" class="form-control" id="workoutSets" min="1" max="99" inputmode="numeric">
                </div>
                <div class="form-group">
                    <label class="form-label" for="workoutReps">ครั้งต่อเซต</label>
                    <input type="number" class="form-control" id="workoutReps" min="1" max="9999" inputmode="numeric">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="workoutNotes">หมายเหตุ</label>
                <textarea class="form-control" id="workoutNotes" rows="2"></textarea>
            </div>
            <p class="form-error" id="workoutError" role="alert" hidden></p>
        </div>
        <div class="modal-footer">
            <button class="btn" type="button" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" type="button" data-act="saveWorkout">บันทึกรายการ</button>
        </div>
    </div>
</div>
