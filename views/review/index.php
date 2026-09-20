<div class="page-header flex items-center justify-between">
    <div>
        <h1 class="page-title">สรุปผล</h1>
        <p class="text-xs text-muted" style="margin-top:4px">
            ภาพรวมข้ามโมดูล — <span id="reviewRange">กำลังโหลด...</span>
        </p>
    </div>
    <div class="flex gap-2">
        <button class="btn btn-primary btn-sm review-period active" data-period="week">สัปดาห์นี้</button>
        <button class="btn btn-ghost btn-sm review-period" data-period="month">เดือนนี้</button>
    </div>
</div>

<!-- Headline numbers -->
<div class="review-strip" id="reviewStrip">
    <div class="review-stat">
        <div class="review-stat-val" id="rvTasksDone">—</div>
        <div class="review-stat-lbl">งานที่ทำเสร็จ</div>
    </div>
    <div class="review-stat">
        <div class="review-stat-val" id="rvFocusHours">—</div>
        <div class="review-stat-lbl">เวลาโฟกัส</div>
    </div>
    <div class="review-stat">
        <div class="review-stat-val" id="rvHabits">—</div>
        <div class="review-stat-lbl">นิสัยที่ทำได้</div>
    </div>
    <div class="review-stat">
        <div class="review-stat-val" id="rvBalance">—</div>
        <div class="review-stat-lbl">คงเหลือในช่วงนี้</div>
    </div>
</div>

<div class="review-grid">
    <div class="card">
        <div class="card-header"><span class="card-title">งาน</span></div>
        <div class="card-body" id="rvTasksBody">
            <div class="widget-loading"><span class="spinner"></span></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><span class="card-title">เวลาโฟกัสรายวัน</span></div>
        <div class="card-body" id="rvFocusBody">
            <div class="widget-loading"><span class="spinner"></span></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><span class="card-title">นิสัยประจำวัน</span></div>
        <div class="card-body" id="rvHabitsBody">
            <div class="widget-loading"><span class="spinner"></span></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><span class="card-title">สุขภาพ &amp; การเงิน</span></div>
        <div class="card-body" id="rvMixedBody">
            <div class="widget-loading"><span class="spinner"></span></div>
        </div>
    </div>
</div>
