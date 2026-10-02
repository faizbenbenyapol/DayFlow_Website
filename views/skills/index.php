<?php
// =====================================================
// views/skills/index.php — time spent on skills
//
// A stopwatch that keeps running on the server, the totals, the log of what was
// timed, and each skill's progress toward its goal (10,000 hours by default).
// assets/js/skills.js draws it from /api/skills.
// =====================================================
?>
<div class="page-head">
    <div>
        <h1>ทักษะ</h1>
        <p class="sub">จับเวลาที่ใช้ฝึกแต่ละทักษะ แล้วดูว่าเข้าใกล้เป้าหมายแค่ไหน</p>
    </div>
    <div class="page-head-actions">
        <button class="btn" type="button" data-act="openSkillModal"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>เพิ่มทักษะ</button>
    </div>
</div>

<section class="sk-timer" aria-label="จับเวลา">
    <div class="sk-timer-face" id="timerDisplay" role="timer" aria-live="off">00:00:00</div>
    <div class="sk-timer-fields">
        <div class="form-group">
            <label class="form-label" for="timerSkillSelect">ทักษะที่ฝึก</label>
            <select id="timerSkillSelect" class="form-control"><option value="">เลือกทักษะ</option></select>
        </div>
        <div class="form-group">
            <label class="form-label" for="timerNotesInput">ทำอะไรอยู่ (ไม่ใส่ก็ได้)</label>
            <input type="text" id="timerNotesInput" class="form-control" maxlength="2000" autocomplete="off" placeholder="เช่น ฝึกคอร์ด F, ทำโจทย์ข้อ 3">
        </div>
        <button class="btn btn-primary sk-timer-btn" type="button" id="btnTimerToggle" data-act="toggleTimer">เริ่มจับเวลา</button>
    </div>
    <p class="form-error" id="timerError" role="alert" hidden></p>
</section>

<div class="facts sk-facts">
    <div class="fact"><div class="k">วันนี้</div><div class="v" id="statToday">—</div></div>
    <div class="fact"><div class="k">สัปดาห์นี้</div><div class="v" id="statWeek">—</div></div>
    <div class="fact"><div class="k">เดือนนี้</div><div class="v" id="statMonth">—</div></div>
    <div class="fact"><div class="k">รวมทั้งหมด</div><div class="v" id="statTotal">—</div></div>
</div>

<div class="cols">
    <div class="col-main">
        <section class="sec" aria-labelledby="skLogsTitle">
            <div class="sec-head"><h2 id="skLogsTitle">ประวัติการจับเวลา</h2></div>
            <div id="logsList" aria-busy="true">
                <div class="skel-row"><span class="skel skel-w-60"></span></div>
                <div class="skel-row"><span class="skel skel-w-45"></span></div>
                <div class="skel-row"><span class="skel skel-w-52"></span></div>
            </div>
        </section>
    </div>
    <aside class="col-side">
        <section class="sec" aria-labelledby="skGoalsTitle">
            <div class="sec-head"><h2 id="skGoalsTitle">เป้าหมาย</h2></div>
            <div id="skillsListContainer" aria-busy="true">
                <div class="skel-row"><span class="skel skel-w-52"></span></div>
                <div class="skel-row"><span class="skel skel-w-45"></span></div>
            </div>
        </section>
    </aside>
</div>

<!-- Add / edit a skill -->
<div class="modal-backdrop" id="skillModal" aria-hidden="true">
    <div class="modal modal-narrow" role="dialog" aria-labelledby="skillModalTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="skillModalTitle">เพิ่มทักษะ</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <form id="skillForm" data-act="saveSkill" novalidate>
            <div class="modal-body">
                <input type="hidden" id="skillId">
                <div class="form-group">
                    <label class="form-label" for="skillName">ชื่อทักษะ</label>
                    <input type="text" id="skillName" class="form-control" maxlength="255" autocomplete="off" placeholder="เช่น เขียนโปรแกรม, กีตาร์">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="skillTargetHours">เป้าหมาย (ชั่วโมง)</label>
                        <input type="number" id="skillTargetHours" class="form-control" value="10000" min="1" max="100000" inputmode="numeric">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="skillColor">สีประจำทักษะ</label>
                        <input type="color" id="skillColor" class="form-control sk-color" value="#2D7B4D">
                    </div>
                </div>
                <p class="form-hint">เริ่มต้นที่ 10,000 ชั่วโมงตามกฎการฝึกฝน ปรับให้เหมาะกับทักษะนั้นได้</p>
                <p class="form-error" id="skillError" role="alert" hidden></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close-modal>ยกเลิก</button>
                <button type="submit" class="btn btn-primary">บันทึกทักษะ</button>
            </div>
        </form>
    </div>
</div>
