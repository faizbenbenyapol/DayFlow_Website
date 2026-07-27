<div class="page-header flex items-center justify-between">
    <div>
        <h1 class="page-title">ออกกำลังกาย</h1>
    </div>
    <button class="btn btn-ghost btn-sm" onclick="openAddWorkout()">+ บันทึก</button>
</div>

<!-- Stats row -->
<div class="grid-3 mb-8" id="statsRow">
    <div class="card card-sm" style="text-align:center">
        <div class="text-xs text-muted mb-2">ครั้ง (เดือนนี้)</div>
        <div class="font-semibold" style="font-size:1.4rem" id="statSessions">—</div>
    </div>
    <div class="card card-sm" style="text-align:center">
        <div class="text-xs text-muted mb-2">นาที (เดือนนี้)</div>
        <div class="font-semibold" style="font-size:1.4rem" id="statMinutes">—</div>
    </div>
    <div class="card card-sm" style="text-align:center">
        <div class="text-xs text-muted mb-2">ประเภทยอดนิยม</div>
        <div class="font-semibold" style="font-size:1rem" id="statTopType">—</div>
    </div>
</div>

<!-- Per-type stats -->
<div class="card mb-8">
    <div class="card-header">
        <span class="card-title">สถิติตามประเภท</span>
    </div>
    <div id="typeStats" class="card-body">
        <div class="spinner"></div>
    </div>
</div>

<!-- Workout list -->
<div class="card">
    <div class="card-header">
        <span class="card-title">ประวัติ</span>
        <input type="month" class="form-control" id="monthFilter"
               value="<?= date('Y-m') ?>"
               style="width:auto"
               onchange="filterByMonth(this.value)">
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>วันที่</th>
                    <th>ประเภท</th>
                    <th>ระยะเวลา</th>
                    <th>เซต/ครั้ง</th>
                    <th>น้ำหนัก</th>
                    <th>หมายเหตุ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="workoutList">
                <tr><td colspan="7" class="text-center text-muted" style="padding:2rem">กำลังโหลด...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal-backdrop" id="workoutModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title" id="workoutModalTitle">บันทึกการออกกำลังกาย</span>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editWorkoutId">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">วันที่</label>
                    <input type="date" class="form-control" id="workoutDate" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">ประเภท</label>
                    <div class="custom-dropdown" id="workoutTypeDropdown">
                        <div class="dropdown-trigger-wrap">
                            <input type="text" class="form-control" id="workoutType"
                                   placeholder="เช่น วิ่ง ยกน้ำหนัก..." maxlength="100" autocomplete="off">
                            <span class="dropdown-caret">
                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 12 12"><path fill="currentColor" d="M6 8L1 3h10z"/></svg>
                            </span>
                        </div>
                        <div class="dropdown-menu" id="workoutTypeMenu">
                            <!-- Populated dynamically by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">ระยะเวลา (นาที)</label>
                    <input type="number" class="form-control" id="workoutDuration" min="1" max="999">
                </div>
                <div class="form-group">
                    <label class="form-label">เซต</label>
                    <input type="number" class="form-control" id="workoutSets" min="1" max="99">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">ครั้ง (reps)</label>
                    <input type="number" class="form-control" id="workoutReps" min="1" max="9999">
                </div>
                <div class="form-group">
                    <label class="form-label">น้ำหนัก (กก.)</label>
                    <input type="number" class="form-control" id="workoutWeight" step="0.5" min="0">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">หมายเหตุ</label>
                <textarea class="form-control" id="workoutNotes" rows="2"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" data-close-modal>ยกเลิก</button>
            <button class="btn btn-primary" onclick="saveWorkout()">บันทึก</button>
        </div>
    </div>
</div>
