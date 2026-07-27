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

<!-- Add Task Modal -->
<div class="modal-backdrop" id="addTaskModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">เพิ่มงานใหม่</span>
            <button class="modal-close" type="button">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">ชื่องาน</label>
                <input type="text" class="form-control" id="addTaskTitle" placeholder="ชื่องาน..." maxlength="255">
            </div>
            <div class="form-group">
                <label class="form-label">รายละเอียด</label>
                <textarea class="form-control" id="addTaskDesc" placeholder="รายละเอียดงาน..." rows="3"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">หมวดหมู่</label>
                    <select class="form-control" id="addTaskQuadrant">
                        <option value="1">สำคัญ + เร่งด่วน (ทำทันที)</option>
                        <option value="2">สำคัญ + ไม่เร่งด่วน (วางแผน)</option>
                        <option value="3">ไม่สำคัญ + เร่งด่วน (มอบหมาย)</option>
                        <option value="4">ไม่สำคัญ + ไม่เร่งด่วน (ตัดทิ้ง)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">วันครบกำหนด</label>
                    <input type="date" class="form-control" id="addTaskDue">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" type="button" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" type="button" onclick="saveAddTask()">บันทึก</button>
        </div>
    </div>
</div>
