<?php
// =====================================================
// views/focus/index.php — a timer, and what it has counted today
//
// The time is the page. assets/js/focus.js runs it from an end time, so a tab
// in the background or a locked phone does not make it run slow.
// $openTasks comes from FocusController.
// =====================================================
?>
<div class="page-head">
    <div>
        <h1>โฟกัส</h1>
        <p class="sub" id="focusTally" aria-live="polite">กำลังโหลด…</p>
    </div>
</div>

<div class="focus-layout-grid" data-mode="work">
    <section class="focus-timer-section" aria-label="ตัวจับเวลา">
        <div class="seg focus-mode-selector" role="group" aria-label="ช่วงเวลา">
            <button type="button" class="focus-mode-btn active" data-mode="work" aria-pressed="true">โฟกัสงาน</button>
            <button type="button" class="focus-mode-btn" data-mode="short_break" aria-pressed="false">พักสั้น</button>
            <button type="button" class="focus-mode-btn" data-mode="long_break" aria-pressed="false">พักยาว</button>
        </div>

        <div class="timer-face" role="timer" aria-label="เวลาที่เหลือ">
            <div class="timer-phase-lbl" id="timerPhaseLabel">พร้อมโฟกัส</div>
            <div class="timer-countdown" id="timerDisplay">25:00</div>
            <div class="progress timer-bar" aria-hidden="true"><div class="progress-bar" id="timerProgress"></div></div>
        </div>

        <div class="timer-controls">
            <button type="button" class="btn btn-primary btn-lg" id="btnStartStop">เริ่มจับเวลา</button>
            <button type="button" class="btn btn-lg" id="btnResetTimer">เริ่มใหม่</button>
            <button type="button" class="btn btn-lg btn-ghost" id="btnSkipTimer">ข้าม</button>
        </div>

        <div class="focus-log-setup">
            <div class="form-group">
                <label class="form-label" for="selectFocusTask">ทำงานไหนอยู่</label>
                <select class="form-control" id="selectFocusTask">
                    <option value="">ไม่ระบุงาน</option>
                    <?php foreach ($openTasks as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"><?= h($t['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="inputFocusTitle">จดสั้น ๆ ว่าทำอะไร</label>
                <input type="text" class="form-control" id="inputFocusTitle" maxlength="200" placeholder="เช่น อ่านโครงสร้างฐานข้อมูล, ตอบอีเมลลูกค้า">
            </div>
        </div>

        <details class="timer-custom-settings">
            <summary>ปรับความยาวของแต่ละช่วง</summary>
            <div class="custom-settings-fields">
                <div class="form-group">
                    <label class="form-label" for="inputWorkDuration">โฟกัส (นาที)</label>
                    <input type="number" class="form-control" id="inputWorkDuration" value="25" min="1" max="180">
                </div>
                <div class="form-group">
                    <label class="form-label" for="inputShortBreak">พักสั้น (นาที)</label>
                    <input type="number" class="form-control" id="inputShortBreak" value="5" min="1" max="60">
                </div>
                <div class="form-group">
                    <label class="form-label" for="inputLongBreak">พักยาว (นาที)</label>
                    <input type="number" class="form-control" id="inputLongBreak" value="15" min="1" max="120">
                </div>
            </div>
        </details>
    </section>

    <section class="focus-stats-section" aria-label="สถิติและประวัติ">
        <div class="facts">
            <div class="fact"><div class="k">โฟกัสวันนี้</div><div class="v" id="statTodayTime">—</div></div>
            <div class="fact"><div class="k">รอบวันนี้</div><div class="v" id="statTodaySessions">—</div></div>
            <div class="fact"><div class="k">สะสมทั้งหมด</div><div class="v" id="statTotalSessions">—</div></div>
        </div>

        <div class="sec">
            <div class="sec-head"><h2>ประวัติล่าสุด</h2></div>
            <div id="focusLogs" aria-busy="true">
                <div class="skel-row"><span class="skel skel-w-60"></span></div>
                <div class="skel-row"><span class="skel skel-w-45"></span></div>
                <div class="skel-row"><span class="skel skel-w-52"></span></div>
            </div>
        </div>
    </section>
</div>
