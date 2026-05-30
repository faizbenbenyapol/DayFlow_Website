<div class="page-header flex items-center justify-between">
    <div>
        <h1 class="page-title">งาน</h1>
        <p class="page-subtitle">Eisenhower Matrix — จัดลำดับความสำคัญ</p>
    </div>
</div>

<!-- Eisenhower Matrix -->
<div class="matrix-grid" id="matrixGrid">

    <!-- Q1: สำคัญ + เร่งด่วน -->
    <div class="quadrant" id="q1">
        <div class="quadrant-header">
            <div>
                <div class="quadrant-label">ทำทันที</div>
                <div class="quadrant-title">สำคัญ + เร่งด่วน</div>
            </div>
            <div class="quadrant-meta">
                <span class="quadrant-count" id="q1-count">0 รายการ</span>
                <button class="btn btn-ghost btn-sm" onclick="openAddTask(1)">+ เพิ่ม</button>
            </div>
        </div>
        <div class="quadrant-body" id="q1-list" data-quadrant="1"></div>
        <form class="add-task-form" id="q1-form" style="display:none" onsubmit="event.preventDefault(); if (document.getElementById('q1-input').value.trim()) submitAddTask(1, document.getElementById('q1-input').value.trim());">
            <div style="display:flex; width:100%; position:relative; align-items:center;">
                <input class="add-task-input" type="text" id="q1-input" placeholder="ชื่องาน..." maxlength="255" style="padding-right:40px; width:100%;">
                <button class="add-task-submit-btn" type="submit" aria-label="เพิ่ม">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                </button>
            </div>
        </form>
    </div>

    <!-- Q2: สำคัญ + ไม่เร่งด่วน -->
    <div class="quadrant" id="q2">
        <div class="quadrant-header">
            <div>
                <div class="quadrant-label">วางแผน</div>
                <div class="quadrant-title">สำคัญ + ไม่เร่งด่วน</div>
            </div>
            <div class="quadrant-meta">
                <span class="quadrant-count" id="q2-count">0 รายการ</span>
                <button class="btn btn-ghost btn-sm" onclick="openAddTask(2)">+ เพิ่ม</button>
            </div>
        </div>
        <div class="quadrant-body" id="q2-list" data-quadrant="2"></div>
        <form class="add-task-form" id="q2-form" style="display:none" onsubmit="event.preventDefault(); if (document.getElementById('q2-input').value.trim()) submitAddTask(2, document.getElementById('q2-input').value.trim());">
            <div style="display:flex; width:100%; position:relative; align-items:center;">
                <input class="add-task-input" type="text" id="q2-input" placeholder="ชื่องาน..." maxlength="255" style="padding-right:40px; width:100%;">
                <button class="add-task-submit-btn" type="submit" aria-label="เพิ่ม">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                </button>
            </div>
        </form>
    </div>

    <!-- Q3: ไม่สำคัญ + เร่งด่วน -->
    <div class="quadrant" id="q3">
        <div class="quadrant-header">
            <div>
                <div class="quadrant-label">มอบหมาย</div>
                <div class="quadrant-title">ไม่สำคัญ + เร่งด่วน</div>
            </div>
            <div class="quadrant-meta">
                <span class="quadrant-count" id="q3-count">0 รายการ</span>
                <button class="btn btn-ghost btn-sm" onclick="openAddTask(3)">+ เพิ่ม</button>
            </div>
        </div>
        <div class="quadrant-body" id="q3-list" data-quadrant="3"></div>
        <form class="add-task-form" id="q3-form" style="display:none" onsubmit="event.preventDefault(); if (document.getElementById('q3-input').value.trim()) submitAddTask(3, document.getElementById('q3-input').value.trim());">
            <div style="display:flex; width:100%; position:relative; align-items:center;">
                <input class="add-task-input" type="text" id="q3-input" placeholder="ชื่องาน..." maxlength="255" style="padding-right:40px; width:100%;">
                <button class="add-task-submit-btn" type="submit" aria-label="เพิ่ม">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                </button>
            </div>
        </form>
    </div>

    <!-- Q4: ไม่สำคัญ + ไม่เร่งด่วน -->
    <div class="quadrant" id="q4">
        <div class="quadrant-header">
            <div>
                <div class="quadrant-label">ตัดทิ้ง</div>
                <div class="quadrant-title">ไม่สำคัญ + ไม่เร่งด่วน</div>
            </div>
            <div class="quadrant-meta">
                <span class="quadrant-count" id="q4-count">0 รายการ</span>
                <button class="btn btn-ghost btn-sm" onclick="openAddTask(4)">+ เพิ่ม</button>
            </div>
        </div>
        <div class="quadrant-body" id="q4-list" data-quadrant="4"></div>
        <form class="add-task-form" id="q4-form" style="display:none" onsubmit="event.preventDefault(); if (document.getElementById('q4-input').value.trim()) submitAddTask(4, document.getElementById('q4-input').value.trim());">
            <div style="display:flex; width:100%; position:relative; align-items:center;">
                <input class="add-task-input" type="text" id="q4-input" placeholder="ชื่องาน..." maxlength="255" style="padding-right:40px; width:100%;">
                <button class="add-task-submit-btn" type="submit" aria-label="เพิ่ม">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                </button>
            </div>
        </form>
    </div>

</div>

<!-- Edit Task Modal -->
<div class="modal-backdrop" id="editTaskModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">แก้ไขงาน</span>
            <button class="modal-close" type="button">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editTaskId">
            <div class="form-group">
                <label class="form-label">ชื่องาน</label>
                <input type="text" class="form-control" id="editTaskTitle" maxlength="255">
            </div>
            <div class="form-group">
                <label class="form-label">รายละเอียด</label>
                <textarea class="form-control" id="editTaskDesc" rows="3"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">หมวดหมู่</label>
                    <select class="form-control" id="editTaskQuadrant">
                        <option value="1">สำคัญ + เร่งด่วน</option>
                        <option value="2">สำคัญ + ไม่เร่งด่วน</option>
                        <option value="3">ไม่สำคัญ + เร่งด่วน</option>
                        <option value="4">ไม่สำคัญ + ไม่เร่งด่วน</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">วันครบกำหนด</label>
                    <input type="date" class="form-control" id="editTaskDue">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" type="button" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" type="button" onclick="saveEditTask()">บันทึก</button>
        </div>
    </div>
</div>
