<?php
// =====================================================
// views/review/index.php — the week or the month, looked back on
//
// Figures from every module for one period: what was finished, how long was
// spent focusing, which habits held, what came in and went out.
// assets/js/review.js draws it from /api/review.
// =====================================================
?>
<div class="page-head">
    <div>
        <h1>สรุปผล</h1>
        <p class="sub" id="reviewRange" aria-live="polite">กำลังโหลดสรุป…</p>
    </div>
    <div class="page-head-actions">
        <div class="seg-pair" role="group" aria-label="ช่วงเวลา" id="reviewPeriods">
            <button type="button" data-period="week" aria-pressed="true">สัปดาห์นี้</button>
            <button type="button" data-period="month" aria-pressed="false">เดือนนี้</button>
        </div>
    </div>
</div>

<div id="reviewError" role="alert" hidden></div>

<div class="facts rv-facts">
    <div class="fact"><div class="k">งานที่ทำเสร็จ</div><div class="v" id="rvTasksDone">—</div></div>
    <div class="fact"><div class="k">เวลาโฟกัส</div><div class="v" id="rvFocusHours">—</div></div>
    <div class="fact"><div class="k">นิสัยที่ทำได้</div><div class="v" id="rvHabits">—</div></div>
    <div class="fact"><div class="k">คงเหลือในช่วงนี้</div><div class="v" id="rvBalance">—</div></div>
</div>

<div class="cols">
    <div class="col-main">
        <section class="sec" aria-labelledby="rvTasksTitle">
            <div class="sec-head"><h2 id="rvTasksTitle">งาน</h2></div>
            <div id="rvTasksBody" aria-busy="true"><div class="skel-row"><span class="skel skel-w-60"></span></div><div class="skel-row"><span class="skel skel-w-45"></span></div></div>
        </section>
        <section class="sec" aria-labelledby="rvFocusTitle">
            <div class="sec-head"><h2 id="rvFocusTitle">เวลาโฟกัสรายวัน</h2></div>
            <div id="rvFocusBody" aria-busy="true"><div class="skel-row"><span class="skel skel-w-52"></span></div><div class="skel-row"><span class="skel skel-w-45"></span></div></div>
        </section>
        <section class="sec" aria-labelledby="rvHabitsTitle">
            <div class="sec-head"><h2 id="rvHabitsTitle">นิสัยประจำวัน</h2></div>
            <div id="rvHabitsBody" aria-busy="true"><div class="skel-row"><span class="skel skel-w-60"></span></div><div class="skel-row"><span class="skel skel-w-52"></span></div></div>
        </section>
    </div>
    <aside class="col-side">
        <section class="sec" aria-labelledby="rvMoneyTitle">
            <div class="sec-head"><h2 id="rvMoneyTitle">การเงิน</h2></div>
            <div id="rvMoneyBody" aria-busy="true"><div class="skel-row"><span class="skel skel-w-60"></span></div><div class="skel-row"><span class="skel skel-w-45"></span></div></div>
        </section>
        <section class="sec" aria-labelledby="rvBodyTitle">
            <div class="sec-head"><h2 id="rvBodyTitle">ออกกำลังกายและโน้ต</h2></div>
            <div id="rvBodyBody" aria-busy="true"><div class="skel-row"><span class="skel skel-w-52"></span></div></div>
        </section>
    </aside>
</div>
