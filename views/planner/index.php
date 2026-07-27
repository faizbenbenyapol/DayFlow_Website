<div class="page-header flex items-center justify-between">
    <h1 class="page-title">แพลนเนอร์</h1>
    <button class="btn btn-primary btn-sm" onclick="openAddEvent()">+ เพิ่มกิจกรรม</button>
</div>

<div class="planner-layout">
    <!-- Calendar Card Grid -->
    <div class="card" style="padding: var(--space-5);">
        <div class="calendar-nav">
            <button class="btn btn-ghost btn-sm" onclick="prevMonth()">&larr;</button>
            <span class="calendar-month-label" id="calMonthLabel"></span>
            <button class="btn btn-ghost btn-sm" onclick="nextMonth()">&rarr;</button>
        </div>
        <div id="calendarGrid"></div>
    </div>

    <!-- Day Details & Todo list panel -->
    <div class="day-panel">
        <div class="day-panel-date" id="dayPanelDate">กำลังโหลด...</div>

        <div class="day-panel-section">
            <div class="day-panel-section-title">กิจกรรมประจำวัน</div>
            <div id="dayEvents" class="flex-col" style="display: flex; flex-direction: column; gap: 8px;"></div>
        </div>

        <div class="day-panel-section" style="border-top: 1px solid var(--color-border); padding-top: var(--space-5);">
            <div class="day-panel-section-title">รายการสิ่งที่ต้องทำ</div>
            <form class="day-todo-add" onsubmit="event.preventDefault(); addTodo();">
                <input type="text" id="todoInput" placeholder="เพิ่มรายการสิ่งที่ต้องทำ..." maxlength="255">
                <button class="btn btn-primary btn-sm" type="submit">เพิ่ม</button>
            </form>
            <div id="dayTodos" class="flex-col" style="display: flex; flex-direction: column; gap: 6px;"></div>
        </div>
    </div>
</div>

<!-- Event Create/Edit Modal -->
<div class="modal-backdrop" id="eventModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title" id="eventModalTitle">บันทึกกิจกรรม</span>
            <button class="modal-close" type="button">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editEventId">
            <div class="form-group">
                <label class="form-label">ชื่อกิจกรรม</label>
                <input type="text" class="form-control" id="eventTitle" placeholder="เช่น ประชุมงาน, ออกกำลังกาย..." maxlength="255">
            </div>
            <div class="form-group">
                <label class="form-label">รายละเอียด</label>
                <textarea class="form-control" id="eventDesc" placeholder="ระบุรายละเอียดเพิ่มเติม..." rows="2"></textarea>
            </div>
            
            <!-- Curated Color Presets Selector -->
            <div class="form-group">
                <label class="form-label">สีประจำกิจกรรม</label>
                <div class="event-color-selector">
                    <input type="hidden" id="eventColor" value="#3b82f6">
                    <span class="color-dot active" data-color="#3b82f6" style="background: #3b82f6;"></span>
                    <span class="color-dot" data-color="#10b981" style="background: #10b981;"></span>
                    <span class="color-dot" data-color="#f97316" style="background: #f97316;"></span>
                    <span class="color-dot" data-color="#f43f5e" style="background: #f43f5e;"></span>
                    <span class="color-dot" data-color="#8b5cf6" style="background: #8b5cf6;"></span>
                    <span class="color-dot" data-color="#64748b" style="background: #64748b;"></span>
                </div>
            </div>

            <div class="form-group" style="margin-top: var(--space-4);">
                <label class="flex items-center gap-3" style="cursor:pointer; font-size: 0.9rem; font-weight: 500;">
                    <input type="checkbox" id="eventAllDay" onchange="toggleAllDay(this.checked)" style="width: 16px; height: 16px;">
                    <span>กิจกรรมทั้งวัน (All Day Event)</span>
                </label>
            </div>
            <div class="form-row" id="dateTimeFields">
                <div class="form-group">
                    <label class="form-label">เวลาเริ่มต้น</label>
                    <input type="datetime-local" class="form-control" id="eventStart">
                </div>
                <div class="form-group">
                    <label class="form-label">เวลาสิ้นสุด</label>
                    <input type="datetime-local" class="form-control" id="eventEnd">
                </div>
            </div>
            <div class="form-row" id="dateOnlyFields" style="display:none">
                <div class="form-group">
                    <label class="form-label">วันที่</label>
                    <input type="date" class="form-control" id="eventDate">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-danger btn-sm" id="deleteEventBtn" style="margin-right:auto;display:none" onclick="deleteEvent()">ลบกิจกรรม</button>
            <button class="btn btn-ghost" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" onclick="saveEvent()">บันทึก</button>
        </div>
    </div>
</div>
