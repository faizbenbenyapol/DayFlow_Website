<?php
// =====================================================
// views/planner/index.php — the month, and the page of the day you picked
//
// The colours below are values stored with each event, not interface colours:
// a person picks one when they save an event, and the page only passes it on as
// --ev. assets/js/planner.js draws the grid and the day sheet.
// =====================================================

$eventColors = [
    '#3b82f6' => 'น้ำเงิน', '#10b981' => 'เขียว', '#f97316' => 'ส้ม',
    '#f43f5e' => 'ชมพูแดง', '#8b5cf6' => 'ม่วง', '#64748b' => 'เทา',
];
$repeatOptions = ['none' => 'ไม่ทำซ้ำ', 'daily' => 'ทุกวัน', 'weekly' => 'ทุกสัปดาห์', 'monthly' => 'ทุกเดือน', 'yearly' => 'ทุกปี'];
?>
<div class="page-head">
    <div>
        <h1>แพลนเนอร์</h1>
        <p class="sub" id="plannerTally" aria-live="polite">กำลังโหลดปฏิทิน…</p>
    </div>
    <div class="page-head-actions">
        <a class="btn btn-ghost" href="<?= APP_URL ?>/api/planner/events/export.ics"
           title="ดาวน์โหลดปฏิทินเป็นไฟล์ .ics เพื่อนำเข้า Google Calendar หรือ Apple Calendar">ส่งออก .ics</a>
        <button class="btn btn-ghost" type="button" data-click="#icsFile" title="นำเข้ากิจกรรมจากไฟล์ .ics">นำเข้า .ics</button>
        <input type="file" id="icsFile" class="sr-only" accept=".ics,text/calendar" tabindex="-1" aria-label="เลือกไฟล์ .ics"
               data-act="importIcs" data-args="[&quot;$el&quot;]" data-on="change">
        <button class="btn btn-primary" type="button" data-act="openAddEvent"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>เพิ่มกิจกรรม</button>
    </div>
</div>

<div class="planner-layout">
    <section class="month" aria-labelledby="calMonthLabel">
        <div class="month-nav">
            <button class="icon-btn" type="button" data-act="prevMonth" aria-label="เดือนก่อน">&lsaquo;</button>
            <h2 class="month-label" id="calMonthLabel"></h2>
            <button class="icon-btn" type="button" data-act="nextMonth" aria-label="เดือนถัดไป">&rsaquo;</button>
        </div>
        <div id="monthError" role="alert" hidden></div>
        <div id="calendarGrid" class="calendar-grid"></div>
    </section>

    <!-- The page of the day: it turns over when another day is picked. -->
    <aside class="day-sheet" id="daySheet" aria-label="รายละเอียดของวันที่เลือก">
        <div class="day-sheet-head">
            <div class="date-block" aria-hidden="true"><span class="d" id="dayNum"></span><span class="m" id="dayMon"></span></div>
            <div>
                <h2 id="dayPanelDate">กำลังโหลด…</h2>
                <p class="sub" id="dayPanelSub"></p>
            </div>
        </div>

        <section class="sec" aria-labelledby="dayEventsTitle">
            <div class="sec-head"><h2 id="dayEventsTitle">กิจกรรม</h2></div>
            <div id="dayEvents"></div>
        </section>

        <section class="sec" aria-labelledby="dayTodosTitle">
            <div class="sec-head"><h2 id="dayTodosTitle">สิ่งที่ต้องทำ</h2></div>
            <div id="dayTodos"></div>
            <form class="add-row" data-act="addTodo">
                <span class="plus" aria-hidden="true">+</span>
                <label class="sr-only" for="todoInput">เพิ่มสิ่งที่ต้องทำในวันนี้</label>
                <input type="text" id="todoInput" placeholder="เพิ่มสิ่งที่ต้องทำ แล้วกด Enter" maxlength="255" autocomplete="off">
            </form>
        </section>
    </aside>
</div>

<!-- Event Create/Edit Modal -->
<div class="modal-backdrop" id="eventModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-labelledby="eventModalTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="eventModalTitle">เพิ่มกิจกรรม</h2>
            <button class="modal-close" type="button" aria-label="ปิด">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editEventId">
            <div class="form-group">
                <label class="form-label" for="eventTitle">ชื่อกิจกรรม</label>
                <input type="text" class="form-control" id="eventTitle" placeholder="เช่น ประชุมทีม, วิ่ง 5 กม." maxlength="255">
            </div>
            <div class="form-group">
                <label class="form-label" for="eventDesc">รายละเอียด</label>
                <textarea class="form-control" id="eventDesc" rows="2"></textarea>
            </div>

            <div class="form-group">
                <span class="form-label" id="eventColorLabel">สีของกิจกรรม</span>
                <div class="event-color-selector" role="radiogroup" aria-labelledby="eventColorLabel">
                    <input type="hidden" id="eventColor" value="#3b82f6">
                    <?php foreach ($eventColors as $hex => $name): ?>
                    <button type="button" class="color-dot<?= $hex === '#3b82f6' ? ' active' : '' ?>" role="radio"
                            aria-checked="<?= $hex === '#3b82f6' ? 'true' : 'false' ?>" aria-label="<?= h($name) ?>"
                            data-color="<?= h($hex) ?>" style="--ev: <?= h($hex) ?>"></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" id="eventAllDay" data-act="toggleAllDay" data-args="[&quot;$checked&quot;]" data-on="change">
                    <span>กิจกรรมทั้งวัน</span>
                </label>
            </div>
            <div class="form-row" id="dateTimeFields">
                <div class="form-group">
                    <label class="form-label" for="eventStart">เริ่ม</label>
                    <input type="datetime-local" class="form-control" id="eventStart">
                </div>
                <div class="form-group">
                    <label class="form-label" for="eventEnd">สิ้นสุด</label>
                    <input type="datetime-local" class="form-control" id="eventEnd">
                </div>
            </div>
            <div class="form-row" id="dateOnlyFields" hidden>
                <div class="form-group">
                    <label class="form-label" for="eventDate">วันที่</label>
                    <input type="date" class="form-control" id="eventDate">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="eventRepeat">ทำซ้ำ</label>
                    <select class="form-control" id="eventRepeat">
                        <?php foreach ($repeatOptions as $value => $text): ?>
                        <option value="<?= $value ?>"><?= h($text) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="eventRepeatUntil">ทำซ้ำถึงวันที่</label>
                    <input type="date" class="form-control" id="eventRepeatUntil">
                    <p class="form-hint">ไม่ใส่ = ไม่สิ้นสุด</p>
                </div>
            </div>
            <p class="form-hint" id="eventRepeatHint" hidden>แก้ไขหรือลบจะมีผลกับทุกครั้งในชุดนี้</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-danger mr-auto" type="button" id="deleteEventBtn" data-act="deleteEvent" hidden>ลบกิจกรรม</button>
            <button class="btn" type="button" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" type="button" data-act="saveEvent">บันทึกกิจกรรม</button>
        </div>
    </div>
</div>
